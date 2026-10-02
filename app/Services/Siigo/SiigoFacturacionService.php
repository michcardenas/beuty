<?php

namespace App\Services\Siigo;

use App\Models\Cliente;
use App\Models\ConfiguracionPdv;
use App\Models\DevolucionParcialPdv;
use App\Models\FacturaSiigo;
use App\Models\VentaPdv;
use Illuminate\Support\Facades\Log;
use Exception;

class SiigoFacturacionService
{
    private const SIIGO_IVA_PORCENTAJE = 19.0;

    private SiigoApiClient $api;

    public function __construct(SiigoApiClient $api)
    {
        $this->api = $api;
    }

    /**
     * Crear factura electrónica para una venta con cliente identificado.
     */
    public function crearFactura(VentaPdv $venta, ?array $datosFiscales = null, bool $enviarEmail = true): FacturaSiigo
    {
        $venta->loadMissing(['items.producto', 'items.variante', 'cliente']);

        $cliente = $venta->cliente;
        $tipoDocumento = 'factura_venta';

        // Resolve or create client in SIIGO
        $customerIdentification = null;
        $emailDestino = null;

        if ($cliente) {
            $this->resolverCliente($cliente, $datosFiscales);
            $customerIdentification = $cliente->numero_identificacion ?? $cliente->nit;
            $emailDestino = $datosFiscales['email'] ?? $cliente->email;
        } elseif ($datosFiscales && !empty($datosFiscales['numero_identificacion'])) {
            $customerIdentification = $datosFiscales['numero_identificacion'];
            $emailDestino = $datosFiscales['email'] ?? null;

            // Create/find the client in SIIGO with provided fiscal data
            $this->resolverClientePorDatosFiscales($datosFiscales);
        }

        if (!$customerIdentification) {
            return $this->crearFacturaConsumidorFinal($venta);
        }

        $payload = $this->construirPayloadFactura($venta, $customerIdentification, $enviarEmail, $emailDestino);

        // Create the factura record first
        $factura = FacturaSiigo::create([
            'venta_pdv_id' => $venta->id,
            'tipo_documento' => $tipoDocumento,
            'siigo_document_type_id' => $payload['document']['id'] ?? null,
            'fecha_emision' => now()->toDateString(),
            'subtotal' => $venta->subtotal,
            'iva' => $venta->iva,
            'total' => $venta->total,
            'estado_dian' => 'pendiente',
            'email_destino' => $emailDestino,
            'siigo_request' => $payload,
            'cliente_id' => $venta->cliente_id,
            'usuario_id' => auth()->id(),
        ]);

        try {
            $factura->incrementarIntento();
            [$response, $payload] = $this->postFacturaConAjustePago('/v1/invoices', $payload, $factura);

            $factura->update([
                'siigo_response' => $response,
                'siigo_request' => $payload,
                'siigo_invoice_id' => $response['id'] ?? null,
                'numero_factura' => isset($response['prefix'], $response['number'])
                    ? $response['prefix'] . '-' . $response['number']
                    : ($response['name'] ?? null),
            ]);

            $this->procesarEstadoRespuesta($factura, $response);

            // Update venta with factura reference
            $venta->update([
                'factura_siigo_id' => $factura->id,
                'requiere_factura' => true,
            ]);

            return $factura->fresh();
        } catch (Exception $e) {
            $factura->marcarError($e->getMessage());
            Log::error("SIIGO crearFactura error para venta {$venta->numero_venta}: {$e->getMessage()}");
            return $factura->fresh();
        }
    }

    /**
     * Crear factura como Consumidor Final (NIT 222222222222).
     */
    public function crearFacturaConsumidorFinal(VentaPdv $venta): FacturaSiigo
    {
        $venta->loadMissing(['items.producto', 'items.variante']);

        $nitConsumidorFinal = ConfiguracionPdv::obtener('siigo_consumidor_final_nit', '222222222222');
        $payload = $this->construirPayloadFactura($venta, $nitConsumidorFinal, false);

        $factura = FacturaSiigo::create([
            'venta_pdv_id' => $venta->id,
            'tipo_documento' => 'consumidor_final',
            'siigo_document_type_id' => $payload['document']['id'] ?? null,
            'fecha_emision' => now()->toDateString(),
            'subtotal' => $venta->subtotal,
            'iva' => $venta->iva,
            'total' => $venta->total,
            'estado_dian' => 'pendiente',
            'siigo_request' => $payload,
            'cliente_id' => $venta->cliente_id,
            'usuario_id' => auth()->id(),
        ]);

        try {
            $factura->incrementarIntento();

            // Ensure Consumidor Final exists in SIIGO
            $this->asegurarConsumidorFinalEnSiigo($nitConsumidorFinal);

            [$response, $payload] = $this->postFacturaConAjustePago('/v1/invoices', $payload, $factura);

            $factura->update([
                'siigo_response' => $response,
                'siigo_request' => $payload,
                'siigo_invoice_id' => $response['id'] ?? null,
                'numero_factura' => isset($response['prefix'], $response['number'])
                    ? $response['prefix'] . '-' . $response['number']
                    : ($response['name'] ?? null),
            ]);

            $this->procesarEstadoRespuesta($factura, $response);

            $venta->update([
                'factura_siigo_id' => $factura->id,
                'requiere_factura' => true,
            ]);

            return $factura->fresh();
        } catch (Exception $e) {
            $factura->marcarError($e->getMessage());
            Log::error("SIIGO crearFacturaConsumidorFinal error para venta {$venta->numero_venta}: {$e->getMessage()}");
            return $factura->fresh();
        }
    }

    /**
     * Anular una factura en SIIGO eligiendo el endpoint correcto:
     *  - Si la factura tiene CUFE (validada por DIAN) → POST /v1/credit-notes (nota crédito reason=2)
     *  - Si la factura NO tiene CUFE (no enviada/no aprobada por DIAN) → POST /v1/invoices/{id}/annul
     *
     * Según la documentación oficial de Siigo, una factura electrónica aprobada
     * por la DIAN no se puede anular directamente: hay que emitir nota crédito.
     * El endpoint annul aplica solo a facturas no validadas todavía por DIAN.
     *
     * Devuelve el FacturaSiigo del documento de anulación creado (nota crédito o registro de annul).
     * Re-lanza la excepción si SIIGO devuelve error.
     */
    public function anularFacturaSiigo(FacturaSiigo $facturaOriginal, string $motivo): FacturaSiigo
    {
        if (!$facturaOriginal->siigo_invoice_id) {
            throw new Exception('La factura original no tiene ID de SIIGO.');
        }

        // Factura validada por DIAN (con CUFE) → emitir nota crédito
        if (!empty($facturaOriginal->cufe)) {
            return $this->crearNotaCredito($facturaOriginal, $motivo);
        }

        // Factura sin CUFE → usar endpoint annul de Siigo
        return $this->annulInvoice($facturaOriginal, $motivo);
    }

