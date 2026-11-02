<?php

declare(strict_types=1);

namespace Tinvar\Testing;

use Tinvar\Generators\Names;
use Tinvar\Generators\ResourcePlan;
use Tinvar\Generators\Sources;

/**
 * The test `tinvar make:resource` writes beside a resource (ADR 0151),
 * through this package's functions -- get(), action(), query(),
 * actingAs() -- which the framework does not depend on: the app's
 * bin/tinvar hands this to its console where tinvar/testing is installed,
 * as it hands codegen's command, and without it no test is written.
 */
final class ResourceTestSource
{
    /**
     * The resource's tests, through the whole PHP request path -- routing,
     * the CSRF check, authorization, validation, loaders -- against a
     * database of each test's own, as the skeleton's own tests run.
     */
    public static function of(ResourcePlan $p): string
    {
        $ns = $p->appNamespace;
        $param = $p->parameter;
        $imports = [
            "{$ns}\\Database\\Migrations",
            "{$ns}\\Kernel",
            $p->model,
            $p->factory,
            $p->actions,
            $p->listQuery,
            $p->showQuery,
            $p->record,
            $p->list,
            'Tinvar\\Auth\\Authenticatable',
            'Tinvar\\Observability\\Logger',
            'Tinvar\\Observability\\MemorySink',
            'Tinvar\\Observability\\Telemetry',
            'Tinvar\\Testing\\Testing',
        ];
        $setup = [];
        $given = [];
        $changed = [];
        $factoryFor = '';
        foreach ($p->fields as $field) {
            [$value, $other] = match ($field->kind) {
                'string' => ["'A {$field->label()}'", "'Another {$field->label()}'"],
                'text' => ["'Some {$field->label()}.'", "'Other {$field->label()}.'"],
                'int' => ['7', '8'],
                'float' => ['1.5', '2.5'],
                'bool' => ['true', 'false'],
                'timestamp' => ["'2026-11-01T09:30'", "'2026-12-01T10:45'"],
                'enum' => ["'" . array_values($field->cases)[0] . "'", "'" . array_values($field->cases)[\count($field->cases) - 1] . "'"],
                'belongsTo' => ["\$this->{$field->name}->id", "\$this->{$field->name}->id"],
            };
            if ($field->kind === 'belongsTo') {
                $factory = (string) $p->relatedFactories[$field->name];
                $imports[] = $factory;
                $setup[] = "    \$this->{$field->name} = " . Names::short($factory) . '::new()->create($kernel->orm);';
                if (!$field->nullable) {
                    $factoryFor .= "->for(\$this->{$field->name})";
                }
            }
            $given[] = "{$field->name}: {$value}";
            $changed[] = "{$field->name}: {$other}";
        }
        $label = $p->labelField();
        $modelShort = Names::short($p->model);
        $factoryShort = Names::short($p->factory);
        $actionsShort = Names::short($p->actions);
        $imports = array_values(array_unique($imports));
        $uses = array_map(static fn (string $class): string => "use {$class};", $imports);
        usort($uses, strcasecmp(...));
        $make = "{$factoryShort}::new(){$factoryFor}";

        $invalid = null;
        foreach ($p->fields as $field) {
            if (!$field->nullable && \in_array($field->kind, ['string', 'text', 'int', 'float', 'timestamp'], true)) {
                $invalid = $field;
                break;
            }
        }
        $invalidValue = $invalid === null ? null : (\in_array($invalid->kind, ['int', 'float'], true) ? "'many'" : ($invalid->kind === 'timestamp' ? "'2026-02-31T09:30'" : "''"));
        $invalidInput = $invalid === null ? [] : array_map(
            static fn (string $pair): string => str_starts_with($pair, "{$invalid->name}: ") ? "{$invalid->name}: {$invalidValue}" : $pair,
            $given,
        );

        $check = $label === null
            ? "\$this->orm->find({$modelShort}::class, \${$param}->id)?->updatedAt !== null"
            : "\$this->orm->find({$modelShort}::class, \${$param}->id)?->{$label->name} === 'Another {$label->label()}'";

        return "<?php\n\ndeclare(strict_types=1);\n\n"
            . "/**\n"
            . Sources::wrap("{$p->short} pages and actions, written by `tinvar make:resource`, through the whole PHP request path -- routing, the CSRF check, authorization, validation, loaders -- against a database of each test's own, without the renderer: get() records what PHP would have sent it.")
            . " *\n"
            . Sources::wrap("Signed in as someone the policy lets in, as it was written: narrow the policy, and say here who it lets in.")
            . " */\n\n"
            . 'namespace ' . self::namespace($p) . ";\n\n"
            . implode("\n", $uses) . "\n\n"
            . "use function Tinvar\\Testing\\action;\n"
            . "use function Tinvar\\Testing\\actingAs;\n"
            . "use function Tinvar\\Testing\\get;\n\n"
            . "beforeEach(function () {\n"
            . "    \$kernel = Kernel::fromEnvironment(database: ':memory:', telemetry: new Telemetry(new Logger(new MemorySink())));\n"
            . "    Migrations::migrate(\$kernel->orm->connection());\n"
            . "    Testing::use(\$kernel);\n"
            . "    \$this->orm = \$kernel->orm;\n"
            . "    \$this->user = new class implements Authenticatable {\n"
            . "        public function authIdentifier(): string\n"
            . "        {\n"
            . "            return 'someone';\n"
            . "        }\n"
            . "    };\n"
            . (\count($setup) === 0 ? '' : implode("\n", $setup) . "\n")
            . "});\n\n"
            . "afterEach(function () {\n"
            . "    Testing::forget();\n"
            . "});\n\n"
            . "it('refuses a guest every page and every action', function () {\n"
            . "    get('{$p->basePath}')->assertForbidden();\n"
            . "    get('{$p->basePath}/new')->assertForbidden();\n"
            . "    action([{$actionsShort}::class, 'create'], " . implode(', ', $given) . ")->assertUnauthenticated();\n"
            . "});\n\n"
            . "it('lists them, newest first, a page at a time', function () {\n"
            . "    {$make}->count(2)->create(\$this->orm);\n\n"
            . "    actingAs(\$this->user)->query(" . Names::short($p->listQuery) . "::class, page: 1)\n"
            . "        ->assertData(fn (" . Names::short($p->list) . " \$page): bool => \$page->total === 2 && \\count(\$page->items) === 2);\n"
            . "});\n\n"
            . "it('shows one, and answers an id there is no row for with a 404', function () {\n"
            . "    \${$param} = {$make}->create(\$this->orm);\n\n"
            . "    actingAs(\$this->user)->query(" . Names::short($p->showQuery) . "::class, {$param}: \${$param}->id)\n"
            . "        ->assertData(fn (" . Names::short($p->record) . " \$shown): bool => \$shown->id === \${$param}->id);\n"
            . "    actingAs(\$this->user)->query(" . Names::short($p->showQuery) . "::class, {$param}: '0190e5a0-0000-7000-8000-000000000000')->assertNotFound();\n"
            . "});\n\n"
            . "it('makes one, and goes to its page', function () {\n"
            . "    actingAs(\$this->user)->action([{$actionsShort}::class, 'create'], " . implode(', ', $given) . ")->assertRedirect();\n\n"
            . "    expect({$modelShort}::query(\$this->orm)->count())->toBe(1);\n"
            . "});\n\n"
            . ($invalid === null ? '' : "it('refuses what the form request does not take, and saves nothing', function () {\n"
                . "    actingAs(\$this->user)->action([{$actionsShort}::class, 'create'], " . implode(', ', $invalidInput) . ")->assertInvalid(['{$invalid->name}']);\n\n"
                . "    expect({$modelShort}::query(\$this->orm)->count())->toBe(0);\n"
                . "});\n\n")
            . "it('changes one', function () {\n"
            . "    \${$param} = {$make}->create(\$this->orm);\n\n"
            . "    actingAs(\$this->user)->action([{$actionsShort}::class, 'update'], {$param}: \${$param}->id, " . implode(', ', $changed) . ")\n"
            . "        ->assertRedirect('{$p->basePath}/' . \${$param}->id);\n\n"
            . "    expect({$check})->toBeTrue();\n"
            . "});\n\n"
            . "it('deletes one, and goes back to the list', function () {\n"
            . "    \${$param} = {$make}->create(\$this->orm);\n\n"
            . "    actingAs(\$this->user)->action([{$actionsShort}::class, 'destroy'], {$param}: \${$param}->id)->assertRedirect('{$p->basePath}');\n\n"
            . "    expect({$modelShort}::query(\$this->orm)->count())->toBe(0);\n"
            . "});\n";
    }

    /** The namespace the test is declared in: Tests\Feature, then the resource's domain. */
    private static function namespace(ResourcePlan $p): string
    {
        return Sources::namespaceOf((string) $p->test);
    }
}
