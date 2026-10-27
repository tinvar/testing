<?php

declare(strict_types=1);

/**
 * The browser test endpoint in front of an application (ADR 0066): every
 * way it refuses, and what it does for a run's own token from loopback.
 */

namespace Tinvar\Tests\Testing\BrowserTests;

use Tinvar\Auth\Authenticatable;
use Tinvar\Http\Kernel;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Testing\Browser\BrowserTestable;
use Tinvar\Testing\Browser\BrowserTestRun;
use Tinvar\Testing\Browser\BrowserTests;

/** An application that records what the endpoint asked of it. */
final class Recorder implements BrowserTestable
{
    /** @var list<string> */
    public array $handled = [];

    /** @var list<array{string, array<string, mixed>}> */
    public array $seeded = [];

    /** @var list<string> */
    public array $signedIn = [];

    /** @var list<int> */
    public array $clock = [];

    public int $next = 1;

    public function handle(Request $request, string $rawBody = ''): Response
    {
        $this->handled[] = $request->path;

        return Response::make(404, 'the application\'s own 404');
    }

    public function reset(): void {}

    public function kernel(): Kernel
    {
        throw new \LogicException('not here');
    }

    public function handleWith(Request $request, \Closure $respond, string $rawBody = ''): Response
    {
        throw new \LogicException('not here');
    }

    public function respond(LoadedRoute $loaded, Request $request, ?\Closure $rendered = null): Response
    {
        throw new \LogicException('not here');
    }

    public function signedIn(Authenticatable $user, Request $request): array
    {
        $this->signedIn[] = $user->authIdentifier();

        return ['session' => 'cookie-for-' . $user->authIdentifier()];
    }

    public function lastFailure(): ?\Throwable
    {
        return null;
    }

    public function seed(string $scenario, array $input): array
    {
        if ($scenario === 'two-lines') {
            return ['users' => ["user-9\nstaff-1"]];
        }
        if ($scenario === 'broken') {
            throw new \RuntimeException('SQLSTATE[HY000]: no such table: customers');
        }
        if ($scenario !== 'customer') {
            throw new \InvalidArgumentException("No scenario '{$scenario}'.");
        }
        $this->seeded[] = [$scenario, $input];

        return ['users' => ['user-' . $this->next++], 'email' => 'someone@shop.test'];
    }

    public function testUser(string $id): Authenticatable
    {
        return new readonly class ($id) implements Authenticatable {
            public function __construct(private string $id) {}

            public function authIdentifier(): string
            {
                return $this->id;
            }
        };
    }

    public function moveClock(int $offset): void
    {
        $this->clock[] = $offset;
    }
}

/**
 * A run, the application, and the endpoint in front of it, for $test.
 *
 * @param \Closure(BrowserTests, Recorder, string, string): void $test given the
 *        endpoint, the application, the run's token and its directory
 */
function served(\Closure $test): void
{
    ['directory' => $directory, 'token' => $token] = BrowserTestRun::prepare();
    try {
        $app = new Recorder();
        $tests = BrowserTests::around($app, ['TINVAR_PROFILE' => 'test', 'TINVAR_ENV' => 'testing', 'TINVAR_TEST_RUN' => $directory]);
        $test($tests, $app, $token, $directory);
    } finally {
        BrowserTestRun::remove($directory);
    }
}

/**
 * A call to the endpoint as the runner makes one: from loopback, naming it,
 * with the token and a JSON body; each part overridable.
 *
 * @param array<string, mixed>|null $body
 * @param array<string, string> $headers
 */
function call(BrowserTests $tests, string $path, ?string $token, ?array $body = [], array $headers = [], string $from = '127.0.0.1', string $method = 'POST'): Response
{
    $headers = [
        'host' => '127.0.0.1:8080',
        'content-type' => 'application/json',
        ...($token === null ? [] : ['x-tinvar-test-token' => $token]),
        ...$headers,
    ];

    return $tests->handle(
        new Request($method, $path, $headers, remoteAddress: $from),
        $body === null ? '' : json_encode((object) $body, JSON_THROW_ON_ERROR),
    );
}

/** @return array<string, mixed> */
function decoded(Response $response): array
{
    $decoded = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
    \assert(\is_array($decoded));

    /** @var array<string, mixed> */
    return $decoded;
}

// ------------------------------------------------------------ refusals

