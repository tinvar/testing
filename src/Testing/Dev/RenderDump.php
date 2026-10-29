<?php

declare(strict_types=1);

namespace Tinvar\Testing\Dev;

use Tinvar\Auth\Authenticatable;
use Tinvar\Http\BrowserTestable;
use Tinvar\Http\DevOverlay;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Http\TestableApplication;
use Tinvar\Render\RenderResult;
use Tinvar\Routing\LoadedRoute;
use Tinvar\Testing\RscPayload;

/**
 * `tinvar render --dump`: one page, through the application's whole request
 * path and its real renderer, with the HTML and the RSC payload it was
 * answered with, for a person or an assistant to read (ADR 0088).
 *
 * As nobody, or as the first user one of the application's scenarios made
 * (BrowserTestable::seed(), the browser test endpoint's): signed in through
 * the application's own sessions, as a browser test signs in, never by a
 * user id handed in from outside. The application it is given is one the
 * caller built for the dump -- the shop's on a copy of its database -- since
 * a scenario writes rows and signing in writes a session.
 *
 * What the dump shows is what the browser was sent, with what would let it
 * act as that browser taken out: no Set-Cookie line (their names alone),
 * and every cookie's value, the nonce, the framework's signed tokens, JWTs
 * and long key material cut out of the HTML and the payload. The render
 * itself is the renderer's, which holds no secret: the dump reads what it
 * wrote, as the browser would.
 */
