<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Almacén del catálogo público (ecommerce)
    |--------------------------------------------------------------------------
    |
    | ID del warehouse cuyos productos y stock se exponen en /prod/ecommerce.
    | Obligatorio en producción para no filtrar catálogos de otras tiendas.
    |
    */

    'warehouse_id' => env('ECOMMERCE_WAREHOUSE_ID') !== null && env('ECOMMERCE_WAREHOUSE_ID') !== ''
        ? (int) env('ECOMMERCE_WAREHOUSE_ID')
        : null,

    /*
    |--------------------------------------------------------------------------
    | URL base del servidor de imágenes (catálogo ecommerce)
    |--------------------------------------------------------------------------
    |
    | Base pública para construir asset_url en ProductEcommerceResource.
    | Sin barra final. Ej: http://localhost:8001
    |
    */

    'image_server_url' => env('IMAGE_SERVER_URL', 'http://localhost:8001'),

];
