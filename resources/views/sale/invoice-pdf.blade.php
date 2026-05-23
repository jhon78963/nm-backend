<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <title>{{ $sale->full_invoice_number ?? $sale->code }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 10pt;
            color: #1a1a1a;
            background: #fff;
        }

        .page { padding: 18mm 15mm 12mm 15mm; }

        /* ── Encabezado ────────────────────────────────── */
        .header { display: table; width: 100%; margin-bottom: 8mm; }
        .header-left  { display: table-cell; width: 65%; vertical-align: top; }
        .header-right { display: table-cell; width: 35%; vertical-align: top; text-align: right; }

        .emisor-name  { font-size: 16pt; font-weight: bold; color: #2d3b8e; }
        .emisor-ruc   { font-size: 9pt; color: #555; margin-top: 2px; }
        .emisor-addr  { font-size: 8pt; color: #777; margin-top: 2px; line-height: 1.4; }

        /* Caja del comprobante */
        .doc-box {
            border: 2px solid #2d3b8e;
            border-radius: 4px;
            padding: 6px 10px;
            text-align: center;
            min-width: 120px;
            display: inline-block;
        }
        .doc-box .doc-type  { font-size: 10pt; font-weight: bold; color: #2d3b8e; text-transform: uppercase; }
        .doc-box .doc-label { font-size: 7pt; color: #888; margin-top: 1px; }
        .doc-box .doc-num   { font-size: 12pt; font-weight: bold; color: #1a1a1a; margin-top: 3px; letter-spacing: 1px; }

        /* ── Separador ─────────────────────────────────── */
        .divider { border: none; border-top: 1px solid #c0c0c0; margin: 4mm 0; }
        .divider-blue { border-color: #2d3b8e; border-width: 2px; }

        /* ── Datos emisión / receptor ──────────────────── */
        .info-grid { display: table; width: 100%; margin-bottom: 5mm; }
        .info-col  { display: table-cell; width: 50%; vertical-align: top; padding-right: 5mm; }
        .info-col:last-child { padding-right: 0; padding-left: 5mm; }

        .label { font-size: 7.5pt; color: #888; text-transform: uppercase; font-weight: bold; margin-bottom: 1px; }
        .value { font-size: 9pt; color: #1a1a1a; }

        /* ── Tabla de ítems ─────────────────────────────── */
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 5mm; }
        .items-table thead tr { background-color: #2d3b8e; }
        .items-table thead th {
            color: #fff;
            font-size: 8pt;
            font-weight: bold;
            padding: 5px 6px;
            text-align: left;
            text-transform: uppercase;
        }
        .items-table tbody tr:nth-child(even) { background-color: #f4f6fb; }
        .items-table tbody td {
            font-size: 9pt;
            padding: 4px 6px;
            border-bottom: 1px solid #e8e8e8;
            vertical-align: top;
        }
        .text-right { text-align: right; }
        .text-center { text-align: center; }

        /* ── Totales ────────────────────────────────────── */
        .totals-table { width: 55%; float: right; border-collapse: collapse; }
        .totals-table td { font-size: 9pt; padding: 3px 6px; }
        .totals-table td:first-child { color: #555; text-align: right; }
        .totals-table td:last-child  { text-align: right; font-weight: bold; min-width: 70px; }
        .totals-table .total-row td  { font-size: 11pt; color: #2d3b8e; border-top: 2px solid #2d3b8e; padding-top: 5px; }

        .clearfix::after { content: ''; display: table; clear: both; }

        /* ── Footer / leyenda ───────────────────────────── */
        .footer {
            margin-top: 8mm;
            font-size: 8pt;
            color: #777;
            border-top: 1px solid #ddd;
            padding-top: 4mm;
        }
        .footer-grid { display: table; width: 100%; }
        .footer-left  { display: table-cell; width: 70%; vertical-align: top; }
        .footer-right { display: table-cell; width: 30%; text-align: right; vertical-align: top; }

        /* Estado SUNAT */
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 8pt;
            font-weight: bold;
            text-transform: uppercase;
        }
        .badge-accepted { background: #d4edda; color: #155724; }
        .badge-pending  { background: #fff3cd; color: #856404; }
        .badge-rejected { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>
<div class="page">

    {{-- ── ENCABEZADO ─────────────────────────────────────────────────── --}}
    <div class="header">
        <div class="header-left">
            <div class="emisor-name">{{ config('sunat.nombre_comercial') ?? config('sunat.razon_social') }}</div>
            <div class="emisor-ruc">{{ config('sunat.razon_social') }} · RUC {{ config('sunat.ruc') }}</div>
            <div class="emisor-addr">
                {{ config('sunat.address.direccion') }}<br>
                {{ config('sunat.address.distrito') }}, {{ config('sunat.address.provincia') }}, {{ config('sunat.address.departamento') }}
            </div>
        </div>
        <div class="header-right">
            <div class="doc-box">
                <div class="doc-type">
                    @if($sale->document_type === 'FACTURA') Factura Electrónica
                    @elseif($sale->document_type === 'BOLETA') Boleta Electrónica
                    @else Ticket Interno @endif
                </div>
                <div class="doc-label">Comprobante N.°</div>
                <div class="doc-num">{{ $sale->full_invoice_number ?? $sale->code }}</div>
            </div>
        </div>
    </div>

    <hr class="divider divider-blue">

    {{-- ── DATOS DE EMISIÓN Y RECEPTOR ────────────────────────────────── --}}
    <div class="info-grid">
        <div class="info-col">
            <div class="label">Fecha de emisión</div>
            <div class="value">{{ $sale->creation_time->format('d/m/Y H:i') }}</div>

            <div class="label" style="margin-top:4px">Estado SUNAT</div>
            <div class="value">
                @php
                    $badgeClass = match($sale->sunat_status) {
                        'ACCEPTED' => 'badge-accepted',
                        'REJECTED' => 'badge-rejected',
                        default    => 'badge-pending',
                    };
                @endphp
                <span class="badge {{ $badgeClass }}">{{ $sale->sunat_status ?? 'N/A' }}</span>
            </div>
        </div>
        <div class="info-col">
            <div class="label">Cliente</div>
            <div class="value">{{ $sale->customer?->name ?? 'PÚBLICO GENERAL' }}</div>
            @if($sale->customer?->document_number)
                <div class="label" style="margin-top:4px">
                    {{ strtoupper($sale->customer->document_type ?? 'Doc') }}
                </div>
                <div class="value">{{ $sale->customer->document_number }}</div>
            @endif
        </div>
    </div>

    <hr class="divider">

    {{-- ── TABLA DE ÍTEMS ──────────────────────────────────────────────── --}}
    <table class="items-table">
        <thead>
            <tr>
                <th class="text-center" style="width:7%">Cant.</th>
                <th style="width:47%">Descripción</th>
                <th style="width:13%">Unid.</th>
                <th class="text-right" style="width:13%">V. Unit.</th>
                <th class="text-right" style="width:10%">IGV</th>
                <th class="text-right" style="width:10%">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($sale->details as $detail)
                @php
                    $pvp          = (float) $detail->unit_price;
                    $valorUnit    = round($pvp / 1.18, 2);
                    $igvUnit      = round($pvp - $valorUnit, 2);
                    $lineTotal    = round($pvp * $detail->quantity, 2);
                @endphp
                <tr>
                    <td class="text-center">{{ $detail->quantity }}</td>
                    <td>
                        {{ $detail->product_name_snapshot }}
                        <br><small style="color:#777">T: {{ $detail->size_name_snapshot }} &nbsp; C: {{ $detail->color_name_snapshot }}</small>
                    </td>
                    <td>NIU</td>
                    <td class="text-right">{{ number_format($valorUnit, 2) }}</td>
                    <td class="text-right">{{ number_format($igvUnit * $detail->quantity, 2) }}</td>
                    <td class="text-right">{{ number_format($lineTotal, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- ── TOTALES ─────────────────────────────────────────────────────── --}}
    <div class="clearfix">
        <table class="totals-table">
            <tr>
                <td>Op. Gravadas (Base IGV):</td>
                <td>S/ {{ number_format((float)$sale->taxable_base, 2) }}</td>
            </tr>
            <tr>
                <td>IGV (18%):</td>
                <td>S/ {{ number_format((float)$sale->igv_amount, 2) }}</td>
            </tr>
            <tr class="total-row">
                <td><strong>TOTAL A PAGAR:</strong></td>
                <td><strong>S/ {{ number_format((float)$sale->total_amount, 2) }}</strong></td>
            </tr>
        </table>
    </div>

    {{-- ── FOOTER ──────────────────────────────────────────────────────── --}}
    <div class="footer">
        <div class="footer-grid">
            <div class="footer-left">
                <strong>Representación Impresa de Comprobante Electrónico</strong><br>
                Consulte su comprobante en: <em>https://ww1.sunat.gob.pe/ol-ti-itconsultaunificadalibre</em><br><br>
                @if($sale->sunat_status === 'ACCEPTED')
                    ✓ Comprobante aceptado por SUNAT. Número: <strong>{{ $sale->full_invoice_number }}</strong>
                @else
                    Este documento es representación impresa de un comprobante en proceso de validación SUNAT.
                @endif
            </div>
            <div class="footer-right">
                Emitido: {{ now()->format('d/m/Y H:i') }}<br>
                Código: {{ $sale->code }}
            </div>
        </div>
    </div>

</div>
</body>
</html>
