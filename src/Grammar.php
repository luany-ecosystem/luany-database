<?php

namespace Luany\Database;

/**
 * Grammar
 *
 * Driver-aware SQL fragments: identifier validation/quoting and operator
 * whitelisting. Every identifier that reaches SQL through the QueryBuilder
 * (tables, columns, aliases, data keys) passes through wrap()/wrapTable().
 *
 * Accepted identifier forms:
 *   column            → `column`
 *   table.column      → `table`.`column`
 *   *  /  table.*     → *  /  `table`.*
 *   column AS alias   → `column` AS `alias`   (case-insensitive "as")
 *
 * Anything else throws \InvalidArgumentException. Use the *Raw() builder
 * methods when you really need an expression.
 */
final class Grammar
{
    /** Single identifier segment: letters, digits, underscore; must not start with a digit. */
    private const SEGMENT = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /** @var array<int, string> */
    private const OPERATORS = [
        '=', '!=', '<>', '<', '<=', '>', '>=',
        'LIKE', 'NOT LIKE',
    ];

    private string $quote;

    public function __construct(private readonly string $driver = 'mysql')
    {
        $this->quote = $driver === 'pgsql' ? '"' : '`';
    }

    public function getDriver(): string
    {
        return $this->driver;
    }

    /**
     * Wrap a (possibly qualified / aliased) column identifier.
     */
    public function wrap(string $identifier): string
    {
        $identifier = trim($identifier);

        if ($identifier === '*') {
            return '*';
        }

        if (preg_match('/^(.+?)\s+as\s+(.+)$/i', $identifier, $m) === 1) {
            return $this->wrap($m[1]) . ' AS ' . $this->wrapSegment($m[2]);
        }

        $parts = explode('.', $identifier);

        if (count($parts) > 2) {
            throw $this->invalid($identifier);
        }

        $wrapped = [];
        foreach ($parts as $i => $part) {
            $wrapped[] = ($part === '*' && $i === count($parts) - 1 && $i > 0)
                ? '*'
                : $this->wrapSegment($part, $identifier);
        }

        return implode('.', $wrapped);
    }

    /**
     * Wrap a table name (optionally "schema.table" and/or "table AS alias").
     */
    public function wrapTable(string $table): string
    {
        $table = trim($table);

        // "posts AS p" → `posts` AS `p`
        if (preg_match('/^(.+?)\s+as\s+(.+)$/i', $table, $m) === 1) {
            return $this->wrapTable($m[1]) . ' AS ' . $this->wrapSegment($m[2], $table);
        }

        $parts = explode('.', $table);

        if (count($parts) > 2) {
            throw $this->invalid($table);
        }

        return implode('.', array_map(fn (string $p) => $this->wrapSegment($p, $table), $parts));
    }

    /**
     * Wrap a list of columns and join them with ", ".
     *
     * @param array<int, string> $columns
     */
    public function columnize(array $columns): string
    {
        return implode(', ', array_map(fn (string $c) => $this->wrap($c), $columns));
    }

    /**
     * Validate and normalise a comparison operator.
     *
     * @throws \InvalidArgumentException
     */
    public function operator(string $operator): string
    {
        $normalised = strtoupper(preg_replace('/\s+/', ' ', trim($operator)) ?? '');

        if (!in_array($normalised, self::OPERATORS, true)) {
            throw new \InvalidArgumentException(
                "Invalid SQL operator \"{$operator}\". Allowed: " . implode(', ', self::OPERATORS) . '.'
            );
        }

        return $normalised;
    }

    /**
     * Whether a string is a single, safe identifier segment.
     */
    public static function isValidSegment(string $segment): bool
    {
        return preg_match(self::SEGMENT, $segment) === 1;
    }

    private function wrapSegment(string $segment, ?string $original = null): string
    {
        $segment = trim($segment);

        if (!self::isValidSegment($segment)) {
            throw $this->invalid($original ?? $segment);
        }

        return $this->quote . $segment . $this->quote;
    }

    private function invalid(string $identifier): \InvalidArgumentException
    {
        return new \InvalidArgumentException(
            "Invalid SQL identifier \"{$identifier}\". Identifiers may only contain letters, digits and "
            . 'underscores (optionally qualified as table.column or aliased with AS). '
            . 'Use the *Raw() methods for expressions.'
        );
    }
}
