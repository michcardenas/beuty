<?php

namespace App\Services\Siigo;

use App\Models\ConfiguracionPdv;
use App\Models\FacturaSiigo;
use App\Models\SolicitudCotizacion;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Factura electrónica (SIIGO → DIAN) de una cotización.
 *
 * Diferencias con la venta del PDV (SiigoFacturacionService):
 * - IVA (decisión de Miracle, 15-oct-2026): si la cotización no tiene «IVA %»,
 *   sus precios YA incluyen el 19 % (como en el PDV) y se descuenta (÷ 1,19).
 *   Si lo tiene (0, 5 o 19 %), la cotización sumó ese IVA encima del precio,
 *   así que el precio es la base. En ambos casos el total de la factura es el
 *   de la cotización.
 * - El descuento es global (descuento_total): se reparte como porcentaje
 *   sobre los productos.
 * - El flete va como una línea aparte con el código configurado en SIIGO.
 * - Una venta a crédito se envía con el medio de pago «crédito» y la fecha
 *   de vencimiento de la cotización; la mixta, en dos pagos.
 * - Se factura con el comprobante general (la cotización no tiene sede).
 */
class SiigoCotizacionService
{
    /** Un envío sin respuesta de SIIGO más reciente que esto se considera en curso. */
    public const MINUTOS_ENVIO_EN_CURSO = 5;

    private SiigoApiClient $api;
    private SiigoFacturacionService $facturacion;

    public function __construct(SiigoApiClient $api, SiigoFacturacionService $facturacion)
    {
        $this->api = $api;
        $this->facturacion = $facturacion;
    }

    /**
     * Revisión de datos antes de enviar: lo que bloquea el envío, lo que conviene
     * mirar y el detalle de lo que se va a mandar. No llama a SIIGO.
     *
     * @return array{errores: string[], avisos: string[], cliente: array, lineas: array, pagos: array, totales: array}
     */
    public function revisar(SolicitudCotizacion $cotizacion): array
    {
        $cotizacion->loadMissing(['items.producto', 'items.varianteProducto', 'cliente', 'facturaSiigo']);

        $errores = [];
        $avisos = [];

        if (!$this->api->estaConfigurado() || !ConfiguracionPdv::obtenerBoolean('siigo_activo', false)) {
            $errores[] = 'SIIGO no está activo o le faltan las credenciales (PDV → Configuración SIIGO).';
        }
        if ($cotizacion->tieneFacturaElectronica()) {
            $errores[] = 'La cotización ya tiene la factura electrónica ' . ($cotizacion->facturaSiigo->numero_factura ?? '') . '.';
        } elseif ($this->envioEnCurso($cotizacion)) {
            $errores[] = 'Ya hay un envío a la DIAN en curso para esta cotización. Espere unos minutos.';
        } elseif ($cotizacion->facturaSiigo && !$cotizacion->facturaSiigo->puedeReintentar()) {
            $errores[] = 'Se alcanzó el máximo de intentos de envío para esta cotización. Revise el error en SIIGO.';
        } elseif (!$cotizacion->puedeFacturarSiigo()) {
            $errores[] = $cotizacion->estado !== SolicitudCotizacion::ESTADO_APLICADA
                ? 'La cotización debe estar aplicada.'
                : 'La cotización debe estar pagada o vendida a crédito.';
        }

        // Cliente
        $cliente = $cotizacion->cliente;
        $identificacion = $cliente ? ($cliente->numero_identificacion ?: $cliente->nit) : null;
        if (!$cliente) {
            $errores[] = 'La cotización no tiene cliente.';
        } else {
            if (!$identificacion) {
                $errores[] = 'El cliente no tiene número de identificación ni NIT.';
            }
            if (!$cliente->email) {
                $avisos[] = 'El cliente no tiene correo: SIIGO no le enviará la factura.';
            }
            if (!$cliente->siigo_id) {
                $avisos[] = 'El cliente aún no existe en SIIGO: se creará al enviar (con ciudad Bogotá y responsabilidad R-99-PN).';
            }
        }

        // Comprobante, impuesto y vendedor
        if (!(int) ConfiguracionPdv::obtener('siigo_document_type_id')) {
            $errores[] = 'No hay comprobante de factura de venta configurado en SIIGO.';
        }
        [$porcentajeIva] = $this->iva($cotizacion);
        $taxId = $this->impuestoPara($porcentajeIva);
        if ($porcentajeIva > 0 && !$taxId) {
            $errores[] = "No hay impuesto de IVA {$porcentajeIva} % configurado en SIIGO.";
        }

        // Productos
        if ($cotizacion->items->isEmpty()) {
            $errores[] = 'La cotización no tiene productos.';
        }
        foreach ($cotizacion->items as $item) {
            if (!$this->codigoSiigoPropio($item)) {
                $avisos[] = "«{$this->descripcion($item)}» no está homologado con SIIGO: se enviará con el código {$this->codigoProducto($item)}.";
            }
            if ((float) $item->cantidad != (int) $item->cantidad) {
                $errores[] = "«{$this->descripcion($item)}» tiene cantidad decimal ({$item->cantidad}).";
            }
        }

        // Flete
        if ((float) $cotizacion->valor_flete > 0 && !ConfiguracionPdv::obtener('siigo_codigo_flete')) {
            $errores[] = 'La cotización tiene flete y no hay código de flete configurado en SIIGO.';
        }

        // Pagos
        $pagos = [];
        $totales = [];
        if ($cotizacion->items->isNotEmpty()) {
            try {
                $items = $this->construirItems($cotizacion);
                $totales = $this->calcularTotales($items, $cotizacion);
                $pagos = $this->construirPayments($cotizacion, $totales['total']);
            } catch (Exception $e) {
                $errores[] = $e->getMessage();
            }
        }

        if ($totales && abs($totales['total'] - (float) $cotizacion->monto_total_con_iva) >= 1) {
            $avisos[] = 'El total de la factura ($' . number_format($totales['total'], 0, ',', '.')
                . ') no coincide con el de la cotización ($' . number_format((float) $cotizacion->monto_total_con_iva, 0, ',', '.')
                . '). En la factura el IVA se calcula después del descuento.';
        }

        return [
            'errores' => array_values(array_unique($errores)),
            'avisos' => array_values(array_unique($avisos)),
            'cliente' => [
                'nombre' => $cliente ? ($cliente->razon_social ?: $cliente->nombre_contacto) : null,
                'identificacion' => $identificacion,
                'email' => $cliente?->email,
                'en_siigo' => (bool) $cliente?->siigo_id,
            ],
            'lineas' => $items ?? [],
            'pagos' => $pagos,
            'totales' => $totales,
        ];
    }

