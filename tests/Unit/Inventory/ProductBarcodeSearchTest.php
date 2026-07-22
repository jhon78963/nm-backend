<?php

use App\Inventory\Product\Support\ProductBarcodeSearch;

it('normalizes scanner input for product search', function () {
    expect(ProductBarcodeSearch::normalize(" 77518146 \r\n"))->toBe('77518146');
});

it('detects suffix vs full barcode search lengths', function () {
    expect(strlen(ProductBarcodeSearch::normalize('1234')))->toBe(4);
    expect(strlen(ProductBarcodeSearch::normalize('12345')))->toBe(5);
    expect(strlen(ProductBarcodeSearch::normalize('7751814641234')))->toBe(13);
});
