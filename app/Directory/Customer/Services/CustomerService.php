<?php

namespace App\Directory\Customer\Services;

use App\Directory\Customer\Models\Customer;
use App\Shared\Foundation\Services\ModelService;
use App\Shared\Foundation\Services\SunatService;

class CustomerService extends ModelService
{
    public function __construct(
        Customer $customer,
        protected SunatService $sunatService
    ) {
        parent::__construct($customer);
    }

    public function findOrCreateByDoc(string $docNumber): ?Customer
    {
        // 1. Primero buscamos en nuestra BD local para ahorrar peticiones
        /** @var Customer|null $localCustomer */
        $localCustomer = $this->model->where('document_number', $docNumber)
                                     ->where('is_deleted', false)
                                     ->first();

        if ($localCustomer) {
            return $localCustomer;
        }

        // 2. Si no existe, consultamos a la API externa
        $len = strlen($docNumber);
        $dataToCreate = [];

        if ($len === 8) {
            // --- CASO DNI ---
            $persona = $this->sunatService->dniConsultation($docNumber);

            // Validamos si la API respondió correctamente (a veces devuelve objeto con error o null)
            if ($persona && isset($persona->nombres)) {
                $dataToCreate = [
                    'document_type'   => 'DNI',
                    'document_number' => $docNumber,
                    // Concatenamos nombre completo
                    'name'            => trim("{$persona->nombres} {$persona->apellidoPaterno} {$persona->apellidoMaterno}"),
                    'address'         => null, // RENIEC usualmente no da dirección exacta pública
                    'email'           => null,
                    'phone'           => null
                ];
            }

        } elseif ($len === 11) {
            // --- CASO RUC ---
            $empresa = $this->sunatService->rucConsultation($docNumber);

            if ($empresa && isset($empresa->razonSocial)) {
                $dataToCreate = [
                    'document_type'   => 'RUC',
                    'document_number' => $docNumber,
                    'name'            => $empresa->razonSocial,
                    'address'         => $empresa->direccion ?? null, // SUNAT sí suele dar dirección fiscal
                    'condition'       => $empresa->condicion ?? null, // Habido / No Habido
                    'status'          => $empresa->estado ?? null     // Activo / Baja
                ];
            }
        }

        // 3. Si obtuvimos datos válidos de la API, creamos el cliente localmente
        if (!empty($dataToCreate)) {
            // Usamos el método create() de tu ModelService para que se llenen los campos de auditoría
            return $this->create($dataToCreate);
        }

        // 4. Si falló todo (no existe en API o error), retornamos null
        // El controlador deberá decidir si pide registro manual
        return null;
    }
}
