<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Ticket {{ $sale->code }}</title>
    <style>
        @page {
            margin: 0;
            size: 80mm auto;
        }

        body {
            /* Sans-serif se lee mejor en térmicas que Courier */
            font-family: sans-serif, system-ui, -apple-system, BlinkMacSystemFont;
            font-size: 13px; /* Tamaño ideal para 80mm */
            margin: 1mm 2mm;
            color: #000000;
            background-color: #ffffff;
            font-weight: 900; /* Negrita extrema para nitidez */
            -webkit-print-color-adjust: exact;
        }

        /* Utilidades */
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .uppercase { text-transform: uppercase; }
        .bold { font-weight: 900; }

        /* Encabezado */
        .header {
            margin-bottom: 10px;
            border-bottom: 2px dashed #000;
            padding-bottom: 5px;
        }

        .header h1 {
            font-size: 18px; /* Título más grande */
            margin: 0;
            font-weight: 900;
        }

        .info-tienda {
            font-size: 12px;
            margin-bottom: 5px;
            line-height: 1.2;
        }

        /* Tablas */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 5px;
        }

        th {
            text-align: left;
            border-bottom: 2px solid #000;
            font-size: 11px;
            font-weight: 900;
            padding-bottom: 2px;
        }

        td {
            padding: 4px 0;
            vertical-align: top;
            font-weight: 900;
        }

        /* Totales */
        .totals {
            margin-top: 10px;
            border-top: 2px dashed #000;
            padding-top: 5px;
        }

        .total-row {
            font-size: 18px;
            font-weight: 900;
        }

        .footer {
            margin-top: 15px;
            font-size: 11px;
            text-align: center;
            font-weight: 900;
            margin-bottom: 20px; /* Espacio extra al final para el corte */
        }
    </style>
</head>

<body>

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
                <td class="text-right bold">TOTAL:</td>
                <td class="text-right total-row">
                    S/ {{ number_format($sale->total_amount, 2) }}
                </td>
            </tr>
            <tr>
                <td class="text-right" style="font-size: 11px;">MÉTODO PAGO:</td>
                <td class="text-right" style="font-size: 11px;">
                    {{ strtoupper($sale->payment_method) }}
                </td>
            </tr>
        </table>
    </div>

    <div class="footer">
        <p>¡GRACIAS POR SU COMPRA!</p>
        <p>NO SE ACEPTAN CAMBIOS NI DEVOLUCIONES</p>
    </div>

    {{--
      BLOQUE DE APERTURA DE GAVETA (SOLUCIÓN HÍBRIDA)
      1. Enviamos el código HEX directo con PHP.
      2. Agregamos un texto oculto por si la app necesita leer texto.
    --}}

    <div style="height:0px; overflow:hidden;">
        {!! chr(27).chr(112).chr(0).chr(25).chr(250) !!}
    </div>

    <div style="font-size: 1px; color: #ffffff;">##CAJA##</div>

    <script type="text/javascript">
        try {
            window.onload = function () {
                window.print();
            }
        } catch (e) {}
    </script>

</body>
</html>