final readonly class RenderDump
{
    /** A path longer than this is no page's. */
    public const int MAX_PATH = 2048;

    /** What a cut-out value is replaced by. */
    public const string REDACTED = '[redacted]';

    /** The scenario input a dump may pass on: JSON-sized, as the test endpoint takes. */
    private const int MAX_INPUT = 64 * 1024;

    /** Values cut out of what was rendered, whatever page they are in. */
    private const array VALUES = [
        // The framework's signed tokens (SignedToken): route, fragment, live.
        '/\bv1\.[A-Za-z0-9_\-]{16,}\.[A-Za-z0-9_\-]{16,}/' => '[token]',
        // JWTs, the live hub's access tokens among them.
        '/\beyJ[A-Za-z0-9_\-]*\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]*/' => '[jwt]',
        // A long run of key material -- a MAC, a history key, a signature --
        // with letters and digits both, as the Redactor reads it.
        '/(?<![A-Za-z0-9_\-])(?=[A-Za-z0-9_\-]*[0-9])(?=[A-Za-z0-9_\-]*[A-Za-z])[A-Za-z0-9_\-]{40,}(?![A-Za-z0-9_\-])/' => self::REDACTED,
    ];

    public function __construct(private TestableApplication $app) {}

    /**
     * Renders $path as nobody, or as $scenario's first user, and says what
     * came back.
     *
     * @param array<string, mixed> $input the scenario's input, as the test endpoint passes it
     * @return array{
     *     path: string,
     *     as: array{scenario: string|null, user: string|null},
     *     status: int,
     *     headers: array<string, string>,
     *     cookies: list<string>,
     *     rendered: bool,
     *     html: string,
     *     payload: string,
     *     head: list<array<string, mixed>>,
     *     timings: array<string, float|int>|null,
     *     failure: string|null,
     * }
     * @throws \InvalidArgumentException for a path that is not a page's, or a scenario the application refuses
     */
    public function dump(string $path, ?string $scenario = null, array $input = []): array
    {
        self::assertPagePath($path);
        if (\strlen(json_encode($input, JSON_THROW_ON_ERROR)) > self::MAX_INPUT) {
            throw new \InvalidArgumentException('The scenario input is larger than 64 KiB.');
        }

        [$rawPath, $query] = array_pad(explode('?', $path, 2), 2, null);
        parse_str($query ?? '', $parsed);
        /** @var array<string, mixed> $parsed */
        $build = static fn (array $cookies): Request => new Request(
            'GET',
            (string) $rawPath,
            ['accept' => 'text/html', 'host' => 'localhost'],
            $parsed,
            [],
            $cookies,
            $query,
            '127.0.0.1',
        );

        $request = $build([]);
        $user = null;
        if ($scenario !== null) {
            $user = $this->scenarioUser($scenario, $input);
            $request = $build($this->app->signedIn($user, $request));
        }

        $result = null;
        $written = '';
        $rendered = static function (RenderResult $measured, string $bytes) use (&$result, &$written): void {
            $result = $measured;
            $written = $bytes;
        };

        $response = $this->app->handleWith(
            $request,
            fn (LoadedRoute $loaded, Request $passed): Response => $this->app->respond($loaded, $passed, $rendered),
        );

        $html = $response->isStreaming() ? $written : $response->body();
        $secrets = self::secretsOf($request, $response);
        $html = self::redact($html, $secrets);
        $failure = $response->status >= 500 ? $this->app->lastFailure() : null;

        return [
            'path' => $path,
            'as' => ['scenario' => $scenario, 'user' => $user?->authIdentifier()],
            'status' => $response->status,
            'headers' => self::headersOf($response, $secrets),
            // Their names alone: a cookie's value is the browser's session.
            'cookies' => array_keys($response->cookies()),
            'rendered' => $result !== null,
            'html' => $html,
            'payload' => RscPayload::of($html, $response->header('content-type')),
            'head' => $result === null ? [] : $result->meta,
            'timings' => $result === null ? null : [
                'totalMs' => round($result->totalMs, 2),
                'timeToFirstByteMs' => round($result->timeToFirstByteMs, 2),
                'waitingOnRendererMs' => round($result->waitingOnRendererMs, 2),
                'queryMs' => round($result->queryMs, 2),
                'queryCalls' => $result->queryCalls,
                'queryBatches' => $result->queryBatches,
                'bytes' => $result->bytes,
            ],
            'failure' => $failure === null ? null : $failure::class . ': ' . self::redact($failure->getMessage(), $secrets),
        ];
    }

    /**
     * Only a page's path: absolute, on this origin, no framework endpoint.
     * Everything under /_ is the framework's -- files, uploads, the test
     * endpoint, probes -- and none of it is a page to dump.
     *
     * @throws \InvalidArgumentException
     */
    public static function assertPagePath(string $path): void
    {
        $bare = explode('?', $path, 2)[0];
        if ($path === ''
            || \strlen($path) > self::MAX_PATH
            || !str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || str_starts_with($bare, '/_')
            || str_contains($path, '#')
            || str_contains($path, '\\')
            || preg_match('/[\x00-\x20\x7f]/', $path) === 1) {
            throw new \InvalidArgumentException(
                "'{$path}' is not a page's path: an absolute path on the application, such as /orders, and not under /_.",
            );
        }
    }

    /** @param array<string, mixed> $input */
    private function scenarioUser(string $scenario, array $input): Authenticatable
    {
        if (!$this->app instanceof BrowserTestable) {
            throw new \InvalidArgumentException('This application has no scenarios to sign in from: it is not BrowserTestable.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9_.\-]{0,63}\z/i', $scenario) !== 1) {
            throw new \InvalidArgumentException("'{$scenario}' is not a scenario's name.");
        }

        $made = $this->app->seed($scenario, $input);
        $users = $made['users'] ?? null;
        $first = \is_array($users) && $users !== [] ? $users[array_key_first($users)] : null;
        if (!\is_string($first) && !\is_int($first)) {
            throw new \InvalidArgumentException("The scenario '{$scenario}' made no user to sign in as.");
        }

        return $this->app->testUser((string) $first)
            ?? throw new \InvalidArgumentException("The scenario '{$scenario}' named a user there is none of.");
    }

    /**
     * What must not be shown, whatever page it turns up in: every cookie
     * either side holds, and the response's nonce.
     *
     * @return list<string>
     */
    private static function secretsOf(Request $request, Response $response): array
    {
        $values = array_values($request->cookies);
        foreach ($response->cookies() as $line) {
            $pair = explode(';', $line, 2)[0];
            $values[] = rawurldecode(explode('=', $pair, 2)[1] ?? '');
            $values[] = explode('=', $pair, 2)[1] ?? '';
        }
        $nonce = DevOverlay::nonceOf($response->header('content-security-policy'));
        if ($nonce !== null) {
            $values[] = $nonce;
        }

        // Longest first, so a value inside another is not left half there;
        // and none so short it would cut words out of the page.
        $values = array_values(array_unique(array_filter($values, static fn (string $v): bool => \strlen($v) >= 8)));
        usort($values, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        return $values;
    }

    /** @param list<string> $secrets */
    private static function redact(string $text, array $secrets): string
    {
        if ($secrets !== []) {
            $text = str_replace($secrets, self::REDACTED, $text);
        }
        $redacted = preg_replace(array_keys(self::VALUES), array_values(self::VALUES), $text);

        // A page the patterns could not finish is not shown at all rather
        // than shown unredacted.
        return \is_string($redacted) ? $redacted : self::REDACTED;
    }

    /**
     * The headers worth reading, redacted; never Set-Cookie.
     *
     * @param list<string> $secrets
     * @return array<string, string>
     */
    private static function headersOf(Response $response, array $secrets): array
    {
        $out = [];
        foreach ($response->headers() as $name => $value) {
            if ($name === 'set-cookie') {
                continue;
            }
            $out[$name] = self::redact($value, $secrets);
        }
        ksort($out);

        return $out;
    }
}
