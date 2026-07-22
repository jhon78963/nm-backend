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

it('adds verifier wildcard for 4-digit suffix search', function () {
    expect(ProductBarcodeSearch::suffixPatterns('1234'))->toBe(['%1234', '%1234_']);
});

it('uses exact suffix for 5-digit search', function () {
    expect(ProductBarcodeSearch::suffixPatterns('12345'))->toBe(['%12345']);
});
