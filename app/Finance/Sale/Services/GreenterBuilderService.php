<?php

declare(strict_types=1);

namespace App\Finance\Sale\Services;

use App\Directory\Customer\Models\Customer;
use App\Finance\Sale\Models\Sale;
use App\Finance\Sale\Models\SaleDetail as SaleDetailModel;
use Greenter\Model\Client\Client;
use Greenter\Model\Company\Address;
use Greenter\Model\Company\Company;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\Legend;
use Greenter\Model\Sale\SaleDetail;
use InvalidArgumentException;

/**
 * Transforma una venta de nuestra BD al grafo de objetos que Greenter necesita
 * para construir el XML UBL 2.1 firmado y enviarlo a SUNAT.
 *
 * Catálogos SUNAT utilizados:
 *  - Cat. 01  tipoDoc:  01 = Factura, 03 = Boleta
 *  - Cat. 06  tipoDoc cliente: '6' = RUC, '1' = DNI, '0' = Doc. Extranjero, '-' = Sin doc.
 *  - Cat. 07  tipAfeIgv: '10' = Gravado – Operación onerosa
 *  - Cat. 51  tipoOperacion: '0101' = Venta interna
 *  - Cat. 52  tipoMoneda: 'PEN'
 *  - Leyenda  '1000' = Monto total en letras (obligatoria)
 */
class GreenterBuilderService
{
    private const IGV_RATE      = 18.0;          // porcentaje que espera Greenter
    private const IGV_FACTOR    = 0.18;           // para cálculos internos
    private const TIP_AFE_IGV   = '10';           // Gravado – Onerosa (Cat. 07)
    private const TIPO_OPERACION = '0101';         // Venta interna (Cat. 51)
    private const TIPO_MONEDA    = 'PEN';
    private const BOLETA_700    = 700.0;          // umbral para clientes sin doc en BOLETA
    private const UBL_VERSION   = '2.1';

    /** Tipos de documento de identidad en cat. 06 SUNAT */
    private const DOC_TYPE_MAP = [
        'RUC' => '6',
        'DNI' => '1',
        'CE'  => '4',
        'PAS' => '7',
    ];

