<?php

declare(strict_types=1);

namespace Tinvar\Testing;

use Tinvar\Server\RendererBinary;
use Tinvar\Support\PrivateDirectory;

/**
 * The renderers a test run renders pages through: one pool per run and app
 * entry, started on the first render() and kept for every later one.
 *
 * "The suite starts one Node renderer pool per run", and "each worker gets
 * its own database ... all workers share one renderer pool." The pool is a
 * directory of sockets (ADR 0028), named before anything starts, so an
 * application can be built on it while no render has been asked for: a page
 * test that never renders never starts Node.
 *
 * Under `pest --parallel` every worker is a process of its own, and every
 * worker is a child of the run (ADR 0095). So the run is what the pool is
 * found by and belongs to: a directory only this user can enter, named for
 * this user and the run's pid, holds where each entry's pool is and a lock.
 * The first worker to render starts the pool under that lock, detached from
 * itself, told to stop once the run has gone; every other worker connects
 * to the same sockets, and one that finds the pool down starts it again.
 * ADR 0043 held that a renderer a worker reached but did not start is one
 * it cannot vouch for. What vouches here is what vouches for a pool of the
 * worker's own: the directory and every socket in it are this user's and no
 * one else's, checked before each is trusted, as the render channel checks
 * them again before every connection (SocketTrust). Anyone who could plant
 * a renderer there could already act as this user. A run directory that is
 * not exactly that -- another user's, a link, open to others -- is not
 * used, and the worker keeps a pool of its own, as outside a parallel run.
 *
 * Started as `tinvar serve` starts one: the production React build, a
 * scrubbed environment, a private directory short enough for macOS's socket
 * limit, every renderer sandboxed. A worker's own pool runs with
 * --exit-with-stdin, on a pipe the worker holds: killed outright, it takes
 * its renderers with it. A run's runs with --exit-with-pid, the run's.
 */
final class RendererPool
{
    /** Variables the renderer may see. */
    private const array ENV = ['PATH', 'TZ', 'LANG', 'LC_ALL'];

    /** What a run's directory is called, beside the hash of its user and pid. */
    private const string RUN_PREFIX = 'tinvar-testrun';

    /** @var array<string, self> by entry and size, for this process */
    private static array $pools = [];

    /** The directory of sockets an application is built on. */
    public readonly string $path;

    /** @var resource|null */
    private $process = null;

    /** @var resource|null the renderer's standard input, held open while it should run */
    private $stdin = null;

    private readonly string $log;

    private readonly int $owner;

    /** The run's directory, where this pool is shared by the run's workers; null for this process's own. */
    private readonly ?string $run;

    /** The run's pid, which a shared pool stops after. */
    private readonly int $runPid;

    /**
     * @param string $entry the application's built rsc entry
     * @param string $projectRoot where node_modules and the renderer package are
     * @param int $processes renderers in the pool
     */
    private function __construct(
        private readonly string $entry,
        private readonly string $projectRoot,
        private readonly int $processes,
    ) {
        $this->owner = getmypid() ?: 0;
        PrivateDirectory::removeAbandoned('tinvar-testpool');
        PrivateDirectory::removeAbandoned(self::RUN_PREFIX);

        $this->runPid = self::runPid();
        $run = $this->runPid > 1 ? self::runDirectory($this->runPid) : null;
        $this->run = $run;

        $this->path = $run === null
            ? PrivateDirectory::create('tinvar-testpool', owner: $this->owner)
            : self::locked($run, fn (): string => $this->sharedPath($run));
        $this->log = "{$this->path}/renderer.log";

        if ($run === null) {
            register_shutdown_function(function (): void {
                // Not in a forked child, which runs shutdown functions too.
                if ((getmypid() ?: 0) === $this->owner) {
                    $this->stop();
                }
            });
        }
    }

    /** This run's pool for $entry, made -- not started -- on first use. */
    public static function for(string $entry, string $projectRoot, int $processes = 1): self
    {
        if ($processes < 1 || $processes > 64) {
            throw new \InvalidArgumentException('A renderer pool has 1 to 64 processes.');
        }

        return self::$pools["{$entry}\0{$processes}"] ??= new self($entry, $projectRoot, $processes);
    }

    /** Whether this pool is the run's, shared by its parallel workers. */
    public function shared(): bool
    {
        return $this->run !== null;
    }

