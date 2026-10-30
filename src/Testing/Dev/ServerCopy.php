<?php

declare(strict_types=1);

namespace Tinvar\Testing\Dev;

use Tinvar\Config\Secret;

/**
 * A copy of a Postgres or MySQL database in a database of its own on the
 * same server, made by the server's own dump and restore tools: what
 * `render --dump` and the MCP server render against for an application not
 * on SQLite (ADR 0113).
 *
 * The rule is SQLite's (ADR 0088): the render never has the live data
 * writable. Here that holds because
 *
 *   - the live database is only ever read, by the dump tool -- `pg_dump`,
 *     whose session the server is told to make read-only
 *     (`default_transaction_read_only`), or `mysqldump`, which reads in one
 *     consistent transaction and is given no statement to run;
 *   - the copy is restored into a database this class created, with a name
 *     it made up, and the restore is held to that one database
 *     (`pg_restore --dbname`, `mysql --one-database`);
 *   - on MySQL, which names any database in a statement, the copy is made,
 *     restored and handed over under a user of its own
 *     (TINVAR_DUMP_MYSQL_DSN) whose grants reach the copies alone, read
 *     with SHOW GRANTS before anything is made;
 *   - what the application is handed is the copy's DSN, checked to name
 *     the copy before it is handed over -- the server asked which database
 *     the connection is in -- and nothing else;
 *   - and the copy is dropped when the render is done, by its own name,
 *     which matches only names this class makes.
 *
 * What it cannot do it refuses, before anything is created: no dump or
 * restore tool on the PATH, a DSN it cannot read, a user the server will
 * not let create a database, and on MySQL no copies' user, or one that
 * could reach any other database. A copy is never made by hand, statement by
 * statement, where a table it did not know of could be left behind.
 *
 * The password goes to the tools in a file only this user may read, in the
 * snapshot's private directory, never on a command line or in the
 * environment; the copy's DSN carries it, as the application's own does.
 */
final class ServerCopy
{
    /** What every copy's name starts with; nothing else is ever dropped. */
    public const string PREFIX = 'tinvar_dump_';

    /** A copy's name: the prefix, this host, the process that made it, and a random part. */
    private const string NAME = '/^tinvar_dump_[0-9a-f]{8}_[1-9][0-9]{0,9}_[0-9a-f]{8}\z/';

    /** Where a MySQL copy's own user is named: a DSN whose user reaches the copies alone. */
    public const string MYSQL_COPY_ENV = 'TINVAR_DUMP_MYSQL_DSN';

    /**
     * @param array<string, string> $dsn where the copy is made, read: the source's server
     *        and credentials on Postgres, the copies' own user's on MySQL
     * @param array<string, string> $source the source's DSN, read: what is dumped
     */
    private function __construct(
        public readonly string $driver,
        public readonly string $name,
        private readonly array $dsn,
        private readonly array $source,
    ) {}

