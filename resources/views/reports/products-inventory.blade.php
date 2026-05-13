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
        .product-title {
            font-size: 11px;
            font-weight: bold;
            margin: 14px 0 6px 0;
            padding: 4px 6px;
            background: #e8eaf6;
            border: 1px solid #333;
            border-bottom: none;
        }
        .product-title:first-of-type { margin-top: 6px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        th, td { border: 1px solid #333; padding: 4px 6px; text-align: left; vertical-align: top; }
        th { background: #e8e8e8; font-weight: bold; }
        .num { text-align: right; white-space: nowrap; }
        .colors { font-size: 8px; line-height: 1.35; }
        .muted { color: #666; }
    </style>
</head>
<body>
<h1>Reporte de productos — tallas y colores</h1>
<p class="meta">Generado: {{ $generatedAt }}</p>

@foreach ($products as $product)
    <div class="product-title">{{ $product['name'] }}</div>
    <table>
        <thead>
        <tr>
            <th>Talla</th>
            <th class="num">P. compra</th>
            <th class="num">P. venta</th>
            <th class="num">P. venta mín.</th>
            <th class="num">Stock talla</th>
            <th>Colores</th>
        </tr>
        </thead>
        <tbody>
        @if (empty($product['sizes']))
            <tr>
                <td>—</td>
                <td class="num">—</td>
                <td class="num">—</td>
                <td class="num">—</td>
                <td class="num">—</td>
                <td class="colors muted">—</td>
            </tr>
        @else
            @foreach ($product['sizes'] as $size)
                @php
                    $colors = $size['colors'] ?? [];
                    if (count($colors) === 0) {
                        $colorsLabel = '—';
                    } else {
                        $colorsLabel = collect($colors)
                            ->map(fn ($c) => ($c['stock'] ?? 0).' '.$c['color'])
                            ->implode(', ');
                    }
                @endphp
                <tr>
                    <td>{{ $size['size'] }}</td>
                    <td class="num">{{ $size['purchase_price'] !== null ? number_format($size['purchase_price'], 2, '.', ',') : '—' }}</td>
                    <td class="num">{{ $size['sale_price'] !== null ? number_format($size['sale_price'], 2, '.', ',') : '—' }}</td>
                    <td class="num">{{ $size['min_sale_price'] !== null ? number_format($size['min_sale_price'], 2, '.', ',') : '—' }}</td>
                    <td class="num">{{ $size['stock'] }}</td>
                    <td class="colors">{{ $colorsLabel }}</td>
                </tr>
            @endforeach
        @endif
        </tbody>
    </table>
@endforeach
</body>
</html>
