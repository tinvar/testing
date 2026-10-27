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
        return self::parseOffset($this->locked(self::CLOCK_FILE, null), $this->directory);
    }

    /** Moves the run's clock to $offset seconds from the system's, for every process at once. */
    public function moveClock(int $offset): void
    {
        self::assertOffset($offset);
        $this->locked(self::CLOCK_FILE, static fn (): string => (string) $offset);
    }

    /**
     * Moves the run's clock on by $seconds, and returns where it is now: read
     * and written under one lock, so two calls at once both count.
     */
    public function travel(int $seconds): int
    {
        $directory = $this->directory;
        $after = $this->locked(self::CLOCK_FILE, static function (string $read) use ($seconds, $directory): string {
            $offset = self::parseOffset($read, $directory) + $seconds;
            self::assertOffset($offset);

            return (string) $offset;
        });

        return (int) $after;
    }

    /** Records users a scenario seeded, whom the endpoint may then sign in. */
    public function remember(string ...$ids): void
    {
        $this->locked(self::USERS_FILE, static function (string $read) use ($ids): string {
            $known = array_values(array_unique([...self::lines($read), ...$ids]));

            return $known === [] ? '' : implode("\n", $known) . "\n";
        });
    }

    /** Whether a scenario of this run seeded $id. */
    public function knows(string $id): bool
    {
        return \in_array($id, self::lines($this->locked(self::USERS_FILE, null)), true);
    }

    /** Forgets every user the run seeded, and puts the clock back. */
    public function reset(): void
    {
        $this->locked(self::USERS_FILE, static fn (): string => '');
        $this->moveClock(0);
    }

    /** @return list<string> */
    private static function lines(string $read): array
    {
        return $read === '' ? [] : explode("\n", rtrim($read, "\n"));
    }

    private static function parseOffset(string $read, string $directory): int
    {
        if (preg_match('/^-?(?:0|[1-9][0-9]{0,9})\z/', $read) !== 1 || abs((int) $read) > self::MAX_OFFSET) {
            throw new \RuntimeException("{$directory}/" . self::CLOCK_FILE . ' does not hold a clock offset this run wrote.');
        }

        return (int) $read;
    }

    private static function assertOffset(int $offset): void
    {
        if (abs($offset) > self::MAX_OFFSET) {
            throw new \InvalidArgumentException('The clock moves at most ten years either way.');
        }
    }

    /**
     * One of the run's files, read under a lock and, given $change,
     * rewritten under it: every process serving the run reads and writes
     * them, and two writes at once both land.
     *
     * @param (\Closure(string): string)|null $change
     * @return string what the file holds after
     */
    private function locked(string $name, ?\Closure $change): string
    {
        $file = "{$this->directory}/{$name}";
        self::assertPrivate($file, 'file');
        $handle = fopen($file, 'r+');
        if ($handle === false) {
            throw new \RuntimeException("Could not open {$file}.");
        }

        try {
            if (!flock($handle, $change === null ? LOCK_SH : LOCK_EX)) {
                throw new \RuntimeException("Could not lock {$file}.");
            }
            $read = (string) stream_get_contents($handle);
            if ($change === null) {
                return $read;
            }

            $after = $change($read);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $after);
            fflush($handle);

            return $after;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
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
