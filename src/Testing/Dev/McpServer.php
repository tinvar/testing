<?php

declare(strict_types=1);

namespace Tinvar\Testing\Dev;

use Tinvar\Observability\Redactor;

/**
 * `tinvar mcp`: a Model Context Protocol server over standard input and
 * output, telling an assistant about the application it runs in -- its
 * pages, queries, actions, models and settings, the end of its log, and a
 * page rendered and dumped (ADR 0088).
 *
 * The spec's "MCP server", one of the development tools the build profiles
 * strip. Stdio, never a socket: the only party that can speak to it is the
 * process that started it -- the assistant's client, on this machine -- so
 * there is no port to bind to loopback and no run's token to check; the
 * pipe is both.
 *
 * What it can do is read, and only what is listed:
 *
 *   - routes, queries, actions, schema and config describe declarations
 *     (Introspection): no row, no value of a setting, no secret;
 *   - logs reads the end of the log through the Redactor again (LogTail);
 *   - render_dump renders one page as nobody or as a scenario's user, on
 *     the application the caller built for it -- the shop's on a copy of
 *     its database -- through the real renderer, which holds no secret,
 *     redacted as `tinvar render --dump` redacts it (RenderDump).
 *
 * There is no tool that runs SQL, PHP, an action or a command, and none
 * that reads a file by name or the environment: an assistant's context goes
 * to its provider, and a prompt it reads -- in a log line, a product's name
 * -- may ask it to call anything it has. So it has nothing that writes, and
 * nothing that reads beyond the application's own declarations, its log
 * and its pages.
 *
 * JSON-RPC 2.0, one message a line, as MCP's stdio transport has it. Every
 * message and every result is bounded.
 */
final class McpServer
{
    public const string NAME = 'tinvar';

    /** The protocol revisions it speaks, newest first. */
    public const array VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    /** The longest message read: anything longer is refused whole. */
    public const int MAX_MESSAGE = 1024 * 1024;

    /** The most of a page's HTML, or of its payload, one result carries. */
    public const int MAX_TEXT = 256 * 1024;

    private const int PARSE_ERROR = -32700;
    private const int INVALID_REQUEST = -32600;
    private const int METHOD_NOT_FOUND = -32601;
    private const int INVALID_PARAMS = -32602;

    /** MCP's code for a resource it does not have. */
    private const int RESOURCE_NOT_FOUND = -32002;

    /** The scheme every resource is under: nothing here reads a file or a URL by name. */
    public const string SCHEME = 'tinvar://';

    /** How many of the log's last lines tinvar://logs reads. */
    private const int RESOURCE_LOG_LINES = 50;

    private bool $initialized = false;

    private readonly Redactor $redactor;

    /**
     * @param (\Closure(string, string|null): array<string, mixed>)|null $dump renders a
     *        path as nobody, or as a scenario's user (RenderDump::dump()); null where
     *        nothing can be rendered, and the tool says so
     */
    public function __construct(
        private readonly Introspection $introspection,
        private readonly ?LogTail $logs = null,
        private readonly ?\Closure $dump = null,
        private readonly string $version = '0.4',
    ) {
        $this->redactor = new Redactor(traces: false);
    }

    /**
     * Answers every message read from $in on $out until $in ends.
     *
     * @param resource $in
     * @param resource $out
     */
    public function serve($in, $out): int
    {
        while (!feof($in)) {
            $line = fgets($in, self::MAX_MESSAGE + 2);
            if ($line === false) {
                break;
            }
            if (!str_ends_with($line, "\n") && !feof($in)) {
                // Longer than any message it takes: the rest of it is read
                // and dropped, and the sender told.
                while (!feof($in) && !str_ends_with((string) fgets($in, 65536), "\n")) {
                    // Discarding.
                }
                self::write($out, self::error(null, self::INVALID_REQUEST, 'The message is larger than 1 MiB.'));

                continue;
            }

            $reply = $this->handle($line);
            if ($reply !== null) {
                self::write($out, $reply);
            }
        }

        return 0;
    }