    /** Starts the pool unless it is running, and waits until every renderer listens. */
    public function start(float $timeoutSeconds = 30.0): void
    {
        if ($this->running()) {
            return;
        }
        if ($this->run === null) {
            $this->stop(keepDirectory: true);
            $this->launch($timeoutSeconds);

            return;
        }

        // One worker starts it; the rest wait on the lock, then find it up.
        self::locked($this->run, function () use ($timeoutSeconds): void {
            if ($this->running()) {
                return;
            }
            $this->launch($timeoutSeconds);
        });
    }

    public function running(): bool
    {
        if ($this->run === null) {
            return \is_resource($this->process) && proc_get_status($this->process)['running'];
        }

        $pid = $this->supervisor();

        return $pid !== null && posix_kill($pid, 0) && \count($this->sockets()) >= $this->processes;
    }

    /**
     * Stops the pool; the directory goes too unless it is to be started
     * again. A run's pool is stopped by the run ending, not by the worker
     * that happened to start it: this stops it only when asked outright.
     */
    public function stop(bool $keepDirectory = false): void
    {
        if (\is_resource($this->stdin)) {
            fclose($this->stdin);
        }
        $this->stdin = null;
        if (\is_resource($this->process)) {
            proc_terminate($this->process, SIGTERM);
            proc_close($this->process);
        }
        $this->process = null;

        if ($this->run !== null) {
            $pid = $this->supervisor();
            if ($pid !== null) {
                posix_kill($pid, SIGTERM);
            }
            @unlink("{$this->path}/supervisor.pid");

            return;
        }

        if (!$keepDirectory) {
            PrivateDirectory::remove($this->path);
        }
    }

    /** What the renderers have written, for a test that wants to know why one failed. */
    public function log(): string
    {
        return (string) @file_get_contents($this->log);
    }

