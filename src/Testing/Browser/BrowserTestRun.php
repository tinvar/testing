<?php

declare(strict_types=1);

namespace Tinvar\Testing\Browser;

use Tinvar\Support\BuildProfile;
use Tinvar\Support\PrivateDirectory;

/**
 * One browser test run: a private directory the test runner makes before it
 * starts the server, which the server is pointed at with TINVAR_TEST_RUN.
 *
 * It holds what every process serving the run has to agree on, since under
 * PHP's built-in server each request is a process of its own and under
 * FrankenPHP each worker is:
 *
 *   - the hash of the run's token. The runner keeps the token itself and
 *     hands it to the browser test's script, never to the server, which
 *     can check a token and cannot give one out. A new run, a new token: one
 *     from an earlier run does not verify.
 *   - the clock's offset from the system's, in seconds, which the endpoint
 *     moves and every request reads (BrowserTests).
 *   - the users the run's scenarios seeded: the only ones the endpoint signs
 *     in.
 *
 * The directory and every file in it must be this user's alone, or the run
 * is refused: whoever could write the token's hash there could sign in as
 * anyone the run seeded.
 */
final class BrowserTestRun
{
    /** The header the token travels in. */
    public const string HEADER = 'x-tinvar-test-token';

    public const string TOKEN_FILE = 'token.sha256';

    public const string CLOCK_FILE = 'clock';

    public const string USERS_FILE = 'users';

    /** How far the clock may be moved either way: ten years. */
    public const int MAX_OFFSET = 315_576_000;

    private function __construct(
        public readonly string $directory,
        private readonly string $tokenHash,
    ) {}

    /**
     * Makes a run, for the test runner: its directory, and the token that
     * opens its endpoint, which only the runner is given.
     *
     * @return array{directory: string, token: string}
     */
    public static function prepare(?string $base = null): array
    {
        // A run whose runner was killed outright left its directory behind.
        PrivateDirectory::removeAbandoned('tinvar-test', $base);
        $directory = PrivateDirectory::create('tinvar-test', $base, owner: getmypid() ?: null);
        $token = bin2hex(random_bytes(32));
        self::create("{$directory}/" . self::TOKEN_FILE, hash('sha256', $token));
        self::create("{$directory}/" . self::CLOCK_FILE, '0');
        self::create("{$directory}/" . self::USERS_FILE, '');

        return ['directory' => $directory, 'token' => $token];
    }

    /** Removes a run's directory, for the runner, once its server has stopped. */
    public static function remove(string $directory): void
    {
        PrivateDirectory::remove($directory);
    }

    /**
     * The run TINVAR_TEST_RUN names, for the server.
     *
     * @param array<string, string>|null $env
     * @throws \RuntimeException when none is named, or it is not a run's
     */
    public static function fromEnvironment(?array $env = null): self
    {
        $env ??= array_filter(getenv(), \is_string(...));
        $directory = $env[BuildProfile::RUN_ENV] ?? '';
        if ($directory === '') {
            throw new \RuntimeException(
                BuildProfile::ENV . '=test, and no ' . BuildProfile::RUN_ENV . ' names the run: the test profile is served '
                . 'to a browser test run, which makes its directory and token before the server starts.',
            );
        }

        return self::open($directory);
    }

    /** @throws \RuntimeException unless $directory is a run's, private to this user */
    public static function open(string $directory): self
    {
        self::assertPrivate($directory, 'directory');
        foreach ([self::TOKEN_FILE, self::CLOCK_FILE, self::USERS_FILE] as $file) {
            self::assertPrivate("{$directory}/{$file}", 'file');
        }

        $hash = file_get_contents("{$directory}/" . self::TOKEN_FILE);
        if (!\is_string($hash) || preg_match('/^[0-9a-f]{64}\z/', $hash) !== 1) {
            throw new \RuntimeException("{$directory} holds no token a browser test run made.");
        }

        return new self($directory, $hash);
    }