    /**
     * One message in, its reply out; null for a notification, or a reply
     * the client sent.
     *
     * @return array<string, mixed>|null
     */
    public function handle(string $line): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }
        if (\strlen($line) > self::MAX_MESSAGE) {
            return self::error(null, self::INVALID_REQUEST, 'The message is larger than 1 MiB.');
        }

        try {
            $message = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::error(null, self::PARSE_ERROR, 'Not JSON.');
        }
        if (!\is_array($message) || array_is_list($message) || ($message['jsonrpc'] ?? null) !== '2.0') {
            return self::error(null, self::INVALID_REQUEST, 'Not a JSON-RPC 2.0 message: one object, no batches.');
        }

        $id = $message['id'] ?? null;
        $hasId = \array_key_exists('id', $message);
        if ($hasId && !\is_string($id) && !\is_int($id)) {
            return self::error(null, self::INVALID_REQUEST, 'An id is a string or an integer.');
        }
        $method = $message['method'] ?? null;
        if (!\is_string($method)) {
            // A response to a request of ours: none is ever sent.
            return null;
        }
        $params = $message['params'] ?? [];
        if (!\is_array($params)) {
            return $hasId ? self::error($id, self::INVALID_PARAMS, 'params is an object.') : null;
        }

        if (!$hasId) {
            if ($method === 'notifications/initialized') {
                $this->initialized = true;
            }

            return null;
        }

        try {
            $result = match ($method) {
                'initialize' => $this->initialize($params),
                'ping' => new \stdClass(),
                'tools/list' => $this->ready(['tools' => self::tools()]),
                'tools/call' => $this->ready($this->call($params)),
                'resources/list' => $this->ready(['resources' => self::resources()]),
                'resources/templates/list' => $this->ready(['resourceTemplates' => self::resourceTemplates()]),
                'resources/read' => $this->ready($this->read($params)),
                'prompts/list' => $this->ready(['prompts' => self::prompts()]),
                'prompts/get' => $this->ready($this->prompt($params)),
                default => null,
            };
        } catch (\OutOfBoundsException $e) {
            return self::error($id, self::RESOURCE_NOT_FOUND, $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return self::error($id, self::INVALID_PARAMS, $e->getMessage());
        } catch (\LogicException $e) {
            return self::error($id, self::INVALID_REQUEST, $e->getMessage());
        }

        return $result === null
            ? self::error($id, self::METHOD_NOT_FOUND, "No method '" . substr($method, 0, 100) . "'.")
            : ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @param array<array-key, mixed> $params
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $asked = $params['protocolVersion'] ?? null;
        $this->initialized = true;

        return [
            'protocolVersion' => \is_string($asked) && \in_array($asked, self::VERSIONS, true) ? $asked : self::VERSIONS[0],
            'capabilities' => [
                'tools' => ['listChanged' => false],
                'resources' => ['listChanged' => false, 'subscribe' => false],
                'prompts' => ['listChanged' => false],
            ],
            'serverInfo' => ['name' => self::NAME, 'version' => $this->version],
            'instructions' => 'A Tinvar application in development. Read its pages, queries, actions, models and settings '
                . '(names and types, never values), the end of its log, and a page rendered as nobody or as a test scenario\'s '
                . 'user -- as tools, or as tinvar:// resources -- and start from a prompt. Nothing here writes, runs code or SQL, or reads a secret.',
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function ready(array $result): array
    {
        if (!$this->initialized) {
            throw new \LogicException('Not initialized: send initialize first.');
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    public static function tools(): array
    {
        $none = ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
        $readOnly = ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false];

        return [
            ['name' => 'routes', 'description' => 'Every page and layout: path, view, class and file, its Data, #[Authorize] abilities, panels and middleware.', 'inputSchema' => $none, 'annotations' => $readOnly],
            ['name' => 'queries', 'description' => 'Every #[Query]: name, arguments and their types, what it returns, cache, abilities, panels.', 'inputSchema' => $none, 'annotations' => $readOnly],
            ['name' => 'actions', 'description' => 'Every #[Action]: name, method and file, models and form requests it takes, abilities, panels. Listed, never run.', 'inputSchema' => $none, 'annotations' => $readOnly],
            ['name' => 'schema', 'description' => 'Every model as its metadata declares it: table, key, columns and their PHP types, relations, tenant column, unique keys.', 'inputSchema' => $none, 'annotations' => $readOnly],
            ['name' => 'config', 'description' => 'Every setting the #[Config] classes read: variable, type, whether it is required or secret, and where it is set from. Never a value.', 'inputSchema' => $none, 'annotations' => $readOnly],
            [
                'name' => 'logs',
                'description' => 'The end of the application log, redacted: up to 200 lines, optionally from one level up or one request alone.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'lines' => ['type' => 'integer', 'minimum' => 1, 'maximum' => LogTail::MAX_LINES],
                        'level' => ['type' => 'string', 'enum' => ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency']],
                        'requestId' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{1,64}$'],
                    ],
                    'additionalProperties' => false,
                ],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'render_dump',
                'description' => 'Render one page through the real renderer, as nobody or as the user a test scenario makes, and return its status, '
                    . 'headers, HTML and RSC payload, with cookies, tokens and the nonce cut out. Runs on a copy of the data, never the original.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'description' => 'The page, such as /orders or /products/ABC-1?tab=x; never under /_.'],
                        'scenario' => ['type' => 'string', 'description' => 'A test scenario whose first user the page is rendered for; nobody without one.'],
                        'part' => ['type' => 'string', 'enum' => ['both', 'html', 'payload'], 'description' => 'Which of the HTML and the payload to return; both by default.'],
                    ],
                    'required' => ['path'],
                    'additionalProperties' => false,
                ],
                'annotations' => $readOnly,
            ],
        ];
    }

    /**
     * The resources: the same declarations the tools answer, and the end of
     * the log, under `tinvar://` -- names this server made, never a path or
     * a URL a client chooses, so nothing is read by name (ADR 0113).
     *
     * @return list<array<string, string>>
     */
    public static function resources(): array
    {
        $resource = static fn (string $name, string $title, string $description): array => [
            'uri' => self::SCHEME . $name,
            'name' => $name,
            'title' => $title,
            'description' => $description,
            'mimeType' => 'application/json',
        ];

        return [
            $resource('routes', 'Routes', 'Every page and layout: path, view, class and file, its Data, abilities, panels and middleware.'),
            $resource('queries', 'Queries', 'Every #[Query]: name, arguments and their types, what it returns, cache, abilities, panels.'),
            $resource('actions', 'Actions', 'Every #[Action]: name, method and file, models and form requests, abilities, panels.'),
            $resource('schema', 'Schema', 'Every model as its metadata declares it: table, key, columns, relations, tenant column.'),
            $resource('config', 'Settings', 'Every setting the #[Config] classes read: variable, type, required, secret. Never a value.'),
            $resource('logs', 'Log', 'The last ' . self::RESOURCE_LOG_LINES . ' lines of the application log, redacted.'),
        ];
    }

    /** @return list<array<string, string>> */
    public static function resourceTemplates(): array
    {
        return [[
            'uriTemplate' => self::SCHEME . 'logs/{requestId}',
            'name' => 'request-log',
            'title' => 'One request\'s log lines',
            'description' => 'The log lines of one request, by the id its response carried as X-Request-Id: redacted, ' . LogTail::MAX_LINES . ' at most.',
            'mimeType' => 'application/json',
        ]];
    }

    /**
     * @param array<array-key, mixed> $params
     * @return array<string, mixed>
     */
    private function read(array $params): array
    {
        $uri = $params['uri'] ?? null;
        $unknown = array_diff(array_keys($params), ['uri', '_meta']);
        if (!\is_string($uri) || $unknown !== []) {
            throw new \InvalidArgumentException('resources/read takes a uri, and nothing else.');
        }

        $name = str_starts_with($uri, self::SCHEME) ? substr($uri, \strlen(self::SCHEME)) : null;
        try {
            $value = $this->resource($name, $uri);
        } catch (\OutOfBoundsException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // As a tool's failure is answered: what failed, redacted, never a stack.
            throw new \LogicException($this->redactor->text($e->getMessage()), previous: $e);
        }

        return ['contents' => [[
            'uri' => $uri,
            'mimeType' => 'application/json',
            'text' => json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION),
        ]]];
    }

    private function resource(?string $name, string $uri): mixed
    {
        return match (true) {
            $name === 'routes' => $this->introspection->routes(),
            $name === 'queries' => $this->introspection->queries(),
            $name === 'actions' => $this->introspection->actions(),
            $name === 'schema' => $this->introspection->schema(),
            $name === 'config' => $this->introspection->config(),
            $name === 'logs' => $this->readLogs(['lines' => self::RESOURCE_LOG_LINES]),
            $name !== null && preg_match('/^logs\/([A-Za-z0-9_-]{1,64})\z/', $name, $m) === 1 => $this->readLogs(['lines' => LogTail::MAX_LINES, 'requestId' => $m[1]]),
            default => throw new \OutOfBoundsException('No resource ' . $this->redactor->text(substr($uri, 0, 100)) . '; resources/list names every one.'),
        };
    }

    /**
     * The prompts: ways into the tools for a task a developer names, each a
     * user message that asks the assistant to read with them. Their only
     * arguments are a page's path and a request's id, each held to the rule
     * its tool holds it to, so a prompt carries no text a client chose
     * beyond those (ADR 0113).
     *
     * @return list<array<string, mixed>>
     */
    public static function prompts(): array
    {
        return [
            [
                'name' => 'explain_page',
                'title' => 'Explain a page',
                'description' => 'Where a page\'s data comes from: its route, loaders and Data, the queries its components call, and what it renders.',
                'arguments' => [['name' => 'path', 'description' => 'The page, such as /orders; never under /_.', 'required' => true]],
            ],
            [
                'name' => 'investigate_request',
                'title' => 'Investigate a request',
                'description' => 'What one request did and why it failed, from its log lines and the declarations they name.',
                'arguments' => [['name' => 'requestId', 'description' => 'The X-Request-Id its response carried.', 'required' => true]],
            ],
            [
                'name' => 'review_authorization',
                'title' => 'Review authorization',
                'description' => 'Every page, query and action with the abilities and panels that guard it, and any that nothing guards.',
                'arguments' => [],
            ],
        ];
    }

    /**
     * @param array<array-key, mixed> $params
     * @return array<string, mixed>
     */
    private function prompt(array $params): array
    {
        $name = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];
        if (!\is_string($name) || !\is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
            throw new \InvalidArgumentException('prompts/get names a prompt, and its arguments are an object.');
        }
        $declared = null;
        foreach (self::prompts() as $candidate) {
            if ($candidate['name'] === $name) {
                $declared = $candidate;
            }
        }
        if ($declared === null) {
            throw new \InvalidArgumentException("No prompt '" . substr($name, 0, 100) . "'.");
        }
        /** @var list<array{name: string}> $takes */
        $takes = $declared['arguments'];
        $unknown = array_diff(array_keys($arguments), array_column($takes, 'name'));
        if ($unknown !== []) {
            throw new \InvalidArgumentException("{$name} takes no " . implode(', ', $unknown) . '.');
        }

        $text = match ($name) {
            'explain_page' => $this->explainPage($arguments['path'] ?? null),
            'investigate_request' => self::investigateRequest($arguments['requestId'] ?? null),
            default => 'Review how this Tinvar application authorizes what it serves. Read the routes, queries and actions tools: '
                . 'list every page, query and action with its #[Authorize] abilities and panels, then name any that nothing guards '
                . 'and say whether each looks deliberate -- a public page, a #[PublicAccess] action -- or a gap. Quote no setting\'s value '
                . 'and run nothing; the tools only read.',
        };

        return [
            'description' => $declared['description'],
            'messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => $text]]],
        ];
    }

    private function explainPage(mixed $path): string
    {
        if (!\is_string($path)) {
            throw new \InvalidArgumentException('explain_page takes path, a page\'s path.');
        }
        RenderDump::assertPagePath($path);

        return "Explain the page at {$path} in this Tinvar application. Use the routes tool to find the route that serves it, its layouts "
            . 'and the Data each loader returns; the queries tool for the queries its components may call; and '
            . ($this->dump === null ? 'say that render_dump is off here, so the page is not rendered.' : "render_dump with path {$path} to see what it renders.")
            . ' Say where each part of the page comes from, and which #[Authorize] checks guard it.';
    }

    private static function investigateRequest(mixed $id): string
    {
        if (!\is_string($id) || preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $id) !== 1) {
            throw new \InvalidArgumentException('investigate_request takes requestId, a request\'s id.');
        }

        return "Investigate the request {$id} in this Tinvar application. Read its log lines with the logs tool, requestId {$id}, "
            . 'or the resource ' . self::SCHEME . "logs/{$id}. Name the route or endpoint it reached, what failed and where, using the "
            . 'routes, queries and actions tools for the code the lines name. The lines are redacted: do not try to recover what was cut out.';
    }

    /**
     * @param array<array-key, mixed> $params
     * @return array<string, mixed>
     */
    private function call(array $params): array
    {
        $name = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];
        if (!\is_string($name)) {
            throw new \InvalidArgumentException('tools/call names a tool.');
        }
        if (!\is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
            throw new \InvalidArgumentException('A tool\'s arguments are an object.');
        }
        /** @var array<string, mixed> $arguments */
        $tool = null;
        foreach (self::tools() as $candidate) {
            if ($candidate['name'] === $name) {
                $tool = $candidate;
            }
        }
        if ($tool === null) {
            throw new \InvalidArgumentException("No tool '" . substr($name, 0, 100) . "'.");
        }
        $allowed = array_keys((array) $tool['inputSchema']['properties']);
        $unknown = array_diff(array_keys($arguments), $allowed);
        if ($unknown !== []) {
            throw new \InvalidArgumentException("{$name} takes no " . implode(', ', $unknown) . '.');
        }

        try {
            $value = match ($name) {
                'routes' => $this->introspection->routes(),
                'queries' => $this->introspection->queries(),
                'actions' => $this->introspection->actions(),
                'schema' => $this->introspection->schema(),
                'config' => $this->introspection->config(),
                'logs' => $this->readLogs($arguments),
                'render_dump' => $this->renderDump($arguments),
                default => throw new \InvalidArgumentException("No tool '{$name}'."),
            };
        } catch (\Throwable $e) {
            // What failed, as the log would say it: the tool's error is the
            // assistant's to read, never a stack or a value the Redactor
            // would have cut out.
            return [
                'content' => [['type' => 'text', 'text' => $this->redactor->text($e->getMessage())]],
                'isError' => true,
            ];
        }

        $text = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);

        return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => false];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return list<mixed>
     */
    private function readLogs(array $arguments): array
    {
        if ($this->logs === null) {
            throw new \RuntimeException('This application names no log file to read.');
        }
        $lines = $arguments['lines'] ?? 50;
        $level = $arguments['level'] ?? null;
        $request = $arguments['requestId'] ?? null;
        if (!\is_int($lines) || ($level !== null && !\is_string($level)) || ($request !== null && (!\is_string($request) || preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $request) !== 1))) {
            throw new \InvalidArgumentException('logs takes lines (an integer), level (a level\'s name) and requestId (a request id).');
        }

        return $this->logs->read($lines, $level, $request);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function renderDump(array $arguments): array
    {
        if ($this->dump === null) {
            throw new \RuntimeException('Nothing can be rendered here: start the server with a build made for the test profile.');
        }
        $path = $arguments['path'] ?? null;
        $scenario = $arguments['scenario'] ?? null;
        $part = $arguments['part'] ?? 'both';
        if (!\is_string($path) || ($scenario !== null && !\is_string($scenario)) || !\in_array($part, ['both', 'html', 'payload'], true)) {
            throw new \InvalidArgumentException('render_dump takes path (a string), scenario (a string) and part (both, html or payload).');
        }
        RenderDump::assertPagePath($path);

        $dump = ($this->dump)($path, $scenario);
        foreach (['html', 'payload'] as $field) {
            if ($part !== 'both' && $part !== $field) {
                unset($dump[$field]);

                continue;
            }
            $text = $dump[$field] ?? '';
            if (\is_string($text) && \strlen($text) > self::MAX_TEXT) {
                $dump[$field] = substr($text, 0, self::MAX_TEXT) . "\n...[cut: " . (\strlen($text) - self::MAX_TEXT) . ' more bytes]';
            }
        }

        return $dump;
    }

    /** @return array<string, mixed> */
    private static function error(int|string|null $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    /**
     * @param resource $out
     * @param array<string, mixed> $message
     */
    private static function write($out, array $message): void
    {
        fwrite($out, json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION) . "\n");
        fflush($out);
    }
}
