<?php

use Greenter\Ws\Services\SunatEndpoints;

/**
 * Configuración de Facturación Electrónica SUNAT (Greenter).
 *
 * Modos:
 *  - beta:          sandbox público (acepta credenciales MODDATOS / MODDATOS).
 *  - homologacion:  proceso oficial SUNAT con credenciales reales.
 *  - produccion:    emisión real con credenciales reales y certificado vigente.
 */

$mode = strtolower((string) env('SUNAT_MODE', 'beta'));

$endpointByMode = [
    'beta'         => SunatEndpoints::FE_BETA,
    'homologacion' => SunatEndpoints::FE_HOMOLOGACION,
    'produccion'   => SunatEndpoints::FE_PRODUCCION,
];

return [
    /*
    |--------------------------------------------------------------------------
    | Modo de operación
    |--------------------------------------------------------------------------
    */
    'mode'     => $mode,
    'endpoint' => $endpointByMode[$mode] ?? SunatEndpoints::FE_BETA,

    /*
    |--------------------------------------------------------------------------
    | Identidad del emisor (contribuyente)
    |--------------------------------------------------------------------------
    */
    'ruc'              => env('SUNAT_RUC'),
    'razon_social'     => env('SUNAT_RAZON_SOCIAL'),
    'nombre_comercial' => env('SUNAT_NOMBRE_COMERCIAL'),

    /*
    |--------------------------------------------------------------------------
    | Credenciales SOL (servicio web SUNAT)
    |--------------------------------------------------------------------------
    */
    'sol_user' => env('SUNAT_SOL_USER', 'MODDATOS'),
    'sol_pass' => env('SUNAT_SOL_PASS', 'MODDATOS'),

    /*
    |--------------------------------------------------------------------------
    | Certificado digital
    |--------------------------------------------------------------------------
    | Dos estrategias (se evalúan en orden):
    |
    |  1. SUNAT_CERT_PATH  → Ruta absoluta al .pem en el servidor.
    |                         Útil en desarrollo local.
    |
    |  2. SUNAT_CERT_CONTENT → Contenido del .pem codificado en Base64.
    |                           Recomendado para producción / CI/CD donde el
    |                           archivo no se puede comprometer en el repo.
    |                           Para generarlo:
    |                             base64 -i cert.pem | tr -d '\n'
    */
    'cert_path'    => env('SUNAT_CERT_PATH'),
    'cert_content' => env('SUNAT_CERT_CONTENT'),

    /*
    |--------------------------------------------------------------------------
    | Dirección fiscal del emisor (cabecera del XML UBL)
    |--------------------------------------------------------------------------
    */
    'address' => [
        'ubigeo'        => env('SUNAT_UBIGEO', '150101'),
        'departamento'  => env('SUNAT_DEPARTAMENTO', 'LIMA'),
        'provincia'     => env('SUNAT_PROVINCIA', 'LIMA'),
        'distrito'      => env('SUNAT_DISTRITO', 'LIMA'),
        'direccion'     => env('SUNAT_DIRECCION', '-'),
        'codigo_pais'   => env('SUNAT_CODIGO_PAIS', 'PE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Caché Twig de Greenter
    |--------------------------------------------------------------------------
    | Greenter usa Twig internamente. Para evitar recompilación en cada request
    | se recomienda apuntar a un directorio writable (storage/app/sunat/cache).
    | Si es null, Twig opera sin caché (más lento, útil en tests).
    */
    'cache_path' => storage_path('app/sunat/cache'),

    /*
    |--------------------------------------------------------------------------
    | Almacenamiento de archivos emitidos
    |--------------------------------------------------------------------------
    | xml_path y cdr_path en la tabla `sales` se guardan relativos a estos dirs.
    */
    'xml_storage_path' => storage_path('app/sunat/xml'),
    'cdr_storage_path' => storage_path('app/sunat/cdr'),
];
