<?php

declare(strict_types=1);

namespace Tinvar\Testing\Dev;

use Tinvar\Support\PrivateDirectory;

/**
 * A copy of an SQLite database, in a directory of this process's own, that
 * goes when the process does: what `render --dump` and the MCP server render
 * against (ADR 0088).
 *
 * A dump signs in through the application's own sessions, which writes a
 * session row, and a scenario writes the users it makes. Against the copy,
 * neither reaches the database the developer works with: the original is
 * opened read-only, copied whole and consistent by SQLite itself (`VACUUM
 * INTO`, which a read-only connection may run), and never written.
 */
final class DatabaseSnapshot
{
    private const string PREFIX = 'tinvar-snapshot';

    private bool $removed = false;

    private function __construct(
        public readonly string $path,
        private readonly string $directory,
    ) {
        $owner = getmypid() ?: 0;
        register_shutdown_function(function () use ($owner): void {
            // Not in a forked child, which runs shutdown functions too.
            if ((getmypid() ?: 0) === $owner) {
                $this->remove();
            }
        });
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

        return new self($path, $directory);
    }

    /** Removes the copy, and whatever the application wrote beside it. */
    public function remove(): void
    {
        if ($this->removed) {
            return;
        }
        $this->removed = true;
        PrivateDirectory::remove($this->directory);
    }
}
