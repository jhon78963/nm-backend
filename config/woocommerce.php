<?php

return [

    /*
    |--------------------------------------------------------------------------
    | WooCommerce REST API
    |--------------------------------------------------------------------------
    |
    | Claves: WooCommerce → Ajustes → Avanzado → REST API.
    | En el mismo VPS usar WOO_BASE_URL=https://127.0.0.1 o el dominio público.
    |
    */

    'enabled' => env('WOO_SYNC_ENABLED', false),

    'base_url' => rtrim((string) env('WOO_BASE_URL', ''), '/'),

    'consumer_key' => (string) env('WOO_CONSUMER_KEY', ''),

    'consumer_secret' => (string) env('WOO_CONSUMER_SECRET', ''),

    'verify_ssl' => filter_var(env('WOO_VERIFY_SSL', true), FILTER_VALIDATE_BOOL),

    'timeout' => (int) env('WOO_HTTP_TIMEOUT', 30),

    /*
    | Almacén fuente (warehouses.id). Debe coincidir con products.warehouse_id.
    */
    'warehouse_id' => (int) env('WOO_SYNC_WAREHOUSE_ID', 0),

    /*
    | Atributos globales WooCommerce para productos variables.
    */
    'attributes' => [
        'color' => (string) env('WOO_ATTR_COLOR', 'Color'),
        'size' => (string) env('WOO_ATTR_SIZE', 'Talla'),
    ],

    /*
    | Meta keys para trazabilidad nm-backend ↔ WooCommerce.
    */
    'meta' => [
        'product_id' => '_nm_product_id',
        'product_size_id' => '_nm_product_size_id',
        'color_id' => '_nm_color_id',
        'variant_key' => '_nm_variant_key',
    ],

    'batch_size' => (int) env('WOO_SYNC_BATCH_SIZE', 50),

    /*
    | Imágenes: sideload vía Application Password cuando el uploader exige API key.
    | Usuarios → Perfil → Contraseñas de aplicación (WordPress 6+).
    */
    'image_sideload' => filter_var(env('WOO_IMAGE_SIDELOAD', true), FILTER_VALIDATE_BOOL),

    'wp_app_user' => (string) env('WOO_WP_APP_USER', env('WP_ADMIN_USER', '')),

    'wp_app_password' => (string) env('WOO_WP_APP_PASSWORD', ''),

];
