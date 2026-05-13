<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Reporte de productos — inventario</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111; }
        h1 { font-size: 14px; margin: 0 0 6px 0; }
        .meta { font-size: 8px; color: #444; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #333; padding: 4px 6px; text-align: left; vertical-align: top; }
        th { background: #e8e8e8; font-weight: bold; }
        .num { text-align: right; white-space: nowrap; }
        .barcode { font-family: DejaVu Sans Mono, monospace; font-size: 8px; white-space: nowrap; }
        .colors { font-size: 8px; line-height: 1.35; }
        .muted { color: #666; }
        .product-break td {
            font-weight: bold;
            font-size: 10px;
            background: #e8eaf6;
            border-bottom: 2px solid #333;
        }
        tr.row-mismatch td { background: #fff3e0; }
    </style>
</head>
<body>
<h1>Reporte de productos — tallas y colores</h1>
<p class="meta">Generado: {{ $generatedAt }} · «Stock colores» = suma de stocks por color (debe coincidir con «Stock talla»).</p>

<table>
    <thead>
    <tr>
        <th>Talla</th>
        <th>Código barras</th>
        <th class="num">P. compra</th>
        <th class="num">P. venta</th>
        <th class="num">P. venta mín.</th>
        <th class="num">Stock talla</th>
        <th>Colores</th>
        <th class="num">Stock colores</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($products as $product)
        <tr class="product-break">
            <td colspan="8">{{ $product['name'] }}</td>
        </tr>
        @if (empty($product['sizes']))
            <tr>
                <td>—</td>
                <td class="barcode muted">—</td>
                <td class="num">—</td>
                <td class="num">—</td>
                <td class="num">—</td>
                <td class="num">—</td>
                <td class="colors muted">—</td>
                <td class="num">—</td>
            </tr>
        @else
            @foreach ($product['sizes'] as $size)
                @php
                    $colors = $size['colors'] ?? [];
                    if (count($colors) === 0) {
                        $colorsLabel = '—';
                        $colorsStockSum = null;
                    } else {
                        $colorsLabel = collect($colors)
                            ->map(fn ($c) => ($c['stock'] ?? 0).' '.$c['color'])
                            ->implode(', ');
                        $colorsStockSum = collect($colors)->sum(fn ($c) => (int) ($c['stock'] ?? 0));
                    }
                    $sizeStock = $size['stock'] ?? 0;
                    $mismatch = $colorsStockSum !== null && (int) $colorsStockSum !== (int) $sizeStock;
                @endphp
                <tr @class(['row-mismatch' => $mismatch])>
                    <td>{{ $size['size'] }}</td>
                    <td class="barcode">{{ ! empty($size['barcode']) ? $size['barcode'] : '—' }}</td>
                    <td class="num">{{ $size['purchase_price'] !== null ? number_format($size['purchase_price'], 2, '.', ',') : '—' }}</td>
                    <td class="num">{{ $size['sale_price'] !== null ? number_format($size['sale_price'], 2, '.', ',') : '—' }}</td>
                    <td class="num">{{ $size['min_sale_price'] !== null ? number_format($size['min_sale_price'], 2, '.', ',') : '—' }}</td>
                    <td class="num">{{ $sizeStock }}</td>
                    <td class="colors">{{ $colorsLabel }}</td>
                    <td class="num">{{ $colorsStockSum !== null ? $colorsStockSum : '—' }}</td>
                </tr>
            @endforeach
        @endif
    @endforeach
    </tbody>
</table>
</body>
</html>
