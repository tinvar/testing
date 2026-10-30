<?php

declare(strict_types=1);

/**
 * `render --dump` and the MCP server's parts that need no application
 * (ADR 0088): where they refuse to run, which paths a dump takes, what it
 * cuts out of a page, the log read back redacted, and the database copy a
 * render writes to instead of the original.
 */

namespace Tinvar\Tests\Testing\DevTools;

use Tinvar\Auth\Authenticatable;
use Tinvar\Http\BrowserTestable;
use Tinvar\Http\Kernel;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Http\TestableApplication;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Testing\Dev\DatabaseSnapshot;
use Tinvar\Testing\Dev\DevTools;
use Tinvar\Testing\Dev\LogTail;
use Tinvar\Testing\Dev\RenderDump;

/** An application that answers every request with the page it was given, and says who asked. */
final class Pages implements BrowserTestable
{
    public ?Request $seen = null;

    /** @var list<string> */
    public array $seeded = [];

    public function __construct(private readonly Response $page) {}

    public function handle(Request $request, string $rawBody = ''): Response
    {
        return $this->page;
    }

    public function handleWith(Request $request, \Closure $respond, string $rawBody = ''): Response
    {
        $this->seen = $request;

        return $this->page;
    }

    public function reset(): void {}

    public function kernel(): Kernel
    {
        throw new \LogicException('not here');
    }

    public function respond(LoadedRoute $loaded, Request $request, ?\Closure $rendered = null): Response
    {
        throw new \LogicException('not here');
    }

    public function signedIn(Authenticatable $user, Request $request): array
    {
        return ['session' => 'session-cookie-' . $user->authIdentifier()];
    }

    public function lastFailure(): ?\Throwable
    {
        return null;
    }

    public function seed(string $scenario, array $input): array
    {
        $this->seeded[] = $scenario;

        return $scenario === 'nobody' ? ['users' => []] : ['users' => ['u-1']];
    }

    public function testUser(string $id): ?Authenticatable
    {
        return new readonly class ($id) implements Authenticatable {
            public function __construct(private string $id) {}

            public function authIdentifier(): string
            {
                return $this->id;
            }
        };
    }

    public function moveClock(int $offset): void {}
}

/** A build directory recording $profile, or none. */
function build(?string $profile, bool $entry = true): string
{
    $dir = sys_get_temp_dir() . '/tinvar-devtools-' . bin2hex(random_bytes(6));
    mkdir("{$dir}/rsc", 0700, true);
    if ($profile !== null) {
        file_put_contents("{$dir}/rsc/profile.json", json_encode(['format' => 1, 'profile' => $profile]));
    }
    if ($entry) {
        file_put_contents("{$dir}/rsc/index.js", '// built');
    }

    return $dir;
}

it('runs only where the environment says this is a developer\'s machine', function (array $env, ?string $refusal) {
    $run = static fn () => DevTools::assertAllowed('tinvar mcp', $env);
    if ($refusal === null) {
        $run();
        expect(true)->toBeTrue();
    } else {
        expect($run)->toThrow(\RuntimeException::class, $refusal);
    }
})->with([
    'local' => [['TINVAR_ENV' => 'local'], null],
    'testing, under the test profile' => [['TINVAR_ENV' => 'testing', 'TINVAR_PROFILE' => 'test'], null],
    'nothing set' => [[], 'nothing says this is not production'],
    'staging' => [['TINVAR_ENV' => 'staging'], 'nothing says this is not production'],
    'TINVAR_ENV=production' => [['TINVAR_ENV' => 'production'], 'TINVAR_ENV=production'],
    'APP_ENV=production beside local' => [['TINVAR_ENV' => 'local', 'APP_ENV' => 'production'], 'APP_ENV=production'],
    'NODE_ENV=production beside local' => [['TINVAR_ENV' => 'local', 'NODE_ENV' => 'Production '], 'NODE_ENV=production'],
    'the production profile' => [['TINVAR_ENV' => 'local', 'TINVAR_PROFILE' => 'production'], 'TINVAR_PROFILE=production'],
    'the staging profile' => [['TINVAR_ENV' => 'local', 'TINVAR_PROFILE' => 'staging'], 'TINVAR_PROFILE=staging'],
]);

