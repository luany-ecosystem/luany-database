<?php

namespace Luany\Database\Casts;

use Luany\Database\Model;

/**
 * Custom attribute cast.
 *
 *   final class MoneyCast implements CastsAttributes
 *   {
 *       public function get(Model $model, string $key, mixed $value, array $attributes): mixed
 *       {
 *           return $value === null ? null : new Money((int) $value);
 *       }
 *
 *       public function set(Model $model, string $key, mixed $value, array $attributes): mixed
 *       {
 *           return $value instanceof Money ? $value->cents : $value;
 *       }
 *   }
 *
 *   protected array $casts = ['price' => MoneyCast::class];
 */
interface CastsAttributes
{
    /**
     * Transform the stored value into the value the application sees.
     *
     * @param array<string, mixed> $attributes Raw attributes of the model
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed;

    /**
     * Transform an application value into the value to store.
     *
     * @param array<string, mixed> $attributes Raw attributes of the model
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed;
}
