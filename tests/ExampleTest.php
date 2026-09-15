<?php

use Eloquage\Tokens\Tokens;

it('bootstraps the package entrypoint', function () {
    $instance = new Tokens;

    expect($instance->name())->toBe('tokens');
});