it('renders only through a build made for the test profile', function () {
    DevTools::assertTestBuild('render --dump', build('test'));

    expect(static fn () => DevTools::assertTestBuild('render --dump', build(null)))
        ->toThrow(\RuntimeException::class, 'was made for production')
        ->and(static fn () => DevTools::assertTestBuild('render --dump', build('production')))
        ->toThrow(\RuntimeException::class, 'was made for production')
        ->and(static fn () => DevTools::assertTestBuild('render --dump', build('staging')))
        ->toThrow(\RuntimeException::class, 'was made for staging')
        ->and(static fn () => DevTools::assertTestBuild('render --dump', build('test', entry: false)))
        ->toThrow(\RuntimeException::class, 'not all there');
});

it('dumps a page\'s path and nothing of the framework\'s', function (string $path, bool $taken) {
    $check = static fn () => RenderDump::assertPagePath($path);
    if ($taken) {
        $check();
        expect(true)->toBeTrue();
    } else {
        expect($check)->toThrow(\InvalidArgumentException::class);
    }
})->with([
    ['/', true],
    ['/orders', true],
    ['/products/p-1?tab=specs&x=1', true],
    ['', false],
    ['orders', false],
    ['//evil.example/', false],
    ['https://evil.example/', false],
    ['/_test/seed', false],
    ['/_dev/toolbar', false],
    ['/_file/abc', false],
    ['/a b', false],
    ["/a\nb", false],
    ['/a\\b', false],
    ['/a#b', false],
    ['/' . str_repeat('a', 2048), false],
]);

