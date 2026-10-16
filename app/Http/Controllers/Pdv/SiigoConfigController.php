<?php

namespace App\Http\Controllers\Pdv;

use App\Http\Controllers\Controller;
use App\Models\Caja;
use App\Models\Ubicacion;
use App\Models\User;
use App\Services\Siigo\SiigoApiClient;
use App\Services\Siigo\SiigoConfigService;
use Illuminate\Http\Request;

class SiigoConfigController extends Controller
{
    private SiigoConfigService $configService;

    public function __construct(SiigoConfigService $configService)
    {
        $this->configService = $configService;
    }

    public function index()
    {
        $config = $this->configService->obtenerConfiguracionActual();
        $sedes = $this->sedesQueFacturan();
        $posiblesFacturadores = $this->posiblesFacturadores();
        return view('pdv.siigo.configuracion', compact('config', 'sedes', 'posiblesFacturadores'));
    }

    /**
     * Quienes pueden entrar a cotizaciones con un rol de oficina: entre ellos se
     * elige a quién se le permite enviar cotizaciones a la DIAN.
     */
    private function posiblesFacturadores()
    {
        return User::where('activo', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', User::ROLES_FACTURAN_COTIZACIONES))
            ->orderBy('name')
            ->get();
    }

    /**
     * Sedes que venden, y por tanto facturan: las tiendas activas y cualquier
     * ubicación que tenga una caja.
     */
    private function sedesQueFacturan()
    {
        return Ubicacion::where('activo', true)
            ->where(function ($q) {
                $q->where('tipo', Ubicacion::TIPO_TIENDA)
                  ->orWhereIn('id', Caja::select('ubicacion_id'));
            })
            ->orderByDesc('es_principal')
            ->orderBy('nombre')
            ->get();
    }

    public function guardar(Request $request)
    {
        $request->validate([
            'siigo_username' => 'nullable|email|max:255',
            'siigo_access_key' => 'nullable|string|max:500',
            'siigo_username_test' => 'nullable|email|max:255',
            'siigo_access_key_test' => 'nullable|string|max:500',
            'siigo_partner_id' => 'nullable|string|max:100',
            'siigo_document_type_id' => 'nullable|integer',
            'siigo_credit_note_type_id' => 'nullable|integer',
            'siigo_payment_type_efectivo_id' => 'nullable|integer',
            'siigo_payment_type_transferencia_id' => 'nullable|integer',
            'siigo_tax_id' => 'nullable|integer',
            'siigo_seller_id' => 'nullable|integer',
            'siigo_consumidor_final_nit' => 'nullable|string|max:20',
            'siigo_max_reintentos' => 'nullable|integer|min:1|max:10',
            'siigo_payment_type_credito_id' => 'nullable|integer',
            'siigo_tax_iva5_id' => 'nullable|integer',
            'siigo_codigo_flete' => 'nullable|string|max:50',
            'facturadores' => 'nullable|array',
            'facturadores.*' => 'integer',
            'sedes' => 'nullable|array',
            'sedes.*.siigo_document_type_id' => 'nullable|integer',
            'sedes.*.siigo_credit_note_type_id' => 'nullable|integer',
        ]);

        $data = $request->only([
            'siigo_username', 'siigo_access_key',
            'siigo_username_test', 'siigo_access_key_test',
            'siigo_partner_id',
            'siigo_document_type_id', 'siigo_credit_note_type_id',
            'siigo_payment_type_efectivo_id', 'siigo_payment_type_transferencia_id',
            'siigo_tax_id', 'siigo_seller_id',
            'siigo_consumidor_final_nit', 'siigo_max_reintentos',
            'siigo_payment_type_credito_id', 'siigo_tax_iva5_id', 'siigo_codigo_flete',
        ]);

        $data['siigo_activo'] = $request->boolean('siigo_activo') ? 'true' : 'false';
        $data['siigo_modo'] = $request->input('siigo_modo', 'test');
        $data['siigo_facturar_siempre'] = $request->boolean('siigo_facturar_siempre') ? 'true' : 'false';

        $this->configService->guardarConfiguracion($data);

        // Numeración propia por sede (vacío = usa el comprobante general)
        $sedesValidas = $this->sedesQueFacturan()->keyBy('id');
        foreach ((array) $request->input('sedes', []) as $ubicacionId => $valores) {
            $sede = $sedesValidas->get((int) $ubicacionId);
            if (!$sede) {
                continue;
            }
            $sede->update([
                'siigo_document_type_id' => ($valores['siigo_document_type_id'] ?? null) ?: null,
                'siigo_credit_note_type_id' => ($valores['siigo_credit_note_type_id'] ?? null) ?: null,
            ]);
        }

        // Quién puede enviar cotizaciones a la DIAN
        $elegidos = array_map('intval', (array) $request->input('facturadores', []));
        foreach ($this->posiblesFacturadores() as $usuario) {
            in_array($usuario->id, $elegidos, true)
                ? $usuario->givePermissionTo(User::PERMISO_FACTURAR_COTIZACIONES)
                : $usuario->revokePermissionTo(User::PERMISO_FACTURAR_COTIZACIONES);
        }

        return redirect()->route('pdv.siigo.config')
            ->with('success', 'Configuración de SIIGO guardada exitosamente.');
    }

    public function testConexion()
    {
        $resultado = $this->configService->testConexion();
        return response()->json($resultado);
    }

    public function cargarCatalogos()
    {
        $api = app(SiigoApiClient::class);
        if (!$api->estaConfigurado()) {
            return response()->json([
                'exito' => false,
                'mensaje' => 'Configure las credenciales de SIIGO primero.',
            ]);
        }

        return response()->json([
            'exito' => true,
            'document_types' => $this->configService->obtenerDocumentTypes(),
            'credit_note_types' => $this->configService->obtenerCreditNoteTypes(),
            'payment_types' => $this->configService->obtenerPaymentTypes(),
            'taxes' => $this->configService->obtenerTaxes(),
            'sellers' => $this->configService->obtenerSellers(),
        ]);
    }
}
