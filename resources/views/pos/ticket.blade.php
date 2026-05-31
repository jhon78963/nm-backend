<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>{{ $sale->full_invoice_number ?? $sale->code }}</title>
    <style>
        @page {
            margin: 0;
            size: 80mm auto;
        }

        * { box-sizing: border-box; }

        body {
            font-family: 'Arial Narrow', Arial, sans-serif;
            font-size: 9.5px;
            margin: 3mm 3mm 6mm 3mm;
            color: #000;
            background: #fff;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* ── Utilidades ──────────────────────────── */
        .tc   { text-align: center; }
        .tr   { text-align: right; }
        .tl   { text-align: left; }
        .bold { font-weight: bold; }
        .upper { text-transform: uppercase; }
        .small { font-size: 8px; }
        .mt2  { margin-top: 2px; }
        .mt4  { margin-top: 4px; }
        .mt6  { margin-top: 6px; }

        /* ── Separadores ─────────────────────────── */
        .line-solid  { border: none; border-top: 1.5px solid #000; margin: 4px 0; }
        .line-dashed { border: none; border-top: 1.5px dashed  #000; margin: 4px 0; }

        /* ── Encabezado / branding ───────────────── */
        .header       { text-align: center; padding-bottom: 4px; }
        .logo-img {
            width: 150px;
            max-width: 40mm;
            max-height: 18mm;
            height: auto;
            display: block;
            margin: 0 auto 4px auto;
            filter: grayscale(100%) brightness(0);
            -webkit-filter: grayscale(100%) brightness(0);
            background: transparent;
        }
        .company-name { font-size: 14px; font-weight: 900; letter-spacing: 0.5px; text-transform: uppercase; }
        .company-legal { font-size: 8px; margin-top: 1px; }
        .header-addr  { font-size: 8px; margin-top: 3px; line-height: 1.5; }
        .header-contact { font-size: 8px; margin-top: 2px; line-height: 1.6; }
        .social-line  { font-size: 7.5px; margin-top: 1px; }

        /* ── Caja tipo documento ─────────────────── */
        .doc-box {
            border: 2px solid #000;
            border-radius: 3px;
            text-align: center;
            padding: 5px 6px;
            margin: 5px 0;
        }
        .doc-box .ruc-label { font-size: 9px; font-weight: bold; margin-bottom: 2px; }
        .doc-box .doc-type  { font-size: 11px; font-weight: 900; text-transform: uppercase; letter-spacing: 0.5px; }
        .doc-box .doc-num   { font-size: 13px; font-weight: 900; margin-top: 3px; letter-spacing: 1.5px; }

        /* ── Sección cliente ─────────────────────── */
        .client-section { font-size: 9px; margin: 4px 0; }
        .client-section .row {
            display: flex;
            justify-content: space-between;
            margin-top: 2px;
        }
        .client-section .lbl { font-weight: bold; white-space: nowrap; margin-right: 4px; }
        .client-section .val { flex: 1; word-break: break-word; }

        /* ── Tabla de ítems ──────────────────────── */
        .items-table { width: 100%; border-collapse: collapse; margin: 4px 0; }
        .items-table thead th {
            font-size: 8.5px;
            font-weight: 900;
            border-top: 1.5px solid #000;
            border-bottom: 1.5px solid #000;
            padding: 3px 2px;
            text-transform: uppercase;
        }
        .items-table tbody td {
            font-size: 9px;
            padding: 3px 2px;
            vertical-align: top;
            border-bottom: 1px dashed #bbb;
        }
        .items-table tbody tr:last-child td { border-bottom: none; }

        /* ── Totales ─────────────────────────────── */
        .totals-table { width: 100%; border-collapse: collapse; margin-top: 2px; }
        .totals-table td { font-size: 9px; padding: 2px; }
        .totals-table td:first-child { text-align: right; padding-right: 6px; }
        .totals-table td:last-child  { text-align: right; white-space: nowrap; min-width: 18mm; }
        .totals-table .grand-total td {
            font-size: 12px;
            font-weight: 900;
            border-top: 1.5px solid #000;
            padding-top: 4px;
        }

        /* ── Monto en letras ─────────────────────── */
        .amount-words {
            font-size: 8px;
            font-weight: bold;
            text-align: center;
            margin: 4px 0;
            line-height: 1.4;
        }

        /* ── Pagos ───────────────────────────────── */
        .payments-section { font-size: 8.5px; margin: 3px 0; }
        .payments-section .pay-row { display: flex; justify-content: space-between; padding: 1px 0; }

        /* ── QR ──────────────────────────────────── */
        .qr-area { text-align: center; margin: 5px auto 3px auto; }
        .qr-area img { width: 38mm; height: 38mm; display: block; margin: 0 auto; }
        .qr-hash {
            font-size: 6.5px;
            color: #444;
            word-break: break-all;
            text-align: center;
            margin: 2px 0 4px 0;
            line-height: 1.4;
        }

        /* ── Pie de página ───────────────────────── */
        .footer {
            font-size: 8px;
            text-align: center;
            margin-top: 6px;
            line-height: 1.6;
        }

        drawer { display: none; }

        @media print {
            html, body { width: 80mm !important; max-width: 80mm !important; margin: 0 !important; }
        }
    </style>
</head>

<body>
    <drawer></drawer>

    @php
        $isFactura = $sale->document_type === 'FACTURA';
        $isBoleta  = $sale->document_type === 'BOLETA';
        $isFiscal  = $isFactura || $isBoleta;

        // ── Datos del tenant (prioridad: BD → fallback .env) ─────────────────
        $ts = $tenantSetting; // puede ser null

        $ruc          = $ts?->ruc             ?: config('sunat.ruc');
        $legalName    = $ts?->legal_name      ?: config('sunat.razon_social');
        $tradeName    = $ts?->trade_name      ?: config('sunat.nombre_comercial') ?: $legalName;
        $address      = $ts?->address         ?: config('sunat.address.direccion');
        $district     = $ts?->district        ?: config('sunat.address.distrito');
        $province     = $ts?->province        ?: config('sunat.address.provincia');
        $department   = $ts?->department      ?: config('sunat.address.departamento');
        $phone        = $ts?->phone;
        $email        = $ts?->email;
        $website      = $ts?->website;
        $facebook     = $ts?->social('facebook');
        $instagram    = $ts?->social('instagram');
        $tiktok       = $ts?->social('tiktok');
        $logoUrl      = $ts?->logo_url;
        $footerNote   = $ts?->ticket_footer_note ?: 'NO SE ACEPTAN CAMBIOS NI DEVOLUCIONES';

        // ── Totales ──────────────────────────────────────────────────────────
        $total       = (float) $sale->total_amount;
        $taxableBase = (float) ($sale->taxable_base ?? ($total / 1.18));
        $igv         = (float) ($sale->igv_amount   ?? ($total - $taxableBase));

        // ── Monto en letras ──────────────────────────────────────────────────
        function _tkt_ones(int $n): string {
            return ['','UNO','DOS','TRES','CUATRO','CINCO','SEIS','SIETE','OCHO','NUEVE',
                    'DIEZ','ONCE','DOCE','TRECE','CATORCE','QUINCE','DIECISÉIS','DIECISIETE',
                    'DIECIOCHO','DIECINUEVE','VEINTE'][$n] ?? '';
        }
        function _tkt_tens(int $n): string {
            if ($n <= 20) return _tkt_ones($n);
            $t = ['','','VEINTE','TREINTA','CUARENTA','CINCUENTA','SESENTA','SETENTA','OCHENTA','NOVENTA'];
            return $t[intdiv($n,10)] . ($n%10 ? ' Y ' . _tkt_ones($n%10) : '');
        }
        function _tkt_hundreds(int $n): string {
            if ($n === 0)   return '';
            if ($n === 100) return 'CIEN';
            $h = ['','CIENTO','DOSCIENTOS','TRESCIENTOS','CUATROCIENTOS','QUINIENTOS',
                  'SEISCIENTOS','SETECIENTOS','OCHOCIENTOS','NOVECIENTOS'];
            return $h[intdiv($n,100)] . ($n%100 ? ' ' . _tkt_tens($n%100) : '');
        }
        function _tkt_int2words(int $n): string {
            if ($n === 0) return 'CERO';
            $p = [];
            if ($n >= 1000000) { $m = intdiv($n,1000000); $p[] = ($m===1?'UN MILLÓN':_tkt_hundreds($m).' MILLONES'); $n %= 1000000; }
            if ($n >= 1000)    { $k = intdiv($n,1000);    $p[] = ($k===1?'MIL':_tkt_hundreds($k).' MIL'); $n %= 1000; }
            if ($n > 0)        { $p[] = _tkt_hundreds($n); }
            return implode(' ', array_filter($p));
        }
        $parts         = explode('.', number_format($total, 2, '.', ''));
        $amountInWords = _tkt_int2words((int)$parts[0]) . ' CON ' . ($parts[1]??'00') . '/100 SOLES';

        // ── Pagos ────────────────────────────────────────────────────────────
        $payments = $sale->relationLoaded('payments') ? $sale->payments : collect();

        function _tkt_payment_label(?string $method): string {
            return match (strtoupper(trim((string) $method))) {
                'CASH'  => 'CONTADO',
                'CARD'  => 'TARJETA',
                'YAPE'  => 'YAPE',
                'PLIN'  => 'PLIN',
                'MIXTO' => 'MIXTO',
                default => strtoupper(trim((string) $method)) ?: 'CONTADO',
            };
        }

        /** Renderiza email con &#64; para evitar ofuscación de Cloudflare/proxies en producción. */
        function _tkt_email_html(?string $email): string {
            if (empty($email)) {
                return '';
            }
            $parts = explode('@', $email, 2);
            if (count($parts) !== 2) {
                return e($email);
            }

            return e($parts[0]) . '&#64;' . e($parts[1]);
        }

        $emailHtml = _tkt_email_html($email);

        // ── Número de serie formateado ────────────────────────────────────────
        // Ej: full_invoice_number = "F001-00005"  → serie "F001", correl "00005"
        $fullNumber = $sale->full_invoice_number ?? $sale->code;
    @endphp

    {{-- ══════════════════════════════════════════════════ --}}
    {{-- ENCABEZADO                                         --}}
    {{-- ══════════════════════════════════════════════════ --}}
    <div class="header">
        @if(!empty($logoUrl))
            <img class="logo-img" src="{{ $logoUrl }}" alt="{{ $tradeName }}" width="150">
        @endif
        <div class="company-name">{{ $tradeName }}</div>
        @if($legalName && $legalName !== $tradeName)
            <div class="company-legal">{{ strtoupper($legalName) }}</div>
        @endif
        <div class="header-addr">
            {{ strtoupper($address) }}<br>
            {{ strtoupper($district) }}, {{ strtoupper($province) }}, {{ strtoupper($department) }}
        </div>
        @if($phone || $email)
            <div class="header-contact">
                @if($phone)
                    Tel: {{ $phone }}
                @endif
                @if($phone && $email)
                    &nbsp;|&nbsp;
                @endif
                @if($email)
                    {!! $emailHtml !!}
                @endif
            </div>
        @endif
        @if($website)
            <div class="social-line">{{ $website }}</div>
        @endif
        @if($facebook || $instagram || $tiktok)
            <div class="social-line">
                @if($facebook)  FB: {{ $facebook }} @endif
                @if($instagram) @if($facebook) &nbsp;·&nbsp; @endif IG: {{ $instagram }} @endif
                @if($tiktok)    @if($facebook||$instagram) &nbsp;·&nbsp; @endif TK: {{ $tiktok }} @endif
            </div>
        @endif
    </div>

    <hr class="line-solid">

    {{-- ══════════════════════════════════════════════════ --}}
    {{-- CAJA TIPO DE DOCUMENTO                             --}}
    {{-- ══════════════════════════════════════════════════ --}}
    <div class="doc-box">
        @if($isFiscal && $ruc)
            <div class="ruc-label">RUC: {{ $ruc }}</div>
        @endif
        @if($isFactura)
            <div class="doc-type">Factura Electrónica</div>
        @elseif($isBoleta)
            <div class="doc-type">Boleta de Venta Electrónica</div>
        @else
            <div class="doc-type">Ticket de Venta</div>
        @endif
        <div class="doc-num">{{ $fullNumber }}</div>
    </div>

    {{-- ══════════════════════════════════════════════════ --}}
    {{-- DATOS DE EMISIÓN Y CLIENTE                         --}}
    {{-- ══════════════════════════════════════════════════ --}}
    <div class="client-section">
        <div class="row">
            <span class="lbl">FECHA EMISIÓN:</span>
            <span class="val">{{ $sale->creation_time->format('d/m/Y H:i') }}</span>
        </div>
        <div class="row">
            <span class="lbl">CONDICIÓN PAGO:</span>
            <span class="val">CONTADO</span>
        </div>
        @if($sale->customer && $sale->customer->document_number)
            <div class="row">
                <span class="lbl">{{ $isFactura ? 'SEÑORES:' : 'CLIENTE:' }}</span>
                <span class="val">{{ strtoupper($sale->customer->name) }}</span>
            </div>
            <div class="row">
                <span class="lbl">{{ strtoupper($sale->customer->document_type ?? 'DOC') }}:</span>
                <span class="val">{{ $sale->customer->document_number }}</span>
            </div>
        @else
            <div class="row">
                <span class="lbl">CLIENTE:</span>
                <span class="val">CLIENTES VARIOS</span>
            </div>
            @if($isBoleta)
                <div class="row">
                    <span class="lbl">DNI:</span>
                    <span class="val">00000000</span>
                </div>
            @endif
        @endif
        <div class="row">
            <span class="lbl">MONEDA:</span>
            <span class="val">SOLES (PEN)</span>
        </div>
    </div>

    <hr class="line-dashed">

    {{-- ══════════════════════════════════════════════════ --}}
    {{-- TABLA DE ÍTEMS                                     --}}
    {{-- ══════════════════════════════════════════════════ --}}
    <table class="items-table">
        <thead>
            <tr>
                <th class="tc"  style="width:10%">CANT.</th>
                <th class="tl"  style="width:55%">DESCRIPCIÓN</th>
                <th class="tr"  style="width:16%">P.UNIT.</th>
                <th class="tr"  style="width:19%">IMPORTE</th>
            </tr>
        </thead>
        <tbody>
            @foreach($sale->details as $detail)
                @php
                    $pvp      = (float) $detail->unit_price;
                    $lineTotal = (float) $detail->subtotal;
                @endphp
                <tr>
                    <td class="tc bold">{{ $detail->quantity }}</td>
                    <td class="tl">
                        <span class="bold">{{ $detail->product_name_snapshot }}</span><br>
                        <span class="small">T:{{ $detail->size_name_snapshot }} C:{{ $detail->color_name_snapshot }}</span>
                    </td>
                    <td class="tr">{{ number_format($pvp, 2) }}</td>
                    <td class="tr bold">{{ number_format($lineTotal, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <hr class="line-dashed">

    {{-- ══════════════════════════════════════════════════ --}}
    {{-- TOTALES (estilo voucher SUNAT)                     --}}
    {{-- ══════════════════════════════════════════════════ --}}
    <table class="totals-table">
        @if($isFiscal)
            <tr>
                <td>OP. GRAVADAS:</td>
                <td>S/ {{ number_format($taxableBase, 2) }}</td>
            </tr>
            <tr>
                <td>OP. EXONERADAS:</td>
                <td>S/ 0.00</td>
            </tr>
            <tr>
                <td>OP. INAFECTAS:</td>
                <td>S/ 0.00</td>
            </tr>
            <tr>
                <td>OP. GRATUITAS:</td>
                <td>S/ 0.00</td>
            </tr>
            <tr>
                <td>SUBTOTAL:</td>
                <td>S/ {{ number_format($taxableBase, 2) }}</td>
            </tr>
            <tr>
                <td>DESCUENTOS:</td>
                <td>S/ 0.00</td>
            </tr>
            <tr>
                <td>IGV 18.0%:</td>
                <td>S/ {{ number_format($igv, 2) }}</td>
            </tr>
            <tr>
                <td>ICBPER:</td>
                <td>S/ 0.00</td>
            </tr>
            <tr>
                <td>ADELANTOS:</td>
                <td>S/ 0.00</td>
            </tr>
        @endif
        <tr class="grand-total">
            <td class="bold">TOTAL:</td>
            <td class="bold">S/ {{ number_format($total, 2) }}</td>
        </tr>
    </table>

    <hr class="line-dashed">

    {{-- SON: en letras ──────────────────────────────────── --}}
    <div class="amount-words">
        SON: {{ $amountInWords }}
    </div>

    <hr class="line-dashed">

    {{-- ══════════════════════════════════════════════════ --}}
    {{-- FORMA DE PAGO                                      --}}
    {{-- ══════════════════════════════════════════════════ --}}
    <div class="payments-section">
        @if($payments->isNotEmpty())
            <div class="bold" style="margin-bottom:2px;">FORMA DE PAGO:</div>
            @foreach($payments as $pay)
                <div class="pay-row">
                    <span>{{ _tkt_payment_label($pay->method ?? $pay->payment_method) }}</span>
                    <span class="bold">S/ {{ number_format((float)($pay->amount ?? 0), 2) }}</span>
                </div>
            @endforeach
        @else
            <div class="pay-row">
                <span class="bold">FORMA DE PAGO:</span>
                <span>{{ _tkt_payment_label($sale->payment_method) }}</span>
            </div>
        @endif
    </div>

    <hr class="line-solid">

    {{-- ══════════════════════════════════════════════════ --}}
    {{-- QR SUNAT                                           --}}
    {{-- ══════════════════════════════════════════════════ --}}
    @if($isFiscal && !empty($qrSvg))
        <div class="qr-area">
            <img src="{{ $qrSvg }}" alt="QR SUNAT">
        </div>
        @if(!empty($xmlHash))
            <div class="qr-hash">
                <strong>Resumen:</strong> {{ $xmlHash }}
            </div>
        @endif
    @endif

    {{-- ══════════════════════════════════════════════════ --}}
    {{-- PIE DE PÁGINA                                      --}}
    {{-- ══════════════════════════════════════════════════ --}}
    <div class="footer">
        @if($isFiscal)
            <strong>Representación Impresa de {{ $isFactura ? 'FACTURA' : 'BOLETA DE VENTA' }} ELECTRÓNICA</strong><br>
            Autorizado mediante Res. N° 014-005-0001495/SUNAT<br>
            Consulte su comprobante en: <em>ww1.sunat.gob.pe</em>
        @else
            <strong>*** TICKET INTERNO — NO ES COMP. DE PAGO ***</strong>
        @endif
        <br><br>
        <strong>¡GRACIAS POR SU PREFERENCIA!</strong><br>
        <span class="small">{{ $footerNote }}</span>
    </div>

</body>
</html>
