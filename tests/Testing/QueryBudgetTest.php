<?php

declare(strict_types=1);

/**
 * `#[QueryBudget(5)]` (ADR 0095), read off the running test: a Pest closure
 * or a PHPUnit method. Should a Pest release move the closure out of reach,
 * these fail -- the budget is never quietly unread.
 */

use Tinvar\Testing\QueryBudget;

it('reads the budget off the test\'s closure', #[QueryBudget(3)] function () {
    expect(QueryBudget::current())->toBe(3);
});

it('reads it off an arrow function too', #[QueryBudget(0)] fn () => expect(QueryBudget::current())->toBe(0));

it('reads it inside what the test calls', #[QueryBudget(7)] function () {
    $nested = static fn (): ?int => (static fn (): ?int => QueryBudget::current())();

    expect($nested())->toBe(7);
});

it('reads none off a test that declares none', function () {
    expect(QueryBudget::current())->toBeNull();
});

it('reads it with each row of a dataset', #[QueryBudget(2)] function (int $row) {
    expect(QueryBudget::current())->toBe(2);
})->with([1, 2]);

it('is a number of statements', function () {
    expect(fn () => new QueryBudget(-1))->toThrow(\InvalidArgumentException::class);
});
