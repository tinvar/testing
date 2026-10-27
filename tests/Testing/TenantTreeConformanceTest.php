<?php

declare(strict_types=1);

/**
 * The tenant tree conformance kit, run against a port with no limits of its
 * own -- the Orm holds the limits whatever the port does -- and against
 * ports that break the contract, which it has to catch.
 */

namespace Tinvar\Tests\Testing\TenantTreeConformance;

use PHPUnit\Framework\AssertionFailedError;
use Tinvar\Orm\TenantSubtrees;
use Tinvar\Testing\TenantTreeConformance;
use Tinvar\Tests\Orm\Fixtures\Tenancy;

require_once __DIR__ . '/../../../database/tests/Orm/Fixtures/tenancy.php';

/** Answers its subtrees from a record other than its parents: a parent reads a tenant that is not its child. */
final readonly class TwoRecords implements TenantSubtrees
{
    /** @param array<string, string> $parents */
    public function __construct(private array $parents) {}

    public function hierarchical(): bool
    {
        return true;
    }

    public function ancestors(string $tenant): array
    {
        return Tenancy::tree($this->parents)->ancestors($tenant);
    }

    public function subtree(string $root): array
    {
        return $root === 'acme' ? ['acme', 'acme-west', 'globex'] : Tenancy::tree($this->parents)->subtree($root);
    }
}

/** Names a tenant no record holds. */
final readonly class Inventive implements TenantSubtrees
{
    /** @param array<string, string> $parents */
    public function __construct(private array $parents) {}

    public function hierarchical(): bool
    {
        return true;
    }

    public function ancestors(string $tenant): array
    {
        return [...Tenancy::tree($this->parents)->ancestors($tenant), 'platform'];
    }

    public function subtree(string $root): array
    {
        return Tenancy::tree($this->parents)->subtree($root);
    }
}

// The suite's fixture walks its parent links without the walk's limits:
// the Orm's own checks hold them for it.
it('passes a port that is the truth, limits or none', function (\Closure $case) {
    $case(static fn (array $parents): TenantSubtrees => Tenancy::tree($parents));
})->with(TenantTreeConformance::cases());

it('catches a port answering its subtrees from another record', function () {
    $case = TenantTreeConformance::cases()['answers both from one record'][0];

    expect(fn () => $case(static fn (array $parents): TenantSubtrees => new TwoRecords($parents)))
        ->toThrow(AssertionFailedError::class, "disagree on whether 'globex' is under 'acme'");
});

it('catches a port naming a tenant the tree does not hold', function () {
    $case = TenantTreeConformance::cases()['names no tenant the tree does not hold'][0];

    expect(fn () => $case(static fn (array $parents): TenantSubtrees => new Inventive($parents)))->toThrow(AssertionFailedError::class);
});

it('catches a port with no hierarchy', function () {
    $case = TenantTreeConformance::cases()['is a hierarchy'][0];

    expect(fn () => $case(static fn (): TenantSubtrees => Tenancy::flat()))->toThrow(AssertionFailedError::class, 'hierarchical() answered false');
});
