<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Ticket {{ $sale->code }}</title>
    <style>
        @page {
            margin: 0;
            size: 80mm auto; /* Cambiado a auto para que no corte si es largo */
        }

        body {
            /* Usamos sans-serif o monospace que suelen renderizar mejor en térmicas */
            font-family: sans-serif, system-ui, -apple-system, BlinkMacSystemFont;
            font-size: 12px; /* Un pelín más grande para legibilidad */
            margin: 2mm;
            color: #000000; /* Negro puro */
            background-color: #ffffff;
            font-weight: 900; /* Negrita FUERTE para todo */
            -webkit-print-color-adjust: exact; /* Fuerza la impresión de colores exactos */
        }

        /* Clases utilitarias */
        .text-center { text-align: center; font-weight: bold; }
        .text-right { text-align: right; font-weight: bold; }
        .uppercase { text-transform: uppercase; }

        /* Encabezado */
        .header {
            margin-bottom: 10px;
            border-bottom: 2px dashed #000; /* Borde más grueso */
            padding-bottom: 5px;
        }

        .header h1 {
            font-size: 16px;
            margin: 0;
            font-weight: 900;
        }

        .info-tienda {
            font-size: 12px;
            margin-bottom: 5px;
        }

        /* Tablas */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 5px;
        }

        th {
            text-align: left;
            border-bottom: 2px solid #000; /* Línea más gruesa */
            font-size: 11px;
            font-weight: 900;
            padding-bottom: 2px;
        }

        td {
            padding: 4px 0;
            vertical-align: top;
            font-weight: 900; /* Asegura que las celdas sean negritas */
        }

        /* Totales */
        .totals {
            margin-top: 10px;
            border-top: 2px dashed #000; /* Línea más gruesa */
            padding-top: 5px;
        }

        .total-big {
            font-size: 16px;
            font-weight: 900;
        }

        .footer {
            margin-top: 15px;
            font-size: 11px;
            text-align: center;
            font-weight: 900;
        }

        /* Ocultar la etiqueta drawer para que no ocupe espacio visual */
        drawer {
            display: none;
        }
    </style>
</head>

<body style="font-weight: bold;">

    <drawer></drawer>

    <div class="header text-center">
        <h1 class="uppercase">Novedades Maritex</h1>
        <div class="info-tienda">
            Mercado Mayorista - Puesto C-74<br>
            Trujillo, La Libertad, Perú
        </div>
        <div>
            Ticket: {{ $sale->code }}<br>
            Fecha: {{ $sale->creation_time->format('d/m/Y H:i') }}
        </div>
    </div>

    <div style="margin-bottom: 5px; font-size: 12px;">
        @if($sale->customer)
            CLIENTE: {{ $sale->customer->name }} {{ $sale->customer->paternal_surname }}<br>
            DOC: {{ $sale->customer->document_number ?? '-' }}
        @else
            CLIENTE: PÚBLICO GENERAL
        @endif
    </div>

    <table>
        <thead>
            <tr>
                <th width="15%">CANT</th>
                <th width="50%">DESC</th>
                <th width="35%" class="text-right">TOTAL</th>
            </tr>
        </thead>
        <tbody>
            @foreach($sale->details as $detail)
                <tr>
                    <td class="text-center">{{ $detail->quantity }}</td>
                    <td>
                        {{ $detail->product_name_snapshot }}<br>
                        <small>T:{{ $detail->size_name_snapshot }} C:{{ $detail->color_name_snapshot }}</small>
                    </td>
                    <td class="text-right">
                        {{ number_format($detail->subtotal, 2) }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals">
        <table style="width: 100%">
            <tr>
                <td class="text-right">TOTAL:</td>
                <td class="text-right total-big">
                    S/ {{ number_format($sale->total_amount, 2) }}
                </td>
            </tr>
            <tr>
                <td class="text-right" style="font-size: 11px;">MÉTODO PAGO:</td>
                <td class="text-right" style="font-size: 11px;">
                    {{ strtoupper($sale->payment_method) }}
                </td>
            </tr>
            @if($sale->tax_amount > 0)
                <tr>
                    <td class="text-right" style="font-size: 10px;">Impuestos (Inc.):</td>
                    <td class="text-right" style="font-size: 10px;">
                        S/ {{ number_format($sale->tax_amount, 2) }}
                    </td>
                </tr>
            @endif
        </table>
    </div>

    <div class="footer">
        <p><strong>¡GRACIAS POR SU COMPRA!</strong></p>
        <p><strong>NO SE ACEPTAN CAMBIOS NI DEVOLUCIONES</strong></p>
        <p><strong>***</strong></p>
    </div>

    <script type="text/javascript">
        try {
            window.onload = function () {
                window.print();
            }
        } catch (e) {}
    </script>

</body>
</html>
