<?php

declare(strict_types=1);

namespace Tinvar\Testing\Dev;

use Tinvar\Actions\CompiledAction;
use Tinvar\Attributes\Authorize;
use Tinvar\Attributes\Panel;
use Tinvar\Config\Configs;
use Tinvar\Http\Kernel;
use Tinvar\Orm\Metadata;
use Tinvar\Orm\Model;
use Tinvar\Routing\CompiledRoute;

/**
 * What the MCP server tells an assistant about an application: its pages,
 * queries, actions, models and settings, read from the registries the Kernel
 * built and the models' metadata -- the declarations, never a row, a value
 * or a secret (ADR 0088).
 *
 * Each list is what codegen and the build rules already read, in a shape
 * made to be read: a class and its file, what it takes, what it returns,
 * the #[Authorize] abilities it asks, its panels and its middleware.
 */
final readonly class Introspection
{
    /**
     * @param list<class-string<Model>> $models the application's models
     * @param list<class-string> $configs the application's #[Config] classes
     * @param array<string, string>|null $env in place of the process's, for config(): a test's
     */
    public function __construct(
        private Kernel $kernel,
        private array $models = [],
        private array $configs = [],
        private ?array $env = null,
    ) {}

    /**
     * Every page and layout, in the order the router tries them.
     *
     * @return array{pages: list<array<string, mixed>>, layouts: list<array<string, mixed>>}
     */
    public function routes(): array
    {
        $describe = fn (CompiledRoute $route): array => [
            'path' => $route->template,
            'name' => $route->name,
            'view' => $route->view,
            'class' => $route->class,
            'site' => self::site($route->class, $route->loader),
            'parameters' => $route->parameters,
            'returns' => self::returns($route->class, $route->loader),
            'authorize' => self::abilities($route->checks),
            'panels' => self::panels($route->panels),
            'middleware' => $route->middleware,
            ...($route->layoutKey === '' ? [] : ['layout' => $route->layoutKey]),
        ];

        return [
            'pages' => array_map($describe, $this->kernel->routes->pages()),
            'layouts' => array_map($describe, $this->kernel->routes->layouts()),
        ];
    }

    /** @return list<array<string, mixed>> every query, by name */
    public function queries(): array
    {
        $registry = $this->kernel->queries;
        $out = [];
        foreach ($registry->names() as $name) {
            $class = (string) $registry->classFor($name);
            $batch = $registry->isBatch($name);
            $declaration = $registry->declarationFor($name);
            $out[] = [
                'name' => $name,
                'class' => $class,
                'site' => self::site($class, $batch ? null : '__invoke'),
                'arguments' => self::arguments($class, $batch ? null : '__invoke', $registry->parametersFor($name)),
                'returns' => $batch ? null : self::returns($class, '__invoke'),
                'batch' => $batch,
                'cache' => $declaration->cache,
                'authorize' => self::abilities($registry->checksFor($name)),
                'panels' => self::panels($registry->panelsFor($name)),
                'middleware' => $registry->middlewareFor($name),
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $out;
    }

    /** @return list<array<string, mixed>> every action, by name; never run */
    public function actions(): array
    {
        $out = array_map(static fn (CompiledAction $action): array => [
            'name' => $action->name,
            'class' => $action->class,
            'method' => $action->method,
            'site' => self::site($action->class, $action->method),
            'models' => $action->models,
            'formRequests' => $action->formRequests,
            'uploads' => array_keys($action->uploads),
            'returns' => self::returns($action->class, $action->method),
            'authorize' => self::abilities($action->checks),
            'panels' => self::panels($action->panels),
            'middleware' => $action->middleware,
        ], $this->kernel->actions->all());
        usort($out, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * The models as their metadata declares them: table, key, columns,
     * relations, the tenant column and each unique key. Read from the
     * classes, so it says what the code expects of the database, without
     * opening it.
     *
     * @return list<array<string, mixed>>
     */
    public function schema(): array
    {
        $out = [];
        foreach ($this->models as $class) {
            $meta = Metadata::for($class);
            $relations = [];
            foreach ($meta->relations as $property => $relation) {
                $relations[$property] = [
                    'kind' => strtolower($relation->kind->name),
                    'target' => $relation->target,
                    'foreignKey' => $relation->foreignKey,
                ];
            }
            $out[] = [
                'model' => $class,
                'table' => $meta->table,
                'key' => $meta->idColumn,
                'columns' => self::columnTypes($class, $meta->columns),
                'relations' => $relations,
                'tenantColumn' => $meta->tenantColumn,
                'unique' => $meta->uniqueKeys,
            ];
        }

        return $out;
    }

    /**
     * Each setting the application's #[Config] classes read: its variable,
     * type and where it is set from -- never its value. A value would go to
     * whoever the assistant sends its context to, and a setting that should
     * have been a Secret is not one the type system knows to keep back.
     *
     * @return list<array{variable: string, type: string, required: bool, secret: bool, source: string}>
     */
    public function config(): array
    {
        $out = [];
        foreach ($this->configs as $class) {
            try {
                $inspected = Configs::inspect($class, $this->env);
            } catch (\LogicException) {
                continue;
            }
            foreach ($inspected['keys'] as $row) {
                $out[] = [
                    'variable' => $row['key']->variable,
                    'type' => $row['key']->describe(),
                    'required' => $row['key']->required,
                    'secret' => $row['key']->secret(),
                    'source' => $row['source'],
                ];
            }
        }

        return $out;
    }

    /**
     * @param class-string<Model> $class
     * @param array<string, string> $columns property => column
     * @return array<string, array{property: string, type: string}> column => its property and PHP type
     */
    private static function columnTypes(string $class, array $columns): array
    {
        $reflection = new \ReflectionClass($class);
        $out = [];
        foreach ($columns as $property => $column) {
            $type = $reflection->hasProperty($property) ? $reflection->getProperty($property)->getType() : null;
            $out[$column] = ['property' => $property, 'type' => $type === null ? 'mixed' : (string) $type];
        }

        return $out;
    }

    /**
     * @param list<Authorize> $checks
     * @return list<string>
     */
    private static function abilities(array $checks): array
    {
        return array_map(
            static fn (Authorize $check): string => $check->argument === null ? $check->ability : "{$check->ability}({$check->argument})",
            $checks,
        );
    }

    /**
     * @param list<Panel> $panels
     * @return list<string>
     */
    private static function panels(array $panels): array
    {
        return array_map(static fn (Panel $panel): string => $panel->name, $panels);
    }

    /** `file:line` of the method, or of the class without one; '' where neither is a file. */
    private static function site(string $class, ?string $method): string
    {
        if (!class_exists($class)) {
            return '';
        }
        $reflection = new \ReflectionClass($class);
        $where = $method !== null && $reflection->hasMethod($method) ? $reflection->getMethod($method) : $reflection;
        $file = $where->getFileName();

        return $file === false ? '' : "{$file}:{$where->getStartLine()}";
    }

    private static function returns(string $class, string $method): ?string
    {
        if (!method_exists($class, $method)) {
            return null;
        }
        $type = new \ReflectionMethod($class, $method)->getReturnType();

        return $type === null ? null : (string) $type;
    }

    /**
     * @param array<string, bool> $parameters name => required, as the registry checks them
     * @return array<string, array{type: string, required: bool}>
     */
    private static function arguments(string $class, ?string $method, array $parameters): array
    {
        $types = [];
        if ($method !== null && method_exists($class, $method)) {
            foreach (new \ReflectionMethod($class, $method)->getParameters() as $parameter) {
                $type = $parameter->getType();
                $types[$parameter->getName()] = $type === null ? 'mixed' : (string) $type;
            }
        }

        $out = [];
        foreach ($parameters as $name => $required) {
            $out[$name] = ['type' => $types[$name] ?? 'mixed', 'required' => $required];
        }

        return $out;
    }
}
