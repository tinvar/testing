<?php

declare(strict_types=1);

namespace Tinvar\Testing\Dev;

use Tinvar\Config\Secret;
use Tinvar\Support\PrivateDirectory;

/**
 * A copy of the application's database, in a directory of this process's
 * own, that goes when the process does: what `render --dump` and the MCP
 * server render against (ADR 0088). SQLite's is a file; a Postgres or
 * MySQL database is copied into a database of its own on its server
 * (ServerCopy, ADR 0113), dropped when this is removed.
 *
 * A dump signs in through the application's own sessions, which writes a
 * session row, and a scenario writes the users it makes. Against the copy,
 * neither reaches the database the developer works with: the original is
 * opened read-only, copied whole and consistent by SQLite itself (`VACUUM
 * INTO`, which a read-only connection may run), and never written.
 *
 * What an application keeps in files beside its database -- a log, a mail
 * log, counts -- it keeps beside the copy, named from $files, so they are
 * the copy's and go with it: the SQLite copy's own path, or for a copy on a
 * server, whose DSN names no place, a path in the snapshot's private
 * directory (ADR 0173).
 */
final class DatabaseSnapshot
{
    private const string PREFIX = 'tinvar-snapshot';

    private bool $removed = false;

    /**
     * What the files an application keeps beside its database are named
     * from: the SQLite copy's path, or for a copy on a server a path in this
     * snapshot's private directory, where nothing is until the application
     * writes beside it. Removed with the copy either way.
     */
    public readonly string $files;

    /**
     * @param string $path the SQLite copy's file; '' for a copy on a server
     * @param string $database what the application is handed: the file, or the copy's DSN
     */
    private function __construct(
        public readonly string $path,
        private readonly string $directory,
        #[\SensitiveParameter]
        public readonly string $database = '',
        private readonly ?ServerCopy $server = null,
    ) {
        $this->files = $path !== '' ? $path : "{$directory}/copy";
        $owner = getmypid() ?: 0;
        register_shutdown_function(function () use ($owner): void {
            // Not in a forked child, which runs shutdown functions too.
            if ((getmypid() ?: 0) === $owner) {
                $this->remove();
            }
        });
    }

    /**
     * A private copy of the database $source names: an SQLite file's path,
     * or a pgsql: or mysql: DSN, which carries a password and so stays out
     * of a failure's trace. A MySQL copy is made as $mysqlCopy's user, one
     * that reaches the copies alone (ServerCopy::make()).
     */
    public static function of(#[\SensitiveParameter] string $source, #[\SensitiveParameter] ?Secret $mysqlCopy = null): self
    {
        if (!ServerCopy::copies($source)) {
            return self::ofSqlite($source);
        }

        PrivateDirectory::removeAbandoned(self::PREFIX);
        $directory = PrivateDirectory::create(self::PREFIX, owner: getmypid() ?: null);
        try {
            $copy = ServerCopy::make($source, $directory, $mysqlCopy);
        } catch (\Throwable $e) {
            PrivateDirectory::remove($directory);

            throw $e;
        }
        // The dump and the credentials the tools read are not kept.
        foreach (['dump', 'pgpass', 'my.cnf', 'my-copy.cnf'] as $file) {
            if (is_file("{$directory}/{$file}")) {
                unlink("{$directory}/{$file}");
            }
        }

        return new self('', $directory, $copy->dsn(), $copy);
    }

    /** A private copy of the SQLite database at $source. */
    public static function ofSqlite(string $source): self
    {
        clearstatcache(true, $source);
        if (!is_file($source)) {
            throw new \RuntimeException("There is no database at {$source} to copy.");
        }

        PrivateDirectory::removeAbandoned(self::PREFIX);
        $directory = PrivateDirectory::create(self::PREFIX, owner: getmypid() ?: null);
        $path = "{$directory}/snapshot.sqlite";
        try {
            $original = \PDO::connect('sqlite:' . $source, options: [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \Pdo\Sqlite::ATTR_OPEN_FLAGS => \Pdo\Sqlite::OPEN_READONLY,
            ]);
            $original->exec('VACUUM INTO ' . $original->quote($path));
        } catch (\PDOException $e) {
            PrivateDirectory::remove($directory);

            throw new \RuntimeException("Could not copy the database at {$source}: {$e->getMessage()}", previous: $e);
        }
        unset($original);
        chmod($path, 0600);

        return new self($path, $directory, $path);
    }

    /** Removes the copy, and whatever the application wrote beside it. */
    public function remove(): void
    {
        if ($this->removed) {
            return;
        }
        $this->removed = true;
        $this->server?->drop();
        PrivateDirectory::remove($this->directory);
    }
}
