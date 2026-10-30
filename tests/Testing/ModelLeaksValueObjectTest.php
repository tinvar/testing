<?php

declare(strict_types=1);

/**
 * The model-leak check inside a value-object column (ADR 0122): stored as
 * JSON, its fields are looked for one by one, each held to what declares
 * it -- a Data field showing the property whole, or a #[From] path to the
 * field -- so a page showing `profile.city` and also, by mistake,
 * `profile.recoveryCode` is caught, where the stored JSON whole never
 * appears in a page to be found.
 */

namespace ModelLeaksDeepFixture;

use Tinvar\Attributes\From;
use Tinvar\Attributes\MapsFrom;
use Tinvar\Data\Data;
use Tinvar\Orm\Attributes\Column;
use Tinvar\Orm\Attributes\Id;
use Tinvar\Orm\Attributes\Table;
use Tinvar\Orm\Cast;
use Tinvar\Orm\IdentityMap;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;
use Tinvar\Orm\PdoConnection;
use Tinvar\Support\Uuid;
use Tinvar\Testing\ModelLeaks;

/** @implements Cast<Profile> */
final readonly class Profile implements Cast
{
    /** @param list<string> $tags */
    public function __construct(public string $city, public string $recoveryCode, public array $tags = []) {}

    public static function columnType(): string
    {
        return 'json';
    }

    public static function fromColumn(mixed $stored): self
    {
        \assert(\is_array($stored) && \is_string($stored['city']) && \is_string($stored['recoveryCode']) && \is_array($stored['tags']));

        return new self($stored['city'], $stored['recoveryCode'], array_values(array_filter($stored['tags'], \is_string(...))));
    }

    /** @param self $value */
    public static function toColumn(object $value): array
    {
        return ['city' => $value->city, 'recoveryCode' => $value->recoveryCode, 'tags' => $value->tags];
    }
}

#[Table('leak_members')]
final class Member extends Model
{
    #[Id] public private(set) string $id;
    #[Column] public Profile $profile;
}

/** Shows the city of a member's profile, by its path, and nothing else of it. */
#[MapsFrom(Member::class)]
final readonly class MemberCityData extends Data
{
    public function __construct(#[From('profile.city')] public string $city) {}
}

const CITY = 'Ballarat East';
const CODE = 'recovery-7f3a91c2';
const TAG = 'wholesale-account';

/** @return list<array{model: class-string, column: string, value: string}> */
function snapshot(): array
{
    $pdo = new \PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE leak_members (id TEXT PRIMARY KEY, profile TEXT NOT NULL)');
    $pdo->prepare('INSERT INTO leak_members VALUES (?, ?)')->execute([
        $id = Uuid::v7(),
        json_encode(['city' => CITY, 'recoveryCode' => CODE, 'tags' => [TAG]], JSON_THROW_ON_ERROR),
    ]);
    $orm = new Orm(new PdoConnection($pdo), new IdentityMap('test'));
    $orm->recordReads(true);
    $orm->find(Member::class, $id);

    return ModelLeaks::snapshot($orm);
}

it('looks for each field inside a value-object column, by its path, and not for one a #[From] path declares', function () {
    $looked = array_column(snapshot(), 'value', 'column');

    expect($looked)->toHaveKey('profile.recoveryCode')
        ->and($looked['profile.recoveryCode'])->toBe(CODE)
        ->and($looked['profile.tags.0'])->toBe(TAG)
        ->and($looked)->not->toHaveKey('profile.city')
        ->and(\in_array(CITY, $looked, true))->toBeFalse();
});

it('finds a field copied into a page, and passes the page showing the declared one', function () {
    $snapshot = snapshot();

    expect(ModelLeaks::find($snapshot, '<p>' . CITY . '</p>'))->toBe([])
        ->and(array_column(ModelLeaks::find($snapshot, '<p>' . CITY . '</p><code>' . CODE . '</code>'), 'column'))->toBe(['profile.recoveryCode']);
});