    /**
     * Construye la instancia `Invoice` de Greenter lista para firmar/enviar.
     *
     * @param  Sale $sale  Venta con `details` y `customer` ya cargados (eager load).
     * @throws InvalidArgumentException Si faltan campos fiscales obligatorios.
     */
    public function buildInvoice(Sale $sale): Invoice
    {
        $this->assertFiscalFieldsPresent($sale);

        $documentType = (string) $sale->document_type;
        $total        = (float) $sale->total_amount;

        // ── LÍNEAS ──────────────────────────────────────────────────────────
        // Recalculamos cada detalle desde su precio PVP (precio con IGV incluido).
        // Sumamos para obtener los totales del cabecera desde abajo hacia arriba,
        // evitando diferencias de redondeo entre la cabecera y la suma de líneas.
        $greenterDetails = [];
        $sumValorVenta   = 0.0;   // suma de valorUnitario × qty  (sin IGV)
        $sumIgv          = 0.0;   // suma de igv por línea

        $sale->loadMissing('details');

        /** @var SaleDetailModel $detail */
        foreach ($sale->details as $detail) {
            [$lineDetail, $lineValor, $lineIgv] = $this->buildDetail($detail);
            $greenterDetails[] = $lineDetail;
            $sumValorVenta    += $lineValor;
            $sumIgv           += $lineIgv;
        }

        $sumValorVenta = round($sumValorVenta, 2);
        $sumIgv        = round($sumIgv, 2);
        $mtoImpVenta   = round($total, 2);           // importe total (PVP)

        // ── EMISOR ──────────────────────────────────────────────────────────
        $company = $this->buildCompany();

        // ── CLIENTE ─────────────────────────────────────────────────────────
        $sale->loadMissing('customer');
        $client = $this->buildClient($documentType, $sale->customer, $total);

        // ── TIPO DE COMPROBANTE (Cat. 01) ────────────────────────────────────
        // 01 = Factura, 03 = Boleta de Venta
        $tipoDoc = $documentType === 'FACTURA' ? '01' : '03';

        // ── LEYENDA (importe en letras – obligatoria) ────────────────────────
        $legend = (new Legend())
            ->setCode('1000')
            ->setValue($this->numberToWords($mtoImpVenta));

        // ── INVOICE ─────────────────────────────────────────────────────────
        $invoice = (new Invoice())
            ->setUblVersion(self::UBL_VERSION)
            ->setTipoOperacion(self::TIPO_OPERACION)
            ->setTipoDoc($tipoDoc)
            ->setSerie((string) $sale->serie)
            ->setCorrelativo((string) $sale->correlativo)
            ->setFechaEmision($sale->creation_time)
            ->setTipoMoneda(self::TIPO_MONEDA)
            ->setCompany($company)
            ->setClient($client)
            // Totales cabecera
            ->setMtoOperGravadas($sumValorVenta)
            ->setMtoIGV($sumIgv)
            ->setTotalImpuestos($sumIgv)
            ->setValorVenta($sumValorVenta)
            ->setSubTotal($mtoImpVenta)
            ->setMtoImpVenta($mtoImpVenta)
            // Líneas e ítems
            ->setDetails($greenterDetails)
            ->setLegends([$legend]);

        return $invoice;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVADOS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Construye un SaleDetail de Greenter a partir de un detalle de nuestra BD.
     *
     * La lógica de desglose:
     *   PVP (precio con IGV)  → precio que el cliente paga
     *   valorUnitario         = PVP / 1.18   (precio sin IGV)
     *   igvUnitario           = PVP - valorUnitario
     *   mtoBaseIgv            = valorUnitario × qty   (suma sin IGV de la línea)
     *   igvLinea              = igvUnitario  × qty
     *   mtoValorVenta         = mtoBaseIgv
     *
     * @return array{0: SaleDetail, 1: float, 2: float}  [objeto, valorVenta, igv]
     */
    private function buildDetail(SaleDetailModel $detail): array
    {
        $qty        = (float) $detail->quantity;
        $pvp        = round((float) $detail->unit_price, 10);  // precio con IGV

        $valorUnitario = round($pvp / (1 + self::IGV_FACTOR), 10);
        $igvUnitario   = round($pvp - $valorUnitario, 10);

        $mtoBaseIgv    = round($valorUnitario * $qty, 2);
        $igvLinea      = round($igvUnitario   * $qty, 2);
        $mtoValorVenta = $mtoBaseIgv;                         // = qty × valorUnitario
        $totalImpLinea = $igvLinea;

        $sku = (string) ($detail->sku_snapshot ?? $detail->id);

        $gDetail = (new SaleDetail())
            ->setCodProducto($sku)
            ->setUnidad('NIU')                                // Unidades (Cat. 03-UoM)
            ->setCantidad($qty)
            ->setDescripcion((string) $detail->product_name_snapshot)
            ->setMtoValorUnitario($valorUnitario)
            ->setMtoPrecioUnitario($pvp)
            ->setMtoValorVenta($mtoValorVenta)
            // IGV línea (Cat. 07 → '10' Gravado oneroso)
            ->setMtoBaseIgv($mtoBaseIgv)
            ->setPorcentajeIgv(self::IGV_RATE)
            ->setIgv($igvLinea)
            ->setTipAfeIgv(self::TIP_AFE_IGV)
            ->setTotalImpuestos($totalImpLinea);

        return [$gDetail, $mtoValorVenta, $igvLinea];
    }

    /**
     * Construye el emisor leyendo `config/sunat.php`.
     */
    private function buildCompany(): Company
    {
        $cfg = (array) config('sunat');
        $addr = (array) ($cfg['address'] ?? []);

        $address = (new Address())
            ->setUbigueo((string) ($addr['ubigeo']       ?? '150101'))
            ->setCodigoPais((string) ($addr['codigo_pais'] ?? 'PE'))
            ->setDepartamento((string) ($addr['departamento'] ?? ''))
            ->setProvincia((string) ($addr['provincia']   ?? ''))
            ->setDistrito((string) ($addr['distrito']     ?? ''))
            ->setDireccion((string) ($addr['direccion']   ?? '-'));

        return (new Company())
            ->setRuc((string) ($cfg['ruc']              ?? ''))
            ->setRazonSocial((string) ($cfg['razon_social']  ?? ''))
            ->setNombreComercial((string) ($cfg['nombre_comercial'] ?? ''))
            ->setAddress($address);
    }

    /**
     * Construye el receptor del comprobante.
     *
     * Reglas SUNAT:
     *  - FACTURA: tipoDoc='6' (RUC), numDoc=11 dígitos, rznSocial obligatorio.
     *  - BOLETA < 700 PEN sin cliente: tipoDoc='-', numDoc='-', rznSocial='-'.
     *  - BOLETA ≥ 700 PEN sin cliente: SUNAT exige dni/ruc; aquí se usa '-' con '0'
     *    (Documento de Identidad del Exterior) como fallback — en la práctica el
     *    cajero debe identificar al cliente antes de emitir por encima del umbral.
     *  - BOLETA con DNI: tipoDoc='1'.
     */
    private function buildClient(
        string $documentType,
        ?Customer $customer,
        float $total,
    ): Client {
        if ($documentType === 'FACTURA') {
            return (new Client())
                ->setTipoDoc('6')
                ->setNumDoc((string) ($customer?->document_number ?? ''))
                ->setRznSocial((string) ($customer?->name ?? ''));
        }

        // BOLETA
        if ($customer === null) {
            // Sin cliente identificado
            $tipoDoc = ($total < self::BOLETA_700) ? '-' : '0';
            $numDoc  = ($total < self::BOLETA_700) ? '-' : '0000000';

            return (new Client())
                ->setTipoDoc($tipoDoc)
                ->setNumDoc($numDoc)
                ->setRznSocial('-');
        }

        $rawType   = strtoupper(trim((string) ($customer->document_type ?? 'DNI')));
        $sunatType = self::DOC_TYPE_MAP[$rawType] ?? '1';

        return (new Client())
            ->setTipoDoc($sunatType)
            ->setNumDoc((string) ($customer->document_number ?? ''))
            ->setRznSocial((string) ($customer->name ?? '-'));
    }

    /**
     * Convierte un importe decimal a texto en soles peruanos.
     * Ej.: 1180.00 → "MIL CIENTO OCHENTA Y 00/100 SOLES"
     *
     * Implementación básica suficiente para SUNAT (Legend código 1000).
     * Para producción se recomienda sustituir por una librería dedicada
     * como `rmunate/utilities` (numeros-letras).
     */
    private function numberToWords(float $amount): string
    {
        $integer  = (int) $amount;
        $cents    = (int) round(($amount - $integer) * 100);
        $centsTxt = str_pad((string) $cents, 2, '0', STR_PAD_LEFT);

        return strtoupper($this->intToWords($integer)) . " Y {$centsTxt}/100 SOLES";
    }

    private function intToWords(int $number): string
    {
        if ($number === 0) {
            return 'cero';
        }

        $ones   = ['', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve',
                   'diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete',
                   'dieciocho', 'diecinueve'];
        $tens   = ['', '', 'veinte', 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta',
                   'ochenta', 'noventa'];
        $hundreds = ['', 'ciento', 'doscientos', 'trescientos', 'cuatrocientos', 'quinientos',
                     'seiscientos', 'setecientos', 'ochocientos', 'novecientos'];

        if ($number < 0) {
            return 'menos ' . $this->intToWords(abs($number));
        }
        if ($number < 20) {
            return $ones[$number];
        }
        if ($number < 100) {
            $t = (int) ($number / 10);
            $o = $number % 10;
            return $tens[$t] . ($o > 0 ? ' y ' . $ones[$o] : '');
        }
        if ($number === 100) {
            return 'cien';
        }
        if ($number < 1000) {
            $h = (int) ($number / 100);
            $r = $number % 100;
            return $hundreds[$h] . ($r > 0 ? ' ' . $this->intToWords($r) : '');
        }
        if ($number < 2000) {
            $r = $number % 1000;
            return 'mil' . ($r > 0 ? ' ' . $this->intToWords($r) : '');
        }
        if ($number < 1_000_000) {
            $t = (int) ($number / 1000);
            $r = $number % 1000;
            return $this->intToWords($t) . ' mil' . ($r > 0 ? ' ' . $this->intToWords($r) : '');
        }
        if ($number < 2_000_000) {
            $r = $number % 1_000_000;
            return 'un millón' . ($r > 0 ? ' ' . $this->intToWords($r) : '');
        }

        $m = (int) ($number / 1_000_000);
        $r = $number % 1_000_000;
        return $this->intToWords($m) . ' millones' . ($r > 0 ? ' ' . $this->intToWords($r) : '');
    }

    private function assertFiscalFieldsPresent(Sale $sale): void
    {
        if (empty($sale->document_type) || empty($sale->serie) || empty($sale->correlativo)) {
            throw new InvalidArgumentException(
                "La venta #{$sale->id} no tiene campos fiscales completos " .
                "(document_type, serie, correlativo). Llama a issueDocument() primero."
            );
        }
    }
}
