import {
  buildAccentInsensitiveNamePattern,
  isFullUuid,
  isUuidSearchFragment,
  normalizeSearchText,
} from './product-search.util';

describe('product-search.util', () => {
  it('normaliza tildes y mayúsculas', () => {
    expect(normalizeSearchText('Polo algodón')).toBe('polo algodon');
  });

  it('detecta UUID completo', () => {
    expect(isFullUuid('b7bff81e-78d8-4fdf-ac73-a09e3db8e6b1')).toBe(true);
  });

  it('detecta fragmentos de UUID', () => {
    expect(isUuidSearchFragment('b7bff81e')).toBe(true);
    expect(isUuidSearchFragment('polo')).toBe(false);
  });

  it('construye patrón insensible a tildes', () => {
    expect(buildAccentInsensitiveNamePattern('algodón')).toBe('%algodon%');
  });
});
