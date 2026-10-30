<?php

declare(strict_types=1);

namespace Tinvar\Testing;

use Tinvar\Attributes\From;
use Tinvar\Attributes\MapsFrom;
use Tinvar\Data\Data;
use Tinvar\Data\DataShape;
use Tinvar\Orm\Metadata;
use Tinvar\Orm\Model;
use Tinvar\Orm\Orm;

/**
 * "assertNoModelLeak() scans the RSC payload for any model column not
 * declared on a Data object, so a leaked password hash fails CI."
 *
 * The encoder already refuses a model at the boundary, so a leak cannot be a
 * model crossing whole. It is a column's value copied into a Data field --
 * `new CustomerData(..., hash: $customer->passwordHash)` -- or into a string
 * a field carries. So the check is by value: every row the request read is
 * snapshotted before the reset clears it, and the value of each column no
 * Data object declares is looked for in what was sent.
 *
 * Every row read is the Orm's record of reads (Orm::recordReads()), which
 * the client turns on around a render, beside the identity map. The map
 * alone held too little: a row read across tenants or a subtree never
 * enters it, and leaving acrossTenants() empties it, so a staff page's
 * reads -- and everything a page read before one acrossTenants() call --
 * were never looked for.
 *
 * Declared means named by a Data field. A model some Data class says it
 * #[MapsFrom] is held to those classes' fields and #[From] sources; any other
 * model to the fields of every Data class of its application -- loaded, and
 * under the model's own top namespace, so a test fixture elsewhere in the
 * process cannot declare a column for it. That is lenient on purpose: a
 * column a Data field shows by name is one a page may well show, and this is
 * a guard against the column nobody meant to show.
 *
 * Only strings of MIN_LENGTH or more are looked for: a status, a count or a
 * flag would match page text by accident, and what this exists to catch --
 * a hash, a token, an internal note -- is long. A value some declared column
 * of a row the request read also holds is not looked for either: the page
 * may show it through that column.
 */
final class ModelLeaks
{
    public const int MIN_LENGTH = 8;

    /**
     * The undeclared column values of every row the request has read so far,
     * less any a declared column also holds.
     *
     * @return list<array{model: class-string, column: string, value: string}>
     */
    public static function snapshot(Orm $orm): array
    {
        $undeclared = [];
        $declared = [];
        $exposedBy = [];

        $read = [];
        foreach ([...$orm->identityMap()->all(), ...$orm->readsRecorded()] as $model) {
            $read[spl_object_id($model)] = $model;
        }

        foreach ($read as $model) {
            // A ghost a relation registered and nothing loaded holds no row;
            // reading its properties would load one.
            if (!$model instanceof Model || !Orm::holdsRow($model)) {
                continue;
            }

            $meta = Metadata::for($model::class);
            $exposed = $exposedBy[$model::class] ??= self::declaredFor($model::class);
            $values = $meta->columnValues($model);

            foreach ($meta->columns as $property => $column) {
                $value = $values[$column] ?? null;
                if (\is_string($value)) {
                    self::sort($model::class, $column, $property, $value, $exposed, $property === $meta->idProperty, $declared, $undeclared);
                }
            }
        }

        // A column read without a model -- pluck(), min(), max() -- is held
        // to the same fields as the model's own column.
        foreach ($orm->columnReadsRecorded() as $read) {
            $meta = Metadata::for($read['model']);
            $property = array_search($read['column'], $meta->columns, true);
            if (!\is_string($property)) {
                continue;
            }
            $exposed = $exposedBy[$read['model']] ??= self::declaredFor($read['model']);
            self::sort($read['model'], $read['column'], $property, $read['value'], $exposed, $property === $meta->idProperty, $declared, $undeclared);
        }

        return array_values(array_filter(
            $undeclared,
            static fn (array $leak): bool => !isset($declared[$leak['value']]),
        ));
    }

    /**
     * One column's value, declared or not -- and, for a value-object or
     * array column stored as JSON, each string inside it by its path, held
     * to its own declaration (ADR 0122). A Data field showing the property
     * whole declares all of it; a #[From('address.city')] declares that
     * field and what is below it, and leaves `address.street` undeclared.
     * The stored JSON whole is looked for too, as any column's value is.
     *
     * @param class-string $model
     * @param array<string, true> $exposed
     * @param array<string, true> $declared
     * @param list<array{model: class-string, column: string, value: string}> $undeclared
     */
    private static function sort(string $model, string $column, string $property, string $value, array $exposed, bool $key, array &$declared, array &$undeclared): void
    {
        $whole = $key || isset($exposed[$property]);
        if ($whole) {
            $declared[$value] = true;
        } elseif (\strlen($value) >= self::MIN_LENGTH) {
            $undeclared[] = ['model' => $model, 'column' => $column, 'value' => $value];
        }

        foreach (self::leaves($value) as $path => $leaf) {
            if ($whole || self::declaresPath($exposed, "{$property}.{$path}")) {
                $declared[$leaf] = true;
            } elseif (\strlen($leaf) >= self::MIN_LENGTH) {
                $undeclared[] = ['model' => $model, 'column' => "{$column}.{$path}", 'value' => $leaf];
            }
        }
    }

