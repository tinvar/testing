<?php

declare(strict_types=1);

namespace Tinvar\Testing;

use PHPUnit\Framework\Assert;
use Tinvar\Orm\TenantBounds;
use Tinvar\Orm\TenantSubtrees;

/**
 * A conformance kit for a tenant tree: any implementation of the Orm's
 * TenantSubtrees -- tinvar/tenancy's TenantHierarchy over an application's
 * own tree, or a port an application writes itself -- run against trees of
 * known shapes (ADR 0056).
 *
 *     it('is a tenant tree', function (\Closure $case) {
 *         $case(static fn (array $parents): TenantSubtrees => new TenantHierarchy(new Tree($parents)));
 *     })->with(TenantTreeConformance::cases());
 *
 * Each case hands the builder a tree as parent links -- child => parent --
 * and the builder answers with the implementation over that tree, saving
 * it wherever the implementation reads from first.
 *
 * The Orm holds every answer to the walk's limits itself (TenantBounds): 32
 * levels above a tenant, a stop at a loop, 1,000 tenants to a subtree. The
 * cases that show those hold with this implementation behind the Orm are
 * here too. What the Orm cannot check is the port's contract, and the rest
 * of the cases are that:
 *
 * - ancestors() names a tenant's parent first and its root last, and none
 *   for a root or a tenant there is no record of;
 * - subtree() names the root and every tenant below it, and no other;
 * - the two answer from one record: a tenant is in a root's subtree exactly
 *   when the root is the tenant or among its ancestors;
 * - neither names a tenant the tree does not hold;
 * - both return on a tree that loops (a case that never returns is a
 *   failure only a timeout can report: run the kit with one).
 *
 * Each case fails as a PHPUnit assertion, so it reads as any test's would.
 */
final class TenantTreeConformance
{
    /**
     * reseller over acme and globex, acme over acme-west, and other beside
     * them all: what most cases read.
     *
     * @var array<string, string>
     */
    public const array TREE = [
        'acme' => 'reseller',
        'globex' => 'reseller',
        'acme-west' => 'acme',
        'acme-west-1' => 'acme-west',
        'other-child' => 'other',
    ];

    /**
     * The cases, by name, for Pest's with(): each takes the builder.
     *
     * @return array<string, array{\Closure(\Closure(array<string, string>): TenantSubtrees): void}>
     */
    public static function cases(): array
    {
        return [
            'is a hierarchy' => [static function (\Closure $build): void {
                Assert::assertTrue(self::build($build, self::TREE)->hierarchical(), 'A tree is a hierarchy: hierarchical() answered false.');
            }],
            'names a tenant\'s ancestors, parent first and root last' => [static function (\Closure $build): void {
                $tree = self::build($build, self::TREE);

                Assert::assertSame(['acme-west', 'acme', 'reseller'], $tree->ancestors('acme-west-1'));
                Assert::assertSame(['reseller'], $tree->ancestors('globex'));
                Assert::assertSame(['other'], $tree->ancestors('other-child'));
            }],
            'names none above a root, or above a tenant it has no record of' => [static function (\Closure $build): void {
                $tree = self::build($build, self::TREE);

                Assert::assertSame([], $tree->ancestors('reseller'));
                Assert::assertSame([], $tree->ancestors('other'));
                Assert::assertSame([], $tree->ancestors('nobody'));
            }],
            'names a subtree as its root and every tenant below it, and no other' => [static function (\Closure $build): void {
                $tree = self::build($build, self::TREE);

                Assert::assertSame(['acme', 'acme-west', 'acme-west-1', 'globex', 'reseller'], self::sorted($tree->subtree('reseller')));
                Assert::assertSame(['acme', 'acme-west', 'acme-west-1'], self::sorted($tree->subtree('acme')));
                Assert::assertSame(['globex'], self::sorted($tree->subtree('globex')));
                Assert::assertSame(['other', 'other-child'], self::sorted($tree->subtree('other')));
            }],
            'gives a tenant it has no record of a subtree of itself alone' => [static function (\Closure $build): void {
                Assert::assertSame([], array_values(array_diff(self::build($build, self::TREE)->subtree('nobody'), ['nobody'])));
            }],
            'answers both from one record' => [static function (\Closure $build): void {
                $tree = self::build($build, self::TREE);
                $tenants = self::tenantsOf(self::TREE);

                foreach ($tenants as $root) {
                    $subtree = $tree->subtree($root);
                    foreach ($tenants as $tenant) {
                        Assert::assertSame(
                            $tenant === $root || \in_array($root, $tree->ancestors($tenant), true),
                            \in_array($tenant, $subtree, true),
                            "subtree('{$root}') and ancestors('{$tenant}') disagree on whether '{$tenant}' is under '{$root}'.",
                        );
                    }
                }
            }],
            'names no tenant the tree does not hold' => [static function (\Closure $build): void {
                $tree = self::build($build, self::TREE);
                $tenants = self::tenantsOf(self::TREE);

                foreach ($tenants as $tenant) {
                    Assert::assertSame([], array_values(array_diff([...$tree->ancestors($tenant), ...$tree->subtree($tenant)], $tenants)));
                }
            }],
            'returns on a tree that loops, and the Orm places no one under the loop' => [static function (\Closure $build): void {
                $tree = self::build($build, ['a' => 'b', 'b' => 'c', 'c' => 'a', 'd' => 'a']);
                $bounds = new TenantBounds($tree);
                $tree->subtree('a');
                $tree->ancestors('d');

                Assert::assertFalse($bounds->contains('root', 'd'));
                Assert::assertLessThanOrEqual(3, \count($bounds->ancestors('d')));
            }],
            'is held by the Orm to 32 levels above a tenant' => [static function (\Closure $build): void {
                $chain = [];
                for ($i = 0; $i < 40; $i++) {
                    $chain["level-{$i}"] = 'level-' . ($i + 1);
                }
                $bounds = new TenantBounds(self::build($build, $chain));

                Assert::assertTrue($bounds->contains('level-' . TenantBounds::MAX_DEPTH, 'level-0'));
                Assert::assertFalse($bounds->contains('level-' . (TenantBounds::MAX_DEPTH + 1), 'level-0'));
                Assert::assertCount(TenantBounds::MAX_DEPTH, $bounds->ancestors('level-0'));
            }],
            'is refused by the Orm past 1,000 tenants to a subtree, rather than read in part' => [static function (\Closure $build): void {
                $wide = [];
                for ($i = 1; $i <= TenantBounds::MAX_SUBTREE; $i++) {
                    $wide["t{$i}"] = 'root';
                }
                $bounds = new TenantBounds(self::build($build, $wide));

                Assert::assertSame(['t1'], $bounds->subtree('t1'));
                try {
                    $bounds->subtree('root');
                } catch (\LengthException) {
                    return;
                }
                Assert::fail('A subtree of 1,001 tenants was read rather than refused.');
            }],
        ];
    }

    /**
     * @param \Closure(array<string, string>): TenantSubtrees $build
     * @param array<string, string> $parents
     */
    private static function build(\Closure $build, array $parents): TenantSubtrees
    {
        return $build($parents);
    }

    /**
     * Every tenant the tree names, child or parent.
     *
     * @param array<string, string> $parents
     * @return list<string>
     */
    private static function tenantsOf(array $parents): array
    {
        return array_values(array_unique([...array_map('strval', array_keys($parents)), ...array_values($parents)]));
    }

    /**
     * @param list<string> $tenants
     * @return list<string>
     */
    private static function sorted(array $tenants): array
    {
        sort($tenants, SORT_STRING);

        return $tenants;
    }
}