    /**
     * Envía la cotización a SIIGO para que la emita ante la DIAN.
     *
     * Bloquea el doble envío: dentro de una transacción con la cotización
     * bloqueada se comprueba que no tenga factura ni un envío en curso y se
     * deja creada la factura (pendiente); un segundo clic, otra pestaña u otra
     * usuaria encuentran esa fila y se detienen.
     */
    public function emitir(SolicitudCotizacion $cotizacion): FacturaSiigo
    {
        $revision = $this->revisar($cotizacion);
        if ($revision['errores']) {
            throw new Exception(implode(' ', $revision['errores']));
        }

        $cliente = $cotizacion->cliente;
        $this->facturacion->resolverCliente($cliente);
        $cliente->refresh();

        $factura = DB::transaction(function () use ($cotizacion) {
            $bloqueada = SolicitudCotizacion::whereKey($cotizacion->id)->lockForUpdate()->first();
            $bloqueada->load(['facturaSiigo', 'items.producto', 'items.varianteProducto', 'cliente']);

            if ($bloqueada->tieneFacturaElectronica() || $this->envioEnCurso($bloqueada)) {
                throw new Exception('Esta cotización ya se envió a la DIAN.');
            }

            $payload = $this->construirPayload($bloqueada);
            $totales = $this->calcularTotales($payload['items'], $bloqueada);
            $datos = [
                'siigo_document_type_id' => $payload['document']['id'],
                'fecha_emision' => now()->toDateString(),
                'subtotal' => $totales['subtotal'] - $totales['descuento'],
                'iva' => $totales['iva'],
                'total' => $totales['total'],
                'estado_dian' => 'pendiente',
                'siigo_invoice_id' => null,
                'email_destino' => $bloqueada->cliente->email,
                'siigo_request' => $payload,
                // Marca el envío como en curso para cualquier otro clic.
                'ultimo_intento_en' => now(),
            ];

            // Un envío anterior que falló se reintenta sobre la misma fila.
            $anterior = $bloqueada->facturaSiigo;
            if ($anterior) {
                if (!$anterior->puedeReintentar()) {
                    throw new Exception('Se alcanzó el máximo de intentos de envío para esta cotización. Revise el error en SIIGO.');
                }
                $anterior->update($datos);

                return $anterior;
            }

            $factura = FacturaSiigo::create($datos + [
                'solicitud_cotizacion_id' => $bloqueada->id,
                'tipo_documento' => 'factura_venta',
                'cliente_id' => $bloqueada->cliente_id,
                'usuario_id' => auth()->id(),
            ]);
            $bloqueada->update(['factura_siigo_id' => $factura->id]);

            return $factura;
        });

        return $this->enviar($factura, $factura->siigo_request);
    }

