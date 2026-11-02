<?php

declare(strict_types=1);

namespace Tinvar\Testing\Fakes;

use PHPUnit\Framework\Assert;
use Tinvar\Search\SearchableModel;
use Tinvar\Search\SearchBackend;
use Tinvar\Search\SearchHits;
use Tinvar\Search\SearchTerms;

/**
 * The search fake (ADR 0153): hand it to Search as its backend, and every
 * document is kept in memory, by model and tenant, where a test asks what
 * is indexed and what was searched for.
 *
 *     $search = new Search($orm, $fake = new SearchFake(), [Product::class]);
 *     $search->observe();
 *     $product->save();
 *     $fake->assertIndexed(Product::class, $product->id);
 *
 * It matches as the real backends do -- every word, the last as a prefix,
 * ranked by the model's weights -- without stemming, and holds each tenant
 * apart as they do: a search reads the tenants Search gives it and no
 * other. Local unless told `remote: true`, which has Search queue its
 * writes as it does for Meilisearch, so a test can hold the queue to them.
 */
final class SearchFake implements SearchBackend
{
    /** @var array<string, array<string, array<string, list<string>>>> table => tenant => id => class texts */
    private array $documents = [];

    /** @var list<array{model: class-string, words: list<string>, tenants: list<string>|null}> */
    private array $searches = [];

    public function __construct(private readonly bool $remote = false) {}

    public function remote(): bool
    {
        return $this->remote;
    }

    public function upsert(SearchableModel $model, string $tenant, array $documents): void
    {
        foreach ($documents as $document) {
            // A key is one row, in one tenant, as the database's unique key holds it.
            foreach ($this->documents[$model->table] ?? [] as $other => $held) {
                if ($other !== $tenant && isset($held[$document->id])) {
                    throw new \LogicException("{$model->class} {$document->id} is indexed under another tenant already: a row never moves tenant.");
                }
            }
            $this->documents[$model->table][$tenant][$document->id] = $document->classes;
        }
    }

    public function remove(SearchableModel $model, ?array $tenants, array $ids): void
    {
        foreach ($tenants ?? array_map(strval(...), array_keys($this->documents[$model->table] ?? [])) as $tenant) {
            foreach ($ids as $id) {
                unset($this->documents[$model->table][$tenant][$id]);
            }
        }
    }

    public function search(SearchableModel $model, SearchTerms $terms, ?array $tenants, int $limit, int $offset): SearchHits
    {
        $this->searches[] = ['model' => $model->class, 'words' => $terms->words, 'tenants' => $tenants];
        if ($terms->isEmpty()) {
            return SearchHits::none();
        }

        $scores = [];
        foreach ($tenants ?? array_map(strval(...), array_keys($this->documents[$model->table] ?? [])) as $tenant) {
            foreach ($this->documents[$model->table][$tenant] ?? [] as $id => $classes) {
                $score = self::score($terms->words, $classes, $model->weights);
                if ($score > 0) {
                    $scores[(string) $id] = $score;
                }
            }
        }
        uksort($scores, static fn (string $x, string $y): int => [$scores[$y], $x] <=> [$scores[$x], $y]);

        return new SearchHits(\array_slice(array_keys($scores), $offset, $limit), \count($scores));
    }

    public function flush(SearchableModel $model, ?string $tenant): void
    {
        if ($tenant === null) {
            unset($this->documents[$model->table]);
        } else {
            unset($this->documents[$model->table][$tenant]);
        }
    }

    /**
     * The keys indexed for $model in $tenant, sorted.
     *
     * @param class-string $model
     * @return list<string>
     */
    public function indexed(string $model, string $tenant = ''): array
    {
        $ids = array_map(strval(...), array_keys($this->documents[SearchableModel::of($model)->table][$tenant] ?? []));
        sort($ids);

        return $ids;
    }

    /**
     * What was searched for, each as its words, the model and the tenants read.
     *
     * @return list<array{model: class-string, words: list<string>, tenants: list<string>|null}>
     */
    public function searches(): array
    {
        return $this->searches;
    }

    /** @param class-string $model */
    public function assertIndexed(string $model, string $id, string $tenant = ''): void
    {
        Assert::assertContains($id, $this->indexed($model, $tenant), "{$model} {$id} is not indexed" . ($tenant === '' ? '.' : " in tenant {$tenant}."));
    }

    /** @param class-string $model */
    public function assertNotIndexed(string $model, string $id, string $tenant = ''): void
    {
        Assert::assertNotContains($id, $this->indexed($model, $tenant), "{$model} {$id} is indexed" . ($tenant === '' ? '.' : " in tenant {$tenant}."));
    }

    /** Asserts a search was made for $query's words, as SearchTerms reads them. */
    public function assertSearched(string $query): void
    {
        $words = SearchTerms::parse($query)->words;
        Assert::assertContains($words, array_column($this->searches, 'words'), 'Nothing searched for "' . implode(' ', $words) . '".');
    }

    public function assertNothingSearched(): void
    {
        Assert::assertSame([], $this->searches, 'A search was made.');
    }

    /**
     * Each word found in a class adds that class's weight; a document
     * missing any word scores 0.
     *
     * @param list<string> $words
     * @param list<string> $classes
     * @param list<float> $weights
     */
    private static function score(array $words, array $classes, array $weights): float
    {
        $tokens = array_map(self::tokens(...), $classes);
        $last = \count($words) - 1;
        $score = 0.0;
        foreach ($words as $i => $word) {
            $found = 0.0;
            foreach ($tokens as $class => $inClass) {
                foreach ($inClass as $token) {
                    if ($token === $word || ($i === $last && str_starts_with($token, $word))) {
                        $found += $weights[$class] ?? 0.0;
                    }
                }
            }
            if ($found === 0.0) {
                return 0.0;
            }
            $score += $found;
        }

        return $score;
    }

    /** @return list<string> every word of $text, lowercased, repeats kept */
    private static function tokens(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text, 'UTF-8'), $matches);

        return $matches[0];
    }
}
