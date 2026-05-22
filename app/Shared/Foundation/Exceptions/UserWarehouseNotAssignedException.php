<?php

namespace App\Shared\Foundation\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class UserWarehouseNotAssignedException extends Exception
{
    protected $message = 'Usuario sin almacén asignado para esta operación';

    public function render(): JsonResponse
    {
        return \App\Shared\Foundation\Exceptions\ApiExceptionRenderer::jsonError(
            $this,
            403,
        );
    }
}