it('shows the page with every cookie, the nonce and the signed tokens cut out', function () {
    $nonce = 'bm9uY2Utb2YtdGhlLXJlc3BvbnNl';
    $csrf = 'csrf-token-of-the-session-value';
    $token = 'v1.' . str_repeat('A', 20) . '.' . str_repeat('b', 20);
    $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJl';
    $history = str_repeat('a1', 24);
    $html = "<!DOCTYPE html><html><body><script nonce=\"{$nonce}\">x</script>"
        . "<input name=\"_token\" value=\"{$csrf}\"><a data-t=\"{$token}\">{$jwt}</a>"
        . "<script>{\"historyKey\":\"{$history}\"}</script><p>Hello</p></body></html>";
    $page = Response::make(200, $html, [
        'content-type' => 'text/html; charset=utf-8',
        'content-security-policy' => "script-src 'nonce-{$nonce}'",
        'x-route-token' => $token,
    ])->withCookie('__Host-XSRF-TOKEN', $csrf, httpOnly: false)->withCookie('__Host-session', 'the-session-cookie-value');

    $dump = new RenderDump(new Pages($page))->dump('/');

    $everything = json_encode($dump, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    expect($dump['status'])->toBe(200)
        ->and($dump['html'])->toContain('<p>Hello</p>')
        ->and($dump['cookies'])->toBe(['__Host-XSRF-TOKEN', '__Host-session'])
        ->and($dump['headers'])->not->toHaveKey('set-cookie')
        ->and($dump['headers']['content-security-policy'])->toBe("script-src 'nonce-[redacted]'")
        ->and($dump['headers']['x-route-token'])->toBe('[token]')
        ->and($everything)->not->toContain($nonce)
        ->and($everything)->not->toContain($csrf)
        ->and($everything)->not->toContain('the-session-cookie-value')
        ->and($everything)->not->toContain($token)
        ->and($everything)->not->toContain($jwt)
        ->and($everything)->not->toContain($history)
        ->and($dump['as'])->toBe(['scenario' => null, 'user' => null]);
});

it('signs in as a scenario\'s first user through the application\'s own sessions, and shows no cookie', function () {
    $app = new Pages(Response::make(200, '<p>session-cookie-u-1 is here</p>', ['content-type' => 'text/html']));

    $dump = new RenderDump($app)->dump('/account?tab=1', 'customer');

    expect($app->seeded)->toBe(['customer'])
        ->and($app->seen?->cookies)->toBe(['session' => 'session-cookie-u-1'])
        ->and($app->seen?->path)->toBe('/account')
        ->and($app->seen?->remoteAddress)->toBe('127.0.0.1')
        ->and($dump['as'])->toBe(['scenario' => 'customer', 'user' => 'u-1'])
        ->and($dump['html'])->toBe('<p>[redacted] is here</p>');

    expect(static fn () => new RenderDump($app)->dump('/', 'nobody'))
        ->toThrow(\InvalidArgumentException::class, 'made no user')
        ->and(static fn () => new RenderDump($app)->dump('/', '../etc'))
        ->toThrow(\InvalidArgumentException::class, 'not a scenario');
});

it('has no scenarios to sign in from for an application that is not BrowserTestable', function () {
    $app = new class implements TestableApplication {
        public function handle(Request $request, string $rawBody = ''): Response
        {
            return Response::make(200);
        }

        public function handleWith(Request $request, \Closure $respond, string $rawBody = ''): Response
        {
            return Response::make(200);
        }

        public function reset(): void {}

        public function kernel(): Kernel
        {
            throw new \LogicException('not here');
        }

        public function respond(LoadedRoute $loaded, Request $request, ?\Closure $rendered = null): Response
        {
            throw new \LogicException('not here');
        }

        public function signedIn(Authenticatable $user, Request $request): array
        {
            return [];
        }

        public function lastFailure(): ?\Throwable
        {
            return null;
        }
    };

    expect(static fn () => new RenderDump($app)->dump('/', 'customer'))
        ->toThrow(\InvalidArgumentException::class, 'not BrowserTestable');
});

it('reads the end of the log back redacted, by level and by request, and bounded', function () {
    $file = sys_get_temp_dir() . '/tinvar-devtools-' . bin2hex(random_bytes(6)) . '.log';
    $lines = [];
    for ($i = 0; $i < 300; ++$i) {
        $lines[] = json_encode(['time' => 't', 'level' => 'info', 'message' => "line {$i}", 'request_id' => 'r' . ($i % 3)]);
    }
    $lines[] = json_encode(['time' => 't', 'level' => 'error', 'message' => 'failed for ada@example.test at mysql://shop:hunter2@db/shop', 'request_id' => 'r9', 'password' => 'hunter2']);
    $lines[] = 'not json, with Bearer abcdefghijklmnop';
    file_put_contents($file, implode("\n", $lines) . "\n");

    $tail = new LogTail($file);
    $all = json_encode($tail->read(LogTail::MAX_LINES), JSON_THROW_ON_ERROR);

    expect($tail->read(1000))->toHaveCount(LogTail::MAX_LINES)
        ->and($tail->read(2))->toHaveCount(2)
        ->and($all)->not->toContain('hunter2')
        ->and($all)->not->toContain('ada@example.test')
        ->and($all)->not->toContain('abcdefghijklmnop')
        ->and($tail->read(10, level: 'error'))->toHaveCount(1)
        ->and(array_column($tail->read(5, requestId: 'r1'), 'request_id'))->toBe(['r1', 'r1', 'r1', 'r1', 'r1'])
        ->and(static fn () => $tail->read(5, level: 'loud'))->toThrow(\InvalidArgumentException::class)
        ->and(new LogTail('/nonexistent/app.log')->read())->toBe([]);
});

it('renders against a private copy of the database and never writes the original', function () {
    $original = sys_get_temp_dir() . '/tinvar-devtools-' . bin2hex(random_bytes(6)) . '.sqlite';
    $pdo = new \PDO("sqlite:{$original}");
    $pdo->exec('CREATE TABLE t (v TEXT)');
    $pdo->exec("INSERT INTO t VALUES ('kept')");
    unset($pdo);
    $before = hash_file('sha256', $original);

    $snapshot = DatabaseSnapshot::ofSqlite($original);
    $copy = new \PDO("sqlite:{$snapshot->path}");
    $copy->exec("INSERT INTO t VALUES ('written to the copy')");

    expect($copy->query('SELECT COUNT(*) FROM t')?->fetchColumn())->toBe(2)
        ->and(hash_file('sha256', $original))->toBe($before)
        ->and(fileperms($snapshot->path) & 0777)->toBe(0600)
        ->and(fileperms(\dirname($snapshot->path)) & 0777)->toBe(0700);

    unset($copy);
    $snapshot->remove();
    expect(file_exists($snapshot->path))->toBeFalse();

    expect(static fn () => DatabaseSnapshot::ofSqlite('/nonexistent/shop.sqlite'))
        ->toThrow(\RuntimeException::class, 'no database');
});

// ADR 0113: a Postgres or MySQL database is copied on its server, by its
// own dump and restore tools, into a database of its own -- and what can
// not be copied that way is refused before anything is made.
describe('a copy on a server', function () {
    /** The Postgres server the tests use, as a DSN: TINVAR_TEST_PGSQL_DSN, or a local one pg_isready finds. */
    function postgresDsn(): ?string
    {
        $dsn = getenv('TINVAR_TEST_PGSQL_DSN');
        if (\is_string($dsn) && $dsn !== '') {
            return $dsn;
        }
        exec('pg_isready -q 2>/dev/null', $output, $status);

        return $status === 0 ? 'pgsql:host=' . (getenv('PGHOST') ?: 'localhost') . ';port=' . (getenv('PGPORT') ?: '5432') . ';dbname=postgres;user=' . (getenv('PGUSER') ?: 'postgres') : null;
    }

    it('copies a Postgres database into one of its own, reads the original only, and drops the copy', function () {
        $server = postgresDsn() ?? test()->markTestSkipped('No Postgres server to copy on.');
        try {
            $admin = new \PDO($server);
        } catch (\PDOException $e) {
            test()->markTestSkipped("The Postgres server is not reachable: {$e->getMessage()}");
        }
        $source = 'tinvar_copy_src_' . bin2hex(random_bytes(4));
        $admin->exec("CREATE DATABASE {$source}");
        $sourceDsn = (string) preg_replace('/dbname=[^;]*/', "dbname={$source}", $server);
        $original = new \PDO($sourceDsn);
        $original->exec("CREATE TABLE customers (id int PRIMARY KEY, email text); INSERT INTO customers VALUES (1, 'ada@example.test')");

        try {
            try {
                $snapshot = DatabaseSnapshot::of($sourceDsn);
            } catch (\RuntimeException $e) {
                // The tools on this PATH are older than the server: refused,
                // which is the point, but there is nothing here to copy with.
                if (str_contains($e->getMessage(), 'version mismatch')) {
                    test()->markTestSkipped("pg_dump on the PATH cannot read this server: {$e->getMessage()}");
                }

                throw $e;
            }
            $copy = new \PDO($snapshot->database);
            $copy->exec("INSERT INTO customers VALUES (2, 'written to the copy')");

            expect($snapshot->database)->toMatch('/dbname=tinvar_dump_[0-9a-f]{8}_\d+_[0-9a-f]{8}(;|$)/')
                ->and($copy->query('SELECT current_database()')?->fetchColumn())->not->toBe($source)
                ->and($copy->query('SELECT COUNT(*) FROM customers')?->fetchColumn())->toBe(2)
                ->and($original->query('SELECT COUNT(*) FROM customers')?->fetchColumn())->toBe(1);

            unset($copy);
            $snapshot->remove();
            $left = $admin->query("SELECT COUNT(*) FROM pg_database WHERE datname LIKE 'tinvar\\_dump\\_%'")?->fetchColumn();
            expect($left)->toBe(0);
        } finally {
            unset($original);
            $admin->exec("DROP DATABASE IF EXISTS {$source} WITH (FORCE)");
        }
    });

    it('refuses to copy without the server\'s own tools, and makes nothing', function (string $dsn, string $tool) {
        $path = getenv('PATH');
        putenv('PATH=/nonexistent');
        try {
            expect(static fn () => DatabaseSnapshot::of($dsn))->toThrow(\RuntimeException::class, "needs {$tool} on the PATH");
        } finally {
            putenv('PATH=' . $path);
        }
    })->with([
        'postgres' => ['pgsql:host=127.0.0.1;dbname=shop;user=shop', 'pg_dump'],
        'mysql' => ['mysql:host=127.0.0.1;dbname=shop;user=shop', 'mysqldump'],
    ]);

    it('refuses a DSN it cannot read whole', function (string $dsn, string $why) {
        expect(static fn () => DatabaseSnapshot::of($dsn))->toThrow(\RuntimeException::class, $why);
    })->with([
        'no database' => ['pgsql:host=127.0.0.1;user=shop', 'names no dbname'],
        'not key=value' => ['pgsql:host=127.0.0.1;dbname=shop;oops', 'not key=value'],
        'a control character' => ["mysql:host=127.0.0.1;dbname=shop\nDROP;user=x", 'control character'],
    ]);
});
