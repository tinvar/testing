<?php

declare(strict_types=1);

namespace Tinvar\Tests\Testing;

use PHPUnit\Framework\TestCase;
use Tinvar\Testing\QueryBudget;

/** `#[QueryBudget]` on a PHPUnit test method, as on a Pest closure (ADR 0095). */
final class QueryBudgetMethodTest extends TestCase
{
    #[QueryBudget(4)]
    public function testReadsTheMethod(): void
    {
        self::assertSame(4, QueryBudget::current());
    }

    public function testReadsNoneOffAMethodWithout(): void
    {
        self::assertNull(QueryBudget::current());
    }
}
