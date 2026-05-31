<?php

declare(strict_types=1);

namespace App\Finance\Sale\Services;

use App\Finance\Sale\Models\Sale;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Greenter\Report\XmlUtils;
use Illuminate\Support\Facades\Storage;

/**
 * Genera el QR SUNAT para el ticket de impresión y extrae el hash
 * de firma digital desde el XML guardado en storage.
 *
 * El contenido del QR sigue el formato exigido por SUNAT (Anexo 6
 * del Reglamento de Comprobantes de Pago):
 *
 *   RUC|TIPO_DOC|SERIE|CORRELATIVO|IGV|IMPORTE_TOTAL|FECHA|TIPO_DOC_CLI|NUM_DOC_CLI|
 *
 * Mapeo de tipo de documento (catálogo 01):
 *   01 = Factura   |   03 = Boleta
 *
 * Mapeo de tipo de documento del receptor (catálogo 06):
 *   6 = RUC   |   1 = DNI   |   4 = CE   |   -  = sin doc
 */
class SunatQrService
{
    private const DOC_TYPE_MAP = [
        'FACTURA' => '01',
        'BOLETA'  => '03',
    ];

    private const CLIENT_DOC_MAP = [
        'RUC' => '6',
        'DNI' => '1',
        'CE'  => '4',
        'PAS' => '7',
    ];

    /**
     * Genera el SVG del QR SUNAT listo para incrustar en HTML con <img src="...">.
     * Devuelve null si la venta no es fiscal (TICKET_INTERNO).
     */
    public function generateQrSvg(Sale $sale): ?string
    {
        $content = $this->buildQrContent($sale);
        if ($content === null) {
            return null;
        }

        $renderer = new ImageRenderer(
            new RendererStyle(160, 0),
            new SvgImageBackEnd()
        );

        $svg = (new Writer($renderer))->writeString(
            $content,
            'UTF-8',
            ErrorCorrectionLevel::Q()
        );

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Extrae el DigestValue (hash SHA-1) del XML firmado almacenado en storage.
     * Devuelve null si el XML no existe o aún no fue generado.
     */
    public function getXmlHash(Sale $sale): ?string
    {
        if (empty($sale->xml_path)) {
            return null;
        }

        if (! Storage::disk('local')->exists($sale->xml_path)) {
            return null;
        }

        $xml = Storage::disk('local')->get($sale->xml_path);
        if (empty($xml)) {
            return null;
        }

        try {
            return (new XmlUtils())->getHashSign($xml) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Arma la cadena QR según el formato SUNAT.
     */
    private function buildQrContent(Sale $sale): ?string
    {
        $docType = strtoupper((string) ($sale->document_type ?? ''));

        if (! isset(self::DOC_TYPE_MAP[$docType])) {
            return null;
        }

        $ruc         = (string) config('sunat.ruc', '');
        $tipoDoc     = self::DOC_TYPE_MAP[$docType];
        $serie       = (string) ($sale->serie ?? '');
        $correlativo = (string) ($sale->correlativo ?? '');
        $igv         = number_format((float) ($sale->igv_amount ?? 0), 2, '.', '');
        $total       = number_format((float) ($sale->total_amount ?? 0), 2, '.', '');
        $fecha       = $sale->creation_time?->format('Y-m-d') ?? date('Y-m-d');

        // Tipo y número de doc del receptor
        $customer        = $sale->relationLoaded('customer') ? $sale->customer : null;
        $rawDocType      = strtoupper(trim((string) ($customer?->document_type ?? '')));
        $clientTipoDoc   = self::CLIENT_DOC_MAP[$rawDocType] ?? '-';
        $clientNumDoc    = (string) ($customer?->document_number ?? '-');

        if ($clientNumDoc === '') {
            $clientTipoDoc = '-';
            $clientNumDoc  = '-';
        }

        return implode('|', [
            $ruc,
            $tipoDoc,
            $serie,
            $correlativo,
            $igv,
            $total,
            $fecha,
            $clientTipoDoc,
            $clientNumDoc,
        ]) . '|';
    }
}
