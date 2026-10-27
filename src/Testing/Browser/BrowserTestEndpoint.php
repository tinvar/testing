<?php

declare(strict_types=1);

namespace Tinvar\Testing\Browser;

use Tinvar\Dev\Loopback;
use Tinvar\Http\Request;
use Tinvar\Http\Response;

/**
 * The browser test helpers' endpoint: tinvar-spec.md's "PHP helpers it
 * calls over a test-only endpoint: seed the database, log in as a user,
 * move the clock, reset between tests".
 *
 *   POST /_test/seed      {"scenario": "customer", "input": {...}}
 *   POST /_test/sign-in   {"user": "<id a scenario returned>"}
 *   POST /_test/clock     {"travel": 3600} | {"at": 1798761600} | {}
 *   POST /_test/reset     {}
 *
 * Every call carries the run's token in X-Tinvar-Test-Token, from loopback,
 * naming loopback (Loopback: no page elsewhere can rebind a name of its own
 * onto it). A request that is not both is not answered here at all: it goes
 * on to the application, which answers it as any path it has no page for.
 * Nobody without the token can tell the endpoint is there.
 *
 * Signing in is the application's (BrowserTestable): a real session, made
 * as signing in makes one, for a user a scenario of this run seeded and no
 * one else -- not an id found in the database, not the staff account.
 */
final class BrowserTestEndpoint
{
    public const string PREFIX = '/_test';

    /** A test's call is small; anything near this is not one. */
    public const int MAX_BODY = 65_536;

    /**
     * A user's id as the run records it: printable, no space, one line --
     * the run's list of users is a line each, and an id that could hold a
     * line break could add a second.
     */
    private const string ID = '/^[\x21-\x7e]{1,200}\z/';

    public function __construct(
        private readonly BrowserTestable $app,
        private readonly BrowserTestRun $run,
    ) {}

    public function matches(Request $request): bool
    {
        return $request->path === self::PREFIX || str_starts_with($request->path, self::PREFIX . '/');
    }

    /** Whether $request may be answered here: from loopback, with the run's token. */
    public function admits(Request $request): bool
    {
        return Loopback::request($request) && $this->run->admits($request->header(BrowserTestRun::HEADER));
    }

    /** Answers an admitted request. */
    public function handle(Request $request, string $rawBody): Response
    {
        if ($request->method !== 'POST') {
            return self::json(405, ['error' => 'POST only.'])->withHeader('allow', 'POST');
        }
        // A JSON body, which no form and no simple cross-origin request can
        // send, beside the token no page has.
        $type = strtolower(trim(explode(';', (string) $request->header('content-type'))[0]));
        if ($type !== 'application/json') {
            return self::json(415, ['error' => 'The endpoint takes application/json.']);
        }
        if (\strlen($rawBody) > self::MAX_BODY) {
            return self::json(413, ['error' => 'The body is too large for a test\'s call.']);
        }

        $body = $rawBody === '' ? [] : json_decode($rawBody, true, 16);
        if (!\is_array($body) || ($body !== [] && array_is_list($body))) {
            return self::json(400, ['error' => 'The body is a JSON object.']);
        }
        /** @var array<string, mixed> $body */

        try {
            return match (substr($request->path, \strlen(self::PREFIX))) {
                '/seed' => $this->seed($body),
                '/sign-in' => $this->signIn($body, $request),
                '/clock' => $this->clock($body),
                '/reset' => $this->reset(),
                default => self::json(404, ['error' => 'No such helper: seed, sign-in, clock or reset.']),
            };
        } catch (\InvalidArgumentException $e) {
            return self::json(422, ['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            // A helper that failed is a failed request, not a worker that
            // stops: the cause to the log, its class to the test.
            error_log('[tinvar] the browser test endpoint failed: ' . $e::class . ': ' . $e->getMessage());

            return self::json(500, ['error' => 'The helper failed: ' . $e::class . '. The server\'s log says why.']);
        }
    }

    /** @param array<string, mixed> $body */
    private function seed(array $body): Response
    {
        $scenario = $body['scenario'] ?? null;
        if (!\is_string($scenario) || preg_match('/^[a-z][a-z0-9-]{0,63}\z/', $scenario) !== 1) {
            throw new \InvalidArgumentException('`scenario` names one of the application\'s scenarios.');
        }
        $input = $body['input'] ?? [];
        if (!\is_array($input) || ($input !== [] && array_is_list($input))) {
            throw new \InvalidArgumentException('`input` is a JSON object.');
        }
        /** @var array<string, mixed> $input */

        $made = $this->app->seed($scenario, $input);
        $users = $made['users'] ?? null;
        if (!\is_array($users) || !array_is_list($users)
            || array_filter($users, static fn (mixed $id): bool => !\is_string($id) || preg_match(self::ID, $id) !== 1) !== []) {
            throw new \LogicException("Scenario '{$scenario}' did not say which users it made, as a list of ids.");
        }
        /** @var list<string> $users */
        $this->run->remember(...$users);

        return self::json(200, $made);
    }

    /** @param array<string, mixed> $body */
    private function signIn(array $body, Request $request): Response
    {
        $id = $body['user'] ?? null;
        if (!\is_string($id) || preg_match(self::ID, $id) !== 1) {
            throw new \InvalidArgumentException('`user` is the id of a user a scenario of this run seeded.');
        }
        // Only a user this run seeded: the endpoint signs in no one else,
        // whoever the token's holder names.
        if (!$this->run->knows($id)) {
            return self::json(403, ['error' => 'Signs in only a user a scenario of this run seeded.']);
        }
        $user = $this->app->testUser($id);
        if ($user === null) {
            return self::json(404, ['error' => 'That user is gone.']);
        }

        return self::json(200, ['cookies' => $this->app->signedIn($user, $request)]);
    }

    /** @param array<string, mixed> $body */
    private function clock(array $body): Response
    {
        $travel = $body['travel'] ?? null;
        $at = $body['at'] ?? null;
        if ($travel !== null && $at !== null) {
            throw new \InvalidArgumentException('`travel` or `at`, not both.');
        }
        if ($travel !== null) {
            if (!\is_int($travel)) {
                throw new \InvalidArgumentException('`travel` is a whole number of seconds.');
            }
            $this->run->moveClock($this->run->offset() + $travel);
        } elseif ($at !== null) {
            if (!\is_int($at) || $at <= 0) {
                throw new \InvalidArgumentException('`at` is a unix time.');
            }
            $this->run->moveClock($at - time());
        }

        // This process's too, at once; every other reads the run's record
        // as its next request starts.
        $offset = $this->run->offset();
        $this->app->moveClock($offset);

        return self::json(200, ['offset' => $offset, 'now' => time() + $offset]);
    }

    private function reset(): Response
    {
        $this->run->reset();
        $this->app->moveClock(0);

        return self::json(200, ['offset' => 0, 'now' => time()]);
    }

    /** @param array<string, mixed> $data */
    private static function json(int $status, array $data): Response
    {
        return Response::make($status, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), [
            'content-type' => 'application/json',
            'cache-control' => 'no-store',
        ]);
    }
}
