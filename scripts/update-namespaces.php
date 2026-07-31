#!/usr/bin/env php
<?php

/**
 * Bulk namespace migration for backend modular refactor.
 * Run from nm-backend root: php scripts/update-namespaces.php
 */

$root = dirname(__DIR__);

$replacements = [
    // Split modules (most specific first)
    'App\\Inventory\\Product\\Controllers\\InventoryReconciliationController' => 'App\\Inventories\\Reconciliations\\Controllers\\InventoryReconciliationController',
    'App\\Inventory\\Product\\Services\\InventoryReconciliationPosSalesService' => 'App\\Inventories\\Reconciliations\\Services\\InventoryReconciliationPosSalesService',
    'App\\Inventory\\Product\\Resources\\InventoryReconciliationProductResource' => 'App\\Inventories\\Reconciliations\\Resources\\InventoryReconciliationProductResource',
    'App\\Inventory\\Product\\Requests\\InventoryReconciliationUpdateRequest' => 'App\\Inventories\\Reconciliations\\Requests\\InventoryReconciliationUpdateRequest',
    'App\\Inventory\\Product\\Requests\\InventoryReconciliationSearchRequest' => 'App\\Inventories\\Reconciliations\\Requests\\InventoryReconciliationSearchRequest',
    'App\\Inventory\\Product\\Requests\\InventoryReconciliationReplaceColorRequest' => 'App\\Inventories\\Reconciliations\\Requests\\InventoryReconciliationReplaceColorRequest',

    'App\\Inventory\\Product\\Controllers\\ProductMediaController' => 'App\\Ecommerce\\Multimedia\\Controllers\\ProductMediaController',
    'App\\Inventory\\Product\\Services\\ProductMediaService' => 'App\\Ecommerce\\Multimedia\\Services\\ProductMediaService',
    'App\\Inventory\\Product\\Requests\\ProductMediaStoreRequest' => 'App\\Ecommerce\\Multimedia\\Requests\\ProductMediaStoreRequest',
    'App\\Inventory\\WooCommerce\\Support\\ProductMediaUrlResolver' => 'App\\Ecommerce\\Multimedia\\Support\\ProductMediaUrlResolver',

    'App\\Inventory\\Product\\Controllers\\ProductEcommerceController' => 'App\\Ecommerce\\Products\\Controllers\\ProductEcommerceController',
    'App\\Inventory\\Product\\Resources\\ProductEcommerceResource' => 'App\\Ecommerce\\Products\\Resources\\ProductEcommerceResource',
    'App\\Inventory\\Product\\Support\\EcommerceCatalogScope' => 'App\\Ecommerce\\Products\\Support\\EcommerceCatalogScope',
    'App\\Inventory\\Product\\Support\\EcommerceStoreResolver' => 'App\\Ecommerce\\Products\\Support\\EcommerceStoreResolver',
    'App\\Inventory\\Product\\Requests\\EcommerceCatalogRequest' => 'App\\Ecommerce\\Products\\Requests\\EcommerceCatalogRequest',

    'App\\Finance\\Sale\\Controllers\\PosController' => 'App\\Finances\\Pos\\Controllers\\PosController',
    'App\\Finance\\Sale\\Requests\\CheckoutPosRequest' => 'App\\Finances\\Pos\\Requests\\CheckoutPosRequest',
    'App\\Finance\\Sale\\Requests\\SearchCustomerDocRequest' => 'App\\Finances\\Pos\\Requests\\SearchCustomerDocRequest',

    'App\\Models\\Media' => 'App\\Ecommerce\\Multimedia\\Models\\Media',
    'App\\Traits\\HasMedia' => 'App\\Ecommerce\\Multimedia\\Traits\\HasMedia',

    'App\\Policies\\UserPolicy' => 'App\\Administrations\\Users\\Policies\\UserPolicy',
    'App\\Policies\\RolePolicy' => 'App\\Administrations\\Roles\\Policies\\RolePolicy',
    'App\\Policies\\SalePolicy' => 'App\\Finances\\Sales\\Policies\\SalePolicy',
    'App\\Policies\\CashMovementPolicy' => 'App\\Finances\\CashMovements\\Policies\\CashMovementPolicy',
    'App\\Policies\\TeamPaymentPolicy' => 'App\\Directories\\Teams\\Policies\\TeamPaymentPolicy',

    // Domain modules
    'App\\Administration\\Audit\\' => 'App\\Administrations\\ActionLogs\\',
    'App\\Administration\\Role\\' => 'App\\Administrations\\Roles\\',
    'App\\Administration\\Tenant\\' => 'App\\Administrations\\Tenants\\',
    'App\\Administration\\User\\' => 'App\\Administrations\\Users\\',
    'App\\Inventory\\Warehouse\\' => 'App\\Administrations\\Warehouses\\',

    'App\\Directory\\Customer\\' => 'App\\Directories\\Customers\\',
    'App\\Directory\\Team\\' => 'App\\Directories\\Teams\\',
    'App\\Directory\\Vendor\\' => 'App\\Directories\\Vendors\\',

    'App\\Finance\\CashMovement\\' => 'App\\Finances\\CashMovements\\',
    'App\\Finance\\Sale\\' => 'App\\Finances\\Sales\\',
    'App\\Finance\\AccumulatedAccount\\' => 'App\\Expenses\\AccumulatedExpenses\\',
    'App\\Finance\\FinancialSummary\\' => 'App\\Reports\\FinancialSummaries\\',

    'App\\Inventory\\WooCommerce\\' => 'App\\Ecommerce\\Products\\WooCommerce\\',
    'App\\Inventory\\InventoryLedger\\' => 'App\\Inventories\\Kardex\\',
    'App\\Inventory\\Color\\' => 'App\\Inventories\\Colors\\',
    'App\\Inventory\\Size\\' => 'App\\Inventories\\Sizes\\',
    'App\\Inventory\\Gender\\' => 'App\\Inventories\\Genders\\',
    'App\\Inventory\\Product\\' => 'App\\Inventories\\Products\\',
    'App\\Inventory\\Purchase\\' => 'App\\Inventories\\Purchases\\',
    'App\\Inventory\\Concerns\\' => 'App\\Inventories\\Concerns\\',
    'App\\Inventory\\Support\\' => 'App\\Inventories\\Support\\',

    'App\\Report\\' => 'App\\Reports\\Management\\',
];

$directories = [
    $root.'/app',
    $root.'/bootstrap',
    $root.'/config',
    $root.'/database',
    $root.'/tests',
    $root.'/resources',
];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root.'/app', FilesystemIterator::SKIP_DOTS)
);

$files = [];
foreach ($directories as $dir) {
    if (! is_dir($dir)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if ($file->isFile() && preg_match('/\.(php|blade\.php)$/', $file->getFilename())) {
            $files[] = $file->getPathname();
        }
    }
}

$files = array_unique($files);
$changed = 0;

foreach ($files as $file) {
    $content = file_get_contents($file);
    $original = $content;

    foreach ($replacements as $from => $to) {
        $content = str_replace($from, $to, $content);
    }

    if ($content !== $original) {
        file_put_contents($file, $content);
        $changed++;
        echo "Updated: {$file}\n";
    }
}

echo "\nDone. {$changed} files updated.\n";
