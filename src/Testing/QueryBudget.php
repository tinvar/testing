<?php

declare(strict_types=1);

namespace Tinvar\Testing;

use PHPUnit\Framework\TestCase;

/**
 * "#[QueryBudget(5)] on a page test fails when batching regresses."
 *
 *   it('lists the orders', #[QueryBudget(5)] function () {
 *       actingAs($customer)->get('/orders')->assertOk();
 *   });
 *
 * Every page the test asks for -- get(), a client visit, render() -- is held
 * to at most $max statements, as assertQueryBudget($max) holds one: its
 * middleware, checks and loaders, and through the renderer its query bodies
 * too. On a Pest test's closure, or on a PHPUnit test method.
 *
 * Read off the running test when a page answers (current()), not when the
 * test starts: Pest has no hook that hands a plugin the closure. ADR 0043
 * left this an assertion for that reason; the closure is reachable all the
 * same, through the test case Pest binds it to (ADR 0095), and a test of
 * this layer fails should a Pest release move it.
 */
#[\Attribute(\Attribute::TARGET_FUNCTION | \Attribute::TARGET_METHOD)]
final readonly class QueryBudget
{
    public function __construct(public int $max)
    {
        if ($max < 0) {
            throw new \InvalidArgumentException('A query budget is a number of statements, 0 or more.');
        }
    }

    /**
     * The budget the running test declares, or null for none: read from the
     * test case on the call stack -- its Pest closure, or its method.
     */
    public static function current(): ?int
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT) as $frame) {
            $test = $frame['object'] ?? null;
            if ($test instanceof TestCase) {
                return self::of($test);
            }
        }

        return null;
    }

    /** The budget $test declares, or null for none. */
    public static function of(TestCase $test): ?int
    {
        foreach (self::declarations($test) as $declaration) {
            $attributes = $declaration->getAttributes(self::class);
            if ($attributes !== []) {
                return $attributes[0]->newInstance()->max;
            }
        }

        return null;
    }

    /**
     * What a budget can be written on: the test's own closure under Pest --
     * the one it wraps in `__test`, kept as the wrapper's `closure` -- and
     * the method PHPUnit runs.
     *
     * @return list<\ReflectionFunctionAbstract>
     */
    private static function declarations(TestCase $test): array
    {
        $found = [];

        $class = new \ReflectionClass($test);
        if ($class->hasProperty('__test')) {
            $wrapper = $class->getProperty('__test')->getValue($test);
            if ($wrapper instanceof \Closure) {
                $closure = new \ReflectionFunction($wrapper)->getStaticVariables()['closure'] ?? null;
                if ($closure instanceof \Closure) {
                    $found[] = new \ReflectionFunction($closure);
                }
            }
        }

        if ($class->hasMethod($test->name())) {
            $found[] = $class->getMethod($test->name());
        }

        return $found;
    }
}
