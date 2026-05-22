<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Catálogo público (legacy / storefront externo)
    |--------------------------------------------------------------------------
    |
    | Cada tienda expone su catálogo con ?store={catalog_public_token} (columna
    | warehouses.catalog_public_token). Fallback legacy monotienda:
    | ECOMMERCE_WAREHOUSE_ID + ECOMMERCE_PUBLIC_STORE_TOKEN.
    |
    */

    'warehouse_id' => env('ECOMMERCE_WAREHOUSE_ID') !== null && env('ECOMMERCE_WAREHOUSE_ID') !== ''
        ? (int) env('ECOMMERCE_WAREHOUSE_ID')
        : null,

    'public_store_token' => env('ECOMMERCE_PUBLIC_STORE_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | URL base del servidor de imágenes (catálogo ecommerce)
    |--------------------------------------------------------------------------
    |
    | Base pública para construir URLs en ProductEcommerceResource.
    | Sin barra final. Ej: http://localhost:8001
    |
    */

    'image_server_url' => env('IMAGE_SERVER_URL', 'http://localhost:8001'),

];
