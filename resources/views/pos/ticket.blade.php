<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ticket {{ $sale->code }}</title>

    <style>
        /* --- CONFIG TICKET 80mm --- */
        html, body {
            margin: 0;
            padding: 0;
            width: 280px; /* 80mm aprox */
            font-family: 'Courier New', monospace;
            font-size: 11px;
            color: #000;
        }

        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .font-bold { font-weight: bold; }
        .uppercase { text-transform: uppercase; }

        .header {
            margin-bottom: 6px;
            border-bottom: 1px dashed #000;
            padding-bottom: 5px;
        }
        .header h1 {
            font-size: 13px;
            margin: 0;
        }
        .info-tienda { font-size: 10px; line-height: 1.2; }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            border-bottom: 1px solid #000;
            font-size: 9px;
            padding-bottom: 2px;
        }

        td {
            padding: 2px 0;
            vertical-align: top;
            line-height: 1.2;
        }

        /* Para evitar saltos en descripción */
        .desc {
            word-break: break-word;
        }

        .totals {
            margin-top: 8px;
            border-top: 1px dashed #000;
            padding-top: 5px;
        }

        .footer {
            margin-top: 10px;
            text-align: center;
            font-size: 10px;
        }
    </style>

</head>
<body>

    <div class="header text-center">
        <h1 class="uppercase">Novedades Maritex</h1>

        <div class="info-tienda">
            Mercado Mayorista - Puesto C-74<br>
            Trujillo - Perú<br>
            RUC: 10000000000
        </div>

        <div>
            <strong>Ticket: {{ $sale->code }}</strong><br>
            Fecha: {{ $sale->creation_time->format('d/m/Y H:i') }}
        </div>
    </div>

    <div style="margin-bottom: 6px; font-size: 10px;">
        @if($sale->customer)
            <strong>Cliente:</strong> {{ $sale->customer->name }} {{ $sale->customer->paternal_surname }}<br>
            <strong>DNI:</strong> {{ $sale->customer->document_number ?? '-' }}
        @else
            <strong>Cliente:</strong> Público General
        @endif
    </div>

    <table>
        <thead>
            <tr>
                <th width="15%">Cant</th>
                <th width="55%">Desc</th>
                <th width="30%" class="text-right">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($sale->details as $detail)
            <tr>
                <td class="text-center">{{ $detail->quantity }}</td>
                <td class="desc">
                    {{ $detail->product_name_snapshot }}
                    T{{ $detail->size_name_snapshot }}
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
        <table>
            <tr>
                <td class="text-right font-bold">TOTAL:</td>
                <td class="text-right font-bold" style="font-size: 14px;">
                    S/ {{ number_format($sale->total_amount, 2) }}
                </td>
            </tr>
            <tr>
                <td class="text-right" style="font-size: 10px;">Pago:</td>
                <td class="text-right" style="font-size: 10px;">
                    {{ $sale->payment_method }}
                </td>
            </tr>
            @if($sale->tax_amount > 0)
            <tr>
                <td class="text-right" style="font-size: 9px;">Impuestos:</td>
                <td class="text-right" style="font-size: 9px;">
                    S/ {{ number_format($sale->tax_amount, 2) }}
                </td>
            </tr>
            @endif
        </table>
    </div>

    <div class="footer">
        ¡Gracias por su compra!<br>
        NO SE ACEPTAN CAMBIOS NI DEVOLUCIONES<br>
        ***
    </div>

</body>
</html>
