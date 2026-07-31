#!/usr/bin/env php
<?php

$root = dirname(__DIR__).'/app';

$namespaceFixes = [
    // Policies
    'Administrations/Users/Policies/UserPolicy.php' => 'App\\Administrations\\Users\\Policies',
    'Administrations/Roles/Policies/RolePolicy.php' => 'App\\Administrations\\Roles\\Policies',
    'Finances/Sales/Policies/SalePolicy.php' => 'App\\Finances\\Sales\\Policies',
    'Finances/CashMovements/Policies/CashMovementPolicy.php' => 'App\\Finances\\CashMovements\\Policies',
    'Directories/Teams/Policies/TeamPaymentPolicy.php' => 'App\\Directories\\Teams\\Policies',

    // Pos
    'Finances/Pos/Controllers/PosController.php' => 'App\\Finances\\Pos\\Controllers',
    'Finances/Pos/Requests/CheckoutPosRequest.php' => 'App\\Finances\\Pos\\Requests',
    'Finances/Pos/Requests/SearchCustomerDocRequest.php' => 'App\\Finances\\Pos\\Requests',

    // Multimedia
    'Ecommerce/Multimedia/Models/Media.php' => 'App\\Ecommerce\\Multimedia\\Models',
    'Ecommerce/Multimedia/Traits/HasMedia.php' => 'App\\Ecommerce\\Multimedia\\Traits',
    'Ecommerce/Multimedia/Controllers/ProductMediaController.php' => 'App\\Ecommerce\\Multimedia\\Controllers',
    'Ecommerce/Multimedia/Services/ProductMediaService.php' => 'App\\Ecommerce\\Multimedia\\Services',
    'Ecommerce/Multimedia/Requests/ProductMediaStoreRequest.php' => 'App\\Ecommerce\\Multimedia\\Requests',
    'Ecommerce/Multimedia/Support/ProductMediaUrlResolver.php' => 'App\\Ecommerce\\Multimedia\\Support',

    // Ecommerce Products
    'Ecommerce/Products/Controllers/ProductEcommerceController.php' => 'App\\Ecommerce\\Products\\Controllers',
    'Ecommerce/Products/Resources/ProductEcommerceResource.php' => 'App\\Ecommerce\\Products\\Resources',
    'Ecommerce/Products/Support/EcommerceCatalogScope.php' => 'App\\Ecommerce\\Products\\Support',
    'Ecommerce/Products/Support/EcommerceStoreResolver.php' => 'App\\Ecommerce\\Products\\Support',
    'Ecommerce/Products/Requests/EcommerceCatalogRequest.php' => 'App\\Ecommerce\\Products\\Requests',

    // Reconciliations
    'Inventories/Reconciliations/Controllers/InventoryReconciliationController.php' => 'App\\Inventories\\Reconciliations\\Controllers',
    'Inventories/Reconciliations/Services/InventoryReconciliationPosSalesService.php' => 'App\\Inventories\\Reconciliations\\Services',
    'Inventories/Reconciliations/Resources/InventoryReconciliationProductResource.php' => 'App\\Inventories\\Reconciliations\\Resources',
    'Inventories/Reconciliations/Requests/InventoryReconciliationUpdateRequest.php' => 'App\\Inventories\\Reconciliations\\Requests',
    'Inventories/Reconciliations/Requests/InventoryReconciliationSearchRequest.php' => 'App\\Inventories\\Reconciliations\\Requests',
    'Inventories/Reconciliations/Requests/InventoryReconciliationReplaceColorRequest.php' => 'App\\Inventories\\Reconciliations\\Requests',

    // Inventories shared
    'Inventories/Concerns/AssertsInventoryMasterMatchesColorPivotSum.php' => 'App\\Inventories\\Concerns',
    'Inventories/Concerns/ProvidesInventoryLockSortKey.php' => 'App\\Inventories\\Concerns',
    'Inventories/Support/StockAvailability.php' => 'App\\Inventories\\Support',
];

foreach ($namespaceFixes as $relativePath => $namespace) {
    $file = $root.'/'.$relativePath;
    if (! file_exists($file)) {
        echo "Missing: {$file}\n";
        continue;
    }
    $content = file_get_contents($file);
    $updated = preg_replace(
        '/^namespace\s+[^;]+;/m',
        'namespace '.$namespace.';',
        $content,
        1,
        $count
    );
    if ($count === 0) {
        echo "No namespace in: {$file}\n";
        continue;
    }
    file_put_contents($file, $updated);
    echo "Fixed namespace: {$relativePath}\n";
}

echo "Done.\n";