    /**
     * Reintento pedido desde el PDV (SiigoFacturacionService::reintentarFactura):
     * es el mismo envío, que reconstruye la factura con los datos actuales.
     */
    public function reintentar(FacturaSiigo $factura): FacturaSiigo
    {
        $cotizacion = $factura->solicitudCotizacion;
        if (!$cotizacion) {
            throw new Exception('No se pudo cargar la cotización de la factura.');
        }

        return $this->emitir($cotizacion);
    }

    /**
     * Hay una factura creada hace poco que todavía no tiene respuesta de SIIGO:
     * alguien la está enviando en este momento.
     */
    public function envioEnCurso(SolicitudCotizacion $cotizacion): bool
    {
        $factura = $cotizacion->facturaSiigo;

        return $factura
            && $factura->estado_dian === 'pendiente'
            && !$factura->siigo_invoice_id
            && ($factura->ultimo_intento_en ?? $factura->created_at)?->gt(now()->subMinutes(self::MINUTOS_ENVIO_EN_CURSO));
    }

    private function enviar(FacturaSiigo $factura, array $payload): FacturaSiigo
    {
        $factura->incrementarIntento();

        // Misma factura y mismo contenido = misma llave: si SIIGO ya la creó
        // (p. ej. respondió tarde y el cliente HTTP reintentó), no crea otra.
        // Si se corrigieron datos, el contenido cambia y la llave también.
        $idempotencia = ['Idempotency-Key' => "miracle-cot-fs{$factura->id}-" . substr(md5(json_encode($payload)), 0, 16)];

        try {
            [$response, $payload] = $this->postConAjustePago($payload, $factura, $idempotencia);

            $factura->update([
                'siigo_response' => $response,
                'siigo_request' => $payload,
                'siigo_invoice_id' => $response['id'] ?? null,
                'numero_factura' => isset($response['prefix'], $response['number'])
                    ? $response['prefix'] . '-' . $response['number']
                    : ($response['name'] ?? null),
                'total' => $response['total'] ?? $factura->total,
                'estado_dian' => 'pendiente',
                'errores' => null,
            ]);

            $this->facturacion->procesarEstadoRespuesta($factura, $response);

            $factura->solicitudCotizacion?->update([
                'factura_siigo_id' => $factura->id,
                'numero_factura' => $factura->numero_factura,
                'facturada_en' => now(),
                'facturada_por' => auth()->id(),
            ]);

            return $factura->fresh();
        } catch (Exception $e) {
            $factura->marcarError($e->getMessage());
            Log::error("SIIGO factura de cotización {$factura->solicitud_cotizacion_id}: {$e->getMessage()}");

            return $factura->fresh();
        }
    }

    /**
     * Si SIIGO calcula un total distinto por redondeos («total invoice calculated
     * is X»), ajusta el último pago a la diferencia y reintenta UNA vez.
     */
    private function postConAjustePago(array $payload, FacturaSiigo $factura, array $headers): array
    {
        try {
            return [$this->api->post('/v1/invoices', $payload, $factura->id, $headers), $payload];
        } catch (Exception $e) {
            if (!preg_match('/total invoice calculated is\s+([0-9]+(?:\.[0-9]+)?)/i', $e->getMessage(), $m)) {
                throw $e;
            }

            $totalSiigo = (float) $m[1];
            $ultimo = count($payload['payments']) - 1;
            $otros = array_sum(array_column(array_slice($payload['payments'], 0, $ultimo), 'value'));
            $payload['payments'][$ultimo]['value'] = round($totalSiigo - $otros, 2);
            Log::info("SIIGO ajuste de pago factura {$factura->id}: total SIIGO {$totalSiigo}");

            $headers['Idempotency-Key'] .= '-ajuste';

            return [$this->api->post('/v1/invoices', $payload, $factura->id, $headers), $payload];
        }
    }

