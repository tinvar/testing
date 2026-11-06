<?php

declare(strict_types=1);

namespace Tinvar\Testing\Dev;

use Tinvar\Config\Configs;
use Tinvar\Config\FrameworkConfig;
use Tinvar\Config\Secret;
use Tinvar\Http\TestableApplication;
use Tinvar\Orm\Model;
use Tinvar\Support\BuildProfile;
use Tinvar\Support\PrivateDirectory;
use Tinvar\Testing\RendererPool;

/**
 * `tinvar render --dump` and `tinvar mcp`, for an application's command line
 * (ADR 0088).
 *
 *   tinvar render --dump PATH [--as=SCENARIO] [--build=DIR | --dev] [--part=html|payload] [--json]
 *   tinvar mcp [--build=DIR | --dev]
 *
 * Both refused before anything is read unless DevTools allows them here.
 * Each render is made on a private copy of the application's database
 * (DatabaseSnapshot) -- an SQLite file, or a database of its own on a
 * Postgres or MySQL server, the server's DSN handed over in a Secret since
 * it carries a password (ADR 0173) -- the files the application keeps
 * beside its database kept beside the copy, in its private directory --
 * through a pool of the application's real renderers
 * started on a build made for the test profile -- scrubbed of every secret,
 * in the production React build, as `tinvar serve` starts them -- and with
 * an application the caller builds on that copy and that pool, keyed by a
 * key made for the one render: what the dump shows was signed by nothing
 * the application's real sessions would take.
 *
 * With --dev, the renderer is instead the dev server of the `tinvar dev`
 * running for the application (DevServerRun), and the application is built
 * in dev mode, with the deferred manifest that dev server keeps: the page
 * as the developer's browser is drawn it, from source, with no build to
 * make first (ADR 0132). Everything else is as it was -- the copy, the key,
 * the redaction -- and the dev server is the one already rendering the
 * developer's own pages, given nothing but the render frames of the copy.
 */
final class DevToolsCommand
{
    public const int USAGE = 64;

    /** @var \Closure(string): void */
    private readonly \Closure $out;

    /** @var \Closure(string): void */
    private readonly \Closure $err;

    /**
     * @param string $app the application's directory, where dist-test/ is by default
     * @param string $projectRoot where node_modules and the renderer package are
     * @param string|Secret $database the application's database, which is copied and never written: an
     *        SQLite file's path, or a pgsql: or mysql: DSN, copied on its server (ADR 0113) -- in a
     *        Secret, as an application on a server hands it, since it carries a password (ADR 0173)
     * @param \Closure(string, string, string, string|null, string|null, string): TestableApplication $application the
     *        application, built on a renderer pool's socket directory, a database, a key and
     *        the build its renderers run -- whose fragment manifest it reads -- or null; and,
     *        rendering through `tinvar dev`, on its dev renderer's socket with no build and the
     *        deferred manifest its dev server keeps, which says to build it in dev mode. Last,
     *        what to name the files it keeps beside its database from, in place of its own
     *        database's path (DatabaseSnapshot::$files): the SQLite copy's, or a path in the
     *        private directory of a copy on a server, whose DSN names no place (ADR 0173)
     * @param list<class-string<Model>> $models the application's models, for the schema
     * @param list<class-string> $configs the application's #[Config] classes
     * @param string|null $log the application's log file
     * @param array<string, string>|null $env in place of the process's: a test's
     */
    public function __construct(
        private readonly string $app,
        private readonly string $projectRoot,
        #[\SensitiveParameter]
        private readonly string|Secret $database,
        private readonly \Closure $application,
        private readonly array $models = [],
        private readonly array $configs = [],
        private readonly ?string $log = null,
        private readonly ?array $env = null,
        ?\Closure $out = null,
        ?\Closure $err = null,
    ) {
        $this->out = $out ?? static function (string $text): void {
            fwrite(STDOUT, $text);
        };
        $this->err = $err ?? static function (string $line): void {
            fwrite(STDERR, $line . "\n");
        };
    }

