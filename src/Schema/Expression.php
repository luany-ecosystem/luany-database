<?php

namespace Luany\Database\Schema;

/**
 * Raw SQL used verbatim as a column default: Schema::raw('CURRENT_TIMESTAMP').
 * Never build one from user input.
 */
final class Expression
{
    public function __construct(public readonly string $sql) {}
}
