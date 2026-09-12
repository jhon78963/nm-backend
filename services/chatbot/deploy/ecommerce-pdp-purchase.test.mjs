import assert from 'node:assert/strict';
import test from 'node:test';

import {
  buildPdpPurchaseHandoffPrompt,
  parsePdpPurchaseMessage,
} from '../dist/application/services/ecommerce-pdp-purchase.service.js';

test('parsePdpPurchaseMessage detects NM-PDP ref token', () => {
  const intent = parsePdpPurchaseMessage(
    [
      'Hola Malu, quiero comprar este producto:',
      '',
      'Producto: Vestido Floral',
      'Cantidad: 2',
      'Precio unitario: S/ 49.90',
      'Talla: M',
      'Color: Azul',
      'SKU: 7890123456789',
      'Enlace: https://novedadesmaritex.net.pe/producto/vestido-floral-a1b2c3d4',
      '',
      '[NM-PDP:pid=a1b2c3d4;qty=2;sku=7890123456789]',
    ].join('\n'),
  );

  assert.ok(intent);
  assert.equal(intent.productName, 'Vestido Floral');
  assert.equal(intent.quantity, 2);
  assert.equal(intent.sku, '7890123456789');
  assert.equal(intent.productIdPrefix, 'a1b2c3d4');
  assert.equal(intent.sizeLabel, 'M');
  assert.equal(intent.colorLabel, 'Azul');
  assert.equal(intent.unitPrice, 49.9);
});

test('parsePdpPurchaseMessage ignores generic catalog questions', () => {
  assert.equal(parsePdpPurchaseMessage('¿Cuánto cuesta el vestido floral?'), null);
});

test('buildPdpPurchaseHandoffPrompt uses product name', () => {
  const prompt = buildPdpPurchaseHandoffPrompt({
    productName: 'Polo Niño Azul',
    sku: null,
    quantity: 1,
    productUrl: null,
    productIdPrefix: null,
    sizeLabel: null,
    colorLabel: null,
    unitPrice: null,
  });

  assert.match(prompt, /Polo Niño Azul/);
});
