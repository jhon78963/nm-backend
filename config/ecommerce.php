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

];
