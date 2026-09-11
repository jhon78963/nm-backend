export const DEFAULT_FOOTER_SLUG = 'default-footer';

export const DEFAULT_FOOTER_CACHE_KEY = 'ecommerce:footer:public';

export const FOOTER_CACHE_TTL_SECONDS = 300;

export interface FooterLinkItem {
  id: string;
  name: string;
  href: string;
}

export interface FooterCategoryItem {
  id: string;
  name: string;
  href: string;
}

export interface PublicFooterConfig {
  newsletterTitle: string;
  newsletterSubtitle: string;
  aboutText: string;
  address: string;
  supportNumber: string;
  supportEmail: string;
  socialMediaEnabled: boolean;
  facebookUrl?: string;
  twitterUrl?: string;
  instagramUrl?: string;
  pinterestUrl?: string;
  tiktokUrl?: string;
  categories: FooterCategoryItem[];
  usefulLinks: FooterLinkItem[];
  helpCenterLinks: FooterLinkItem[];
  copyrightEnabled: boolean;
  copyrightContent: string;
  paymentImageUrl?: string;
}

export const DEFAULT_FOOTER_CONFIG: PublicFooterConfig = {
  newsletterTitle: '¡Suscríbete ahora!',
  newsletterSubtitle:
    'Regístrate en nuestro Newsletter y recibe ofertas, promociones y lanzamientos.',
  aboutText:
    'Descubre las últimas tendencias y disfruta de una experiencia de compra única con nuestras colecciones exclusivas.',
  address: 'Puesto C-74, Mercado Mayorista, Trujillo, Perú',
  supportNumber: '+51 921619616',
  supportEmail: 'soporte@novedadesmaritex.net.pe',
  socialMediaEnabled: true,
  facebookUrl: 'https://facebook.com/',
  twitterUrl: 'https://twitter.com/',
  instagramUrl: 'https://instagram.com/',
  pinterestUrl: 'https://pinterest.com/',
  tiktokUrl: 'https://www.tiktok.com/',
  categories: [
    { id: 'nosotros-1', name: 'Acerca de nosotros', href: '/acerca-de-nosotros' },
    { id: 'nosotros-2', name: 'Ventas al por mayor', href: '/ventas-al-por-mayor' },
  ],
  usefulLinks: [
    { id: 'info-1', name: 'Términos y condiciones', href: '/terminos-y-condiciones' },
    { id: 'info-2', name: 'Libro de reclamaciones', href: '/libro-de-reclamaciones' },
    { id: 'info-3', name: 'Política de privacidad', href: '/politica-de-privacidad' },
    { id: 'info-4', name: 'Política de cookies', href: '/politica-de-cookies' },
  ],
  helpCenterLinks: [
    {
      id: 'help-1',
      name: 'Políticas de garantía y devoluciones',
      href: '/politicas-de-garantia-y-devoluciones',
    },
    { id: 'help-2', name: 'Tarifas y zonas de reparto', href: '/tarifas-y-zonas-de-reparto' },
    { id: 'help-3', name: 'Mi cuenta', href: '/micuenta/miperfil' },
  ],
  copyrightEnabled: true,
  copyrightContent: '2026 NovedadesMaritex © Todos los derechos reservados.',
  paymentImageUrl: '/images/theme/data/payments.png',
};