    private function construirPayload(SolicitudCotizacion $cotizacion): array
    {
        @ini_set('serialize_precision', '-1');
        $cotizacion->loadMissing(['items.producto', 'items.varianteProducto', 'cliente']);

        $documentTypeId = (int) ConfiguracionPdv::obtener('siigo_document_type_id');
        if (!$documentTypeId) {
            throw new Exception('No se ha configurado el tipo de documento para facturas en SIIGO.');
        }

        $cliente = $cotizacion->cliente;
        $items = $this->construirItems($cotizacion);
        $totales = $this->calcularTotales($items, $cotizacion);

        $observaciones = "Cotización #{$cotizacion->numero_solicitud}";
        if ($cotizacion->forma_pago_factura) {
            $observaciones .= " · {$cotizacion->forma_pago_factura}";
        }

        return [
            'document' => ['id' => $documentTypeId],
            'date' => now()->format('Y-m-d'),
            'customer' => [
                'identification' => (string) ($cliente->numero_identificacion ?: $cliente->nit),
                'branch_office' => 0,
            ],
            'stamp' => ['send' => true],
            'mail' => ['send' => (bool) $cliente->email],
            'observations' => $observaciones,
            'items' => $items,
            'payments' => $this->construirPayments($cotizacion, $totales['total']),
        ];
    }

    /**
     * Productos de la cotización (precio sin IVA, como está en la cotización)
     * con el descuento global repartido en porcentaje, más la línea de flete.
     */
    private function construirItems(SolicitudCotizacion $cotizacion): array
    {
        [$porcentajeIva, $incluido] = $this->iva($cotizacion);
        $taxId = $this->impuestoPara($porcentajeIva);
        $sellerId = (int) ConfiguracionPdv::obtener('siigo_seller_id');
        $descuento = $this->porcentajeDescuento($cotizacion);
        $items = [];

        foreach ($cotizacion->items as $item) {
            $linea = [
                'code' => substr($this->codigoProducto($item), 0, 50),
                'description' => substr($this->descripcion($item), 0, 250),
                'quantity' => (int) $item->cantidad,
                'price' => $incluido && $taxId
                    ? round((float) $item->precio_unitario / (1 + $porcentajeIva / 100), 6)
                    : round((float) $item->precio_unitario, 6),
            ];
            if ($descuento > 0) {
                $linea['discount'] = $descuento;
            }
            if ($taxId) {
                $linea['taxes'] = [['id' => $taxId]];
            }
            if ($sellerId) {
                $linea['seller'] = $sellerId;
            }
            $items[] = $linea;
        }

        if ((float) $cotizacion->valor_flete > 0) {
            $codigoFlete = ConfiguracionPdv::obtener('siigo_codigo_flete');
            if (!$codigoFlete) {
                throw new Exception('La cotización tiene flete y no hay código de flete configurado en SIIGO.');
            }
            // El flete no lleva IVA ni descuento, igual que en la cotización.
            $flete = [
                'code' => substr($codigoFlete, 0, 50),
                'description' => 'Flete',
                'quantity' => 1,
                'price' => round((float) $cotizacion->valor_flete, 2),
            ];
            if ($sellerId) {
                $flete['seller'] = $sellerId;
            }
            $items[] = $flete;
        }

        return $items;
    }

    /**
     * Total como lo calcula SIIGO: por línea, base menos descuento, y el IVA
     * sobre eso, redondeando cada línea a 2 decimales.
     */
    private function calcularTotales(array $items, SolicitudCotizacion $cotizacion): array
    {
        $tasa = $this->iva($cotizacion)[0] / 100;
        $subtotal = 0.0;
        $descuento = 0.0;
        $iva = 0.0;

        foreach ($items as $linea) {
            $bruto = round($linea['price'] * $linea['quantity'], 2);
            $desc = isset($linea['discount']) ? round($bruto * $linea['discount'] / 100, 2) : 0.0;
            $base = $bruto - $desc;
            $subtotal += $bruto;
            $descuento += $desc;
            if (!empty($linea['taxes'])) {
                $iva += round($base * $tasa, 2);
            }
        }

        return [
            'subtotal' => round($subtotal, 2),
            'descuento' => round($descuento, 2),
            'iva' => round($iva, 2),
            'total' => round($subtotal - $descuento + $iva, 2),
        ];
    }

