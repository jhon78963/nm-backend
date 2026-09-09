import type { OutboundInteractiveListMessage } from '../ports/messaging-provider.port.js';

/** list_reply.id values for the main bot menu. */
export const MENU_ROW_IDS = {
  CATALOG: 'catalog',
  STORE: 'store',
  HANDOFF: 'handoff',
  CONTACT: 'contact',
} as const;

export type MenuSelection = (typeof MENU_ROW_IDS)[keyof typeof MENU_ROW_IDS];

/** button_reply.id values for interactive handoff confirmation. */
export const HANDOFF_BUTTON_IDS = {
  YES: 'handoff_yes',
  NO: 'handoff_no',
} as const;

const MENU_KEYWORD_PATTERN = /\b(men[uú]|menu|opciones)\b/i;

const GREETING_PATTERN =
  /^(hola|buenas|buenos\s+d[ií]as|buenas\s+tardes|buenas\s+noches|hi|hello|hey|ola|qué tal|que tal|buen\s*d[ií]a)[\s!?.]*$/i;

/** Fixed welcome message from knowledge_base.md — do not modify. */
export const NM_WELCOME_MESSAGE =
  '👋 ¡Hola! Bienvenido(a) a 𝐌𝐚𝐫𝐢𝐭𝐞𝐱 🛍️✨\n' +
  '¡Gracias por escribirnos! Tenemos ropa para toda la familia 👨‍👩‍👧‍👦\n\n' +
  'Tenemos modelos para:\n' +
  '1️⃣ Niño\n' +
  '2️⃣ Joven\n' +
  '3️⃣ Señorita\n' +
  '4️⃣ Adulto mayor\n\n' +
  '👉 Respóndenos con el número de la opción y te mostraremos los modelos disponibles, precios y tallas. 😊';

function botName(): string {
  return process.env['BOT_NAME'] ?? 'Malu';
}

function storeUrl(): string {
  return (process.env['STORE_URL'] ?? 'https://novedadesmaritex.net.pe').replace(/\/$/, '');
}

export function isGreeting(text: string): boolean {
  return GREETING_PATTERN.test(text.trim());
}

export function isMainMenuTrigger(text: string, _isFirstMessage: boolean): boolean {
  const trimmed = text.trim();
  return MENU_KEYWORD_PATTERN.test(trimmed);
}

export function getWelcomeMessage(): string {
  return NM_WELCOME_MESSAGE;
}

/** Canonical user phrases passed to the intent router per menu row. */
export const MENU_INTENT_PHRASES: Record<MenuSelection, string> = {
  [MENU_ROW_IDS.CATALOG]: 'Quiero ver el catálogo de productos de Maritex',
  [MENU_ROW_IDS.STORE]: 'Quiero ir a la tienda online de Maritex',
  [MENU_ROW_IDS.HANDOFF]: 'Quiero hablar con un asesor',
  [MENU_ROW_IDS.CONTACT]: '¿Cómo puedo contactar a Maritex?',
};

export function parseMenuSelection(interactiveReplyId: string | undefined): MenuSelection | null {
  if (!interactiveReplyId) return null;
  const ids = Object.values(MENU_ROW_IDS) as string[];
  return ids.includes(interactiveReplyId) ? (interactiveReplyId as MenuSelection) : null;
}

export function buildMainMenuList(to: string): OutboundInteractiveListMessage {
  const name = botName();
  return {
    to,
    body: `¡Hola! Soy ${name} de Maritex. ¿En qué puedo ayudarte hoy?`,
    buttonText: 'Ver opciones',
    sections: [
      {
        title: 'Menú principal',
        rows: [
          {
            id: MENU_ROW_IDS.CATALOG,
            title: 'Ver catálogo',
            description: 'Productos y categorías',
          },
          {
            id: MENU_ROW_IDS.STORE,
            title: 'Tienda online',
            description: 'novedadesmaritex.net.pe',
          },
          {
            id: MENU_ROW_IDS.HANDOFF,
            title: 'Hablar con asesor',
            description: 'Atención personalizada',
          },
          {
            id: MENU_ROW_IDS.CONTACT,
            title: 'Contacto',
            description: 'Soporte y tiendas',
          },
        ],
      },
    ],
  };
}

export function getCampusLocationFromEnv(): {
  latitude: number;
  longitude: number;
  name: string;
  address: string;
} {
  return {
    latitude: Number(process.env['LOCATION_LATITUDE'] ?? -8.1116),
    longitude: Number(process.env['LOCATION_LONGITUDE'] ?? -79.0285),
    name: process.env['LOCATION_NAME'] ?? 'Novedades Maritex',
    address: process.env['LOCATION_ADDRESS'] ?? 'Trujillo, Perú — consulta tiendas en la web',
  };
}

export function getStoreLinkMessage(): string {
  return `Visita nuestra tienda online:\n👉 ${storeUrl()}`;
}

export function isInteractiveHandoffEnabled(): boolean {
  return process.env['INTERACTIVE_HANDOFF'] === 'true';
}
