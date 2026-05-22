<?php

namespace App\Auth\Exceptions;

use Exception;

class InvalidTokenException extends Exception
{
    protected $message = 'Invalid token.';

    public function render()
    {
        return \App\Shared\Foundation\Exceptions\ApiExceptionRenderer::jsonError(
            $this,
            422,
        );
    }
}
