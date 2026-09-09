/**
 * Permisos del rol Cliente (compradores de nm-ecommerce).
 *
 * Mapeo con funcionalidades del storefront:
 * - customer.read/update/password.change → /micuenta/miperfil (perfil y seguridad)
 * - review.read/create → PDP reseñas (PdpReviews, PdpReviewModal)
 * - order.create/read.own/track → checkout, historial y seguimiento de pedidos
 * - wishlist.manage → /favoritos (sincronización futura)
 * - address.read/manage → direcciones guardadas (futuro)
 */
export const CLIENTE_ROLE = 'Cliente';

export const ECOMMERCE_CUSTOMER_PERMISSIONS: readonly string[] = [
  'ecommerce.customer.read',
  'ecommerce.customer.update',
  'ecommerce.customer.password.change',
  'ecommerce.review.read',
  'ecommerce.review.create',
  'ecommerce.order.create',
  'ecommerce.order.read.own',
  'ecommerce.order.track',
  'ecommerce.wishlist.manage',
  'ecommerce.cart.manage',
  'ecommerce.address.read',
  'ecommerce.address.manage',
];

/**
 * Permisos de administración ecommerce (staff ERP — nm-frontend-v2).
 * Se crean en BD para uso futuro con @Permissions; hoy se aplican vía @Roles.
 */
export const ECOMMERCE_ADMIN_PERMISSIONS: readonly string[] = [
  'ecommerce.review.index',
  'ecommerce.review.moderate',
  'ecommerce.order.admin.read',
  'ecommerce.order.admin.update',
  'ecommerce.customer.admin.read',
];
