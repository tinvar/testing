<?php

declare(strict_types=1);

/**
 * The search fake (ADR 0152): a Search over it indexes in memory, by model
 * and tenant, and a test asks what was indexed and searched for.
 */

namespace Tinvar\Tests\Testing\SearchFake;

use PHPUnit\Framework\AssertionFailedError;
use Tinvar\Attributes\Searchable;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Attributes\TenantScoped;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Orm\TenantSubtrees;
use Tinvar\Search\Search;
use Tinvar\Testing\Fakes\SearchFake;

#[Table('fake_articles'), Searchable(fields: ['title', 'body'], weights: ['title' => 2])]
final class Article extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $title;
    #[Column] public string $body = '';
}

#[Table('fake_cases'), TenantScoped, Searchable(fields: ['subject'])]
final class SupportCase extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public string $subject;
}

/** Tenants side by side. */
final readonly class Flat implements TenantSubtrees
{
    public function hierarchical(): bool
    {
        return false;
    }

    public function ancestors(string $tenant): array
    {
        return [];
    }

    public function subtree(string $root): array
    {
        return [$root];
    }
}

/** @return array{Orm, Search, SearchFake} */
function faked(bool $remote = false): array
{
    $connection = new PdoConnection(new \PDO('sqlite::memory:'));
    $connection->execute("CREATE TABLE fake_articles (id TEXT PRIMARY KEY, title TEXT NOT NULL, body TEXT NOT NULL DEFAULT '')");
    $connection->execute('CREATE TABLE fake_cases (id TEXT PRIMARY KEY, subject TEXT NOT NULL, tenant_id TEXT NOT NULL)');
    $orm = new Orm($connection, new IdentityMap('fake'), new Flat());
    $fake = new SearchFake($remote);

    return [$orm, new Search($orm, $fake, [Article::class, SupportCase::class])->observe(), $fake];
}

function article(Orm $orm, string $title, string $body = ''): Article
{
    $article = new Article();
    $article->title = $title;
    $article->body = $body;

    return $orm->save($article);
}

it('keeps what is indexed, ranks by the model\'s weights, and asserts on it', function () {
    [$orm, $search, $fake] = faked();
    $kettle = article($orm, 'Kettle', 'Boils water for the teapot.');
    $teapot = article($orm, 'Teapot');

    $fake->assertIndexed(Article::class, $kettle->id);
    expect(array_map(static fn (Article $a): string => $a->title, $search->for(Article::class)->query('teapot')->get()))->toBe(['Teapot', 'Kettle'])
        ->and(array_map(static fn (Article $a): string => $a->title, $search->for(Article::class)->query('tea')->get()))->toBe(['Teapot', 'Kettle']);
    $fake->assertSearched('teapot');

    $orm->delete($teapot);
    $fake->assertNotIndexed(Article::class, $teapot->id);
    expect(fn () => $fake->assertIndexed(Article::class, $teapot->id))->toThrow(AssertionFailedError::class);
});

it('holds each tenant apart, as the real backends do', function () {
    [$orm, $search, $fake] = faked();
    $acme = $orm->actAs('acme', function () use ($orm): SupportCase {
        $case = new SupportCase();
        $case->subject = 'Printer jammed';

        return $orm->save($case);
    });

    $fake->assertIndexed(SupportCase::class, $acme->id, 'acme');
    $fake->assertNotIndexed(SupportCase::class, $acme->id, 'globex');
    expect($orm->actAs('globex', fn (): array => $search->for(SupportCase::class)->query('printer')->get()))->toBe([])
        ->and($fake->searches()[0]['tenants'])->toBe(['globex']);
});

it('records nothing searched until something is', function () {
    [, , $fake] = faked();

    $fake->assertNothingSearched();
    expect($fake->remote())->toBeFalse()
        ->and(new SearchFake(remote: true)->remote())->toBeTrue();
});
