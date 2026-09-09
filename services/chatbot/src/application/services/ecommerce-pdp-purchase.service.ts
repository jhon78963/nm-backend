const NM_PDP_REF_PATTERN = /\[NM-PDP:([^\]]+)\]/i;
const PRODUCT_LINE_PATTERN = /^Producto:\s*(.+)$/im;
const SKU_LINE_PATTERN = /^SKU:\s*(.+)$/im;
const QTY_LINE_PATTERN = /^Cantidad:\s*(\d+)/im;
const PRODUCT_URL_PATTERN =
  /https?:\/\/(?:www\.)?novedadesmaritex\.net\.pe\/producto\/[^\s]+/i;

export interface ParsedPdpPurchaseIntent {
  productName: string | null;
  sku: string | null;
  quantity: number | null;
  productUrl: string | null;
  productIdPrefix: string | null;
}

function parseRefToken(raw: string): Partial<ParsedPdpPurchaseIntent> {
  const parsed: Partial<ParsedPdpPurchaseIntent> = {};

  for (const segment of raw.split(";")) {
    const [key, value] = segment.split("=").map((part) => part.trim());
    if (!key || !value) continue;

    if (key === "pid") {
      parsed.productIdPrefix = value.toLowerCase();
    } else if (key === "sku") {
      parsed.sku = value;
    } else if (key === "qty") {
      const qty = Number.parseInt(value, 10);
      parsed.quantity = Number.isFinite(qty) ? qty : null;
    }
  }

  return parsed;
}

export function parsePdpPurchaseMessage(text: string): ParsedPdpPurchaseIntent | null {
  const trimmed = text.trim();
  if (!trimmed) return null;

  const refMatch = trimmed.match(NM_PDP_REF_PATTERN);
  const fromRef = refMatch?.[1] ? parseRefToken(refMatch[1]) : {};

  const productName = trimmed.match(PRODUCT_LINE_PATTERN)?.[1]?.trim() ?? null;
  const sku = trimmed.match(SKU_LINE_PATTERN)?.[1]?.trim() ?? fromRef.sku ?? null;
  const quantityRaw = trimmed.match(QTY_LINE_PATTERN)?.[1];
  const quantity = quantityRaw
    ? Number.parseInt(quantityRaw, 10)
    : fromRef.quantity ?? null;
  const productUrl = trimmed.match(PRODUCT_URL_PATTERN)?.[0] ?? null;

  const hasPurchaseIntent =
    Boolean(refMatch)
    || (
      /\bquiero comprar este producto\b/i.test(trimmed)
      && Boolean(productName || productUrl)
    );

  if (!hasPurchaseIntent) {
    return null;
  }

  return {
    productName,
    sku,
    quantity: Number.isFinite(quantity) ? quantity : null,
    productUrl,
    productIdPrefix: fromRef.productIdPrefix ?? null,
  };
}

export function buildPdpPurchaseHandoffPrompt(intent: ParsedPdpPurchaseIntent): string {
  const label = intent.productName?.trim() || "el producto de la tienda";
  return (
    `¡Perfecto! Vi que quieres comprar *${label}* desde nuestra tienda online 🛍️\n\n` +
    '¿Te comunico con un asesor de Maritex para ayudarte a completar tu pedido por WhatsApp?'
  );
}
