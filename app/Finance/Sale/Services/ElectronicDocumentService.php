<?php

namespace App\Finance\Sale\Services;

use App\Directory\Customer\Models\Customer;
use App\Finance\Sale\Models\ElectronicDocumentLog;
use App\Finance\Sale\Models\Sale;
use Greenter\Model\Response\BaseResult;
use Greenter\Model\Response\BillResult;
use Greenter\See;
use Greenter\Ws\Services\SunatEndpoints;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Orquesta el ciclo de vida fiscal de un comprobante electrónico:
 *
 *  1. issueDocument()   → Reserva correlativo, calcula IGV, marca PENDING.
 *  2. sendDocument()    → Construye el Invoice (Greenter), firma el XML,
 *                         envía a SUNAT, persiste XML/CDR y actualiza el estado.
 *
 * issueDocument() se llama DENTRO de la transacción de processPosSale.
 * sendDocument()  se llama FUERA de ella (ya confirmada), pues la comunicación
 * SOAP con SUNAT no debe participar en la transacción de base de datos.
 */
class ElectronicDocumentService
{
    private const IGV_RATE = 0.18;

    /** Singleton por request para reutilizar SoapClient y plantillas Twig. */
    private ?See $see = null;

    public function __construct(
        private readonly DocumentSeriesService  $documentSeriesService,
        private readonly GreenterBuilderService $greenterBuilderService,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // FASE 1: reservar correlativo y estampar campos fiscales (dentro de TX)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Reserva el correlativo atómico, calcula IGV y pone la venta en PENDING.
     * Se ejecuta DENTRO de la transacción de processPosSale (savepoint).
     *
     * @throws ValidationException   Si el tipo de doc no cuadra con el cliente.
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException  Serie inexistente.
     */
    public function issueDocument(Sale $sale, string $documentType, string $serie): void
    {
        if ($documentType === 'TICKET_INTERNO') {
            return;
        }

        $customer = $sale->customer_id !== null
            ? Customer::query()->find($sale->customer_id)
            : null;

        $this->assertDocumentTypeMatchesCustomer($documentType, $customer);

        $total       = (float) $sale->total_amount;
        $taxableBase = round($total / (1 + self::IGV_RATE), 2);
        $igvAmount   = round($total - $taxableBase, 2);

        $nextNumber        = $this->documentSeriesService->generateNextNumber(
            (int) $sale->warehouse_id,
            $documentType,
            $serie,
        );
        $fullInvoiceNumber = $this->documentSeriesService->formatInvoiceNumber($serie, $nextNumber);

        $sale->document_type       = $documentType;
        $sale->serie               = $serie;
        $sale->correlativo         = $nextNumber;
        $sale->full_invoice_number = $fullInvoiceNumber;
        $sale->taxable_base        = $taxableBase;
        $sale->igv_amount          = $igvAmount;
        $sale->sunat_status        = 'PENDING';
        $sale->save();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FASE 2: construir XML, enviar a SUNAT y persistir resultado (fuera de TX)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Envía el comprobante a SUNAT, guarda XML y CDR, y actualiza `sunat_status`.
     *
     * Debe llamarse DESPUÉS de que la transacción de la venta haya hecho COMMIT.
     * Si SUNAT no responde o lanza excepción, la venta queda en PENDING y puede
     * reintentarse sin riesgo de duplicar correlativos.
     *
     * @throws RuntimeException  Si el certificado o credenciales no están configurados.
     */
    public function sendDocument(Sale $sale): void
    {
        if ($sale->sunat_status !== 'PENDING') {
            return;
        }

        $see     = $this->getSee();
        $invoice = $this->greenterBuilderService->buildInvoice($sale);

        // ── Firmar y enviar ─────────────────────────────────────────────────
        $result = $see->send($invoice);

        // ── Persistir XML firmado ───────────────────────────────────────────
        $xmlContent = $see->getFactory()->getLastXml() ?? '';
        $xmlPath    = $this->storeXml($invoice->getName(), $xmlContent);

        $sale->xml_path = $xmlPath;

        // ── Interpretar respuesta ───────────────────────────────────────────
        if ($result === null || ! $result->isSuccess()) {
            $this->handleFailure($sale, $result);
            return;
        }

        /** @var BillResult $result */
        $cdrPath = $this->storeCdr($invoice->getName(), $result);
        $cdr     = $result->getCdrResponse();

        // CdrResponse::isAccepted() → code=0 (aceptado) o code≥4000 (observado-aceptado)
        if ($cdr !== null && $cdr->isAccepted()) {
            $sale->sunat_status = 'ACCEPTED';
        } else {
            $sale->sunat_status = 'REJECTED';
        }

        $sale->cdr_path = $cdrPath;
        $sale->save();

        // ── Log de auditoría ────────────────────────────────────────────────
        $this->writeLog($sale, 'ISSUE', null, [
            'sunat_code'  => $cdr?->getCode(),
            'description' => $cdr?->getDescription(),
            'notes'       => $cdr?->getNotes(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVADOS — almacenamiento
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Guarda el XML firmado en `storage/app/sunat/xml/<nombre>.xml`.
     * Retorna la ruta relativa para guardar en BD.
     */
    private function storeXml(string $invoiceName, string $xmlContent): string
    {
        $path = "sunat/xml/{$invoiceName}.xml";
        Storage::disk('local')->put($path, $xmlContent);

        return $path;
    }

    /**
     * Guarda el CDR (.zip binario) en `storage/app/sunat/cdr/<nombre>-CDR.zip`.
     * Retorna la ruta relativa para guardar en BD.
     */
    private function storeCdr(string $invoiceName, BillResult $result): string
    {
        $path   = "sunat/cdr/{$invoiceName}-CDR.zip";
        $cdrZip = $result->getCdrZip() ?? '';

        if ($cdrZip !== '') {
            Storage::disk('local')->put($path, $cdrZip);
        }

        return $path;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVADOS — manejo de errores
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Marca la venta como REJECTED, persiste xml_path y escribe el log.
     * No lanza excepción: la venta queda registrada aunque el envío falle.
     */
    private function handleFailure(Sale $sale, ?BaseResult $result): void
    {
        $errorCode    = $result?->getError()?->getCode()    ?? 'UNKNOWN';
        $errorMessage = $result?->getError()?->getMessage() ?? 'Sin respuesta de SUNAT';

        $sale->sunat_status = 'REJECTED';
        $sale->save();

        Log::error('SUNAT send failed', [
            'sale_id'   => $sale->id,
            'full_number' => $sale->full_invoice_number,
            'error_code'  => $errorCode,
            'error_msg'   => $errorMessage,
        ]);

        $this->writeLog($sale, 'ISSUE', null, [
            'error_code' => $errorCode,
            'error_msg'  => $errorMessage,
        ]);
    }

    /**
     * Inserta una fila en `electronic_document_logs`.
     *
     * @param array<string,mixed>|null $requestPayload
     * @param array<string,mixed>|null $responsePayload
     */
    private function writeLog(
        Sale $sale,
        string $action,
        ?array $requestPayload,
        ?array $responsePayload,
    ): void {
        try {
            ElectronicDocumentLog::create([
                'sale_id'          => $sale->id,
                'action'           => $action,
                'request_payload'  => $requestPayload,
                'response_payload' => $responsePayload,
                'sunat_code'       => $responsePayload['sunat_code']
                                       ?? $responsePayload['error_code']
                                       ?? null,
            ]);
        } catch (\Throwable $e) {
            // El log nunca debe romper el flujo principal.
            Log::warning('No se pudo escribir electronic_document_log', [
                'sale_id' => $sale->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVADOS — validación del receptor
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * FACTURA → cliente obligatorio con RUC de 11 dígitos.
     * BOLETA  → cliente opcional; si existe no puede tener RUC.
     */
    private function assertDocumentTypeMatchesCustomer(
        string $documentType,
        ?Customer $customer,
    ): void {
        if ($documentType === 'FACTURA') {
            if ($customer === null) {
                throw ValidationException::withMessages([
                    'document_type' => ['Para emitir una FACTURA se requiere un cliente con RUC registrado.'],
                ]);
            }

            $docType   = strtoupper(trim((string) ($customer->document_type ?? '')));
            $docNumber = trim((string) ($customer->document_number ?? ''));

            if ($docType !== 'RUC' || strlen($docNumber) !== 11) {
                throw ValidationException::withMessages([
                    'document_type' => ['Para emitir una FACTURA el cliente debe tener un RUC de exactamente 11 dígitos.'],
                ]);
            }

            return;
        }

        if ($customer !== null) {
            $docType = strtoupper(trim((string) ($customer->document_type ?? '')));

            if ($docType === 'RUC') {
                throw ValidationException::withMessages([
                    'document_type' => ['El cliente tiene RUC; debe emitir una FACTURA, no una BOLETA.'],
                ]);
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVADOS — inicialización de Greenter\See
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Construye (una sola vez por request) la instancia `Greenter\See` lista
     * para firmar y enviar comprobantes a SUNAT/OSE.
     *
     * @throws RuntimeException  Si faltan credenciales o el certificado no es legible.
     */
    private function getSee(): See
    {
        if ($this->see !== null) {
            return $this->see;
        }

        $config      = (array) config('sunat');
        $ruc         = (string) ($config['ruc']          ?? '');
        $solUser     = (string) ($config['sol_user']     ?? '');
        $solPass     = (string) ($config['sol_pass']     ?? '');
        $certPath    = (string) ($config['cert_path']    ?? '');
        $certContent = (string) ($config['cert_content'] ?? '');
        $endpoint    = (string) ($config['endpoint']     ?? SunatEndpoints::FE_BETA);
        $cachePath   = $config['cache_path'] ?? null;

        if ($ruc === '' || strlen($ruc) !== 11) {
            throw new RuntimeException('SUNAT: RUC del emisor inválido (revisa SUNAT_RUC en .env).');
        }
        if ($solUser === '' || $solPass === '') {
            throw new RuntimeException('SUNAT: credenciales SOL no configuradas.');
        }

        // Estrategia 1: contenido base64 (recomendado para producción / CI-CD)
        // Estrategia 2: ruta absoluta al archivo .pem (conveniente en local)
        if ($certContent !== '') {
            $certificate = base64_decode($certContent, strict: true);
            if ($certificate === false || $certificate === '') {
                throw new RuntimeException('SUNAT: SUNAT_CERT_CONTENT no es un Base64 válido.');
            }
        } elseif ($certPath !== '' && is_readable($certPath)) {
            $certificate = file_get_contents($certPath);
            if ($certificate === false || $certificate === '') {
                throw new RuntimeException("SUNAT: no se pudo leer el certificado en: {$certPath}");
            }
        } else {
            throw new RuntimeException(
                'SUNAT: certificado digital no configurado. ' .
                'Define SUNAT_CERT_CONTENT (base64) o SUNAT_CERT_PATH (ruta absoluta) en .env.'
            );
        }

        $see = new See();
        $see->setCertificate($certificate);
        $see->setClaveSOL($ruc, $solUser, $solPass);
        $see->setService($endpoint);

        if (is_string($cachePath) && $cachePath !== '') {
            if (! is_dir($cachePath)) {
                @mkdir($cachePath, 0o775, true);
            }
            if (is_writable($cachePath)) {
                $see->setCachePath($cachePath);
            }
        }

        return $this->see = $see;
    }
}
