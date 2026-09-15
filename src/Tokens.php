<?php

namespace Eloquage\Tokens;

/**
 * Primary entrypoint for eloquage/tokens.
 *
 * Pure-PHP implementation lives here. Optional TypePHP/native acceleration
 * can be added under native/ later without changing this public API.
 */
final class Tokens
{
    public function name(): string
    {
        return 'tokens';
    }
}