    /**
     * Copies the database $source names into a new one beside it, its dump
     * written in $directory, a private directory of this process's own.
     *
     * On MySQL the copy is made, restored and handed over under a user of
     * its own, $mysqlCopy (TINVAR_DUMP_MYSQL_DSN), whose grants reach the
     * copies' databases and nothing else: MySQL names any database in a
     * statement (`live.orders`), so a render, a trigger or a routine
     * handed the source's own user on the copy could still write the live
     * data. SHOW GRANTS is read before anything is made, and a user that
     * could reach any other database is refused. Postgres connects to one
     * database at a time, and keeps the source's user.
     *
     * @throws \RuntimeException when it cannot be copied as above
     */
    public static function make(string $source, string $directory, #[\SensitiveParameter] ?Secret $mysqlCopy = null, ?\Closure $run = null): self
    {
        [$driver, $sourceDsn] = self::read($source);
        $dsn = $sourceDsn;
        if ($driver === 'mysql') {
            if ($mysqlCopy === null) {
                throw new \RuntimeException('Copying a mysql database needs a user of its own that reaches the copies alone, named in ' . self::MYSQL_COPY_ENV
                    . ": GRANT ALL ON `tinvar\\_dump\\_%`.* and nothing else. Without one the copy would be handed the live database's user; nothing was copied.");
            }
            [$copyDriver, $dsn] = self::read($mysqlCopy->reveal(), database: false);
            if ($copyDriver !== 'mysql') {
                throw new \RuntimeException(self::MYSQL_COPY_ENV . ' is not a mysql: DSN; nothing was copied.');
            }
            unset($dsn['dbname']);
        }
        $run ??= self::run(...);
        $tools = $driver === 'pgsql' ? ['pg_dump', 'pg_restore'] : ['mysqldump', 'mysql'];
        foreach ($tools as $tool) {
            if (self::find($tool) === null) {
                throw new \RuntimeException("Copying a {$driver} database needs {$tool} on the PATH, and there is none: nothing was copied.");
            }
        }

        // Opened for the two statements it sends -- CREATE DATABASE, and the
        // sweep's DROPs of this host's abandoned copies -- and closed: no
        // connection to the live database stays open while anything renders.
        $admin = self::connect($driver, $dsn);
        if ($driver === 'mysql') {
            $why = self::grantsConfined(self::grants($admin));
            if ($why !== null) {
                unset($admin);

                throw new \RuntimeException('The user ' . self::MYSQL_COPY_ENV . " names is not one that reaches the copies alone: {$why}. Nothing was copied.");
            }
        }
        self::dropAbandoned($driver, $admin);
        $name = self::PREFIX . substr(hash('sha256', (string) gethostname()), 0, 8) . '_' . (getmypid() ?: 1) . '_' . bin2hex(random_bytes(4));
        $copy = new self($driver, $name, $dsn, $sourceDsn);
        try {
            $admin->exec($driver === 'pgsql' ? "CREATE DATABASE \"{$name}\" TEMPLATE template0" : "CREATE DATABASE `{$name}`");
        } catch (\PDOException $e) {
            throw new \RuntimeException("The {$driver} server would not create a database to copy into: {$e->getMessage()}", previous: $e);
        } finally {
            unset($admin);
        }

        try {
            $copy->fill($directory, $run);
            $copy->assertConnectsToCopy();
        } catch (\Throwable $e) {
            $copy->drop();

            throw $e instanceof \RuntimeException ? $e : new \RuntimeException($e->getMessage(), previous: $e);
        }

        return $copy;
    }

    /** The DSN the application is handed: the copy's, with the source's credentials on Postgres and the copies' user's on MySQL. */
    public function dsn(): string
    {
        $parts = [...$this->dsn, 'dbname' => $this->name];
        $pairs = [];
        foreach ($parts as $key => $value) {
            $pairs[] = "{$key}={$value}";
        }

        return "{$this->driver}:" . implode(';', $pairs);
    }

    /** Drops the copy, by its own name; nothing when it is gone already. */
    public function drop(): void
    {
        try {
            $admin = self::connect($this->driver, $this->dsn);
        } catch (\RuntimeException) {
            // The server is gone; the next copy's sweep drops this one.
            return;
        }
        self::dropNamed($this->driver, $admin, $this->name);
    }

    /**
     * Why a MySQL user with $grants (SHOW GRANTS, one line each) could
     * reach more than the copies' databases, or null when it cannot.
     *
     * Each line must be one of two: USAGE on `*.*`, which is no privilege,
     * or a list of plain privileges on exactly `tinvar\_dump\_%` -- its
     * underscores escaped, since an unescaped `_` matches any character.
     * A role, a proxy, a grant on any other database, table or column, a
     * privilege on `*.*`, a partial revoke and WITH GRANT OPTION are each
     * refused, as is a user with no grant on the copies at all. What this
     * cannot read is refused rather than guessed at.
     *
     * @internal
     * @param list<string> $grants
     */
    public static function grantsConfined(array $grants): ?string
    {
        $copies = false;
        foreach ($grants as $grant) {
            if (str_contains($grant, 'WITH GRANT OPTION') || str_contains($grant, 'WITH ADMIN OPTION')) {
                return 'it may grant what it holds to others';
            }
            if (preg_match('/^GRANT USAGE ON \*\.\* TO .+\z/s', $grant) === 1) {
                continue;
            }
            if (preg_match('/^GRANT [A-Z][A-Z ,_]* ON `tinvar\\\\_dump\\\\_%`\.\* TO .+\z/s', $grant) === 1) {
                $copies = true;

                continue;
            }

            return 'it holds a grant beyond the copies\' databases (`tinvar\_dump\_%`)';
        }

        return $copies ? null : 'it holds no grant on the copies\' databases (`tinvar\_dump\_%`)';
    }

    /**
     * The connected user's grants, and any role it has active as one more
     * line, so that it is refused: a role's grants are not in SHOW GRANTS.
     *
     * @return list<string>
     */
    private static function grants(\PDO $admin): array
    {
        try {
            $listed = $admin->query('SHOW GRANTS');
            $grants = $listed === false ? [] : $listed->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\PDOException $e) {
            throw new \RuntimeException('The server would not say what the copies\' user may do (SHOW GRANTS); nothing was copied.', previous: $e);
        }
        try {
            $statement = $admin->query('SELECT CURRENT_ROLE()');
            $role = $statement === false ? null : $statement->fetchColumn();
        } catch (\PDOException) {
            // A server with no roles: there is none to have.
            $role = null;
        }
        if (\is_string($role) && $role !== '' && $role !== 'NONE') {
            $grants[] = "ROLE {$role}";
        }

        return array_values(array_map(strval(...), $grants));
    }

    /**
     * Whether $source is a DSN this class copies: a Postgres or MySQL one.
     */
    public static function copies(string $source): bool
    {
        return str_starts_with($source, 'pgsql:') || str_starts_with($source, 'mysql:');
    }

    /**
     * The driver and the DSN's parts.
     *
     * @param bool $database whether it must name a dbname: the source's must, the copies' user's need not
     * @return array{'pgsql'|'mysql', array<string, string>}
     */
    private static function read(#[\SensitiveParameter] string $source, bool $database = true): array
    {
        $driver = str_starts_with($source, 'pgsql:') ? 'pgsql' : (str_starts_with($source, 'mysql:') ? 'mysql' : null);
        if ($driver === null) {
            throw new \RuntimeException('Only a pgsql: or mysql: DSN is copied on its server.');
        }

        $parts = [];
        foreach (explode(';', substr($source, \strlen($driver) + 1)) as $pair) {
            if (trim($pair) === '') {
                continue;
            }
            $at = strpos($pair, '=');
            if ($at === false) {
                throw new \RuntimeException("The {$driver} DSN has a part that is not key=value; nothing was copied.");
            }
            $key = strtolower(trim(substr($pair, 0, $at)));
            if (preg_match('/^[a-z_]+\z/', $key) !== 1) {
                throw new \RuntimeException("The {$driver} DSN has a key that is not one; nothing was copied.");
            }
            $parts[$key] = trim(substr($pair, $at + 1));
        }
        if ($database && ($parts['dbname'] ?? '') === '') {
            throw new \RuntimeException("The {$driver} DSN names no dbname to copy.");
        }
        // A value the tools' own syntax would read as more than one value.
        foreach ($parts as $key => $value) {
            if (preg_match('/[\x00-\x1f]/', $value) === 1) {
                throw new \RuntimeException("The {$driver} DSN's {$key} holds a control character; nothing was copied.");
            }
        }

        return [$driver, $parts];
    }

    /** @param array<string, string> $dsn */
    private static function connect(string $driver, array $dsn, ?string $database = null): \PDO
    {
        $parts = $dsn;
        $user = $parts['user'] ?? null;
        $password = $parts['password'] ?? null;
        unset($parts['user'], $parts['password']);
        if ($database !== null) {
            $parts['dbname'] = $database;
        }
        $pairs = [];
        foreach ($parts as $key => $value) {
            $pairs[] = "{$key}={$value}";
        }

        try {
            return new \PDO("{$driver}:" . implode(';', $pairs), $user, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        } catch (\PDOException $e) {
            throw new \RuntimeException("Could not connect to the {$driver} server to copy its database: {$e->getMessage()}", previous: $e);
        }
    }

    /** Dumps the source -- reading it, and nothing else -- and restores it into the copy. */
    private function fill(string $directory, \Closure $run): void
    {
        $dump = "{$directory}/dump";
        $secrets = "{$directory}/" . ($this->driver === 'pgsql' ? 'pgpass' : 'my.cnf');
        $this->writeSecrets($secrets, $this->source);
        $base = ['PATH' => (string) getenv('PATH'), 'LANG' => 'C'];

        if ($this->driver === 'pgsql') {
            $env = [...$base, 'PGPASSFILE' => $secrets, ...(isset($this->dsn['sslmode']) ? ['PGSSLMODE' => $this->dsn['sslmode']] : [])];
            // The dump's session is read-only on the server's word, whatever
            // pg_dump would send: it is only ever asked to read.
            $run(['pg_dump', '--format=custom', '--no-owner', '--no-privileges', "--file={$dump}", '--dbname=' . $this->conninfo($this->dsn['dbname'])], [...$env, 'PGOPTIONS' => '-c default_transaction_read_only=on'], null);
            $run(['pg_restore', '--no-owner', '--no-privileges', '--single-transaction', '--exit-on-error', '--dbname=' . $this->conninfo($this->name), $dump], $env, null);

            return;
        }

        // Dumped as the source's user, which reads it; restored as the
        // copies' own, which can reach nothing else.
        $copySecrets = "{$directory}/my-copy.cnf";
        $this->writeSecrets($copySecrets, $this->dsn);
        $run(['mysqldump', "--defaults-extra-file={$secrets}", ...self::mysqlConnection($this->source), '--single-transaction', '--skip-lock-tables', '--no-tablespaces', '--routines', '--triggers', "--result-file={$dump}", '--', $this->source['dbname']], $base, null);
        self::assertNoClientCommands($dump);
        $run(self::mysqlRestore($this->dsn, $copySecrets, $this->name), $base, $dump);
    }

    /**
     * The `mysql` command that restores a dump, read from standard input,
     * into $copy alone.
     *
     * `--binary-mode` turns off every one of the client's own commands but
     * `delimiter` and `charset` -- `\!` and `system`, which run a shell
     * command, `source`, `connect`, `use` -- so nothing in the dump is
     * anything but SQL sent to the server: the dump holds the live
     * database's names, routines and data. `--one-database` skips any
     * statement a `USE` of another database would have run.
     *
     * @internal
     * @param array<string, string> $dsn where the copy is
     * @return list<string>
     */
    public static function mysqlRestore(array $dsn, string $secrets, string $copy): array
    {
        return ['mysql', "--defaults-extra-file={$secrets}", ...self::mysqlConnection($dsn), '--binary-mode', '--one-database', "--database={$copy}"];
    }

    /**
     * Refuses a dump with a line that begins as the client's own command
     * does, a backslash: mysqldump writes none -- a newline in a value is
     * written `\n` -- so one there was put there, and is not restored even
     * with --binary-mode to stop it.
     *
     * @internal
     * @throws \RuntimeException
     */
    public static function assertNoClientCommands(string $dump): void
    {
        $handle = @fopen($dump, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('The dump could not be read back; nothing was restored.');
        }
        try {
            while (($line = fgets($handle)) !== false) {
                if (preg_match('/^[ \t]*\\\\/', $line) === 1) {
                    throw new \RuntimeException('The dump holds a line the mysql client would read as a command of its own; nothing was restored.');
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array<string, string> $dsn
     * @return list<string>
     */
    private static function mysqlConnection(array $dsn): array
    {
        return [
            ...(isset($dsn['host']) ? ["--host={$dsn['host']}"] : []),
            ...(isset($dsn['port']) ? ["--port={$dsn['port']}"] : []),
            ...(isset($dsn['unix_socket']) ? ["--socket={$dsn['unix_socket']}"] : []),
        ];
    }

    /**
     * $dsn's user and password, for the tools, in a file only this user can read.
     *
     * @param array<string, string> $dsn
     */
    private function writeSecrets(string $file, #[\SensitiveParameter] array $dsn): void
    {
        $user = $dsn['user'] ?? '';
        $password = $dsn['password'] ?? '';
        $escape = static fn (string $value): string => str_replace(['\\', ':'], ['\\\\', '\\:'], $value);
        $text = $this->driver === 'pgsql'
            ? '*:*:*:' . $escape($user) . ':' . $escape($password) . "\n"
            : "[client]\nuser=\"" . addcslashes($user, "\"\\") . "\"\npassword=\"" . addcslashes($password, "\"\\") . "\"\n";

        $handle = @fopen($file, 'x');
        if ($handle === false) {
            throw new \RuntimeException("Could not write {$file}; nothing was copied.");
        }
        try {
            chmod($file, 0600);
            fwrite($handle, $text);
        } finally {
            fclose($handle);
        }
    }

    /** A libpq connection string for $database on the source's server, with no password in it. */
    private function conninfo(string $database): string
    {
        $parts = ['dbname' => $database];
        foreach (['host', 'port', 'user', 'sslmode', 'connect_timeout'] as $key) {
            if (isset($this->dsn[$key])) {
                $parts[$key] = $this->dsn[$key];
            }
        }
        $pairs = [];
        foreach ($parts as $key => $value) {
            $pairs[] = $key . "='" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
        }

        return implode(' ', $pairs);
    }

    /** That a connection made with the copy's DSN is in the copy: what the application gets is not the source. */
    private function assertConnectsToCopy(): void
    {
        $pdo = self::connect($this->driver, [...$this->dsn, 'dbname' => $this->name]);
        $statement = $pdo->query($this->driver === 'pgsql' ? 'SELECT current_database()' : 'SELECT DATABASE()');
        $current = $statement === false ? null : $statement->fetchColumn();
        if ($current !== $this->name) {
            throw new \RuntimeException("A connection to the copy reached '" . (\is_string($current) ? $current : '?') . "', not the copy; it is not handed over.");
        }
    }

    /** Drops copies this host's processes made that are no longer running. */
    private static function dropAbandoned(string $driver, \PDO $admin): void
    {
        if (!\function_exists('posix_kill')) {
            return;
        }
        $host = substr(hash('sha256', (string) gethostname()), 0, 8);
        $listed = $admin->query($driver === 'pgsql'
            ? "SELECT datname FROM pg_database WHERE datname LIKE 'tinvar\\_dump\\_%'"
            : "SHOW DATABASES LIKE 'tinvar\\_dump\\_%'");
        foreach ($listed === false ? [] : $listed->fetchAll(\PDO::FETCH_COLUMN) as $name) {
            if (!\is_string($name) || preg_match(self::NAME, $name) !== 1 || !str_starts_with($name, self::PREFIX . "{$host}_")) {
                continue;
            }
            $pid = (int) explode('_', substr($name, \strlen(self::PREFIX)))[1];
            if ($pid !== (getmypid() ?: 0) && !posix_kill($pid, 0) && posix_get_last_error() === 3) {
                self::dropNamed($driver, $admin, $name);
            }
        }
    }

    private static function dropNamed(string $driver, \PDO $admin, string $name): void
    {
        // Only a name this class made: nothing else is ever dropped.
        if (preg_match(self::NAME, $name) !== 1) {
            return;
        }
        $statements = $driver === 'pgsql'
            // FORCE ends a session the application left open on it; a server
            // before Postgres 13 has no FORCE, and is asked plainly.
            ? ["DROP DATABASE IF EXISTS \"{$name}\" WITH (FORCE)", "DROP DATABASE IF EXISTS \"{$name}\""]
            : ["DROP DATABASE IF EXISTS `{$name}`"];
        foreach ($statements as $statement) {
            try {
                $admin->exec($statement);

                return;
            } catch (\PDOException) {
                // The next form, or left for the next copy's sweep of abandoned ones.
            }
        }
    }

    /** The tool's path on the PATH, or null. */
    private static function find(string $tool): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            if ($directory !== '' && is_file("{$directory}/{$tool}") && is_executable("{$directory}/{$tool}")) {
                return "{$directory}/{$tool}";
            }
        }

        return null;
    }

    /**
     * Runs a tool, no shell between, with $env alone, standard input from
     * $input or nothing; throws with what it wrote to standard error when it
     * fails.
     *
     * @param list<string> $command
     * @param array<string, string> $env
     */
    private static function run(array $command, array $env, ?string $input): void
    {
        $tool = self::find($command[0]) ?? throw new \RuntimeException("{$command[0]} is not on the PATH.");
        $command[0] = $tool;
        $process = proc_open($command, [0 => $input === null ? ['file', '/dev/null', 'r'] : ['file', $input, 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!\is_resource($process)) {
            throw new \RuntimeException("Could not run {$command[0]}.");
        }
        $error = (string) stream_get_contents($pipes[2], 8192);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0) {
            throw new \RuntimeException(basename($command[0]) . " failed ({$status}): " . trim($error));
        }
    }
}
