<?php

namespace App\Auth\Exceptions;

use Exception;

class InvalidUserCredentialsException extends Exception
{
    protected $message = 'Credenciales incorrectas';

    public function render()
    {
        return response()->json([
            'message' => [$this->message],
        ], 422);
    }
}
