<?php

declare(strict_types=1);

namespace Tinvar\Testing\Dev;

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
 *   - what the application is handed is the copy's DSN, checked to name
 *     the copy before it is handed over -- the server asked which database
 *     the connection is in -- and nothing else;
 *   - and the copy is dropped when the render is done, by its own name,
 *     which matches only names this class makes.
 *
 * What it cannot do it refuses, before anything is created: no dump or
 * restore tool on the PATH, a DSN it cannot read, a user the server will
 * not let create a database. A copy is never made by hand, statement by
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

    /** @param array<string, string> $dsn the source's DSN, read */
    private function __construct(
        public readonly string $driver,
        public readonly string $name,
        private readonly array $dsn,
    ) {}

    /**
     * Copies the database $source names into a new one beside it, its dump
     * written in $directory, a private directory of this process's own.
     *
     * @throws \RuntimeException when it cannot be copied as above
     */
    public static function make(string $source, string $directory, ?\Closure $run = null): self
    {
        [$driver, $dsn] = self::read($source);
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
        self::dropAbandoned($driver, $admin);
        $name = self::PREFIX . substr(hash('sha256', (string) gethostname()), 0, 8) . '_' . (getmypid() ?: 1) . '_' . bin2hex(random_bytes(4));
        $copy = new self($driver, $name, $dsn);
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

    /** The DSN the application is handed: the copy's, with the source's credentials and settings. */
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
     * Whether $source is a DSN this class copies: a Postgres or MySQL one.
     */
    public static function copies(string $source): bool
    {
        return str_starts_with($source, 'pgsql:') || str_starts_with($source, 'mysql:');
    }

    /**
     * The driver and the DSN's parts.
     *
     * @return array{'pgsql'|'mysql', array<string, string>}
     */
    private static function read(string $source): array
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
        if (($parts['dbname'] ?? '') === '') {
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
        $this->writeSecrets($secrets);
        $base = ['PATH' => (string) getenv('PATH'), 'LANG' => 'C'];

        if ($this->driver === 'pgsql') {
            $env = [...$base, 'PGPASSFILE' => $secrets, ...(isset($this->dsn['sslmode']) ? ['PGSSLMODE' => $this->dsn['sslmode']] : [])];
            // The dump's session is read-only on the server's word, whatever
            // pg_dump would send: it is only ever asked to read.
            $run(['pg_dump', '--format=custom', '--no-owner', '--no-privileges', "--file={$dump}", '--dbname=' . $this->conninfo($this->dsn['dbname'])], [...$env, 'PGOPTIONS' => '-c default_transaction_read_only=on'], null);
            $run(['pg_restore', '--no-owner', '--no-privileges', '--single-transaction', '--exit-on-error', '--dbname=' . $this->conninfo($this->name), $dump], $env, null);

            return;
        }

        $connection = [
            ...(isset($this->dsn['host']) ? ["--host={$this->dsn['host']}"] : []),
            ...(isset($this->dsn['port']) ? ["--port={$this->dsn['port']}"] : []),
            ...(isset($this->dsn['unix_socket']) ? ["--socket={$this->dsn['unix_socket']}"] : []),
        ];
        $run(['mysqldump', "--defaults-extra-file={$secrets}", ...$connection, '--single-transaction', '--skip-lock-tables', '--no-tablespaces', '--routines', '--triggers', "--result-file={$dump}", '--', $this->dsn['dbname']], $base, null);
        // Held to the copy: a statement in the dump for any other database is skipped.
        $run(['mysql', "--defaults-extra-file={$secrets}", ...$connection, '--one-database', "--database={$this->name}"], $base, $dump);
    }

    /** The source's user and password, for the tools, in a file only this user can read. */
    private function writeSecrets(string $file): void
    {
        $user = $this->dsn['user'] ?? '';
        $password = $this->dsn['password'] ?? '';
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
