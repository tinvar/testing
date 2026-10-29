<?php

declare(strict_types=1);

namespace Tinvar\Testing\Dev;

use Tinvar\Support\BuildProfile;
use Tinvar\Support\DevMode;

/**
 * Where `tinvar render --dump` and the MCP server may run: a developer's
 * machine, rendering through a build made for the test profile, and nowhere
 * else (ADR 0088).
 *
 * tinvar-spec.md, "Dev and test tooling": "`tinvar build
 * --profile=production` and `--profile=staging` strip ... the MCP server
 * and `render --dump`. Only `tinvar dev` and `--profile=test` include
 * them." PHP is not built, so -- as for the browser test endpoint (ADR
 * 0066) -- what keeps them out of a production deployment is several things
 * at once, none of which an environment variable alone supplies:
 *
 *   - They are tinvar/testing's, a development dependency: an application
 *     installed with --no-dev has none of this code.
 *   - The environment must say this is not production, and nothing may say
 *     it is: no production marker, and TINVAR_ENV local, development or
 *     testing -- unset counts as production, as for `migrate:fresh`.
 *   - A server profile, where one is named, must be test: production,
 *     staging, or any profile this does not know, is refused.
 *   - What renders is a build whose own record says test (`tinvar build
 *     --profile=test`): an image's production build cannot be dumped from,
 *     whatever its environment says.
 */
final class DevTools
{
    /**
     * Refuses $tool here unless the environment says this is a developer's
     * machine.
     *
     * @param array<string, string>|null $env the environment; the process's own by default
     * @throws \RuntimeException
     */
    public static function assertAllowed(string $tool, ?array $env = null): void
    {
        $env ??= array_filter(getenv(), \is_string(...));

        $marker = DevMode::productionMarker($env);
        if ($marker !== null) {
            throw new \RuntimeException(
                "{$tool} is refused: {$marker}=production. It reads the application's routes, logs and pages for "
                . 'whoever runs it, and never runs in production.',
            );
        }

        if (!\in_array(strtolower(trim($env['TINVAR_ENV'] ?? '')), BuildProfile::NON_PRODUCTION, true)) {
            throw new \RuntimeException(
                "{$tool} is refused: nothing says this is not production. Set TINVAR_ENV=local (or development, or "
                . 'testing) on the machine you develop on.',
            );
        }

        $profile = strtolower(trim($env[BuildProfile::ENV] ?? ''));
        if ($profile !== '' && $profile !== BuildProfile::TEST) {
            throw new \RuntimeException(
                "{$tool} is refused under " . BuildProfile::ENV . "={$profile}: only the test profile, and dev, "
                . 'carry the development tools.',
            );
        }
    }

    /**
     * Refuses to render through the build in $directory unless it was made
     * for the test profile, and is all there.
     *
     * @throws \RuntimeException
     */
    public static function assertTestBuild(string $tool, string $directory): void
    {
        $profile = BuildProfile::ofBuild($directory);
        if ($profile !== BuildProfile::TEST) {
            throw new \RuntimeException(
                "{$tool} renders through a build made for the test profile, and the one in {$directory} was made for "
                . "{$profile}. Build one with `tinvar build --profile=test --out=DIR`, and name it with --build=DIR.",
            );
        }

        if (!is_file(rtrim($directory, '/') . '/rsc/index.js')) {
            throw new \RuntimeException("{$directory} has no rsc/index.js: the build is not all there. Build it again.");
        }
    }
}
