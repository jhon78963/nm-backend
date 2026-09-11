const FULL_UUID_PATTERN =
  /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

const UUID_FRAGMENT_PATTERN = /^[0-9a-f-]{8,}$/i;

export const PRODUCT_SEARCH_ACCENT_FROM = 'áéíóúüñàèìòùâêîôûäëïö';
export const PRODUCT_SEARCH_ACCENT_TO = 'aeiouunaeiouaeiouaeio';

export function normalizeSearchText(value: string): string {
  return value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim();
}

export function isFullUuid(value: string): boolean {
  return FULL_UUID_PATTERN.test(value.trim());
}

export function isUuidSearchFragment(value: string): boolean {
  const trimmed = value.trim();
  return UUID_FRAGMENT_PATTERN.test(trimmed) && trimmed.length >= 8;
}

export function buildAccentInsensitiveNamePattern(search: string): string {
  const normalized = normalizeSearchText(search);
  return normalized ? `%${normalized}%` : '';
}