    /** Whether $token is this run's. */
    public function admits(#[\SensitiveParameter] ?string $token): bool
    {
        return $token !== null
            && preg_match('/^[0-9a-f]{64}\z/', $token) === 1
            && hash_equals($this->tokenHash, hash('sha256', $token));
    }

    /** How far the run's clock is from the system's, in seconds. */
    public function offset(): int
    {
        $file = "{$this->directory}/" . self::CLOCK_FILE;
        self::assertPrivate($file, 'file');
        $read = file_get_contents($file);
        if (!\is_string($read) || preg_match('/^-?(?:0|[1-9][0-9]{0,9})\z/', $read) !== 1 || abs((int) $read) > self::MAX_OFFSET) {
            throw new \RuntimeException("{$file} does not hold a clock offset this run wrote.");
        }

        return (int) $read;
    }

    /** Moves the run's clock to $offset seconds from the system's: written whole, for every process at once. */
    public function moveClock(int $offset): void
    {
        if (abs($offset) > self::MAX_OFFSET) {
            throw new \InvalidArgumentException('The clock moves at most ten years either way.');
        }

        $this->replace(self::CLOCK_FILE, (string) $offset);
    }

    /** Records users a scenario seeded, whom the endpoint may then sign in. */
    public function remember(string ...$ids): void
    {
        $this->locked(static function (array $known) use ($ids): array {
            return array_values(array_unique([...$known, ...$ids]));
        });
    }

    /** Whether a scenario of this run seeded $id. */
    public function knows(string $id): bool
    {
        return \in_array($id, $this->locked(null), true);
    }

    /** Forgets every user the run seeded, and puts the clock back. */
    public function reset(): void
    {
        $this->locked(static fn (): array => []);
        $this->moveClock(0);
    }

    /**
     * The users file, read under a lock and, given $change, rewritten under
     * it: two scenarios seeded at once by two processes keep both.
     *
     * @param (\Closure(list<string>): list<string>)|null $change
     * @return list<string> the users after
     */
    private function locked(?\Closure $change): array
    {
        $file = "{$this->directory}/" . self::USERS_FILE;
        self::assertPrivate($file, 'file');
        $handle = fopen($file, 'r+');
        if ($handle === false) {
            throw new \RuntimeException("Could not open {$file}.");
        }

        try {
            flock($handle, $change === null ? LOCK_SH : LOCK_EX);
            $read = stream_get_contents($handle);
            $known = \is_string($read) && $read !== '' ? explode("\n", rtrim($read, "\n")) : [];
            if ($change === null) {
                return $known;
            }

            $after = $change($known);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $after === [] ? '' : implode("\n", $after) . "\n");
            fflush($handle);

            return $after;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Writes $name whole: to a new file beside it, renamed over it. */
    private function replace(string $name, string $contents): void
    {
        $temporary = "{$this->directory}/.{$name}." . bin2hex(random_bytes(6));
        self::create($temporary, $contents);
        if (!rename($temporary, "{$this->directory}/{$name}")) {
            @unlink($temporary);
            throw new \RuntimeException("Could not write {$this->directory}/{$name}.");
        }
    }

    /** A new file, this user's alone, that nothing was at before. */
    private static function create(string $path, string $contents): void
    {
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new \RuntimeException("Could not create {$path}: something is already there.");
        }
        try {
            chmod($path, 0600);
            fwrite($handle, $contents);
        } finally {
            fclose($handle);
        }
    }

    /**
     * That $path is this user's alone: no symlink, owned by this user, and
     * nobody else may read, write or enter it.
     */
    private static function assertPrivate(string $path, string $kind): void
    {
        clearstatcache(true, $path);
        $exists = $kind === 'directory' ? is_dir($path) : is_file($path);
        if (!str_starts_with($path, '/') || is_link($path) || !$exists) {
            throw new \RuntimeException("The browser test run's {$kind} {$path} is not one a run made.");
        }

        $stat = @stat($path);
        if ($stat === false
            || (\function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())
            || ($stat['mode'] & 0077) !== 0) {
            throw new \RuntimeException("The browser test run's {$kind} {$path} is not private to this user; refusing it.");
        }
    }
}
