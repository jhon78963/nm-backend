<?php

namespace App\Auth\Exceptions;

use Exception;

class InvalidUserCredentialsException extends Exception
{
    protected $message = 'Credenciales incorrectas';

    public function render()
    {
        return \App\Shared\Foundation\Exceptions\ApiExceptionRenderer::jsonError(
            $this,
            401,
        );
    }
}
