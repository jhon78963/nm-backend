<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Ticket {{ $sale->code }}</title>
    <style>
        @page {
            margin: 0;
            size: 80mm 297mm;
        }

        body {
            font-family: 'Courier New', Courier, monospace;
            font-size: 11px;
            margin: 4px;
            color: #000;
        }

        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }

        .font-bold {
            font-weight: bold;
        }

        .uppercase {
            text-transform: uppercase;
        }

        .header {
            margin-bottom: 8px;
            border-bottom: 1px dashed #000;
            padding-bottom: 5px;
        }

        .header h1 {
            font-size: 14px;
            margin: 0;
        }

        .info-tienda {
            font-size: 10px;
            margin-bottom: 4px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 5px;
        }

        th {
            text-align: left;
            border-bottom: 1px solid #000;
            font-size: 9px;
        }

        td {
            padding: 3px 0;
            vertical-align: top;
        }

        .totals {
            margin-top: 10px;
            border-top: 1px dashed #000;
            padding-top: 5px;
        }

        .footer {
            margin-top: 15px;
            font-size: 10px;
            text-align: center;
        }
    </style>
</head>

<body>

    <div class="header text-center">
        <h1 class="uppercase">Novedades Maritex</h1>
        <div class="info-tienda">
            Mercado Mayorista - Puesto C-74<br>
            Trujillo, La Libertad, Perú<br>
            RUC: 10000000000 </div>
        <div>
            <strong>Ticket: {{ $sale->code }}</strong><br>
            Fecha: {{ $sale->creation_time->format('d/m/Y H:i') }}
        </div>
    </div>

    <div style="margin-bottom: 5px; font-size: 10px;">
        @if($sale->customer)
            <strong>Cliente:</strong> {{ $sale->customer->name }} {{ $sale->customer->paternal_surname }}<br>
            <strong>DOC:</strong> {{ $sale->customer->document_number ?? '-' }}
        @else
            <strong>Cliente:</strong> Público General
        @endif
    </div>

    <table>
        <thead>
            <tr>
                <th width="10%">Cant.</th>
                <th width="55%">Desc.</th>
                <th width="35%" class="text-right">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($sale->details as $detail)
                <tr>
                    <td class="text-center">{{ $detail->quantity }}</td>
                    <td>
                        {{ $detail->product_name_snapshot }} | T{{ $detail->size_name_snapshot }} |
                        C{{ $detail->color_name_snapshot }}
                    </td>
                    <td class="text-right">
                        S/ {{ number_format($detail->subtotal, 2) }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals">
        <table style="width: 100%">
            <tr>
                <td class="text-right font-bold">TOTAL:</td>
                <td class="text-right font-bold" style="font-size: 14px;">
                    S/ {{ number_format($sale->total_amount, 2) }}
                </td>
            </tr>
            <tr>
                <td class="text-right" style="font-size: 10px;">Método Pago:</td>
                <td class="text-right" style="font-size: 10px;">
                    {{ $sale->payment_method }}
                </td>
            </tr>
            @if($sale->tax_amount > 0)
                <tr>
                    <td class="text-right" style="font-size: 9px;">Impuestos (Inc.):</td>
                    <td class="text-right" style="font-size: 9px;">
                        S/ {{ number_format($sale->tax_amount, 2) }}
                    </td>
                </tr>
            @endif
        </table>
    </div>

    <div class="footer">
        <p>¡Gracias por su compra!</p>
        <p>NO SE ACEPTAN CAMBIOS NI DEVOLUCIONES</p>
        <p>***</p>
    </div>

    <script type="text/javascript">
        try {
            // Esto abre el diálogo de impresión apenas carga la página
            window.onload = function () {
                window.print();

                // Opcional: Cerrar la ventana automáticamente después de imprimir (funciona en algunos móviles)
                // setTimeout(function() { window.close(); }, 1000);
            }
        } catch (e) {
            // Ignorar errores
        }
    </script>

</body>

</html>
