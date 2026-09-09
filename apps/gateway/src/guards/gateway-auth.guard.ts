import { ExecutionContext, Injectable } from '@nestjs/common';
import { JwtAuthGuard } from '@app/common/guards/jwt-auth.guard';

/** Rutas públicas del gateway (equivalente a public_api.php en Laravel). */
const PUBLIC_AUTH_PATHS = new Set([
  '/api/v1/auth/login',
  '/api/v1/auth/refresh',
  '/api/v1/auth/forgot-password',
  '/api/v1/auth/reset-password',
  '/api/v1/auth/customer/register',
  '/api/v1/auth/customer/login',
  '/api/v1/auth/customer/google',
]);

/**
 * JwtAuthGuard del gateway: permite login/refresh sin token y exige JWT en el resto.
 * Las rutas @Public() del auth-service no aplican aquí — el gateway valida antes de proxificar.
 */
/** Archivos servidos por storage (avatares, productos, etc.) — <img> no envía JWT. */
function isPublicStorageFileRequest(method: string, path: string): boolean {
  return ['GET', 'HEAD'].includes(method) && path.startsWith('/api/v1/storage/files/');
}

function isPublicEcommerceHeaderRequest(method: string, path: string): boolean {
  return method === 'GET' && path === '/api/v1/ecommerce/header';
}

function isPublicEcommerceBannersRequest(method: string, path: string): boolean {
  return (
    method === 'GET'
    && (path === '/api/v1/ecommerce/banners' || path === '/api/v1/ecommerce/banners/offer')
  );
}

function isPublicEcommerceHeroSlidesRequest(method: string, path: string): boolean {
  return method === 'GET' && path === '/api/v1/ecommerce/hero-slides';
}

function isPublicEcommerceFooterRequest(method: string, path: string): boolean {
  return method === 'GET' && path === '/api/v1/ecommerce/footer';
}

function isPublicEcommerceHomeServicesRequest(method: string, path: string): boolean {
  return method === 'GET' && path === '/api/v1/ecommerce/home/services';
}

function isPublicEcommerceHomeSocialMediaRequest(method: string, path: string): boolean {
  return method === 'GET' && path === '/api/v1/ecommerce/home/social-media';
}

function isPublicEcommerceProductsRequest(method: string, path: string): boolean {
  return (
    method === 'GET'
    && (
      path === '/api/v1/ecommerce/products/public'
      || /^\/api\/v1\/ecommerce\/products\/public\/by-slug\/[^/]+$/.test(path)
      || /^\/api\/v1\/ecommerce\/products\/[^/]+\/stock$/.test(path)
    )
  );
}

function isPublicEcommerceHomeCollectionsRequest(method: string, path: string): boolean {
  return method === 'GET' && path === '/api/v1/ecommerce/home/collections';
}

function isPublicEcommerceHomeCategoryProductsRequest(method: string, path: string): boolean {
  return method === 'GET' && path === '/api/v1/ecommerce/home/category-products';
}

function isPublicEcommerceShopCollectionsRequest(method: string, path: string): boolean {
  if (method !== 'GET') return false;
  if (path === '/api/v1/ecommerce/shop/collections') return true;
  if (path === '/api/v1/ecommerce/shop/collections/admin') return false;
  return /^\/api\/v1\/ecommerce\/shop\/collections\/[^/]+$/.test(path);
}

function isPublicEcommerceShopProductsRequest(method: string, path: string): boolean {
  return method === 'GET' && path === '/api/v1/ecommerce/shop/products';
}

function isPublicEcommerceSearchRequest(method: string, path: string): boolean {
  return method === 'GET' && path === '/api/v1/ecommerce/search';
}

function isPublicEcommerceOrdersRequest(method: string, path: string): boolean {
  if (path === '/api/v1/ecommerce/orders' && method === 'POST') {
    return true;
  }

  if (method === 'POST' && path === '/api/v1/ecommerce/orders/track') {
    return true;
  }

  if (method === 'POST' && path === '/api/v1/ecommerce/orders/cancel-checkout') {
    return true;
  }

  return method === 'GET' && /^\/api\/v1\/ecommerce\/orders\/public\/[^/]+$/.test(path);
}

function isPublicEcommerceNewsletterRequest(method: string, path: string): boolean {
  return method === 'POST' && path === '/api/v1/ecommerce/newsletter/subscribe';
}

function isPublicEcommerceCouponsRequest(method: string, path: string): boolean {
  return method === 'POST' && path === '/api/v1/ecommerce/coupons/validate';
}

function isPublicEcommerceCulqiRequest(method: string, path: string): boolean {
  if (method !== 'POST') {
    return false;
  }

  return (
    path === '/api/v1/ecommerce/payments/culqi/charge'
    || path === '/api/v1/ecommerce/payments/culqi/prepare'
    || path === '/api/v1/ecommerce/webhooks/culqi'
  );
}

/**
 * Storefront: JWT de cliente (rol Cliente). El gateway no valida admin JWT;
 * ecommerce-service valida con CustomerJwtAuthGuard.
 */
function isEcommerceCustomerAccountRequest(method: string, path: string): boolean {
  if (path.startsWith('/api/v1/ecommerce/customer/')) {
    return true;
  }

  if (method === 'GET' && path === '/api/v1/ecommerce/orders/mine') {
    return true;
  }

  if (method === 'GET' && /^\/api\/v1\/ecommerce\/orders\/mine\/[^/]+$/.test(path)) {
    return true;
  }

  if (method === 'POST' && /^\/api\/v1\/ecommerce\/products\/[^/]+\/reviews$/.test(path)) {
    return true;
  }

  return false;
}

/** Chatbot usa JWT propio — el gateway solo proxifica sin validar sesión NM. */
function isChatbotProxyRequest(path: string): boolean {
  return path.startsWith('/api/v1/chatbot');
}

@Injectable()
export class GatewayAuthGuard extends JwtAuthGuard {
  canActivate(context: ExecutionContext) {
    const req = context.switchToHttp().getRequest<{ url?: string; method?: string }>();
    const path = (req.url ?? '').split('?')[0];
    const method = (req.method ?? 'GET').toUpperCase();

    if (
      PUBLIC_AUTH_PATHS.has(path)
      || isPublicStorageFileRequest(method, path)
      || isPublicEcommerceHeaderRequest(method, path)
      || isPublicEcommerceBannersRequest(method, path)
      || isPublicEcommerceHeroSlidesRequest(method, path)
      || isPublicEcommerceFooterRequest(method, path)
      || isPublicEcommerceHomeServicesRequest(method, path)
      || isPublicEcommerceHomeSocialMediaRequest(method, path)
      || isPublicEcommerceProductsRequest(method, path)
      || isPublicEcommerceHomeCollectionsRequest(method, path)
      || isPublicEcommerceHomeCategoryProductsRequest(method, path)
      || isPublicEcommerceShopCollectionsRequest(method, path)
      || isPublicEcommerceShopProductsRequest(method, path)
      || isPublicEcommerceSearchRequest(method, path)
      || isPublicEcommerceOrdersRequest(method, path)
      || isPublicEcommerceNewsletterRequest(method, path)
      || isPublicEcommerceCouponsRequest(method, path)
      || isPublicEcommerceCulqiRequest(method, path)
      || isEcommerceCustomerAccountRequest(method, path)
      || isChatbotProxyRequest(path)
    ) {
      return true;
    }
    return super.canActivate(context);
  }
}