    /**
     * Contado: un pago por el medio de la cotización. Crédito: todo a crédito
     * con vencimiento. Mixto: lo de contado y el resto a crédito.
     */
    private function construirPayments(SolicitudCotizacion $cotizacion, float $total): array
    {
        $vencimiento = ($cotizacion->fecha_vencimiento ?? now())->format('Y-m-d');
        $hoy = now()->format('Y-m-d');

        if (!$cotizacion->esVentaACredito()) {
            return [['id' => $this->medioContado($cotizacion), 'value' => $total, 'due_date' => $hoy]];
        }

        $credito = (int) ConfiguracionPdv::obtener('siigo_payment_type_credito_id');
        if (!$credito) {
            throw new Exception('La cotización es a crédito y no hay medio de pago «crédito» configurado en SIIGO.');
        }

        $esMixto = str_starts_with($cotizacion->forma_pago_factura, 'Mixto');
        $montoCredito = $esMixto ? min((float) $cotizacion->monto_credito, $total) : $total;
        $contado = round($total - $montoCredito, 2);

        $pagos = [];
        if ($contado > 0) {
            $pagos[] = ['id' => $this->medioContado($cotizacion), 'value' => $contado, 'due_date' => $hoy];
        }
        $pagos[] = ['id' => $credito, 'value' => round($montoCredito, 2), 'due_date' => $vencimiento];

        return $pagos;
    }

    private function medioContado(SolicitudCotizacion $cotizacion): int
    {
        $clave = $cotizacion->metodo_pago === 'efectivo'
            ? 'siigo_payment_type_efectivo_id'
            : 'siigo_payment_type_transferencia_id';
        $id = (int) ConfiguracionPdv::obtener($clave);

        if (!$id) {
            throw new Exception('No se ha configurado el medio de pago en SIIGO para: ' . ($cotizacion->metodo_pago ?: 'transferencia'));
        }

        return $id;
    }

    /**
     * Impuesto de SIIGO para el IVA de la cotización: el general es el de 19 %
     * (el mismo del PDV); el de 5 % se configura aparte. 0 % = sin impuesto.
     */
    /**
     * [porcentaje, el precio ya lo incluye]. Sin «IVA %» en la cotización, 19 %
     * incluido; con él, ese porcentaje sumado encima.
     */
    private function iva(SolicitudCotizacion $cotizacion): array
    {
        if ($cotizacion->porcentaje_iva === null || $cotizacion->porcentaje_iva === '') {
            return [19.0, true];
        }

        return [(float) $cotizacion->porcentaje_iva, false];
    }

    private function impuestoPara(float $porcentaje): ?int
    {
        if ($porcentaje <= 0) {
            return null;
        }

        $id = (int) ConfiguracionPdv::obtener(abs($porcentaje - 5) < 0.01 ? 'siigo_tax_iva5_id' : 'siigo_tax_id');

        return $id ?: null;
    }

    /**
     * El descuento global de la cotización como porcentaje de los productos.
     */
    private function porcentajeDescuento(SolicitudCotizacion $cotizacion): float
    {
        $descuento = (float) $cotizacion->descuento_total;
        $productos = (float) $cotizacion->items->sum(fn ($i) => $i->precio_unitario * $i->cantidad);

        if ($descuento <= 0 || $productos <= 0) {
            return 0.0;
        }

        return round(min(100, $descuento / $productos * 100), 2);
    }

    private function codigoSiigoPropio($item): ?string
    {
        return $item->varianteProducto?->siigo_product_code ?: $item->producto?->siigo_product_code;
    }

    /**
     * Mismo orden que el PDV: código homologado, luego SKU / referencias.
     */
    private function codigoProducto($item): string
    {
        $variante = $item->varianteProducto;
        $producto = $item->producto;

        return $this->codigoSiigoPropio($item)
            ?: $variante?->sku
            ?: $variante?->referencia_variante
            ?: $producto?->referencia
            ?: $item->referencia_producto
            ?: 'PROD-' . $item->producto_id;
    }

    private function descripcion($item): string
    {
        $nombre = $item->nombre_producto ?: ($item->producto->nombre ?? 'Producto');
        if ($item->info_variante) {
            $nombre .= ' - ' . $item->info_variante;
        }

        return $nombre;
    }
}
