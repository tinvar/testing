<?php

declare(strict_types=1);

namespace Tinvar\Testing\Dev;

use Tinvar\Observability\Redactor;

/**
 * The end of an application's log, for the MCP server: the last lines,
 * optionally only one request's or from one level up, read through the
 * Redactor again (ADR 0088).
 *
 * The logger redacted every line as it wrote it. It is read through the
 * Redactor once more because what is read here leaves the machine -- an
 * assistant sends its context to its provider -- and a line written by an
 * older version, or by something other than the logger, has had no such
 * pass. Bounded on both sides: the file's last 256 KiB at most, and 200
 * lines.
 */
final readonly class LogTail
{
    public const int MAX_LINES = 200;

    /** How much of the file's end is read: a line longer than this is cut. */
    public const int MAX_BYTES = 256 * 1024;

    private const array LEVELS = ['debug' => 0, 'info' => 1, 'notice' => 2, 'warning' => 3, 'error' => 4, 'critical' => 5, 'alert' => 6, 'emergency' => 7];

    public function __construct(private string $file, private Redactor $redactor = new Redactor(traces: false)) {}

    /**
     * The last $lines lines, oldest first; with $level, that level and
     * worse; with $requestId, that request's alone.
     *
     * @return list<mixed> each line decoded where it is JSON, its text where not
     */
    public function read(int $lines = 50, ?string $level = null, ?string $requestId = null): array
    {
        $lines = max(1, min(self::MAX_LINES, $lines));
        if ($level !== null && !isset(self::LEVELS[strtolower($level)])) {
            throw new \InvalidArgumentException("'{$level}' is not a log level: " . implode(', ', array_keys(self::LEVELS)) . '.');
        }
        $floor = $level === null ? null : self::LEVELS[strtolower($level)];

        clearstatcache(true, $this->file);
        if (!is_file($this->file)) {
            return [];
        }
        $handle = @fopen($this->file, 'r');
        if ($handle === false) {
            return [];
        }
        try {
            $size = (int) (fstat($handle)['size'] ?? 0);
            $from = max(0, $size - self::MAX_BYTES);
            fseek($handle, $from);
            $text = (string) stream_get_contents($handle, self::MAX_BYTES);
        } finally {
            fclose($handle);
        }

        $all = explode("\n", rtrim($text, "\n"));
        // The first line read from the middle of the file is a piece of one.
        if ($from > 0) {
            array_shift($all);
        }

        $kept = [];
        foreach (array_reverse($all) as $line) {
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (\is_array($decoded)) {
                if ($floor !== null && (self::LEVELS[strtolower((string) ($decoded['level'] ?? ''))] ?? -1) < $floor) {
                    continue;
                }
                if ($requestId !== null && ($decoded['request_id'] ?? $decoded['requestId'] ?? null) !== $requestId) {
                    continue;
                }
                $kept[] = $this->redactor->value($decoded);
            } elseif ($floor === null && $requestId === null) {
                $kept[] = $this->redactor->text($line);
            }
            if (\count($kept) >= $lines) {
                break;
            }
        }

        return array_reverse($kept);
    }
}
