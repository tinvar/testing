<?php

declare(strict_types=1);

/**
 * Finding the `tinvar dev` that `render --dump --dev` and `mcp --dev`
 * render through (ADR 0132): one serve --dev's private directory, for this
 * application, by its record -- and nothing about that directory taken on
 * trust, since what listens on its socket is handed the page's props.
 */

namespace Tinvar\Tests\Testing\DevServerRun;

use Tinvar\Server\ServeCommand;
use Tinvar\Support\PrivateDirectory;
use Tinvar\Testing\Dev\DevServerRun;
use Tinvar\Testing\Dev\DevToolsCommand;

/** A base directory for runs, and an application directory; both removed after. */
function base(): array
{
    // Short, under /tmp: a socket's whole path is held to 104 bytes on macOS.
    $base = (is_dir('/tmp') ? '/tmp' : sys_get_temp_dir()) . '/tinvar-runs-' . bin2hex(random_bytes(4));
    mkdir($base, 0700);
    mkdir("{$base}/app", 0700);
    register_shutdown_function(static function () use ($base): void {
        exec('rm -rf ' . escapeshellarg($base));
    });

    return [(string) realpath($base), (string) realpath("{$base}/app")];
}

/**
 * A serve --dev's directory under $base, as serve makes one: owned by this
 * process, a record naming $app, and a socket listening.
 *
 * @return array{string, resource}
 */
function run(string $base, string $app, ?int $owner = null): array
{
    $directory = PrivateDirectory::create(ServeCommand::RUN_PREFIX, $base, $owner ?? getmypid());
    file_put_contents("{$directory}/" . ServeCommand::DEV_RECORD, json_encode(['app' => $app]));
    $server = stream_socket_server('unix://' . $directory . '/' . ServeCommand::DEV_SOCKET);
    \assert(\is_resource($server));

    return [$directory, $server];
}

it('finds the one dev run serving the application, its socket and its deferred manifest', function () {
    [$base, $app] = base();
    [$directory] = run($base, $app);
    run($base, '/somewhere/else');

    $found = DevServerRun::find($app, $base);

    expect($found->socket)->toBe("{$directory}/renderer.sock")
        ->and($found->deferred)->toBe("{$directory}/deferred.json");
});

it('takes the socket from where serve puts it, never from what the record says', function () {
    [$base, $app] = base();
    [$directory] = run($base, $app);
    file_put_contents("{$directory}/" . ServeCommand::DEV_RECORD, json_encode(['app' => $app, 'socket' => '/tmp/elsewhere.sock']));

    expect(DevServerRun::find($app, $base)->socket)->toBe("{$directory}/renderer.sock");
});

it('finds nothing in a run it cannot vouch for', function (\Closure $spoil) {
    [$base, $app] = base();
    [$directory] = run($base, $app);
    $spoil($directory, $app);

    expect(fn () => DevServerRun::find($app, $base))->toThrow(\RuntimeException::class, 'No `tinvar dev` is serving');
})->with([
    'a directory others may enter' => [static fn (string $d): bool => chmod($d, 0755)],
    'a serve that has gone' => [static fn (string $d): int|false => file_put_contents("{$d}/owner.pid", '999999')],
    'no owner' => [static fn (string $d): bool => unlink("{$d}/owner.pid")],
    'a record for another application' => [static fn (string $d): int|false => file_put_contents("{$d}/dev.json", json_encode(['app' => '/elsewhere']))],
    'a record that is not JSON' => [static fn (string $d): int|false => file_put_contents("{$d}/dev.json", '{')],
    'a record that is a link' => [static function (string $d, string $app): void {
        file_put_contents("{$d}/../real.json", json_encode(['app' => $app]));
        unlink("{$d}/dev.json");
        symlink("{$d}/../real.json", "{$d}/dev.json");
    }],
    'no socket' => [static fn (string $d): bool => unlink("{$d}/renderer.sock")],
    'a file where the socket goes' => [static function (string $d): void {
        unlink("{$d}/renderer.sock");
        file_put_contents("{$d}/renderer.sock", 'x');
    }],
    'a socket that is a link' => [static function (string $d): void {
        rename("{$d}/renderer.sock", "{$d}/../moved.sock");
        symlink("{$d}/../moved.sock", "{$d}/renderer.sock");
    }],
    'a directory named as no serve names one' => [static fn (string $d): bool => rename($d, \dirname($d) . '/tinvar-serve-notours')],
]);

it('refuses to choose between two runs of the same application', function () {
    [$base, $app] = base();
    run($base, $app);
    run($base, $app);

    expect(fn () => DevServerRun::find($app, $base))->toThrow(\RuntimeException::class, '2 runs of `tinvar dev`');
});

it('takes --dev or --build, never both, and says when no dev run serves the application', function () {
    [, $app] = base();
    $err = '';
    $tools = new DevToolsCommand(
        app: $app,
        projectRoot: $app,
        database: "{$app}/none.sqlite",
        application: static fn () => throw new \LogicException('nothing is built'),
        env: ['TINVAR_ENV' => 'local'],
        out: static function (): void {},
        err: static function (string $line) use (&$err): void {
            $err .= $line . "\n";
        },
    );

    expect($tools->render(['--dump', '/', '--dev', '--build=/tmp']))->toBe(DevToolsCommand::USAGE)
        ->and($tools->mcp(['--dev', '--build=/tmp']))->toBe(DevToolsCommand::USAGE)
        ->and($tools->render(['--dump', '/', '--dev']))->toBe(1)
        ->and($err)->toContain('No `tinvar dev` is serving');
});

it('is refused for --dev where the environment says production, as without it', function () {
    [, $app] = base();
    $err = '';
    $tools = new DevToolsCommand(
        app: $app,
        projectRoot: $app,
        database: "{$app}/none.sqlite",
        application: static fn () => throw new \LogicException('nothing is built'),
        env: ['TINVAR_ENV' => 'local', 'NODE_ENV' => 'production'],
        out: static function (): void {},
        err: static function (string $line) use (&$err): void {
            $err .= $line;
        },
    );

    expect($tools->render(['--dump', '/', '--dev']))->toBe(1)
        ->and($err)->toContain('NODE_ENV=production');
});