    /** @param list<string> $arguments what follows `render` */
    public function render(array $arguments): int
    {
        $usage = 'usage: tinvar render --dump PATH [--as=SCENARIO] [--build=DIR | --dev] [--part=html|payload] [--json]';
        $options = [];
        $paths = [];
        $dump = false;
        foreach ($arguments as $argument) {
            if ($argument === '--dump' || $argument === '--json' || $argument === '--dev') {
                $options[$argument] = true;
                $dump = $dump || $argument === '--dump';
            } elseif (preg_match('/^--(as|build|part)=(.+)\z/s', $argument, $m) === 1) {
                $options[$m[1]] = $m[2];
            } elseif (!str_starts_with($argument, '--')) {
                $paths[] = $argument;
            } else {
                ($this->err)("Unknown argument '{$argument}'.\n{$usage}");

                return self::USAGE;
            }
        }
        $part = $options['part'] ?? null;
        $dev = isset($options['--dev']);
        if (!$dump || \count($paths) !== 1 || ($part !== null && !\in_array($part, ['html', 'payload'], true)) || ($dev && isset($options['build']))) {
            ($this->err)($usage);

            return self::USAGE;
        }

        try {
            DevTools::assertAllowed('tinvar render --dump', $this->env);
            if ($dev) {
                $dumper = $this->devDumper();
            } else {
                $build = $this->build(\is_string($options['build'] ?? null) ? $options['build'] : null);
                DevTools::assertTestBuild('tinvar render --dump', $build);
                $dumper = $this->dumper($build);
            }
            $dumped = $dumper($paths[0], \is_string($options['as'] ?? null) ? $options['as'] : null);
        } catch (\InvalidArgumentException $e) {
            ($this->err)($e->getMessage());

            return self::USAGE;
        } catch (\RuntimeException $e) {
            ($this->err)($e->getMessage());

            return 1;
        }

        if ($part !== null) {
            ($this->out)((string) $dumped[$part]);
        } elseif (isset($options['--json'])) {
            ($this->out)(json_encode($dumped, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
        } else {
            ($this->out)(self::readable($dumped));
        }

        return $dumped['status'] >= 500 ? 1 : 0;
    }

    /**
     * Serves MCP on standard input and output until the client closes it.
     *
     * @param list<string> $arguments what follows `mcp`
     * @param resource|null $in
     * @param resource|null $out
     */
    public function mcp(array $arguments, $in = null, $out = null): int
    {
        $build = null;
        $dev = false;
        foreach ($arguments as $argument) {
            if ($argument === '--dev') {
                $dev = true;
            } elseif (preg_match('/^--build=(.+)\z/s', $argument, $m) === 1) {
                $build = $m[1];
            } else {
                ($this->err)("Unknown argument '{$argument}'.\nusage: tinvar mcp [--build=DIR | --dev]");

                return self::USAGE;
            }
        }
        if ($dev && $build !== null) {
            ($this->err)('--dev renders through `tinvar dev`, and --build through a build: one or the other.');

            return self::USAGE;
        }

        try {
            DevTools::assertAllowed('tinvar mcp', $this->env);
        } catch (\RuntimeException $e) {
            ($this->err)($e->getMessage());

            return 1;
        }

        $dump = null;
        $directory = null;
        try {
            if ($dev) {
                // Found again at each render: a dev run may be restarted
                // while the server answers, and one stopped renders nothing.
                DevServerRun::find($this->app);
                $directory = '`tinvar dev`';
                $dump = $this->devDumper();
            } else {
                $directory = $this->build($build);
                DevTools::assertTestBuild('tinvar mcp', $directory);
                $dump = $this->dumper($directory);
            }
        } catch (\RuntimeException $e) {
            // Everything but render_dump still answers; it says why not.
            ($this->err)("render_dump is off: {$e->getMessage()}");
        }

        try {
            // The declarations, from an application on a copy of its own --
            // building one opens the database -- on a socket directory
            // nothing listens in: nothing it is asked renders.
            $snapshot = $this->snapshot();
            $silent = PrivateDirectory::create('tinvar-mcp', owner: getmypid() ?: null);
            register_shutdown_function(static fn () => PrivateDirectory::remove($silent));
            $kernel = ($this->application)($silent, $snapshot->database, self::key(), null, null, $snapshot->files)->kernel();
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            // An application that will not be built on its copy says why, as
            // a render's does; nothing is served.
            ($this->err)($e->getMessage());

            return 1;
        }

        $server = new McpServer(
            new Introspection($kernel, $this->models, $this->configs, $this->env),
            $this->log === null ? null : new LogTail($this->log),
            $dump,
        );
        ($this->err)('tinvar mcp: serving on standard input and output; render_dump ' . ($dump === null ? 'off' : 'renders ' . (string) $directory));

        return $server->serve($in ?? STDIN, $out ?? STDOUT);
    }

    /**
     * A fresh copy of the database: on MySQL, made as the copies' own user
     * TINVAR_DUMP_MYSQL_DSN names (ServerCopy::make()), refused without one.
     */
    private function snapshot(): DatabaseSnapshot
    {
        $source = $this->database instanceof Secret ? $this->database->reveal() : $this->database;
        $mysqlCopy = str_starts_with($source, 'mysql:')
            ? Configs::load(FrameworkConfig::class, $this->env)->dumpMysqlDsn
            : null;

        return DatabaseSnapshot::of($source, $mysqlCopy);
    }

    /**
     * Renders a path, each time on a fresh copy of the database and with a
     * key of its own, through the pool for $build.
     *
     * @return \Closure(string, string|null): array<string, mixed>
     */
    private function dumper(string $build): \Closure
    {
        $pool = RendererPool::for(self::entry($build), $this->projectRoot);

        return function (string $path, ?string $scenario) use ($pool, $build): array {
            RenderDump::assertPagePath($path);
            $pool->start();
            $snapshot = $this->snapshot();
            try {
                return self::underTheTestProfile(function () use ($pool, $snapshot, $build, $path, $scenario): array {
                    $app = ($this->application)($pool->path, $snapshot->database, self::key(), $build, null, $snapshot->files);
                    try {
                        return new RenderDump($app)->dump($path, $scenario);
                    } finally {
                        $app->kernel()->scope->reset();
                    }
                });
            } finally {
                $snapshot->remove();
            }
        };
    }

    /**
     * Renders a path through the dev server of the `tinvar dev` running for
     * the application, found afresh each time, on a fresh copy of the
     * database and with a key of its own: the application built in dev
     * mode, with the deferred manifest the dev server keeps from its module
     * graph, so a route mints fragment tokens as the developer's own pages
     * do. As the test profile's server, as a build's dump is, so a
     * scenario's user may be seeded into the copy.
     *
     * @return \Closure(string, string|null): array<string, mixed>
     */
    private function devDumper(): \Closure
    {
        return function (string $path, ?string $scenario): array {
            RenderDump::assertPagePath($path);
            $run = DevServerRun::find($this->app);
            $snapshot = $this->snapshot();
            try {
                return self::underTheTestProfile(function () use ($run, $snapshot, $path, $scenario): array {
                    $app = ($this->application)($run->socket, $snapshot->database, self::key(), null, $run->deferred, $snapshot->files);
                    try {
                        return new RenderDump($app)->dump($path, $scenario);
                    } finally {
                        $app->kernel()->scope->reset();
                    }
                });
            } finally {
                $snapshot->remove();
            }
        };
    }

    /**
     * $render, run as the test profile's server runs: the build it renders
     * was made for the test profile, so the process serving it says test
     * too -- the two halves of a profile agreeing, as ADR 0066 has them --
     * and what the application refuses outside it, a scenario's users
     * above all (ADR 0090), it allows here and nowhere else. Held to
     * BuildProfile's own refusals again before anything runs (dev mode, a
     * production marker, TINVAR_ENV), and put back after.
     *
     * @template T
     * @param \Closure(): T $render
     * @return T
     */
    private static function underTheTestProfile(\Closure $render): mixed
    {
        $before = getenv(BuildProfile::ENV);
        putenv(BuildProfile::ENV . '=' . BuildProfile::TEST);
        try {
            BuildProfile::fromEnvironment();

            return $render();
        } finally {
            putenv($before === false ? BuildProfile::ENV : BuildProfile::ENV . '=' . $before);
        }
    }

    /** The build directory: --build, TINVAR_BUILD_DIR, or dist-test/ in the application. */
    private function build(?string $given): string
    {
        $env = $this->env ?? array_filter(getenv(), \is_string(...));
        $directory = $given ?? (($env[BuildProfile::BUILD_DIR_ENV] ?? '') !== '' ? $env[BuildProfile::BUILD_DIR_ENV] : rtrim($this->app, '/') . '/dist-test');
        $resolved = realpath($directory);
        if ($resolved === false || !is_dir($resolved)) {
            throw new \RuntimeException(
                "There is no build at {$directory}. Make one for the test profile: "
                . "`tinvar build --profile=test --out={$directory}`.",
            );
        }

        return $resolved;
    }

    private static function entry(string $build): string
    {
        return "{$build}/rsc/index.js";
    }

    /** A key for one application, which signs nothing anything else accepts. */
    private static function key(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** @param array<string, mixed> $dump */
    private static function readable(array $dump): string
    {
        $as = \is_array($dump['as']) && \is_string($dump['as']['scenario'] ?? null)
            ? "scenario {$dump['as']['scenario']} (user {$dump['as']['user']})"
            : 'nobody';
        $lines = ["{$dump['path']} as {$as}: {$dump['status']}" . ($dump['rendered'] ? '' : ' (not rendered)')];
        foreach ((array) $dump['headers'] as $name => $value) {
            $lines[] = "  {$name}: {$value}";
        }
        if ($dump['cookies'] !== []) {
            $lines[] = '  cookies set: ' . implode(', ', (array) $dump['cookies']);
        }
        if (\is_array($dump['timings'])) {
            $timings = [];
            foreach ($dump['timings'] as $name => $value) {
                $timings[] = "{$name}={$value}";
            }
            $lines[] = '  timings: ' . implode(' ', $timings);
        }
        if (\is_string($dump['failure'])) {
            $lines[] = "  failure: {$dump['failure']}";
        }

        return implode("\n", $lines) . "\n\n--- HTML ---\n{$dump['html']}\n\n--- RSC payload ---\n{$dump['payload']}\n";
    }
}