it('is not made outside the test profile', function (array $env) {
    ['directory' => $directory] = BrowserTestRun::prepare();
    try {
        BrowserTests::around(new Recorder(), [...$env, 'TINVAR_TEST_RUN' => $directory]);
    } finally {
        BrowserTestRun::remove($directory);
    }
})->with([
    'no profile' => [['TINVAR_ENV' => 'testing']],
    'production profile' => [['TINVAR_PROFILE' => 'production', 'TINVAR_ENV' => 'testing']],
])->throws(\RuntimeException::class, 'TINVAR_TEST_RUN is set');

it('is not made where the environment says production, or says nothing', function (array $env) {
    ['directory' => $directory] = BrowserTestRun::prepare();
    try {
        BrowserTests::around(new Recorder(), ['TINVAR_PROFILE' => 'test', 'TINVAR_TEST_RUN' => $directory, ...$env]);
    } finally {
        BrowserTestRun::remove($directory);
    }
})->with([
    'unset' => [[]],
    'TINVAR_ENV' => [['TINVAR_ENV' => 'production']],
    'APP_ENV' => [['TINVAR_ENV' => 'testing', 'APP_ENV' => 'production']],
    'NODE_ENV' => [['TINVAR_ENV' => 'testing', 'NODE_ENV' => 'production']],
])->throws(\RuntimeException::class, 'TINVAR_PROFILE=test is refused');

it('is not made without a run', function () {
    BrowserTests::around(new Recorder(), ['TINVAR_PROFILE' => 'test', 'TINVAR_ENV' => 'testing']);
})->throws(\RuntimeException::class, 'no TINVAR_TEST_RUN names the run');

it('is not made for a directory that is not a run\'s', function () {
    $directory = sys_get_temp_dir() . '/tinvar-not-a-run-' . bin2hex(random_bytes(4));
    mkdir($directory, 0700);
    try {
        BrowserTests::around(new Recorder(), ['TINVAR_PROFILE' => 'test', 'TINVAR_ENV' => 'testing', 'TINVAR_TEST_RUN' => $directory]);
    } finally {
        rmdir($directory);
    }
})->throws(\RuntimeException::class, 'is not one a run made');

it('refuses a run another user could reach into', function (string $file, int $mode) {
    ['directory' => $directory] = BrowserTestRun::prepare();
    $path = $file === '' ? $directory : "{$directory}/{$file}";
    chmod($path, $mode);
    try {
        BrowserTestRun::open($directory);
    } finally {
        chmod($path, $file === '' ? 0700 : 0600);
        BrowserTestRun::remove($directory);
    }
})->with([
    'the directory' => ['', 0755],
    'the token' => ['token.sha256', 0644],
    'the clock' => ['clock', 0620],
])->throws(\RuntimeException::class, 'is not private to this user');

it('refuses a token file that is a link', function () {
    ['directory' => $directory] = BrowserTestRun::prepare();
    $elsewhere = (string) tempnam(sys_get_temp_dir(), 'tinvar-hash-');
    chmod($elsewhere, 0600);
    file_put_contents($elsewhere, hash('sha256', 'chosen'));
    unlink("{$directory}/token.sha256");
    symlink($elsewhere, "{$directory}/token.sha256");
    try {
        BrowserTestRun::open($directory);
    } finally {
        unlink($elsewhere);
        BrowserTestRun::remove($directory);
    }
})->throws(\RuntimeException::class, 'is not one a run made');

// Not from loopback, it is not there: the application answers, as it does
// any path it has no page for, and nothing of the endpoint's runs.
it('leaves a request from elsewhere to the application', function (string $from) {
    served(function (BrowserTests $tests, Recorder $app, string $token) use ($from): void {
        $response = call($tests, '/_test/seed', $token, ['scenario' => 'customer'], from: $from);

        expect($response->body())->toBe('the application\'s own 404')
            ->and($app->handled)->toBe(['/_test/seed'])
            ->and($app->seeded)->toBe([]);
    });
})->with(['10.0.0.8', '192.168.1.20', '::ffff:10.0.0.8']);

it('leaves a request with no address to the application', function () {
    served(function (BrowserTests $tests, Recorder $app, string $token): void {
        $response = $tests->handle(new Request('POST', '/_test/seed', ['host' => '127.0.0.1', 'content-type' => 'application/json', 'x-tinvar-test-token' => $token]), '{"scenario":"customer"}');

        expect($response->status)->toBe(404)
            ->and($app->seeded)->toBe([]);
    });
});

