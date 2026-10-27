<?php

declare(strict_types=1);

namespace Tinvar\Testing\Browser;

use Tinvar\Http\Application;
use Tinvar\Http\BrowserTestable;
use Tinvar\Http\Request;
use Tinvar\Http\Response;
use Tinvar\Http\Terminable;
use Tinvar\Support\BuildProfile;

/**
 * An application served to a browser test run: the application, with the
 * test endpoint (BrowserTestEndpoint) in front of it and its clock set to
 * the run's on every request (ADR 0066).
 *
 * How it stays out of production, each layer on its own:
 *
 *   - Its code is tinvar/testing's, which an application requires for
 *     development only: `composer install --no-dev` leaves none of it, and
 *     an application asked for the test profile without it refuses to start.
 *   - It is made only under the test profile, which BuildProfile refuses
 *     beside any production marker and wherever TINVAR_ENV does not say
 *     local, development or testing -- unset is production -- checked again
 *     here, whatever the application checked.
 *   - The profile needs a build made for it, `tinvar build --profile=test`,
 *     which the application holds its build to before it calls this: an
 *     image's production build cannot be given the endpoint by its
 *     environment.
 *   - It needs a run: a private directory the runner made, holding the hash
 *     of a token only the runner has.
 *   - Each request must come from loopback, naming loopback, with that
 *     token; anything else goes to the application as if none of this were
 *     here.
 *   - It signs in only users the run's own scenarios seeded, through the
 *     application's sessions.
 *
 * Moving the clock moves the application's clock in the processes serving
 * this run, and nothing else: the record is the run's, read by each request
 * the run's server takes.
 */
final class BrowserTests implements Application, Terminable
{
    private readonly BrowserTestEndpoint $endpoint;

    private function __construct(
        private readonly BrowserTestable $app,
        private readonly BrowserTestRun $run,
    ) {
        $this->endpoint = new BrowserTestEndpoint($app, $run);
    }

    /**
     * $app, served to the run TINVAR_TEST_RUN names.
     *
     * @param array<string, string>|null $env the environment; the process's own by default
     * @throws \RuntimeException unless the profile is test, and allowed here, and a run is named
     */
    public static function around(BrowserTestable $app, ?array $env = null): self
    {
        $env ??= array_filter(getenv(), \is_string(...));
        if (BuildProfile::fromEnvironment($env) !== BuildProfile::TEST) {
            throw new \RuntimeException('The browser test endpoint is served under ' . BuildProfile::ENV . '=test alone.');
        }

        return new self($app, BrowserTestRun::fromEnvironment($env));
    }

    public function handle(Request $request, string $rawBody = ''): Response
    {
        // First, for the endpoint's own requests too: every process serving
        // the run reads the one record, so the clock is the same wherever
        // the request lands.
        $this->app->moveClock($this->run->offset());

        if ($this->endpoint->matches($request) && $this->endpoint->admits($request)) {
            return $this->endpoint->handle($request, $rawBody);
        }

        return $this->app->handle($request, $rawBody);
    }

    public function reset(): void
    {
        $this->app->reset();
    }

    public function terminate(): void
    {
        if ($this->app instanceof Terminable) {
            $this->app->terminate();
        }
    }
}
