<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Cliente;
use App\Models\User;
use App\Models\ListaPrecio;
use App\Models\Sucursal;
use App\Models\DocumentoCliente;
use Illuminate\Validation\Rule;
use Yajra\DataTables\Facades\DataTables;
use App\Models\Departamento;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class ClientesController extends Controller
{
    public function index(Request $request)
    {
        if ($request->ajax()) {
            $query = Cliente::with(['vendedor', 'listaPrecio',
                        'pais',        // <-- relación país
            'ciudad',      // <-- relación ciudad
            'documentos',  // <-- relación documentos
            ])
            ->activos() // Solo mostrar clientes activos (no eliminados)
            ->select('clientes.*');

            // Filtrar por vendedor si no es admin ni inventarios ni facturacion
            $user = auth()->user();
            if ($user->hasRole('vendedor')) {
                $query->where('vendedor_id', $user->id);
            }

            return DataTables::of($query)
                        ->addColumn('pais', fn($c) => $c->pais?->nombre)
            ->addColumn('ciudad', fn($c) => $c->ciudad?->nombre)
                ->addColumn('vendedor', fn($c) => $c->vendedor?->name)
                ->addColumn('lista_precio', fn($c) => $c->listaPrecio?->nombre)
                ->addColumn('activo', fn($c) => $c->activo ? 'Sí' : 'No')
                ->addColumn('documentos', function($c) {
                    if ($c->documentos->isEmpty()) {
                        return '<span class="text-muted">-</span>';
                    }

                    $html = '<div class="dropdown"><button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-file-earmark-text me-1"></i>' . $c->documentos->count() . '</button>';
                    $html .= '<ul class="dropdown-menu">';
                    foreach ($c->documentos as $doc) {
                        $url = route('clientes.documentos.descargar', $doc->id);
                        $html .= '<li><a class="dropdown-item" href="' . $url . '"><i class="bi bi-download me-2"></i>' . e($doc->nombre) . '</a></li>';
                    }
                    $html .= '</ul></div>';
                    return $html;
                })
                ->addColumn('action', function($c) {
                    $user = auth()->user();
                    // Admin, auxiliar_administrativo e inventarios pueden editar y eliminar
                    if ($user->hasRole(['admin', 'auxiliar_administrativo', 'inventarios'])) {
                        $url = route('clientes.form', $c->id);
                        return <<<HTML
<div class="d-flex justify-content-center gap-1">
  <a href="{$url}" class="btn btn-outline-info btn-sm" title="Editar">
    <i class="bi bi-pencil"></i>
  </a>
  <button type="button" class="btn btn-outline-danger btn-sm" title="Eliminar" onclick="eliminarCliente({$c->id}, '{$c->nombre_contacto}')">
    <i class="bi bi-trash"></i>
  </button>
</div>
HTML;
                    }
                    // Facturación puede editar pero no eliminar
                    if ($user->hasRole('facturacion')) {
                        $url = route('clientes.form', $c->id);
                        return <<<HTML
<div class="d-flex justify-content-center gap-1">
  <a href="{$url}" class="btn btn-outline-info btn-sm" title="Editar">
    <i class="bi bi-pencil"></i>
  </a>
</div>
HTML;
                    }
                    // Vendedor solo puede ver
                    return '<span class="text-muted">-</span>';
                })
                ->filterColumn('pais', function($query, $keyword) {
                    $query->whereHas('pais', function($q) use ($keyword) {
                        $q->where('nombre', 'like', "%{$keyword}%");
                    });
                })
                ->filterColumn('ciudad', function($query, $keyword) {
                    $query->whereHas('ciudad', function($q) use ($keyword) {
                        $q->where('nombre', 'like', "%{$keyword}%");
                    });
                })
                ->filterColumn('vendedor', function($query, $keyword) {
                    $query->whereHas('vendedor', function($q) use ($keyword) {
                        $q->where('name', 'like', "%{$keyword}%");
                    });
                })
                ->filterColumn('lista_precio', function($query, $keyword) {
                    $query->whereHas('listaPrecio', function($q) use ($keyword) {
                        $q->where('nombre', 'like', "%{$keyword}%");
                    });
                })
                ->rawColumns(['action', 'documentos'])
                ->make(true);
        }

        return view('clientes.clientes_index');
    }

    public function form(Cliente $cliente = null)
    {
        $cliente = $cliente ?? new Cliente(['tipo_cliente' => 'natural']);
        $vendedores = User::role('vendedor')->pluck('name', 'id');
        $listas = ListaPrecio::activas()->pluck('nombre', 'id');
        $departamentos = Departamento::orderBy('nombre')->pluck('nombre', 'id');
        $pais_id = 1;

        // Datos adicionales para el formulario mejorado
        $tiposCliente = Cliente::tiposCliente();
        $tiposDocumento = DocumentoCliente::tiposDocumento();

        // Cargar sucursales y documentos si es edición
        $sucursales = $cliente->exists ? $cliente->sucursales()->with('ciudad')->get() : collect();
        $documentos = $cliente->exists ? $cliente->documentos()->with('subidoPor')->get() : collect();

        return view('clientes.clientes_form', compact(
            'cliente', 'departamentos', 'vendedores', 'listas', 'pais_id',
            'tiposCliente', 'tiposDocumento', 'sucursales', 'documentos'
        ));
    }

    public function guardar(Request $request)
    {
        $esNuevo = !$request->id;
        $cliente = $request->id
                 ? Cliente::findOrFail($request->id)
                 : new Cliente();

        $rules = [
            'numero_identificacion' => [
                $request->tipo_cliente === 'juridica' ? 'nullable' : 'required', 'string', 'max:255',
                // Dice QUIÉN tiene el número: los clientes eliminados solo quedan
                // inactivos, no salen en la lista y siguen ocupando su número.
                function ($attribute, $value, $fail) use ($cliente) {
                    $otro = Cliente::where('numero_identificacion', $value)
                        ->when($cliente->exists, fn ($q) => $q->where('id', '!=', $cliente->id))
                        ->first();
                    if ($otro) {
                        $fail("Ya existe el cliente #{$otro->id} «{$otro->nombre_completo}» con este número"
                            . ($otro->activo ? '.' : ' (está eliminado: pida a un administrador que lo restaure o le cambie el número).'));
                    }
                },
            ],
            'tipo_cliente' => ['required', 'in:natural,juridica'],
            'nombre_contacto' => [$request->tipo_cliente === 'juridica' ? 'nullable' : 'required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'emails_adicionales' => ['nullable', 'array'],
            'emails_adicionales.*' => ['nullable', 'email', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:100'],
            'direccion' => ['nullable', 'string', 'max:500'],
            'pais_id' => ['required', 'exists:paises,id'],
            'departamento_id' => ['required', 'exists:departamentos,id'],
            'ciudad_id' => ['required', 'exists:ciudades,id'],
            'vendedor_id' => ['nullable', 'exists:users,id'],
            'lista_precio_id' => ['required', 'exists:listas_precios,id'],
            'valor_flete' => ['nullable', 'numeric', 'min:0'],
            'aplica_flete' => ['nullable', 'boolean'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            // Validación de documentos en creación
            'documentos' => ['nullable', 'array'],
            'documentos.*' => ['file', 'max:51200'], // Max 50MB por archivo
            'documentos_nombres' => ['nullable', 'array'],
            'documentos_tipos' => ['nullable', 'array'],
            // Validación de sucursales en creación
            'sucursales' => ['nullable', 'array'],
        ];

        // Campos adicionales para persona jurídica
        if ($request->tipo_cliente === 'juridica') {
            $rules['razon_social'] = ['required', 'string', 'max:255'];
            $rules['nit'] = ['required', 'string', 'max:50'];
            $rules['representante_legal'] = ['nullable', 'string', 'max:255'];
        }

        $messages = [
            'required' => 'Este campo es obligatorio.',
            'email' => 'Debe ser un correo válido.',
            'max' => 'No debe superar los :max caracteres.',
            'unique' => 'Ya existe un registro con este valor.',
            'exists' => 'El valor seleccionado no es válido.',
            'numeric' => 'Debe ser un valor numérico.',
            'min' => 'El valor mínimo es :min.',
        ];

        $data = $request->validate($rules, $messages);

        // Manejar checkbox de flete
        $data['aplica_flete'] = $request->has('aplica_flete');

        // Limpiar campos de persona jurídica si es natural
        if ($data['tipo_cliente'] === 'natural') {
            $data['razon_social'] = null;
            $data['nit'] = null;
            $data['representante_legal'] = null;
        }

        // Proteger campos sensibles: solo admin puede cambiar vendedor y lista de precios
        // en clientes existentes
        if ($cliente->exists && !auth()->user()->hasRole(['admin', 'auxiliar_administrativo'])) {
            // Restaurar valores originales que no puede modificar
            $data['vendedor_id'] = $cliente->vendedor_id;
            $data['lista_precio_id'] = $cliente->lista_precio_id;
        }

        // Limpiar emails adicionales: quitar vacíos
        if (isset($data['emails_adicionales'])) {
            $data['emails_adicionales'] = array_values(
                array_filter(array_map('trim', $data['emails_adicionales']), fn($e) => !empty($e))
            );
            if (empty($data['emails_adicionales'])) {
                $data['emails_adicionales'] = null;
            }
        }

        // Remover datos de documentos y sucursales antes de guardar cliente
        unset($data['documentos'], $data['documentos_nombres'], $data['documentos_tipos'], $data['sucursales']);

        DB::beginTransaction();
        try {
            $cliente->fill($data)->save();

            // Procesar sucursales si es cliente nuevo
            if ($esNuevo && $request->has('sucursales')) {
                $sucursalesData = json_decode($request->sucursales, true);
                if (is_array($sucursalesData)) {
                    foreach ($sucursalesData as $sucursal) {
                        Sucursal::create([
                            'cliente_id' => $cliente->id,
                            'nombre' => $sucursal['nombre'],
                            'direccion' => $sucursal['direccion'],
                            'ciudad_id' => $sucursal['ciudad_id'] ?: null,
                            'telefono' => $sucursal['telefono'] ?? null,
                            'email' => $sucursal['email'] ?? null,
                            'contacto' => $sucursal['contacto'] ?? null,
                            'es_principal' => $sucursal['es_principal'] ?? false,
                        ]);
                    }
                }
            }

            // Procesar documentos si es cliente nuevo
            if ($esNuevo && $request->hasFile('documentos')) {
                $nombres = $request->documentos_nombres ?? [];
                $tipos = $request->documentos_tipos ?? [];

                foreach ($request->file('documentos') as $index => $archivo) {
                    $nombreDoc = $nombres[$index] ?? $archivo->getClientOriginalName();
                    $tipoDoc = $tipos[$index] ?? 'otro';

                    // Guardar en public/documentos/clientes/{cliente_id}/
                    $directorio = "documentos/clientes/{$cliente->id}";
                    $rutaPublic = public_path($directorio);

                    if (!file_exists($rutaPublic)) {
                        mkdir($rutaPublic, 0755, true);
                    }

                    $nombreArchivo = time() . '_' . $index . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $archivo->getClientOriginalName());
                    $archivo->move($rutaPublic, $nombreArchivo);

                    DocumentoCliente::create([
                        'cliente_id' => $cliente->id,
                        'nombre' => $nombreDoc,
                        'archivo' => "{$directorio}/{$nombreArchivo}",
                        'tipo' => $tipoDoc,
                        'mime_type' => $archivo->getClientMimeType(),
                        'tamano' => filesize("{$rutaPublic}/{$nombreArchivo}"),
                        'subido_por' => auth()->id(),
                    ]);
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()
                             ->withInput()
                             ->with('error', 'Error al guardar: ' . $e->getMessage());
        }

        return redirect()->route('clientes')
                         ->with('success', 'Cliente guardado correctamente.');
    }

    /**
     * Guardar una sucursal del cliente
     */
    public function guardarSucursal(Request $request, Cliente $cliente)
    {
        $rules = [
            'nombre' => ['required', 'string', 'max:255'],
            'direccion' => ['required', 'string', 'max:500'],
            'ciudad_id' => ['nullable', 'exists:ciudades,id'],
            'telefono' => ['nullable', 'string', 'max:100'],
            'contacto' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'es_principal' => ['nullable', 'boolean'],
        ];

        $data = $request->validate($rules);
        $data['cliente_id'] = $cliente->id;
        $data['es_principal'] = $request->has('es_principal');

        if ($request->sucursal_id) {
            $sucursal = Sucursal::findOrFail($request->sucursal_id);
            $sucursal->update($data);
        } else {
            $sucursal = Sucursal::create($data);
        }

        // Si es principal, desmarcar las demás
        if ($data['es_principal']) {
            $sucursal->marcarComoPrincipal();
        }

        return response()->json([
            'success' => true,
            'message' => 'Sucursal guardada correctamente.',
            'sucursal' => $sucursal->load('ciudad')
        ]);
    }

    /**
     * Eliminar una sucursal
     */
    public function eliminarSucursal(Sucursal $sucursal)
    {
        $sucursal->delete();

        return response()->json([
            'success' => true,
            'message' => 'Sucursal eliminada correctamente.'
        ]);
    }

    /**
     * Subir documento del cliente
     */
    public function subirDocumento(Request $request, Cliente $cliente)
    {
        $request->validate([
            'documento' => ['required', 'file', 'max:51200'], // Max 50MB, cualquier tipo
            'nombre' => ['required', 'string', 'max:255'],
            'tipo' => ['nullable', 'string', 'max:50'],
        ]);

        $archivo = $request->file('documento');

        // Guardar en public/documentos/clientes/{cliente_id}/
        $directorio = "documentos/clientes/{$cliente->id}";
        $rutaPublic = public_path($directorio);

        if (!file_exists($rutaPublic)) {
            mkdir($rutaPublic, 0755, true);
        }

        $nombreArchivo = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $archivo->getClientOriginalName());
        $archivo->move($rutaPublic, $nombreArchivo);

        $documento = DocumentoCliente::create([
            'cliente_id' => $cliente->id,
            'nombre' => $request->nombre,
            'archivo' => "{$directorio}/{$nombreArchivo}",
            'tipo' => $request->tipo,
            'mime_type' => $archivo->getClientMimeType(),
            'tamano' => filesize("{$rutaPublic}/{$nombreArchivo}"),
            'subido_por' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Documento subido correctamente.',
            'documento' => $documento->load('subidoPor')
        ]);
    }

    /**
     * Eliminar documento del cliente
     */
    public function eliminarDocumento(DocumentoCliente $documento)
    {
        // Eliminar archivo físico
        $documento->eliminarArchivo();

        // Eliminar registro
        $documento->delete();

        return response()->json([
            'success' => true,
            'message' => 'Documento eliminado correctamente.'
        ]);
    }

    /**
     * Descargar documento del cliente
     */
    public function descargarDocumento(DocumentoCliente $documento)
    {
        $rutaCompleta = public_path($documento->archivo);

        if (!file_exists($rutaCompleta)) {
            abort(404, 'Archivo no encontrado.');
        }

        // Obtener extensión original del archivo
        $extension = pathinfo($documento->archivo, PATHINFO_EXTENSION);
        $nombreDescarga = $documento->nombre;

        // Agregar extensión si no la tiene
        if (!str_ends_with(strtolower($nombreDescarga), '.' . strtolower($extension))) {
            $nombreDescarga .= '.' . $extension;
        }

        return response()->download($rutaCompleta, $nombreDescarga);
    }

    /**
     * Eliminar cliente (soft delete - marca como inactivo)
     */
    public function eliminar(Cliente $cliente)
    {
        // Solo admin e inventarios pueden eliminar
        if (!auth()->user()->hasRole(['admin', 'auxiliar_administrativo', 'inventarios'])) {
            return response()->json([
                'success' => false,
                'message' => 'No tiene permisos para eliminar clientes.'
            ], 403);
        }

        try {
            $cliente->update(['activo' => false]);

            return response()->json([
                'success' => true,
                'message' => 'Cliente eliminado correctamente.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar el cliente: ' . $e->getMessage()
            ], 500);
        }
    }
}