    /** Starts the renderers and waits until each listens. */
    private function launch(float $timeoutSeconds): void
    {
        if (!is_file($this->entry)) {
            throw new \RuntimeException("No app entry at {$this->entry}: build the application before rendering through it.");
        }

        $command = [
            RendererBinary::node(),
            RendererBinary::script($this->projectRoot),
            $this->path,
            $this->entry,
            "--processes={$this->processes}",
        ];
        $env = [...array_intersect_key(getenv(), array_flip(self::ENV)), 'NODE_ENV' => 'production'];

        // Sockets a pool that died left behind would be counted as this
        // one listening before it does.
        foreach ($this->sockets() as $socket) {
            @unlink("{$this->path}/{$socket}");
        }

        if ($this->run === null) {
            $this->spawnOwn([...$command, '--exit-with-stdin'], $env);
        } else {
            $this->spawnDetached([...$command, "--exit-with-pid={$this->runPid}"], $env);
        }

        $deadline = microtime(true) + $timeoutSeconds;
        while (\count($this->sockets()) < $this->processes) {
            if (!$this->alive()) {
                $log = $this->log();
                $this->stop(keepDirectory: true);

                throw new \RuntimeException("The renderer pool exited before listening.\n{$log}");
            }
            if (microtime(true) > $deadline) {
                $this->stop(keepDirectory: true);

                throw new \RuntimeException("The renderer pool did not listen within {$timeoutSeconds}s.");
            }
            usleep(50_000);
        }
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     */
    private function spawnOwn(array $command, array $env): void
    {
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['file', $this->log, 'a'], 2 => ['file', $this->log, 'a']],
            $pipes,
            $this->projectRoot,
            $env,
        );
        if (!\is_resource($process)) {
            throw new \RuntimeException('Could not start the renderer pool.');
        }
        $this->process = $process;
        $this->stdin = $pipes[0];
    }

    /**
     * Started in the background of a shell that exits at once, so the pool
     * is no child of this worker's: it outlives the worker, and serves the
     * run's others after this one has finished. Its pid is kept beside its
     * sockets, for the next worker to ask after.
     *
     * @param list<string> $command
     * @param array<string, string> $env
     */
    private function spawnDetached(array $command, array $env): void
    {
        $process = proc_open(
            ['/bin/sh', '-c', 'log=$1; shift; "$@" </dev/null >>"$log" 2>&1 & echo $!', 'sh', $this->log, ...$command],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $this->log, 'a']],
            $pipes,
            $this->projectRoot,
            $env,
        );
        if (!\is_resource($process)) {
            throw new \RuntimeException('Could not start the renderer pool.');
        }
        $pid = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        proc_close($process);

        if (preg_match('/^[1-9][0-9]{0,9}\z/', $pid) !== 1) {
            throw new \RuntimeException('Could not start the renderer pool: the shell named no process.');
        }
        file_put_contents("{$this->path}/supervisor.pid", $pid, LOCK_EX);
    }

    /** Whether what this process started is still running. */
    private function alive(): bool
    {
        if ($this->run === null) {
            return \is_resource($this->process) && proc_get_status($this->process)['running'];
        }
        $pid = $this->supervisor();

        return $pid !== null && posix_kill($pid, 0);
    }

    /** The pid a run's pool was started as, or null. */
    private function supervisor(): ?int
    {
        $file = "{$this->path}/supervisor.pid";
        clearstatcache(true, $file);
        if (is_link($file) || !is_file($file)) {
            return null;
        }
        $pid = (string) @file_get_contents($file);

        return preg_match('/^[1-9][0-9]{0,9}\z/', $pid) === 1 ? (int) $pid : null;
    }

    /** @return list<string> */
    private function sockets(): array
    {
        clearstatcache();

        return array_values(array_filter(
            scandir($this->path) ?: [],
            static fn (string $name): bool => str_ends_with($name, '.sock'),
        ));
    }

    /**
     * The pool directory the run keeps for this entry and size: the one a
     * worker before this one made, when it is still this user's alone, or a
     * new one. Called under the run's lock.
     */
    private function sharedPath(string $run): string
    {
        $record = "{$run}/pool-" . substr(hash('sha256', "{$this->entry}\0{$this->processes}"), 0, 16);
        clearstatcache();
        if (is_file($record) && !is_link($record)) {
            $path = trim((string) file_get_contents($record));
            if (preg_match('#^/[A-Za-z0-9._/-]+/tinvar-testpool-[0-9a-f]{10}\z#', $path) === 1 && self::privateToMe($path)) {
                return $path;
            }
        }

        // Owned, for removeAbandoned(), by the run: it outlives the worker.
        $path = PrivateDirectory::create('tinvar-testpool', owner: $this->runPid);
        file_put_contents($record, $path, LOCK_EX);

        return $path;
    }

    /**
     * The run this worker belongs to: its parent, when it is a worker of
     * `pest --parallel` (paratest sets PARATEST for each); 0 otherwise, and
     * wherever POSIX cannot say.
     */
    private static function runPid(): int
    {
        if (getenv('PARATEST') === false || getenv('TEST_TOKEN') === false
            || !\function_exists('posix_getppid') || !\function_exists('posix_geteuid') || !\function_exists('posix_kill')) {
            return 0;
        }

        return posix_getppid();
    }

    /**
     * The run's directory: made by the first worker to ask, private to this
     * user -- or null, and a pool of the worker's own, for one that is not.
     * Its name is the user and the run, so every worker of the run finds it
     * and no other run or user shares it.
     */
    private static function runDirectory(int $run): ?string
    {
        $base = is_dir('/tmp') ? '/tmp' : rtrim(sys_get_temp_dir(), '/');
        $directory = "{$base}/" . self::RUN_PREFIX . '-' . substr(hash('sha256', posix_geteuid() . ':' . $run), 0, 10);

        // mkdir() claims it, or loses to a worker that already has; either
        // way what is there is checked, not assumed.
        if (@mkdir($directory, 0700)) {
            chmod($directory, 0700);
        }
        if (!self::privateToMe($directory)) {
            return null;
        }

        $owner = "{$directory}/" . PrivateDirectory::OWNER_FILE;
        if (!is_file($owner) || is_link($owner)) {
            file_put_contents($owner, (string) $run, LOCK_EX);
        }

        return $directory;
    }

    /** A real directory -- no link -- of this user's, that no one else may enter. */
    private static function privateToMe(string $directory): bool
    {
        clearstatcache(true, $directory);
        $stat = @lstat($directory);

        return $stat !== false
            && ($stat['mode'] & 0170000) === 0040000
            && $stat['uid'] === posix_geteuid()
            && ($stat['mode'] & 0777) === 0700;
    }

    /**
     * Runs $work holding the run's lock.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    private static function locked(string $run, \Closure $work): mixed
    {
        $lock = fopen("{$run}/lock", 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException("Could not lock the test run's renderer pool in {$run}.");
        }

        try {
            return $work();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
