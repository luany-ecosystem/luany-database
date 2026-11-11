<?php

namespace Luany\Database\Concerns;

use Luany\Database\Casts\CastsAttributes;

/**
 * Attribute casting for Model.
 *
 *   protected array $casts = [
 *       'active'       => 'bool',
 *       'qty'          => 'int',
 *       'price'        => 'decimal:2',
 *       'meta'         => 'array',        // JSON column ↔ PHP array
 *       'published_at' => 'datetime',     // DateTimeImmutable
 *       'birthday'     => 'date',
 *       'amount'       => MoneyCast::class, // implements CastsAttributes
 *   ];
 *
 * Raw database values are kept in $attributes; casts apply when reading
 * (property access, getAttribute(), toArray()) and when writing (property
 * assignment, fill(), forceFill()).
 *
 * @phpstan-require-extends \Luany\Database\Model
 */
trait HasCasts
{
    /** @var array<string, string> */
    protected array $casts = [];

    /** Storage format for datetime casts and timestamps. */
    protected string $dateFormat = 'Y-m-d H:i:s';

    /** Built-in cast names (checked before custom cast classes). */
    private const BUILTIN_CASTS = [
        'int', 'integer', 'float', 'double', 'real', 'decimal', 'bool', 'boolean', 'string',
        'array', 'json', 'datetime', 'immutable_datetime', 'date', 'immutable_date', 'timestamp',
    ];

    /** @var array<class-string, CastsAttributes> */
    private static array $castInstances = [];

    public function hasCast(string $key): bool
    {
        return isset($this->casts[$key]);
    }

    /**
     * @return array<string, string>
     */
    public function getCasts(): array
    {
        return $this->casts;
    }

    public function getDateFormat(): string
    {
        return $this->dateFormat;
    }

    /**
     * Raw value as stored (no cast).
     */
    public function getRawAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    protected function castForRead(string $key, mixed $value): mixed
    {
        $cast = $this->casts[$key] ?? null;

        if ($cast === null) {
            return $value;
        }

        if ($custom = $this->customCast($cast)) {
            return $custom->get($this, $key, $value, $this->attributes);
        }

        if ($value === null) {
            return null;
        }

        [$type, $argument] = $this->parseCast($cast);

        return match ($type) {
            'int', 'integer'              => (int) $value,
            'float', 'double', 'real'     => (float) $value,
            'decimal'                     => number_format((float) $value, (int) ($argument ?? 2), '.', ''),
            'bool', 'boolean'             => $this->toBool($value),
            'string'                      => (string) $value,
            'array', 'json'               => $this->decodeJson($key, $value),
            'datetime', 'immutable_datetime' => $this->toDateTime($value),
            'date', 'immutable_date'      => $this->toDateTime($value)->setTime(0, 0),
            'timestamp'                   => $this->toDateTime($value)->getTimestamp(),
            default                       => throw new \InvalidArgumentException(
                "Unknown cast [{$cast}] for attribute [{$key}] on " . static::class . '.'
            ),
        };
    }

    protected function castForWrite(string $key, mixed $value): mixed
    {
        $cast = $this->casts[$key] ?? null;

        if ($cast === null) {
            return $value;
        }

        if ($custom = $this->customCast($cast)) {
            return $custom->set($this, $key, $value, $this->attributes);
        }

        if ($value === null) {
            return null;
        }

        [$type] = $this->parseCast($cast);

        return match ($type) {
            'bool', 'boolean'             => $this->toBool($value) ? 1 : 0,
            'array', 'json'               => is_string($value)
                ? $value
                : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'datetime', 'immutable_datetime',
            'timestamp'                   => $this->normaliseDate($key, $value)->format($this->dateFormat),
            'date', 'immutable_date'      => $this->normaliseDate($key, $value)->format('Y-m-d'),
            default                       => $value,
        };
    }

    /**
     * Value for toArray()/JSON: dates as strings, everything else as cast.
     */
    protected function serializeAttribute(string $key, mixed $value): mixed
    {
        $value = $this->castForRead($key, $value);

        if ($value instanceof \DateTimeInterface) {
            [$type] = $this->parseCast($this->casts[$key] ?? '');
            return $value->format(in_array($type, ['date', 'immutable_date'], true) ? 'Y-m-d' : $this->dateFormat);
        }

        if ($value instanceof \JsonSerializable) {
            return $value->jsonSerialize();
        }

        return $value;
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function parseCast(string $cast): array
    {
        $parts = explode(':', strtolower($cast), 2);
        return [$parts[0], $parts[1] ?? null];
    }

    private function customCast(string $cast): ?CastsAttributes
    {
        // Built-in names first: class_exists('datetime') is true (PHP's DateTime).
        if (in_array($this->parseCast($cast)[0], self::BUILTIN_CASTS, true) || !class_exists($cast)) {
            return null;
        }

        if (!is_subclass_of($cast, CastsAttributes::class)) {
            throw new \InvalidArgumentException("Cast class [{$cast}] must implement " . CastsAttributes::class . '.');
        }

        return self::$castInstances[$cast] ??= new $cast();
    }

    private function toBool(mixed $value): bool
    {
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
        }

        return (bool) $value;
    }

    private function decodeJson(string $key, mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        try {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \UnexpectedValueException(
                "Attribute [{$key}] on " . static::class . ' does not contain valid JSON: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Parse any accepted date input (DateTimeInterface, timestamp, '2026-11-02',
     * '2026-11-02T10:30' from <input type="datetime-local">, …) so every
     * stored value uses one format and compares correctly in SQL.
     */
    private function normaliseDate(string $key, mixed $value): \DateTimeImmutable
    {
        try {
            return $this->toDateTime($value);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(
                "Invalid date for attribute [{$key}] on " . static::class . ': ' . (is_scalar($value) ? (string) $value : get_debug_type($value)),
                0,
                $e
            );
        }
    }

    private function toDateTime(mixed $value): \DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (new \DateTimeImmutable())->setTimestamp((int) $value);
        }

        $parsed = \DateTimeImmutable::createFromFormat('!' . $this->dateFormat, (string) $value);

        return $parsed !== false ? $parsed : new \DateTimeImmutable((string) $value);
    }
}
