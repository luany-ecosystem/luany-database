<?php

namespace Luany\Database;

/**
 * Thrown by findOrFail() / firstOrFail() when no row matches.
 */
class ModelNotFoundException extends \RuntimeException
{
    /**
     * @param class-string $model
     */
    public function __construct(public readonly string $model, public readonly int|string|null $id = null)
    {
        $short = substr(strrchr('\\' . $model, '\\') ?: $model, 1);
        parent::__construct($id === null
            ? "No [{$short}] matches the query."
            : "No [{$short}] found with key [{$id}].");
    }
}
