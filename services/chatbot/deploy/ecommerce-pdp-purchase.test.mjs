import assert from 'node:assert/strict';
import test from 'node:test';

import {
  buildPdpPurchaseHandoffPrompt,
  parseAllPdpPurchaseIntents,
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

test('parseAllPdpPurchaseIntents merges multiple NM-PDP tokens', () => {
  const intents = parseAllPdpPurchaseIntents(
    [
      'Hola Malu, quiero comprar estos productos de mi carrito:',
      '',
      'Producto: Bermuda Drill',
      'Cantidad: 1',
      'Precio unitario: S/ 52.00',
      '[NM-PDP:pid=aaaa1111;qty=1]',
      '',
      'Producto: Camisa Jean',
      'Cantidad: 1',
      'Precio unitario: S/ 68.00',
      '[NM-PDP:pid=bbbb2222;qty=1]',
    ].join('\n'),
  );

  assert.equal(intents.length, 2);
  assert.equal(intents[0]?.productIdPrefix, 'aaaa1111');
  assert.equal(intents[1]?.productIdPrefix, 'bbbb2222');
});

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