    /**
     * Anular una factura NO electrónica (sin CUFE) vía POST /v1/invoices/{id}/annul.
     */
    public function annulInvoice(FacturaSiigo $facturaOriginal, string $motivo): FacturaSiigo
    {
        $invoiceGuid = $facturaOriginal->siigo_invoice_id;

        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', (string) $invoiceGuid)) {
            throw new Exception("El siigo_invoice_id no tiene formato GUID válido (recibido: '{$invoiceGuid}').");
        }

        try {
            $facturaOriginal->incrementarIntento();
            $response = $this->api->post("/v1/invoices/{$invoiceGuid}/annul", [], $facturaOriginal->id);

            $facturaOriginal->update([
                'siigo_response' => $response,
                'estado_dian' => 'anulada',
                'errores' => null,
            ]);

            return $facturaOriginal->fresh();
        } catch (Exception $e) {
            Log::error("SIIGO annulInvoice error para {$invoiceGuid}: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Crear nota crédito electrónica para una factura ya emitida (anulación total).
     *
     * Usa reason = 2 (Anulación de factura electrónica) según códigos DIAN —
     * el SDK oficial de Siigo expone este enum como DianReason 1..6.
     *
     * Si SIIGO devuelve error, re-lanza la excepción para que el caller
     * pueda decidir no aplicar la anulación local.
     */
    public function crearNotaCredito(FacturaSiigo $facturaOriginal, string $motivo): FacturaSiigo
    {
        $invoiceGuid = $facturaOriginal->siigo_invoice_id;

        if (!$invoiceGuid) {
            throw new Exception('La factura original no tiene ID de SIIGO.');
        }

        // Validar formato GUID estándar (00000000-0000-0000-0000-000000000000).
        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $invoiceGuid)) {
            throw new Exception("El siigo_invoice_id no tiene formato GUID válido (recibido: '{$invoiceGuid}').");
        }

        // Para emitir nota crédito DIAN la factura original debe tener CUFE
        if (empty($facturaOriginal->cufe)) {
            throw new Exception('La factura original no tiene CUFE; no es factura electrónica DIAN. Use annulInvoice en su lugar.');
        }

        // Evitar duplicar nota crédito si ya existe una aprobada/pendiente
        $existente = $facturaOriginal->notasCredito()
            ->whereIn('estado_dian', ['aprobada', 'pendiente'])
            ->first();
        if ($existente) {
            return $existente;
        }

        // Buscar en SIIGO si ya existe una NC para esta factura (creada manualmente
        // desde el panel SIIGO o por otra vía). Si la encontramos aprobada, la
        // importamos en lugar de crear una nueva.
        $ncEnSiigo = $this->buscarNotaCreditoExistenteEnSiigo($facturaOriginal);
        if ($ncEnSiigo) {
            return $this->importarNotaCreditoExistente($facturaOriginal, $ncEnSiigo);
        }

        $venta = $facturaOriginal->ventaPdv;
        $venta->loadMissing(['items.producto', 'items.variante', 'cliente']);

        $creditNoteTypeId = $this->comprobanteNotaCredito($venta);
        if (!$creditNoteTypeId) {
            throw new Exception('No se ha configurado el tipo de documento para notas crédito en SIIGO.');
        }

        // PRIORIDAD: replicar exactamente lo que se envió en la factura original
        // (customer, items, payments). Si la NC envía datos distintos a los de la
        // factura original, SIIGO devuelve 500 unhandled_error.
        $payloadOriginal = $facturaOriginal->siigo_request ?? [];

        // Customer: tomar el del request original; fallback al cliente actual / consumidor final
        $cliente = $venta->cliente;
        $customerIdentification = ($payloadOriginal['customer']['identification'] ?? null)
            ?? ($cliente ? ($cliente->numero_identificacion ?? $cliente->nit ?? null) : null)
            ?? ConfiguracionPdv::obtener('siigo_consumidor_final_nit', '222222222222');
        $customerBranch = $payloadOriginal['customer']['branch_office'] ?? 0;

        // Seller: el mismo de la factura original (si existía)
        $sellerId = (int) ($payloadOriginal['seller'] ?? ConfiguracionPdv::obtener('siigo_seller_id'));

        // Items: replicar los del request original tal cual (códigos, cantidades,
        // precios, taxes y descuentos). Removemos `seller` por item ya que SIIGO
        // espera seller solo a nivel global en notas crédito.
        $items = !empty($payloadOriginal['items'])
            ? array_map(function ($item) {
                unset($item['seller'], $item['warehouse']);
                return $item;
            }, $payloadOriginal['items'])
            : $this->construirItems($venta);

        // Payments: replicar valor y método del original, pero ajustar due_date
        // a la fecha de la NC (no aceptar fechas anteriores a la NC).
        $payments = !empty($payloadOriginal['payments'])
            ? array_map(function ($pago) {
                $pago['due_date'] = now()->format('Y-m-d');
                return $pago;
            }, $payloadOriginal['payments'])
            : $this->construirPayments($venta);

        // Para facturas electrónicas DIAN se referencia por invoice_data (cufe + prefix + number).
        [$invoicePrefix, $invoiceNumber] = $this->extraerPrefixNumero($facturaOriginal->numero_factura);
        $invoiceData = array_filter([
            'prefix' => $invoicePrefix,
            'number' => $invoiceNumber !== null ? (string) $invoiceNumber : null,
            'date' => optional($facturaOriginal->fecha_emision)->format('Y-m-d'),
            'cufe' => $facturaOriginal->cufe,
        ], fn($v) => $v !== null && $v !== '');

        $invoiceField = !empty($invoiceData)
            ? ['invoice_data' => $invoiceData]
            : ['invoice' => $invoiceGuid];

        if (!$sellerId) {
            throw new Exception('SIIGO requiere un seller para crear la nota crédito. Configure siigo_seller_id en la configuración.');
        }

        $payload = array_merge([
            'document' => ['id' => $creditNoteTypeId],
            'date' => now()->format('Y-m-d'),
        ], $invoiceField, [
            'customer' => [
                'identification' => $customerIdentification,
                'branch_office' => $customerBranch,
            ],
            'seller' => $sellerId, // Requerido por SIIGO
            'reason' => 2, // DIAN: Anulación de factura electrónica
            'observations' => $motivo,
            'items' => $items,
            'payments' => $payments,
            'stamp' => ['send' => true], // Envía automáticamente a DIAN (status pasa de Draft a Sending → Accepted con CUDE)
            'mail' => ['send' => false],
        ]);

        $factura = FacturaSiigo::create([
            'venta_pdv_id' => $venta->id,
            'tipo_documento' => 'nota_credito',
            'siigo_document_type_id' => $creditNoteTypeId,
            'fecha_emision' => now()->toDateString(),
            'subtotal' => $venta->subtotal,
            'iva' => $venta->iva,
            'total' => $venta->total,
            'estado_dian' => 'pendiente',
            'siigo_request' => $payload,
            'nota_credito_de' => $facturaOriginal->id,
            'cliente_id' => $venta->cliente_id,
            'usuario_id' => auth()->id(),
        ]);

        try {
            $factura->incrementarIntento();
            $response = $this->api->post('/v1/credit-notes', $payload, $factura->id);

            $factura->update([
                'siigo_response' => $response,
                'siigo_invoice_id' => $response['id'] ?? null,
                'numero_factura' => isset($response['prefix'], $response['number'])
                    ? $response['prefix'] . '-' . $response['number']
                    : ($response['name'] ?? null),
            ]);

            $this->procesarEstadoRespuesta($factura, $response);

            // SIIGO responde con stamp.status='Sending' al inicio (DIAN procesa async).
            // Hacemos polling corto (hasta ~6s) para obtener el CUDE final antes de devolver.
            if ($factura->fresh()->estado_dian === 'pendiente' && !empty($response['id'])) {
                $this->esperarRespuestaDian($factura, $response['id'], 'credit-notes');
            }

            $facturaFresh = $factura->fresh();

            // DIAN rechazada → tratar como error para no aplicar la anulación local
            if ($facturaFresh->estado_dian === 'rechazada') {
                throw new Exception('SIIGO/DIAN rechazó la nota crédito: ' . ($facturaFresh->errores ?? 'sin detalle'));
            }

            return $facturaFresh;
        } catch (Exception $e) {
            $factura->marcarError($e->getMessage());
            Log::error("SIIGO crearNotaCredito error: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Polling corto del estado DIAN tras crear un documento electrónico.
     * SIIGO devuelve status='Sending' inicialmente y DIAN tarda ~2-5s en aprobar.
     * Bloquea la request hasta obtener CUFE/CUDE o agotar intentos.
     */
    private function esperarRespuestaDian(FacturaSiigo $factura, string $siigoId, string $tipo, int $maxIntentos = 4, int $segundosEntre = 2): void
    {
        $endpoint = $tipo === 'credit-notes'
            ? "/v1/credit-notes/{$siigoId}"
            : "/v1/invoices/{$siigoId}";

        for ($i = 0; $i < $maxIntentos; $i++) {
            sleep($segundosEntre);
            try {
                $detalle = $this->api->get($endpoint, [], $factura->id);
                $this->procesarEstadoRespuesta($factura, $detalle);
                $estado = $factura->fresh()->estado_dian;
                if (in_array($estado, ['aprobada', 'rechazada'])) {
                    return;
                }
            } catch (Exception $e) {
                Log::warning("SIIGO esperarRespuestaDian iteración $i error: {$e->getMessage()}");
            }
        }
    }

    /**
     * Crear nota crédito parcial para una devolución parcial.
     */
    public function crearNotaCreditoParcial(FacturaSiigo $facturaOriginal, DevolucionParcialPdv $devolucion, string $motivo): FacturaSiigo
    {
        $invoiceGuid = $facturaOriginal->siigo_invoice_id;

        if (!$invoiceGuid) {
            throw new Exception('La factura original no tiene ID de SIIGO.');
        }
        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $invoiceGuid)) {
            throw new Exception("El siigo_invoice_id de la factura original no tiene formato GUID válido (recibido: '{$invoiceGuid}').");
        }
        if (!$facturaOriginal->estaAprobada()) {
            throw new Exception('La factura original no está aprobada en DIAN; no se puede generar nota crédito parcial.');
        }

        $devolucion->loadMissing(['items.producto', 'items.variante', 'ventaPdv.cliente']);

        $creditNoteTypeId = $this->comprobanteNotaCredito($devolucion->ventaPdv);
        if (!$creditNoteTypeId) {
            throw new Exception('No se ha configurado el tipo de documento para notas crédito en SIIGO.');
        }

        $venta = $devolucion->ventaPdv;
        $payloadOriginal = $facturaOriginal->siigo_request ?? [];
        $cliente = $venta ? $venta->cliente : null;
        $customerIdentification = ($payloadOriginal['customer']['identification'] ?? null)
            ?? ($cliente ? ($cliente->numero_identificacion ?? $cliente->nit ?? null) : null)
            ?? ConfiguracionPdv::obtener('siigo_consumidor_final_nit', '222222222222');
        $customerBranch = $payloadOriginal['customer']['branch_office'] ?? 0;

        $sellerId = (int) ($payloadOriginal['seller'] ?? ConfiguracionPdv::obtener('siigo_seller_id'));

        if (!$sellerId) {
            throw new Exception('SIIGO requiere un seller para crear la nota crédito parcial. Configure siigo_seller_id en la configuración.');
        }

        // Referencia DIAN por invoice_data (cufe + prefix + number) para facturas electrónicas.
        [$invoicePrefix, $invoiceNumber] = $this->extraerPrefixNumero($facturaOriginal->numero_factura);
        $invoiceData = array_filter([
            'prefix' => $invoicePrefix,
            'number' => $invoiceNumber !== null ? (string) $invoiceNumber : null,
            'date' => optional($facturaOriginal->fecha_emision)->format('Y-m-d'),
            'cufe' => $facturaOriginal->cufe,
        ], fn($v) => $v !== null && $v !== '');

        $invoiceField = !empty($invoiceData)
            ? ['invoice_data' => $invoiceData]
            : ['invoice' => $invoiceGuid];

        // Items: SIIGO no acepta seller dentro de items en NC
        $itemsParcial = array_map(function ($item) {
            unset($item['seller'], $item['warehouse']);
            return $item;
        }, $this->construirItemsDesdeDevolucion($devolucion));

        $payload = array_merge([
            'document' => ['id' => $creditNoteTypeId],
            'date' => now()->format('Y-m-d'),
        ], $invoiceField, [
            'customer' => [
                'identification' => $customerIdentification,
                'branch_office' => $customerBranch,
            ],
            'seller' => $sellerId, // Requerido por SIIGO
            'reason' => 1, // DIAN: Devolución parcial de bienes
            'observations' => $motivo,
            'items' => $itemsParcial,
            'payments' => $this->construirPaymentsDesdeDevolucion($devolucion->ventaPdv, $devolucion),
            'stamp' => ['send' => true], // Envía automáticamente a DIAN
            'mail' => ['send' => false],
        ]);

        $factura = FacturaSiigo::create([
            'venta_pdv_id' => $devolucion->venta_pdv_id,
            'tipo_documento' => 'nota_credito',
            'siigo_document_type_id' => $creditNoteTypeId,
            'fecha_emision' => now()->toDateString(),
            'subtotal' => $devolucion->subtotal,
            'iva' => $devolucion->iva,
            'total' => $devolucion->total,
            'estado_dian' => 'pendiente',
            'siigo_request' => $payload,
            'nota_credito_de' => $facturaOriginal->id,
            'cliente_id' => $devolucion->ventaPdv->cliente_id,
            'usuario_id' => auth()->id(),
        ]);

        try {
            $factura->incrementarIntento();
            $response = $this->api->post('/v1/credit-notes', $payload, $factura->id);

            $factura->update([
                'siigo_response' => $response,
                'siigo_invoice_id' => $response['id'] ?? null,
                'numero_factura' => isset($response['prefix'], $response['number'])
                    ? $response['prefix'] . '-' . $response['number']
                    : ($response['name'] ?? null),
            ]);

            $this->procesarEstadoRespuesta($factura, $response);

            // Polling corto a DIAN para obtener CUDE antes de retornar
            if ($factura->fresh()->estado_dian === 'pendiente' && !empty($response['id'])) {
                $this->esperarRespuestaDian($factura, $response['id'], 'credit-notes');
            }

            // Vincular nota crédito con la devolución
            $devolucion->update(['factura_siigo_id' => $factura->id]);

            return $factura->fresh();
        } catch (Exception $e) {
            $factura->marcarError($e->getMessage());
            Log::error("SIIGO crearNotaCreditoParcial error: {$e->getMessage()}");
            return $factura->fresh();
        }
    }

    /**
     * Reintentar factura fallida.
     */
    public function reintentarFactura(FacturaSiigo $factura): FacturaSiigo
    {
        if (!$factura->puedeReintentar()) {
            throw new Exception('Esta factura no se puede reintentar (máximo de intentos alcanzado o ya aprobada).');
        }

        if ($factura->tipo_documento === 'nota_credito') {
            // Las notas crédito mantienen el payload original (referencian factura específica)
            $payload = $factura->siigo_request;
            if (!$payload) {
                throw new Exception('No se encontró el payload original de la factura.');
            }
            $payload['date'] = now()->format('Y-m-d');
            $endpoint = '/v1/credit-notes';
        } else {
            // Reconstruir payload desde la venta para aplicar la lógica actual de IVA/precios
            $venta = $factura->ventaPdv;
            if (!$venta) {
                throw new Exception('No se pudo cargar la venta asociada a la factura.');
            }
            $venta->loadMissing(['items.producto', 'items.variante', 'cliente']);

            $payloadOriginal = $factura->siigo_request ?? [];
            $clienteVenta = $venta->cliente;
            $customerIdentification = ($payloadOriginal['customer']['identification'] ?? null)
                ?? ($clienteVenta ? ($clienteVenta->numero_identificacion ?? $clienteVenta->nit ?? null) : null)
                ?? ConfiguracionPdv::obtener('siigo_consumidor_final_nit', '222222222222');
            $sendEmail = (bool) ($payloadOriginal['mail']['send'] ?? false);
            $emailDestino = $factura->email_destino;

            $payload = $this->construirPayloadFactura($venta, $customerIdentification, $sendEmail, $emailDestino);
            $endpoint = '/v1/invoices';
        }

        try {
            $factura->incrementarIntento();
            [$response, $payload] = $this->postFacturaConAjustePago($endpoint, $payload, $factura);

            // Al aceptar SIIGO limpiamos estado 'error'/'rechazada' y errores previos
            // (procesarEstadoRespuesta lo elevará a 'aprobada' si trae CUFE, o dejará
            // 'pendiente' mientras DIAN valida).
            $factura->update([
                'siigo_response' => $response,
                'siigo_request' => $payload,
                'siigo_invoice_id' => $response['id'] ?? null,
                'numero_factura' => isset($response['prefix'], $response['number'])
                    ? $response['prefix'] . '-' . $response['number']
                    : ($response['name'] ?? null),
                'estado_dian' => 'pendiente',
                'errores' => null,
            ]);

            $this->procesarEstadoRespuesta($factura, $response);

            return $factura->fresh();
        } catch (Exception $e) {
            $factura->marcarError($e->getMessage());
            throw $e;
        }
    }

    /**
     * Consultar estado actual de una factura en SIIGO/DIAN.
     */
    public function consultarEstado(FacturaSiigo $factura): array
    {
        if (!$factura->siigo_invoice_id) {
            return ['estado' => $factura->estado_dian, 'mensaje' => 'Sin ID de SIIGO'];
        }

        $endpoint = $factura->tipo_documento === 'nota_credito'
            ? "/v1/credit-notes/{$factura->siigo_invoice_id}"
            : "/v1/invoices/{$factura->siigo_invoice_id}";

        try {
            $response = $this->api->get($endpoint, [], $factura->id);
            $this->procesarEstadoRespuesta($factura, $response);

            return [
                'estado' => $factura->estado_dian,
                'cufe' => $factura->cufe,
                'numero' => $factura->numero_factura,
                'mensaje' => 'Estado actualizado correctamente',
            ];
        } catch (Exception $e) {
            return ['estado' => $factura->estado_dian, 'mensaje' => $e->getMessage()];
        }
    }

    /**
     * Obtener URL/contenido del PDF de la factura.
     */
    public function obtenerPdf(FacturaSiigo $factura)
    {
        if (!$factura->siigo_invoice_id) {
            throw new Exception('La factura no tiene ID de SIIGO.');
        }

        return $this->api->getRaw("/v1/invoices/{$factura->siigo_invoice_id}/pdf", $factura->id);
    }

    /**
     * Buscar en SIIGO si ya existe una nota crédito ELECTRÓNICA aceptada por DIAN
     * que apunte a la factura indicada. Útil cuando el usuario creó manualmente
     * la NC desde el panel SIIGO y queremos importarla al sistema local en lugar
     * de generar una nueva.
     *
     * SIIGO no permite filtrar /v1/credit-notes por invoice, así que paginamos
     * los resultados desde la fecha de la factura.
     *
     * @return array|null Datos crudos de SIIGO si encuentra la NC, null si no.
     */
    public function buscarNotaCreditoExistenteEnSiigo(FacturaSiigo $factura, int $maxPaginas = 10, int $pageSize = 100): ?array
    {
        if (!$factura->siigo_invoice_id) {
            return null;
        }

        $fechaInicio = $factura->fecha_emision
            ? $factura->fecha_emision->copy()->subDay()->format('Y-m-d')
            : now()->subMonths(3)->format('Y-m-d');

        for ($page = 1; $page <= $maxPaginas; $page++) {
            try {
                $r = $this->api->get('/v1/credit-notes', [
                    'created_start' => $fechaInicio,
                    'page' => $page,
                    'page_size' => $pageSize,
                ]);
            } catch (Exception $e) {
                Log::warning("SIIGO buscarNotaCreditoExistenteEnSiigo: error pag $page: {$e->getMessage()}");
                break;
            }

            $results = $r['results'] ?? [];
            if (empty($results)) break;

            foreach ($results as $nc) {
                $invoiceId = is_array($nc['invoice'] ?? null) ? ($nc['invoice']['id'] ?? null) : null;
                if ($invoiceId !== $factura->siigo_invoice_id) continue;

                // Filtrar solo NCs aceptadas por DIAN con CUDE
                $status = strtolower($nc['stamp']['status'] ?? '');
                $cude = $nc['stamp']['cude'] ?? $nc['stamp']['cufe'] ?? null;
                if ($status !== 'accepted' || empty($cude)) continue;

                // reason 2 = anulación total, 6 = devolución total
                $reason = (int) ($nc['reason'] ?? 0);
                if (!in_array($reason, [2, 6])) continue;

                return $nc;
            }

            // Si la página no llenó, no hay más
            if (count($results) < $pageSize) break;
        }

        return null;
    }

    /**
     * Crear un registro local de FacturaSiigo a partir de una NC ya existente en SIIGO.
     * Se usa cuando la NC fue creada manualmente desde el panel SIIGO y queremos
     * dejarla vinculada al sistema sin duplicarla.
     */
    public function importarNotaCreditoExistente(FacturaSiigo $facturaOriginal, array $nc): FacturaSiigo
    {
        $venta = $facturaOriginal->ventaPdv;

        $nuevaFactura = FacturaSiigo::create([
            'venta_pdv_id'           => $venta?->id,
            'tipo_documento'         => 'nota_credito',
            'siigo_document_type_id' => $nc['document']['id'] ?? null,
            'siigo_invoice_id'       => $nc['id'] ?? null,
            'numero_factura'         => $nc['name']
                ?? (isset($nc['prefix'], $nc['number']) ? $nc['prefix'].'-'.$nc['number'] : null),
            'cufe'                   => $nc['stamp']['cude'] ?? $nc['stamp']['cufe'] ?? null,
            'fecha_emision'          => $nc['date'] ?? now()->toDateString(),
            'subtotal'               => $nc['total'] ?? $facturaOriginal->subtotal,
            'iva'                    => 0,
            'total'                  => $nc['total'] ?? $facturaOriginal->total,
            'estado_dian'            => 'aprobada',
            'siigo_request'          => null,
            'siigo_response'         => $nc,
            'nota_credito_de'        => $facturaOriginal->id,
            'cliente_id'             => $venta?->cliente_id,
            'usuario_id'             => auth()->id() ?? $venta?->usuario_id,
            'intentos'               => 1,
            'ultimo_intento_en'      => now(),
        ]);

        Log::info("SIIGO: NC existente importada {$nuevaFactura->numero_factura} para factura {$facturaOriginal->numero_factura}");
        return $nuevaFactura;
    }

    /**
     * Obtener la URL pública del documento en SIIGO (vista DocumentView).
     * SIIGO devuelve este campo en el detalle de la factura: documentview.siigo.com/document?data=...
     */
    public function obtenerPublicUrl(FacturaSiigo $factura): ?string
    {
        if (!$factura->siigo_invoice_id) {
            throw new Exception('La factura no tiene ID de SIIGO.');
        }

        $endpoint = $factura->tipo_documento === 'nota_credito'
            ? "/v1/credit-notes/{$factura->siigo_invoice_id}"
            : "/v1/invoices/{$factura->siigo_invoice_id}";

        $detalle = $this->api->get($endpoint, [], $factura->id);
        return $detalle['public_url'] ?? null;
    }

    /**
     * Reenviar email de la factura.
     */
    public function reenviarEmail(FacturaSiigo $factura): bool
    {
        if (!$factura->siigo_invoice_id) {
            throw new Exception('La factura no tiene ID de SIIGO.');
        }

        try {
            $this->api->post("/v1/invoices/{$factura->siigo_invoice_id}/mail", [], $factura->id);
            $factura->update(['estado_envio_email' => 'enviado']);
            return true;
        } catch (Exception $e) {
            $factura->update(['estado_envio_email' => 'error']);
            throw $e;
        }
    }

    // ========================================
    // Private helper methods
    // ========================================

    /**
     * Resolver (buscar o crear) un cliente en SIIGO.
     */
    private function resolverCliente(Cliente $cliente, ?array $datosFiscales = null): void
    {
        if ($cliente->siigo_id) {
            return; // Already synced
        }

        $identificacion = $datosFiscales['numero_identificacion'] ?? $cliente->numero_identificacion ?? $cliente->nit;
        if (!$identificacion) {
            return;
        }

        // Search in SIIGO
        try {
            $response = $this->api->get('/v1/customers', [
                'identification' => $identificacion,
            ]);

            $results = $response['results'] ?? $response;
            if (!empty($results) && is_array($results)) {
                $siigoCustomer = is_array($results[0] ?? null) ? $results[0] : $results;
                if (isset($siigoCustomer['id'])) {
                    $cliente->update(['siigo_id' => $siigoCustomer['id']]);
                    return;
                }
            }
        } catch (Exception $e) {
            // Customer not found, will create below
            Log::info("SIIGO: Cliente {$identificacion} no encontrado, se creará.");
        }

        // Create in SIIGO
        $this->crearClienteEnSiigo($cliente, $datosFiscales);
    }

    /**
     * Resolve client by fiscal data (when no Cliente model linked).
     */
    private function resolverClientePorDatosFiscales(array $datos): void
    {
        $identificacion = $datos['numero_identificacion'] ?? null;
        if (!$identificacion) return;

        try {
            $response = $this->api->get('/v1/customers', [
                'identification' => $identificacion,
            ]);
            $results = $response['results'] ?? $response;
            if (!empty($results) && is_array($results)) {
                return; // Already exists
            }
        } catch (Exception $e) {
            // Will create below
        }

        $tipoDoc = $datos['tipo_documento'] ?? '13';
        $esEmpresa = $tipoDoc === '31';

        $payload = [
            'type' => 'Customer',
            'person_type' => $esEmpresa ? 'Company' : 'Person',
            'id_type' => $tipoDoc,
            'identification' => $identificacion,
            'branch_office' => 0,
            'active' => true,
            'vat_responsible' => $esEmpresa,
            'fiscal_responsibilities' => [['code' => 'R-99-PN']],
            'name' => $esEmpresa
                ? [$datos['razon_social'] ?? $datos['nombre'] ?? 'Cliente']
                : $this->parsearNombre($datos['nombre'] ?? 'Cliente'),
            'contacts' => [
                [
                    'first_name' => $esEmpresa ? ($datos['nombre'] ?? 'Contacto') : (explode(' ', $datos['nombre'] ?? 'Cliente')[0]),
                    'last_name' => $esEmpresa ? '' : (explode(' ', $datos['nombre'] ?? 'Cliente')[1] ?? ''),
                    'email' => $datos['email'] ?? 'sin-email@miracle.com',
                ],
            ],
        ];

        if (!empty($datos['telefono'])) {
            $payload['phones'] = [['indicative' => '57', 'number' => $datos['telefono']]];
        }

        try {
            $this->api->post('/v1/customers', $payload);
        } catch (Exception $e) {
            Log::warning("SIIGO: No se pudo crear cliente por datos fiscales: {$e->getMessage()}");
        }
    }

    /**
     * Create a Miracle client in SIIGO.
     */
    private function crearClienteEnSiigo(Cliente $cliente, ?array $datosFiscales = null): void
    {
        $tipoDoc = $datosFiscales['tipo_documento'] ?? $cliente->tipo_documento ?? ($cliente->es_persona_juridica ? '31' : '13');
        $esEmpresa = $cliente->es_persona_juridica || $tipoDoc === '31';
        $identificacion = $datosFiscales['numero_identificacion'] ?? $cliente->numero_identificacion ?? $cliente->nit;

        $nombre = $esEmpresa
            ? [$cliente->razon_social ?? $cliente->nombre_contacto ?? 'Cliente']
            : $this->parsearNombre($cliente->nombre_contacto ?? 'Cliente');

        $payload = [
            'type' => 'Customer',
            'person_type' => $esEmpresa ? 'Company' : 'Person',
            'id_type' => $tipoDoc,
            'identification' => $identificacion,
            'branch_office' => 0,
            'active' => true,
            'vat_responsible' => $esEmpresa,
            'fiscal_responsibilities' => [['code' => 'R-99-PN']],
            'name' => $nombre,
            'contacts' => [
                [
                    'first_name' => $esEmpresa
                        ? ($cliente->nombre_contacto ?? 'Contacto')
                        : (explode(' ', $cliente->nombre_contacto ?? 'Cliente')[0]),
                    'last_name' => $esEmpresa
                        ? ''
                        : (explode(' ', $cliente->nombre_contacto ?? 'Cliente')[1] ?? ''),
                    'email' => $datosFiscales['email'] ?? $cliente->email ?? 'sin-email@miracle.com',
                ],
            ],
        ];

        if ($cliente->telefono) {
            $payload['phones'] = [['indicative' => '57', 'number' => $cliente->telefono]];
        }

        if ($cliente->direccion) {
            $payload['address'] = [
                'address' => $cliente->direccion,
                'city' => [
                    'country_code' => 'Co',
                    'state_code' => '11',
                    'city_code' => '11001',
                ],
            ];
        }

        try {
            $response = $this->api->post('/v1/customers', $payload);
            if (isset($response['id'])) {
                $cliente->update([
                    'siigo_id' => $response['id'],
                    'tipo_documento' => $tipoDoc,
                ]);
            }
        } catch (Exception $e) {
            Log::warning("SIIGO: No se pudo crear cliente {$identificacion}: {$e->getMessage()}");
            // Don't throw - invoice creation might still work if client exists
        }
    }

    /**
     * Ensure "Consumidor Final" exists in SIIGO.
     */
    private function asegurarConsumidorFinalEnSiigo(string $nit): void
    {
        try {
            $response = $this->api->get('/v1/customers', ['identification' => $nit]);
            $results = $response['results'] ?? $response;
            if (!empty($results)) return;
        } catch (Exception $e) {
            // Will try to create
        }

        try {
            $this->api->post('/v1/customers', [
                'type' => 'Customer',
                'person_type' => 'Person',
                'id_type' => '13',
                'identification' => $nit,
                'branch_office' => 0,
                'active' => true,
                'vat_responsible' => false,
                'fiscal_responsibilities' => [['code' => 'R-99-PN']],
                'name' => ['Consumidor', 'Final'],
                'contacts' => [
                    [
                        'first_name' => 'Consumidor',
                        'last_name' => 'Final',
                        'email' => 'consumidorfinal@miracle.com',
                    ],
                ],
            ]);
        } catch (Exception $e) {
            Log::info("SIIGO: Consumidor Final might already exist: {$e->getMessage()}");
        }
    }

    /**
     * Build the invoice payload for SIIGO.
     */
    /**
     * Comprobante de SIIGO con el que factura la sede de la venta.
     *
     * En SIIGO la numeración (prefijo y resolución DIAN) pertenece al
     * comprobante, así que cada sede con numeración propia tiene el suyo.
     * Una sede sin comprobante asignado usa el general de la configuración.
     */
    private function comprobanteFactura(VentaPdv $venta): int
    {
        $venta->loadMissing('ubicacion');

        return (int) ($venta->ubicacion?->siigo_document_type_id
            ?: ConfiguracionPdv::obtener('siigo_document_type_id'));
    }

    /**
     * Comprobante de notas crédito de la sede donde se hizo la venta original,
     * para que la nota salga con la misma numeración que su factura.
     */
    private function comprobanteNotaCredito(?VentaPdv $venta): int
    {
        $venta?->loadMissing('ubicacion');

        return (int) ($venta?->ubicacion?->siigo_credit_note_type_id
            ?: ConfiguracionPdv::obtener('siigo_credit_note_type_id'));
    }

    private function construirPayloadFactura(VentaPdv $venta, string $customerIdentification, bool $sendEmail = true, ?string $emailDestino = null): array
    {
        // Asegurar precisión completa al serializar floats (por si el hosting tiene serialize_precision < 0).
        @ini_set('serialize_precision', '-1');

        $documentTypeId = $this->comprobanteFactura($venta);
        $sellerId = (int) ConfiguracionPdv::obtener('siigo_seller_id');

        if (!$documentTypeId) {
            throw new Exception('No se ha configurado el tipo de documento para facturas en SIIGO.');
        }

        $payload = [
            'document' => ['id' => $documentTypeId],
            'date' => now()->format('Y-m-d'),
            'customer' => [
                'identification' => $customerIdentification,
                'branch_office' => 0,
            ],
            'stamp' => ['send' => true],
            'mail' => ['send' => $sendEmail],
            // Marcador de versión de código para verificar deploy (ver logs SIIGO)
            'observations' => "Venta PdV #{$venta->numero_venta} [v-precision-6]",
            'items' => $this->construirItems($venta),
            'payments' => $this->construirPayments($venta),
        ];

        return $payload;
    }

    /**
     * Convertir precio del catálogo (con IVA incluido) a base gravable.
     * Los productos en SIIGO Nube están homologados sin IVA, así que SIIGO suma
     * el 19% por encima del precio enviado. Se divide entre 1.19 para que al
     * recalcular el total coincida con el precio del catálogo.
     *
     * OJO: se usan 4 decimales (no 2) porque con 2 decimales, precios "redondos"
     * como $17.000 dan base=14285.71 → SIIGO calcula 14285.71*1.19=16999.99 y
     * como enviamos payment=17000 rechaza con 500 "Unhandled" (mismatch de 1 ct).
     * Con base=14285.7143 → SIIGO redondea a 17000.00 exacto.
     */
    private function calcularPrecioBase(float $precioConIva): float
    {
        return round($precioConIva / (1 + self::SIIGO_IVA_PORCENTAJE / 100), 6);
    }

    /**
     * POST factura con fix adaptativo: si SIIGO responde "total invoice calculated is X.XX",
     * ajusta payment.value a ese valor y reintenta UNA vez. Evita perseguir la matemática
     * interna de SIIGO (que redondea prices distinto de nosotros según el ticket).
     * Solo aplica al endpoint /v1/invoices. Retorna [respuesta, payload_efectivamente_enviado].
     */
    private function postFacturaConAjustePago(string $endpoint, array $payload, FacturaSiigo $factura): array
    {
        try {
            $response = $this->api->post($endpoint, $payload, $factura->id);
            return [$response, $payload];
        } catch (Exception $e) {
            if ($endpoint === '/v1/invoices'
                && isset($payload['payments'][0]['value'])
                && preg_match('/total invoice calculated is\s+([0-9]+(?:\.[0-9]+)?)/i', $e->getMessage(), $m)
            ) {
                $totalSiigo = (float) $m[1];
                Log::info("SIIGO ajuste adaptativo factura {$factura->id}: payment {$payload['payments'][0]['value']} -> {$totalSiigo}");
                $payload['payments'][0]['value'] = round($totalSiigo, 2);
                $response = $this->api->post($endpoint, $payload, $factura->id);
                return [$response, $payload];
            }
            throw $e;
        }
    }

    /**
     * Build items array for SIIGO.
     */
    private function construirItems(VentaPdv $venta): array
    {
        $taxId = ConfiguracionPdv::obtener('siigo_tax_id');
        $sellerId = (int) ConfiguracionPdv::obtener('siigo_seller_id');
        $items = [];

        foreach ($venta->items as $item) {
            $producto = $item->producto;
            $variante = $item->variante;

            $code = $variante
                ? ($variante->siigo_product_code
                    ?? $producto->siigo_product_code
                    ?? $variante->sku
                    ?? $variante->referencia_variante
                    ?? $producto->referencia
                    ?? 'PROD-' . $item->producto_id)
                : ($producto->siigo_product_code
                    ?? $producto->referencia
                    ?? 'PROD-' . $item->producto_id);

            $description = $producto->nombre ?? 'Producto';
            if ($variante) {
                $description .= ' - ' . ($variante->referencia_variante ?? $variante->sku ?? '');
            }

            $itemData = [
                'code' => substr($code, 0, 50), // SIIGO code max length
                'description' => substr($description, 0, 250),
                'quantity' => (int) $item->cantidad,
                'price' => $taxId
                    ? $this->calcularPrecioBase((float) $item->precio_unitario)
                    : round((float) $item->precio_unitario, 2),
            ];

            // Add discount if any
            $descuentoPorcentaje = (float) ($item->descuento_porcentaje ?? 0);
            if ($descuentoPorcentaje > 0) {
                $itemData['discount'] = round($descuentoPorcentaje, 2);
            }

            if ($taxId) {
                $itemData['taxes'] = [['id' => (int) $taxId]];
            }

            // Add seller per item if configured (required when seller_by_item is true in SIIGO)
            if ($sellerId) {
                $itemData['seller'] = $sellerId;
            }

            $items[] = $itemData;
        }

        return $items;
    }

    /**
     * Build payments array for SIIGO.
     * The payment value must match what SIIGO calculates from items (before global discount).
     */
    private function construirPayments(VentaPdv $venta): array
    {
        // Seleccionar tipo de pago según el método de pago de la venta
        if ($venta->metodo_pago === 'efectivo') {
            $paymentTypeId = (int) ConfiguracionPdv::obtener('siigo_payment_type_efectivo_id');
        } else {
            // 'transferencia' y 'mixto' usan el tipo de pago de transferencia
            $paymentTypeId = (int) ConfiguracionPdv::obtener('siigo_payment_type_transferencia_id');
        }

        if (!$paymentTypeId) {
            throw new Exception('No se ha configurado el tipo de pago en SIIGO para el método: ' . ($venta->metodo_pago ?? 'desconocido'));
        }

        $taxId = ConfiguracionPdv::obtener('siigo_tax_id');
        $ivaTasa = self::SIIGO_IVA_PORCENTAJE / 100;

        // Replicar EXACTO el redondeo de SIIGO por línea: line_total = round(base * qty * (1 + IVA), 2).
        // Antes usábamos round(iva) + subtotal y quedaba 1 centavo por debajo del total real de SIIGO
        // (ej. base=14285.71 → 14285.71 + round(2714.2849,2)=2714.28 = 16999.99, pero SIIGO calcula
        //  round(14285.71*1.19,2)=17000.00). Ese descuadre disparaba 500 "Unhandled" al validar el pago.
        $totalItems = 0.0;
        foreach ($venta->items as $item) {
            $precioBase = $taxId
                ? $this->calcularPrecioBase((float) $item->precio_unitario)
                : round((float) $item->precio_unitario, 2);

            $itemSubtotal = $precioBase * (int) $item->cantidad;
            $descPorcentaje = (float) ($item->descuento_porcentaje ?? 0);
            if ($descPorcentaje > 0) {
                $itemSubtotal -= $itemSubtotal * ($descPorcentaje / 100);
                $itemSubtotal = round($itemSubtotal, 2);
            }

            if ($taxId) {
                $itemTotal = round($itemSubtotal * (1 + $ivaTasa), 2);
            } else {
                $itemTotal = round($itemSubtotal, 2);
            }

            $totalItems += $itemTotal;
        }

        return [
            [
                'id' => $paymentTypeId,
                'value' => round($totalItems, 2),
                'due_date' => now()->format('Y-m-d'),
            ],
        ];
    }

    /**
     * Parse SIIGO response and update factura status.
     */
    private function procesarEstadoRespuesta(FacturaSiigo $factura, array $response): void
    {
        $stamp = $response['stamp'] ?? null;

        if ($stamp) {
            $status = strtolower($stamp['status'] ?? '');
            $cufe = $stamp['cufe'] ?? $stamp['cude'] ?? null;
            $errors = $stamp['errors'] ?? $stamp['observations'] ?? null;

            if ($cufe && !empty($cufe) && $cufe !== 'string') {
                $factura->marcarAprobada($cufe, $factura->numero_factura);
            } elseif ($status === 'rejected' || $status === 'rechazada') {
                $factura->marcarRechazada($errors);
            }
            // Otherwise stays as 'pendiente'
        }

        $mail = $response['mail'] ?? null;
        if ($mail) {
            $mailStatus = strtolower($mail['status'] ?? '');
            if (in_array($mailStatus, ['sent', 'enviado', 'success'])) {
                $factura->update(['estado_envio_email' => 'enviado']);
            }
        }
    }

    /**
     * Build items array from a partial return (DevolucionParcialPdv).
     */
    private function construirItemsDesdeDevolucion(DevolucionParcialPdv $devolucion): array
    {
        $taxId = ConfiguracionPdv::obtener('siigo_tax_id');
        $sellerId = (int) ConfiguracionPdv::obtener('siigo_seller_id');
        $items = [];

        foreach ($devolucion->items as $itemDev) {
            $producto = $itemDev->producto;
            $variante = $itemDev->variante;

            $code = $variante
                ? ($variante->siigo_product_code
                    ?? $producto->siigo_product_code
                    ?? $variante->sku
                    ?? $variante->referencia_variante
                    ?? $producto->referencia
                    ?? 'PROD-' . $itemDev->producto_id)
                : ($producto->siigo_product_code
                    ?? $producto->referencia
                    ?? 'PROD-' . $itemDev->producto_id);

            $description = $producto->nombre ?? 'Producto';
            if ($variante) {
                $description .= ' - ' . ($variante->referencia_variante ?? $variante->sku ?? '');
            }

            $itemData = [
                'code' => substr($code, 0, 50),
                'description' => substr($description, 0, 250),
                'quantity' => (int) $itemDev->cantidad_devuelta,
                'price' => $taxId
                    ? $this->calcularPrecioBase((float) $itemDev->precio_unitario)
                    : round((float) $itemDev->precio_unitario, 2),
            ];

            $descuentoPorcentaje = (float) ($itemDev->descuento_porcentaje ?? 0);
            if ($descuentoPorcentaje > 0) {
                $itemData['discount'] = round($descuentoPorcentaje, 2);
            }

            if ($taxId) {
                $itemData['taxes'] = [['id' => (int) $taxId]];
            }

            if ($sellerId) {
                $itemData['seller'] = $sellerId;
            }

            $items[] = $itemData;
        }

        return $items;
    }

    /**
     * Build payments array from a partial return.
     */
    private function construirPaymentsDesdeDevolucion(VentaPdv $venta, DevolucionParcialPdv $devolucion): array
    {
        if ($venta->metodo_pago === 'efectivo') {
            $paymentTypeId = (int) ConfiguracionPdv::obtener('siigo_payment_type_efectivo_id');
        } else {
            $paymentTypeId = (int) ConfiguracionPdv::obtener('siigo_payment_type_transferencia_id');
        }

        if (!$paymentTypeId) {
            throw new Exception('No se ha configurado el tipo de pago en SIIGO para el método: ' . ($venta->metodo_pago ?? 'desconocido'));
        }

        $taxId = ConfiguracionPdv::obtener('siigo_tax_id');
        $ivaTasa = self::SIIGO_IVA_PORCENTAJE / 100;

        $totalItems = 0.0;
        foreach ($devolucion->items as $itemDev) {
            $precioBase = $taxId
                ? $this->calcularPrecioBase((float) $itemDev->precio_unitario)
                : round((float) $itemDev->precio_unitario, 2);

            $itemSubtotal = $precioBase * (int) $itemDev->cantidad_devuelta;
            $descPorcentaje = (float) ($itemDev->descuento_porcentaje ?? 0);
            if ($descPorcentaje > 0) {
                $itemSubtotal -= $itemSubtotal * ($descPorcentaje / 100);
                $itemSubtotal = round($itemSubtotal, 2);
            }

            if ($taxId) {
                $iva = round($itemSubtotal * $ivaTasa, 2);
                $itemTotal = round($itemSubtotal + $iva, 2);
            } else {
                $itemTotal = round($itemSubtotal, 2);
            }

            $totalItems += $itemTotal;
        }

        return [
            [
                'id' => $paymentTypeId,
                'value' => round($totalItems, 2),
                'due_date' => now()->format('Y-m-d'),
            ],
        ];
    }

    /**
     * Parse a full name into [first_name, last_name] array.
     */
    private function parsearNombre(string $nombre): array
    {
        $parts = explode(' ', trim($nombre), 2);
        return [
            $parts[0] ?? 'Cliente',
            $parts[1] ?? '',
        ];
    }

    /**
     * Extraer prefix y number de un numero_factura tipo "FV-14047" → ["FV", 14047].
     * Si no logra parsear, retorna [null, null].
     */
    private function extraerPrefixNumero(?string $numeroFactura): array
    {
        if (!$numeroFactura) return [null, null];

        // Soporta "FV-14047", "FV-2-14047" (toma el último número)
        if (preg_match('/^([A-Za-z]+)[-\s]+(?:\d+[-\s]+)?(\d+)$/', trim($numeroFactura), $m)) {
            return [strtoupper($m[1]), (int) $m[2]];
        }

        return [null, null];
    }
}