// DNS rebinding: a page elsewhere whose name now points at 127.0.0.1.
it('leaves a request naming another host to the application', function () {
    served(function (BrowserTests $tests, Recorder $app, string $token): void {
        $response = call($tests, '/_test/seed', $token, ['scenario' => 'customer'], ['host' => 'attacker.example:8080']);

        expect($response->body())->toBe('the application\'s own 404')
            ->and($app->seeded)->toBe([]);
    });
});

it('leaves a request without the run\'s token to the application', function (?string $token) {
    served(function (BrowserTests $tests, Recorder $app) use ($token): void {
        $response = call($tests, '/_test/sign-in', $token, ['user' => 'user-1']);

        expect($response->body())->toBe('the application\'s own 404')
            ->and($app->signedIn)->toBe([]);
    });
})->with([
    'none' => [null],
    'empty' => [''],
    'wrong' => [str_repeat('ab', 32)],
    'not hex' => [str_repeat('z', 64)],
]);

it('leaves a request with another run\'s token to the application', function () {
    ['directory' => $other, 'token' => $theirs] = BrowserTestRun::prepare();
    try {
        served(function (BrowserTests $tests, Recorder $app) use ($theirs): void {
            $response = call($tests, '/_test/seed', $theirs, ['scenario' => 'customer']);

            expect($response->body())->toBe('the application\'s own 404')
                ->and($app->seeded)->toBe([]);
        });
    } finally {
        BrowserTestRun::remove($other);
    }
});

// The hash is all the server holds: the token is not in the run's directory.
it('keeps no token where the server can read it back', function () {
    served(function (BrowserTests $tests, Recorder $app, string $token, string $directory): void {
        foreach (glob("{$directory}/{,.}*", GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                expect((string) file_get_contents($file))->not->toContain($token);
            }
        }
    });
});

it('takes POST alone', function () {
    served(function (BrowserTests $tests, Recorder $app, string $token): void {
        $response = call($tests, '/_test/seed', $token, null, method: 'GET');

        expect($response->status)->toBe(405)
            ->and($response->header('allow'))->toBe('POST')
            ->and($app->seeded)->toBe([]);
    });
});

// A form, or a simple cross-origin request, cannot send JSON.
it('takes JSON alone', function (string $type) {
    served(function (BrowserTests $tests, Recorder $app, string $token) use ($type): void {
        $response = call($tests, '/_test/seed', $token, ['scenario' => 'customer'], ['content-type' => $type]);

        expect($response->status)->toBe(415)
            ->and($app->seeded)->toBe([]);
    });
})->with(['application/x-www-form-urlencoded', 'text/plain', 'multipart/form-data; boundary=x']);

it('signs in only a user a scenario of this run seeded', function () {
    served(function (BrowserTests $tests, Recorder $app, string $token): void {
        $response = call($tests, '/_test/sign-in', $token, ['user' => 'staff-1']);

        expect($response->status)->toBe(403)
            ->and($app->signedIn)->toBe([]);
    });
});

// The run keeps its users a line each: an id that could hold a line break
// could add someone the scenario never made.
it('records only ids of one printable line', function () {
    served(function (BrowserTests $tests, Recorder $app, string $token): void {
        $log = (string) tempnam(sys_get_temp_dir(), 'tinvar-endpoint-log-');
        $before = ini_set('error_log', $log);
        try {
            $seeded = call($tests, '/_test/seed', $token, ['scenario' => 'two-lines']);
        } finally {
            ini_set('error_log', (string) $before);
            unlink($log);
        }

        expect($seeded->status)->toBe(500)
            ->and(call($tests, '/_test/sign-in', $token, ['user' => 'staff-1'])->status)->toBe(403)
            ->and(call($tests, '/_test/sign-in', $token, ['user' => "user-9\nstaff-1"])->status)->toBe(422)
            ->and($app->signedIn)->toBe([]);
    });
});

it('answers a helper that failed with its class, and the cause to the log alone', function () {
    served(function (BrowserTests $tests, Recorder $app, string $token): void {
        $log = (string) tempnam(sys_get_temp_dir(), 'tinvar-endpoint-log-');
        $before = ini_set('error_log', $log);
        try {
            $response = call($tests, '/_test/seed', $token, ['scenario' => 'broken']);
        } finally {
            ini_set('error_log', (string) $before);
        }

        expect($response->status)->toBe(500)
            ->and($response->body())->toContain('RuntimeException')
            ->and($response->body())->not->toContain('SQLSTATE')
            ->and((string) file_get_contents($log))->toContain('SQLSTATE');
        unlink($log);
    });
});

