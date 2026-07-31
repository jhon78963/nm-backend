#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT/app"

echo "==> Phase 1: Top-level module moves"

mkdir -p Administrations Directories Finances Expenses Reports Inventories Ecommerce

# Administrations
mv Administration/Audit Administrations/ActionLogs
mv Administration/Role Administrations/Roles
mv Administration/Tenant Administrations/Tenants
mv Administration/User Administrations/Users
rmdir Administration

# Warehouses → Administrations
mv Inventory/Warehouse Administrations/Warehouses

# Directories
mv Directory/Customer Directories/Customers
mv Directory/Team Directories/Teams
mv Directory/Vendor Directories/Vendors
rmdir Directory

# Finances: split Pos before moving Sale
mkdir -p Finances/Pos/{Controllers,Requests,Routes}
mv Finance/Sale/Controllers/PosController.php Finances/Pos/Controllers/
mv Finance/Sale/Requests/CheckoutPosRequest.php Finances/Pos/Requests/
mv Finance/Sale/Requests/SearchCustomerDocRequest.php Finances/Pos/Requests/

mv Finance/CashMovement Finances/CashMovements
mv Finance/Sale Finances/Sales

# Expenses
mv Finance/AccumulatedAccount Expenses/AccumulatedExpenses

# Reports (before removing Finance)
mv Finance/FinancialSummary Reports/FinancialSummaries

rm -rf Finance/Expense
rmdir Finance 2>/dev/null || rm -rf Finance

# Report → Reports/Management
if [ -d Report ]; then
  mv Report Reports/Management
fi

echo "==> Phase 2: Extract submodules (Reconciliations, Ecommerce, Policies)"

# Reconciliations
mkdir -p Inventories/Reconciliations/{Controllers,Services,Resources,Requests,Routes}
mv Inventory/Product/Controllers/InventoryReconciliationController.php Inventories/Reconciliations/Controllers/
mv Inventory/Product/Services/InventoryReconciliationPosSalesService.php Inventories/Reconciliations/Services/
mv Inventory/Product/Resources/InventoryReconciliationProductResource.php Inventories/Reconciliations/Resources/
mv Inventory/Product/Requests/InventoryReconciliationUpdateRequest.php Inventories/Reconciliations/Requests/
mv Inventory/Product/Requests/InventoryReconciliationSearchRequest.php Inventories/Reconciliations/Requests/
mv Inventory/Product/Requests/InventoryReconciliationReplaceColorRequest.php Inventories/Reconciliations/Requests/

# Ecommerce Multimedia
mkdir -p Ecommerce/Multimedia/{Models,Traits,Controllers,Services,Requests,Routes,Support}
mv Models/Media.php Ecommerce/Multimedia/Models/
mv Traits/HasMedia.php Ecommerce/Multimedia/Traits/
mv Inventory/Product/Controllers/ProductMediaController.php Ecommerce/Multimedia/Controllers/
mv Inventory/Product/Services/ProductMediaService.php Ecommerce/Multimedia/Services/
mv Inventory/Product/Requests/ProductMediaStoreRequest.php Ecommerce/Multimedia/Requests/
mv Inventory/WooCommerce/Support/ProductMediaUrlResolver.php Ecommerce/Multimedia/Support/

# Ecommerce Products
mkdir -p Ecommerce/Products/{Controllers,Resources,Requests,Support,WooCommerce}
mv Inventory/Product/Controllers/ProductEcommerceController.php Ecommerce/Products/Controllers/
mv Inventory/Product/Resources/ProductEcommerceResource.php Ecommerce/Products/Resources/
mv Inventory/Product/Support/EcommerceCatalogScope.php Ecommerce/Products/Support/
mv Inventory/Product/Support/EcommerceStoreResolver.php Ecommerce/Products/Support/
mv Inventory/Product/Requests/EcommerceCatalogRequest.php Ecommerce/Products/Requests/
mv Inventory/WooCommerce Ecommerce/Products/WooCommerce

# Policies → modules
mkdir -p Administrations/Users/Policies Administrations/Roles/Policies
mkdir -p Finances/Sales/Policies Finances/CashMovements/Policies
mkdir -p Directories/Teams/Policies
mv Policies/UserPolicy.php Administrations/Users/Policies/
mv Policies/RolePolicy.php Administrations/Roles/Policies/
mv Policies/SalePolicy.php Finances/Sales/Policies/
mv Policies/CashMovementPolicy.php Finances/CashMovements/Policies/
mv Policies/TeamPaymentPolicy.php Directories/Teams/Policies/
rmdir Policies Models Traits 2>/dev/null || true

echo "==> Phase 3: Remaining Inventory → Inventories"

mv Inventory/Color Inventories/Colors
mv Inventory/Size Inventories/Sizes
mv Inventory/Gender Inventories/Genders
mv Inventory/Product Inventories/Products
mv Inventory/Purchase Inventories/Purchases
mv Inventory/InventoryLedger Inventories/Kardex
mv Inventory/Concerns Inventories/Concerns
mv Inventory/Support Inventories/Support
rmdir Inventory 2>/dev/null || rm -rf Inventory

# Remove empty shells
rm -rf Shared/Image

echo "==> Structure migration complete"
