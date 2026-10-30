<?php

declare(strict_types=1);

namespace Tinvar\Testing\Dev;

use Tinvar\Server\ServeCommand;
use Tinvar\Support\PrivateDirectory;

/**
 * A `tinvar dev` running for an application on this machine, as `render
 * --dump --dev` and `mcp --dev` find it: the dev renderer's socket, and
 * the deferred manifest it keeps beside it (ADR 0132).
 *
 * Found where serve --dev runs, in a private directory of its own under
 * /tmp, by the record it writes there once its dev renderer listens. Each
 * thing about that directory is checked before anything in it is trusted,
 * since a render handed to a socket is the page's props handed to whatever
 * listens on it:
 *
 *   - named as PrivateDirectory names one, not a link, this user's, and
 *     nobody else's to read, write or enter (0700);
 *   - its owner, the serve that made it, still running;
 *   - its record a plain file of this user's, naming this application;
 *   - the socket the one in that directory, by the name serve gives it --
 *     never a path the record says -- and a socket.
 *
 * The render channel holds the socket to its directory again as it
 * connects (RenderChannel).
 */
final readonly class DevServerRun
{
    private function __construct(
        public string $socket,
        public string $deferred,
    ) {}

    /**
     * The one dev run serving $app.
     *
     * @param string|null $base where the runs' directories are; /tmp unless that is missing
     * @throws \RuntimeException when none is running for $app, or more than one is
     */
    public static function find(string $app, ?string $base = null): self
    {
        // Without them, whose a directory is and whether its serve still
        // runs cannot be told: nothing is found rather than anything trusted.
        if (!\function_exists('posix_geteuid') || !\function_exists('posix_kill')) {
            throw new \RuntimeException('Rendering through `tinvar dev` needs PHP\'s posix extension, to tell whose its run is.');
        }
        $app = realpath($app) ?: $app;
        $base = rtrim($base ?? (is_dir('/tmp') ? '/tmp' : sys_get_temp_dir()), '/');
        $uid = posix_geteuid();

        $found = [];
        foreach (glob("{$base}/" . ServeCommand::RUN_PREFIX . '-*', GLOB_ONLYDIR) ?: [] as $directory) {
            if (self::serves($directory, $app, $uid)) {
                $found[] = $directory;
            }
        }

        if ($found === []) {
            throw new \RuntimeException(
                "No `tinvar dev` is serving {$app} on this machine. Start one, and render through it with --dev; "
                . 'or render through a test build without --dev.',
            );
        }
        if (\count($found) > 1) {
            throw new \RuntimeException(
                \count($found) . " runs of `tinvar dev` serve {$app}; stop all but one, so it is clear which renders.",
            );
        }

        return new self("{$found[0]}/" . ServeCommand::DEV_SOCKET, "{$found[0]}/" . ServeCommand::DEV_DEFERRED);
    }

    private static function serves(string $directory, string $app, int $uid): bool
    {
        clearstatcache(true, $directory);
        if (preg_match('/^' . preg_quote(ServeCommand::RUN_PREFIX, '/') . '-[0-9a-f]{10}\z/', basename($directory)) !== 1
            || is_link($directory)
            || !self::mine($directory, $uid)
            || ((int) fileperms($directory) & 0777) !== 0700) {
            return false;
        }

        $owner = self::read("{$directory}/" . PrivateDirectory::OWNER_FILE, $uid);
        if ($owner === null || preg_match('/^[1-9][0-9]{0,9}\z/', $owner) !== 1 || !self::running((int) $owner)) {
            return false;
        }

        $record = self::read("{$directory}/" . ServeCommand::DEV_RECORD, $uid);
        $said = $record === null ? null : json_decode($record, true);
        if (!\is_array($said) || !\is_string($said['app'] ?? null) || (realpath($said['app']) ?: $said['app']) !== $app) {
            return false;
        }

        $socket = "{$directory}/" . ServeCommand::DEV_SOCKET;

        return !is_link($socket) && file_exists($socket) && filetype($socket) === 'socket' && self::mine($socket, $uid);
    }

    /** $path's contents, where it is a plain file of this user's and small; null otherwise. */
    private static function read(string $path, int $uid): ?string
    {
        if (is_link($path) || !is_file($path) || !self::mine($path, $uid) || (int) filesize($path) > 4096) {
            return null;
        }
        $contents = @file_get_contents($path);

        return \is_string($contents) ? trim($contents) : null;
    }

    private static function mine(string $path, int $uid): bool
    {
        return @fileowner($path) === $uid;
    }

    /** Signal 0 asks without sending anything; the serve is this user's, so it answers. */
    private static function running(int $pid): bool
    {
        return posix_kill($pid, 0);
    }
}