    /**
     * The strings inside a value stored as a JSON object or list, by their
     * dotted path; none for any other value. Followed as deep as json_decode
     * reads by default, and no deeper.
     *
     * @return array<string, string>
     */
    private static function leaves(string $value): array
    {
        $trimmed = ltrim($value);
        if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
            return [];
        }
        $decoded = json_decode($value, true);
        if (!\is_array($decoded)) {
            return [];
        }

        $leaves = [];
        $walk = static function (array $node, string $at) use (&$walk, &$leaves): void {
            foreach ($node as $key => $item) {
                $path = $at === '' ? (string) $key : "{$at}.{$key}";
                if (\is_array($item)) {
                    $walk($item, $path);
                } elseif (\is_string($item)) {
                    $leaves[$path] = $item;
                }
            }
        };
        $walk($decoded, '');

        return $leaves;
    }

    /**
     * Whether a #[From] path declares $path: the path itself, or a field
     * above it.
     *
     * @param array<string, true> $exposed
     */
    private static function declaresPath(array $exposed, string $path): bool
    {
        $parts = explode('.', $path);
        while ($parts !== []) {
            if (isset($exposed[implode('.', $parts)])) {
                return true;
            }
            array_pop($parts);
        }

        return false;
    }

    /**
     * Which of $snapshot's values appear in $sent, as the payload and the
     * HTML would spell them.
     *
     * @param list<array{model: class-string, column: string, value: string}> $snapshot
     * @return list<array{model: class-string, column: string, value: string}>
     */
    public static function find(array $snapshot, string $sent): array
    {
        $found = [];
        foreach ($snapshot as $leak) {
            foreach (self::spellings($leak['value']) as $spelling) {
                if ($spelling !== '' && str_contains($sent, $spelling)) {
                    $found[] = $leak;
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * The fields that declare a model's columns: those of the Data classes
     * mapping from it, or of its application's when none says it does.
     *
     * @param class-string $model
     * @return array<string, true>
     */
    private static function declaredFor(string $model): array
    {
        $mapping = [];
        $all = [];
        $root = strstr($model, '\\', true);
        foreach (get_declared_classes() as $class) {
            if (!is_subclass_of($class, Data::class)) {
                continue;
            }
            $ours = $root === false || str_starts_with($class, $root . '\\');

            $fields = self::fieldsOf($class);
            if ($ours) {
                $all += $fields;
            }
            foreach ((new \ReflectionClass($class))->getAttributes(MapsFrom::class) as $attribute) {
                if ($attribute->newInstance()->model === $model) {
                    $mapping += $fields;
                }
            }
        }

        return $mapping === [] ? $all : $mapping;
    }

    /**
     * A Data class's fields, by name and by the model property each #[From]
     * names.
     *
     * @param class-string $class
     * @return array<string, true>
     */
    private static function fieldsOf(string $class): array
    {
        $fields = [];
        foreach (DataShape::fieldsOf($class) as $property) {
            $fields[$property->getName()] = true;
            $from = $property->getAttributes(From::class)[0] ?? null;
            if ($from === null && $property->isPromoted()) {
                $parameter = new \ReflectionParameter([$class, '__construct'], $property->getName());
                $from = $parameter->getAttributes(From::class)[0] ?? null;
            }
            if ($from !== null) {
                // The whole path: a property, or a field inside a
                // value-object column, which declares that field and
                // what is below it, not the rest of the column. A path
                // through a relation names another model's column, which
                // is declared, or not, by that model's own fields.
                $fields[$from->newInstance()->property] = true;
            }
        }

        return $fields;
    }

    /** @return list<string> */
    private static function spellings(string $value): array
    {
        // As JavaScript writes it into a payload row, and as PHP writes JSON
        // -- the page metadata escapes every slash.
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $escaped = json_encode($value);

        return array_values(array_unique([
            $value,
            \is_string($json) ? substr($json, 1, -1) : '',
            \is_string($escaped) ? substr($escaped, 1, -1) : '',
            htmlspecialchars($value, ENT_QUOTES | ENT_HTML5),
            htmlspecialchars($value, ENT_QUOTES),
        ]));
    }
}
