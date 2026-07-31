<?php

use App\Inventories\Products\Support\ProductBarcodeSearch;

it('normalizes text search preserving spaces', function () {
    expect(ProductBarcodeSearch::normalize("  polo   algodon \r\n"))->toBe('polo algodon');
});

it('normalizes scanner barcode input by stripping spaces', function () {
    expect(ProductBarcodeSearch::normalizeBarcode(" 77518146 \r\n"))->toBe('77518146');
    expect(ProductBarcodeSearch::normalizeBarcode('77 518 146'))->toBe('77518146');
});

it('detects suffix vs full barcode search lengths', function () {
    expect(strlen(ProductBarcodeSearch::normalizeBarcode('1234')))->toBe(4);
    expect(strlen(ProductBarcodeSearch::normalizeBarcode('12345')))->toBe(5);
    expect(strlen(ProductBarcodeSearch::normalizeBarcode('7751814641234')))->toBe(13);
});

it('adds verifier wildcard for 4-digit suffix search', function () {
    expect(ProductBarcodeSearch::suffixPatterns('1234'))->toBe(['%1234', '%1234_']);
});

it('uses exact suffix for 5-digit search', function () {
    expect(ProductBarcodeSearch::suffixPatterns('12345'))->toBe(['%12345']);
});

it('builds compact search token without spaces', function () {
    expect(preg_replace('/\s+/u', '', ProductBarcodeSearch::normalize('Pantalón De Vestir')))->toBe('PantalónDeVestir');
});