it('refuses a body that is not an object, or names no helper', function (string $path, ?string $raw, int $status) {
    served(function (BrowserTests $tests, Recorder $app, string $token) use ($path, $raw, $status): void {
        $response = $tests->handle(
            new Request('POST', $path, ['host' => 'localhost:9000', 'content-type' => 'application/json', 'x-tinvar-test-token' => $token], remoteAddress: '::1'),
            (string) $raw,
        );

        expect($response->status)->toBe($status)
            ->and($app->seeded)->toBe([]);
    });
})->with([
    'a list' => ['/_test/seed', '["customer"]', 400],
    'not JSON' => ['/_test/seed', 'scenario=customer', 400],
    'no scenario' => ['/_test/seed', '{}', 422],
    'a scenario named oddly' => ['/_test/seed', '{"scenario":"../customer"}', 422],
    'input as a list' => ['/_test/seed', '{"scenario":"customer","input":[1]}', 422],
    'no such helper' => ['/_test/drop-tables', '{}', 404],
    'too large' => ['/_test/seed', '{"scenario":"customer","input":{"x":"' . str_repeat('a', 70_000) . '"}}', 413],
]);

// ------------------------------------------------------------ the helpers

it('seeds a scenario, and then signs in the user it made', function () {
    served(function (BrowserTests $tests, Recorder $app, string $token): void {
        $seeded = call($tests, '/_test/seed', $token, ['scenario' => 'customer', 'input' => ['orders' => 2]]);
        expect($seeded->status)->toBe(200)
            ->and(decoded($seeded))->toBe(['users' => ['user-1'], 'email' => 'someone@shop.test'])
            ->and($app->seeded)->toBe([['customer', ['orders' => 2]]])
            ->and($seeded->header('cache-control'))->toBe('no-store');

        $signedIn = call($tests, '/_test/sign-in', $token, ['user' => 'user-1']);
        expect($signedIn->status)->toBe(200)
            ->and(decoded($signedIn))->toBe(['cookies' => ['session' => 'cookie-for-user-1']])
            ->and($app->signedIn)->toBe(['user-1'])
            ->and($app->handled)->toBe([]);
    });
});

it('says why a scenario was refused', function () {
    served(function (BrowserTests $tests, Recorder $app, string $token): void {
        $response = call($tests, '/_test/seed', $token, ['scenario' => 'everyone']);

        expect($response->status)->toBe(422)
            ->and(decoded($response)['error'])->toBe("No scenario 'everyone'.");
    });
});

it('moves the clock for every request after, and puts it back', function () {
    served(function (BrowserTests $tests, Recorder $app, string $token, string $directory): void {
        $moved = call($tests, '/_test/clock', $token, ['travel' => 3600]);
        expect(decoded($moved)['offset'])->toBe(3600);
        call($tests, '/_test/clock', $token, ['travel' => 60]);

        // The record is the run's: a process that never saw the call reads it.
        expect(BrowserTestRun::open($directory)->offset())->toBe(3660);

        $tests->handle(Request::get('/orders', ['host' => '127.0.0.1']));
        // Each call moves this process's clock as it answers, and each
        // request reads the run's record as it starts.
        expect($app->clock)->toBe([0, 3600, 3600, 3660, 3660]);

        $at = call($tests, '/_test/clock', $token, ['at' => time() - 86_400]);
        expect(decoded($at)['offset'])->toBeLessThanOrEqual(-86_400)->toBeGreaterThan(-86_405);

        call($tests, '/_test/reset', $token);
        $tests->handle(Request::get('/orders', ['host' => '127.0.0.1']));
        expect(end($app->clock))->toBe(0);
    });
});

it('refuses a clock moved further than ten years, or by a fraction', function (array $body) {
    served(function (BrowserTests $tests, Recorder $app, string $token) use ($body): void {
        expect(call($tests, '/_test/clock', $token, $body)->status)->toBe(422);
    });
})->with([
    'too far' => [['travel' => 400_000_000]],
    'a fraction' => [['travel' => 1.5]],
    'a string' => [['travel' => '3600']],
    'both' => [['travel' => 1, 'at' => 1]],
]);

it('forgets the run\'s users on reset', function () {
    served(function (BrowserTests $tests, Recorder $app, string $token): void {
        call($tests, '/_test/seed', $token, ['scenario' => 'customer']);
        call($tests, '/_test/reset', $token);

        expect(call($tests, '/_test/sign-in', $token, ['user' => 'user-1'])->status)->toBe(403)
            ->and($app->signedIn)->toBe([]);
    });
});
