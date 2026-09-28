<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SolicitudCotizacion;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\StockProducto;
use App\Models\MovimientoStock;
use App\Models\ReservaStock;
use Yajra\DataTables\Facades\DataTables;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Mail\SolicitudAplicada;
use App\Mail\AlertaCotizacionAceptada;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\SolicitudesExport;
use Illuminate\Support\Facades\Log;
use App\Models\HistorialEstadoSolicitud;
use App\Services\CotizacionService;
use App\Services\ReservaStockService;
use App\Services\FacturacionService;
use App\Http\Requests\ActualizarSolicitudRequest;
use App\Models\LogSolicitudCotizacion;
use App\Http\Requests\CambiarEstadoSolicitudRequest;

class SolicitudController extends Controller
{
    protected CotizacionService $cotizacionService;
    protected ReservaStockService $reservaService;
    protected FacturacionService $facturacionService;

    public function __construct(
        CotizacionService $cotizacionService,
        ReservaStockService $reservaService,
        FacturacionService $facturacionService
    ) {
        $this->middleware('auth');
        $this->cotizacionService = $cotizacionService;
        $this->reservaService = $reservaService;
        $this->facturacionService = $facturacionService;
    }
    
    public function index(Request $request)
    {
        // Verificar que el usuario sea admin, vendedor, facturación, auxiliar inventario o garantías
        $user = Auth::user();
        if (!$user->hasRole(['admin', 'auxiliar_administrativo', 'vendedor', 'facturacion', 'inventarios', 'auxiliar_inventario', 'garantias'])) {
            abort(403, 'No tienes permisos para acceder a este módulo.');
        }

        if ($request->ajax()) {
            $query = SolicitudCotizacion::with(['cliente', 'cliente.vendedor', 'createdBy', 'items', 'stockDescontadoPor'])
                                       ->select('solicitudes_cotizacion.*');

            // Vendedor ve las cotizaciones que él creó O las de sus clientes (ej. compras por enlace público)
            if ($user->hasRole('vendedor') && !$user->hasAnyRole(['admin', 'auxiliar_administrativo'])) {
                $query->where(function($q) use ($user) {
                    $q->where('created_by', $user->id)
                      ->orWhereHas('cliente', function($sub) use ($user) {
                          $sub->where('vendedor_id', $user->id);
                      });
                });
            }


            // Filtro para rol inventarios: solo aplicadas con pago != pendiente O con forma de pago Crédito
            if ($user->hasRole('inventarios') && !$user->hasAnyRole(['admin', 'auxiliar_administrativo'])) {
                $query->where('estado', 'aplicada')
                      ->where(function($q) {
                          $q->where('estado_pago', '!=', 'pendiente')
                            ->orWhere('forma_pago_factura', 'like', '%Crédito%');
                      });
            }

            // Filtrar por vendedor si se proporciona (solo admin puede usar este filtro)
            if ($request->filled('vendedor_id') && $user->hasAnyRole(['admin', 'auxiliar_administrativo', 'inventarios'])) {
                $vendedorId = $request->vendedor_id;
                $query->where(function($q) use ($vendedorId) {
                    $q->where('created_by', $vendedorId)
                      ->orWhereHas('cliente', function($subQ) use ($vendedorId) {
                          $subQ->where('vendedor_id', $vendedorId);
                      });
                });
            }

            // Filtrar por estado de pago
            if ($request->filled('estado_pago')) {
                $estadoPago = $request->estado_pago;
                if ($estadoPago === 'credito') {
                    $query->where('forma_pago_factura', 'like', '%Crédito%');
                } else {
                    // Para inventarios: no excluir cotizaciones con forma de pago Crédito
                    if ($user->hasRole('inventarios') && !$user->hasAnyRole(['admin', 'auxiliar_administrativo'])) {
                        $query->where(function($q) use ($estadoPago) {
                            $q->where('estado_pago', $estadoPago)
                              ->orWhere('forma_pago_factura', 'like', '%Crédito%');
                        });
                    } else {
                        $query->where('estado_pago', $estadoPago)
                              ->where(function($q) {
                                  $q->whereNull('forma_pago_factura')
                                    ->orWhere('forma_pago_factura', 'not like', '%Crédito%');
                              });
                    }
                }
            }

            return DataTables::of($query)
                ->addColumn('cliente_nombre', function($s) {
                    return $s->cliente->razon_social ?: $s->cliente->nombre_contacto;
                })
                ->addColumn('vendedor', function($s) {
                    // Mostrar quién creó la solicitud, o el vendedor del cliente si no hay creador
                    if ($s->createdBy) {
                        return $s->createdBy->name;
                    } elseif ($s->cliente->vendedor) {
                        return $s->cliente->vendedor->name . ' (cliente)';
                    }
                    return 'Sin asignar';
                })
                ->addColumn('fecha', function($s) {
                    return $s->created_at->format('d/m/Y H:i');
                })
                ->addColumn('total_items', function($s) {
                    return $s->total_items;
                })
                ->addColumn('monto_formateado', function($s) {
                    return '$' . number_format($s->monto_total, 2);
                })
                ->addColumn('estado_badge', function($s) {
                    if ($s->estado === 'pendiente') {
                        $class = 'warning';
                        $text = 'Pendiente';
                    } elseif ($s->estado === 'aplicada') {
                        $class = 'success';
                        $text = 'Aplicada';
                    } else {
                        $class = 'danger';
                        $text = 'Rechazada';
                    }
                    return '<span class="badge bg-'.$class.'">'.$text.'</span>';
                })
                ->addColumn('reserva_badge', function($s) {
                    return $s->badge_reserva;
                })
                ->addColumn('marcada_badge', function($s) {
                    if (!auth()->user()->hasRole('inventarios')) {
                        return '';
                    }
                    $icon = $s->marcada_inventario ? 'bi-heart-fill' : 'bi-heart';
                    $color = $s->marcada_inventario ? 'text-danger' : 'text-muted';
                    return '<button type="button" class="btn btn-sm btn-link p-0" onclick="toggleMarcada('.$s->id.', this)" title="Marcar/Desmarcar">
                                <i class="bi '.$icon.' '.$color.' fs-5"></i>
                            </button>';
                })
                ->addColumn('estado_pago_badge', function($s) {
                    if ($s->color_estado_pago === 'pink') {
                        return '<span class="badge" style="background-color:#FF84D5;color:#fff;">' . $s->etiqueta_estado_pago . '</span>';
                    }
                    return '<span class="badge bg-' . $s->color_estado_pago . '">' . $s->etiqueta_estado_pago . '</span>';
                })
                ->addColumn('descargue_badge', function($s) {
                    if ($s->stock_descontado && $s->stockDescontadoPor) {
                        $nombre = e($s->stockDescontadoPor->name);
                        $fecha = $s->stock_descontado_en?->format('d/m/Y H:i');
                        return '<span class="badge bg-success" title="'.$fecha.'"><i class="bi bi-check-circle me-1"></i>'.$nombre.'</span>';
                    }
                    return '<span class="badge bg-secondary">No</span>';
                })
                ->addColumn('estado_envio_badge', function($s) {
                    if ($s->estado !== 'aplicada') {
                        return '-';
                    }
                    $badge = '<span class="badge bg-' . $s->color_estado_envio . '">'
                           . '<i class="bi ' . $s->icono_estado_envio . ' me-1"></i>'
                           . $s->etiqueta_estado_envio . '</span>';

                    // Agregar botón de gestión de envío si el usuario tiene permisos (no aplica para rol garantias puro)
                    if (auth()->user()->hasAnyRole(['admin', 'auxiliar_administrativo', 'facturacion', 'inventarios', 'auxiliar_inventario'])) {
                        $archivoGuiaJs = addslashes($s->archivo_guia ?? '');
                        $badge .= ' <button type="button" class="btn btn-sm btn-link p-0"
                                           title="Gestionar Envío" onclick="gestionarEnvio(' . $s->id . ', \'' . $s->estado_envio . '\', \'' . addslashes($s->numero_guia ?? '') . '\', \'' . addslashes($s->transportadora ?? '') . '\', \'' . $archivoGuiaJs . '\')">
                                      <i class="bi bi-pencil-square text-info"></i>
                                   </button>';
                    }

                    // Botón para vendedor: solo ver/descargar guía si existe
                    if (auth()->user()->hasRole('vendedor') && !auth()->user()->hasAnyRole(['admin', 'auxiliar_administrativo', 'facturacion', 'inventarios', 'auxiliar_inventario'])) {
                        if ($s->archivo_guia) {
                            $esImagen = preg_match('/\.(jpg|jpeg|png|webp)$/i', $s->archivo_guia);
                            $icono = $esImagen ? 'bi-file-earmark-image text-primary' : 'bi-file-earmark-pdf text-danger';
                            $badge .= ' <a href="' . asset($s->archivo_guia) . '" target="_blank" class="btn btn-sm btn-link p-0"
                                           title="Ver Guía de Envío">
                                          <i class="bi ' . $icono . '"></i>
                                       </a>';
                        }
                        if ($s->numero_guia) {
                            $badge .= ' <small class="text-muted ms-1" title="Nº Guía">' . e($s->numero_guia) . '</small>';
                        }
                    }

                    return $badge;
                })
                ->addColumn('factura', function($s) {
                    return '-';
                })
                ->addColumn('action', function($s) {
                    $buttons = '<div class="d-flex justify-content-center gap-1">';
                    $isAuxiliar = auth()->user()->hasRole('auxiliar_inventario');
                    $isVendedor = auth()->user()->hasRole('vendedor') && !auth()->user()->hasAnyRole(['admin', 'auxiliar_administrativo']);
                    $isGarantiasOnly = auth()->user()->hasRole('garantias') && !auth()->user()->hasRole('admin');

                    // Determinar si la cotización es "propia" del vendedor
                    $esSuya = $isVendedor
                        ? ($s->created_by == auth()->id() || ($s->cliente && $s->cliente->vendedor_id == auth()->id()))
                        : true;

                    // Botón ver detalle (desde aquí se puede aprobar o rechazar)
                    $buttons .= '<button type="button" class="btn btn-outline-info btn-sm"
                                        title="Ver Detalle" onclick="verDetalle('.$s->id.')">
                                   <i class="bi bi-eye"></i>
                                </button>';

                    if (!$isAuxiliar && !$isGarantiasOnly) {
                        // Botón editar: admin/facturación/auxiliar_administrativo/inventarios siempre según su lógica.
                        // Vendedor: solo si la cotización es suya y cumple reglas de pago.
                        if ($isVendedor) {
                            $puedeEditar = $esSuya && $s->puedeEditarVendedor();
                        } else {
                            $puedeEditar = auth()->user()->hasAnyRole(['facturacion', 'auxiliar_administrativo'])
                                || ($s->esEditable() && auth()->user()->hasAnyRole(['admin', 'inventarios']));
                        }
                        if ($puedeEditar) {
                            $buttons .= '<a href="'.route('solicitudes.edit', $s->id).'" class="btn btn-outline-primary btn-sm"
                                            title="Editar Cotización">
                                           <i class="bi bi-pencil"></i>
                                        </a>';
                        }

                        // Botón clonar (solo cotización propia del vendedor, o admin/otros roles)
                        if (!$isVendedor || $esSuya) {
                            $buttons .= '<button type="button" class="btn btn-outline-secondary btn-sm"
                                                title="Clonar Cotización" onclick="clonarSolicitud('.$s->id.')">
                                           <i class="bi bi-copy"></i>
                                        </button>';
                        }
                    }

                    // Botón descargar PDF
                    $buttons .= '<a href="'.route('solicitudes.pdf', $s->id).'" class="btn btn-outline-danger btn-sm"
                                    title="Descargar PDF" target="_blank">
                                   <i class="bi bi-file-earmark-pdf"></i>
                                </a>';

                    if (!$isAuxiliar && !$isGarantiasOnly) {
                        // Botón registrar pago (solo si puede registrar pago)
                        if ($s->puedeRegistrarPago()) {
                            $buttons .= '<a href="'.route('pagos.create', $s->id).'" class="btn btn-outline-success btn-sm"
                                            title="Registrar Pago">
                                           <i class="bi bi-cash-coin"></i>
                                        </a>';
                        }

                        // Botón eliminar (solo admin y facturación, y si es eliminable - nunca vendedor)
                        if ($s->esEliminable() && auth()->user()->hasAnyRole(['admin', 'auxiliar_administrativo', 'facturacion', 'inventarios'])) {
                            $buttons .= '<button type="button" class="btn btn-outline-danger btn-sm"
                                                title="Eliminar Cotización" onclick="eliminarSolicitud('.$s->id.')">
                                           <i class="bi bi-trash"></i>
                                        </button>';
                        }
                    }

                    // Botón ver logs
                    $buttons .= '<button type="button" class="btn btn-outline-warning btn-sm"
                                        title="Ver Logs" onclick="verLogsSolicitud('.$s->id.')">
                                   <i class="bi bi-clock-history"></i>
                                </button>';

                    $buttons .= '</div>';

                    return $buttons;
                })
                ->filterColumn('cliente_nombre', function($query, $keyword) {
                    $query->whereHas('cliente', function($q) use ($keyword) {
                        $q->where('nombre_contacto', 'like', "%{$keyword}%")
                          ->orWhere('razon_social', 'like', "%{$keyword}%");
                    });
                })
                ->filterColumn('vendedor', function($query, $keyword) {
                    $query->where(function($q) use ($keyword) {
                        // Buscar en el usuario que creó la solicitud (createdBy)
                        $q->whereHas('createdBy', function($subQ) use ($keyword) {
                            $subQ->where('name', $keyword);
                        })
                        // O buscar en el vendedor del cliente
                        ->orWhereHas('cliente.vendedor', function($subQ) use ($keyword) {
                            $subQ->where('name', $keyword);
                        });
                    });
                })
                ->setRowClass(function($s) {
                    // Verificar si es pendiente y tiene más de 3 días
                    if ($s->estado === 'pendiente') {
                        $diasDiferencia = now()->diffInDays($s->created_at);
                        if ($diasDiferencia > 3) {
                            return 'solicitud-antigua';
                        }
                    }
                    return '';
                })
                ->rawColumns(['estado_badge', 'reserva_badge', 'marcada_badge', 'estado_pago_badge', 'descargue_badge', 'estado_envio_badge', 'action'])
                ->make(true);
        }

        // Contar solicitudes pendientes con más de 3 días (no aplica para auxiliar inventario)
        $totalAntiguas = 0;
        if (!$user->hasRole('auxiliar_inventario')) {
            $queryAntiguas = SolicitudCotizacion::where('estado', 'pendiente')
                ->where('created_at', '<', now()->subDays(3));

            // Si es vendedor (y NO es admin/auxiliar_administrativo), filtrar solo solicitudes de sus clientes asignados
            if ($user->hasRole('vendedor') && !$user->hasRole(['admin', 'auxiliar_administrativo'])) {
                $queryAntiguas->whereHas('cliente', function($subQ) use ($user) {
                    $subQ->where('vendedor_id', $user->id);
                });
            }

            $totalAntiguas = $queryAntiguas->count();
        }

        // Obtener lista de vendedores únicos (usuarios que han creado solicitudes o son vendedores de clientes)
        $vendedores = \App\Models\User::whereIn('id', function($query) {
            $query->select('created_by')
                  ->from('solicitudes_cotizacion')
                  ->whereNotNull('created_by')
                  ->union(
                      \DB::table('clientes')
                          ->select('vendedor_id')
                          ->whereNotNull('vendedor_id')
                  );
        })->orderBy('name')->get();

        $ubicaciones = \App\Models\Ubicacion::where('activo', true)->orderBy('nombre')->get(['id', 'nombre', 'tipo']);

        return view('solicitudes.solicitudes_index', compact('totalAntiguas', 'vendedores', 'ubicaciones'));
    }
    
    public function detalle(Request $request, SolicitudCotizacion $solicitud)
    {
        $user = Auth::user();

        // Verificar que sea admin, vendedor, facturación, auxiliar inventario o garantías
        if (!$user->hasAnyRole(['admin', 'auxiliar_administrativo', 'vendedor', 'facturacion', 'inventarios', 'auxiliar_inventario', 'garantias'])) {
            return response()->json(['error' => 'No tiene permisos para ver esta solicitud'], 403);
        }

        // Vendedor puro: solo puede ver el detalle de SUS cotizaciones (creadas por él
        // o de sus clientes). Los demás roles conservan su acceso actual. Mismo criterio
        // que edit(): evita que un vendedor abra por URL una cotización ajena (IDOR).
        $esVendedorPuro = $user->hasRole('vendedor')
            && !$user->hasAnyRole(['admin', 'auxiliar_administrativo', 'facturacion', 'inventarios', 'auxiliar_inventario', 'garantias']);

        if ($esVendedorPuro) {
            $esSuya = $solicitud->created_by == $user->id
                || ($solicitud->cliente && $solicitud->cliente->vendedor_id == $user->id);

            if (!$esSuya) {
                if ($request->ajax() || $request->wantsJson()) {
                    return response()->json(['error' => 'No tiene permisos para ver esta cotización'], 403);
                }
                abort(403, 'No tiene permisos para ver esta cotización');
            }
        }

        $isAuxiliar = $user->hasRole('auxiliar_inventario');

        $solicitud->load(['cliente', 'cliente.listaPrecio', 'cliente.ciudad.departamento', 'cliente.pais', 'items.producto', 'items.varianteProducto', 'enlaceAcceso', 'sucursal.ciudad', 'pagos.registradoPor', 'pagos.aprobadoPor', 'stockDescontadoPor', 'createdBy', 'aplicadaPor', 'rechazadaPor', 'editadaPor', 'historialEstados.usuario', 'reservas.liberadaPor']);

        $html = '<div class="row">';

        // Información del cliente
        $html .= '<div class="col-md-6">';
        $html .= '<h6>Información del Cliente</h6>';
        $html .= '<table class="table table-sm">';
        $html .= '<tr><td><strong>Cliente:</strong></td><td>' . $solicitud->cliente->nombre_contacto . '</td></tr>';
        $html .= '<tr><td><strong>Email:</strong></td><td>' . $solicitud->cliente->email . '</td></tr>';
        $html .= '<tr><td><strong>Teléfono:</strong></td><td>' . $solicitud->cliente->telefono . '</td></tr>';
        $html .= '<tr><td><strong>Lista de Precios:</strong></td><td>' . ($solicitud->cliente->listaPrecio?->nombre ?? 'Sin lista') . '</td></tr>';

        // Ubicación del cliente
        $ubicacionParts = [];
        if ($solicitud->cliente->ciudad) {
            $ubicacionParts[] = $solicitud->cliente->ciudad->nombre;
            if ($solicitud->cliente->ciudad->departamento) {
                $ubicacionParts[] = $solicitud->cliente->ciudad->departamento->nombre;
            }
        }
        if ($solicitud->cliente->pais) {
            $ubicacionParts[] = $solicitud->cliente->pais->nombre;
        }
        if (!empty($ubicacionParts)) {
            $html .= '<tr><td><strong>Ubicación:</strong></td><td>' . e(implode(', ', $ubicacionParts)) . '</td></tr>';
        }

        // Información de flete
        if ($solicitud->cliente->aplica_flete) {
            $valorFlete = ($solicitud->cliente->valor_flete && $solicitud->cliente->valor_flete > 0)
                ? '$' . number_format($solicitud->cliente->valor_flete, 2)
                : 'Valor variable según entrega';
            $html .= '<tr><td><strong>Flete:</strong></td><td><span class="badge bg-success">Aplica</span> — ' . $valorFlete . '</td></tr>';
        } else {
            $html .= '<tr><td><strong>Flete:</strong></td><td><span class="badge bg-secondary">No aplica</span></td></tr>';
        }

        $html .= '</table>';
        $html .= '</div>';

        // Información de la cotización
        $html .= '<div class="col-md-6">';
        $html .= '<h6>Información de la Cotización</h6>';
        $html .= '<table class="table table-sm">';
        $html .= '<tr><td><strong>Número:</strong></td><td><code>' . $solicitud->numero_solicitud . '</code></td></tr>';
        $html .= '<tr><td><strong>Fecha:</strong></td><td>' . $solicitud->created_at->format('d/m/Y H:i') . '</td></tr>';
        $html .= '<tr><td><strong>Estado:</strong></td><td>';
        if ($solicitud->estado === 'pendiente') {
            $html .= '<span class="badge bg-warning">Pendiente</span>';
        } elseif ($solicitud->estado === 'aplicada') {
            $html .= '<span class="badge bg-success">Aplicada</span>';
        } else {
            $html .= '<span class="badge bg-danger">Rechazada</span>';
        }
        $html .= '</td></tr>';
        
        if ($solicitud->enlaceAcceso) {
            $html .= '<tr><td><strong>Origen:</strong></td><td>Enlace de Acceso</td></tr>';
        } else {
            $html .= '<tr><td><strong>Origen:</strong></td><td>Tienda a Tienda</td></tr>';
        }
        
        if ($solicitud->estado === 'aplicada') {
            $html .= '<tr><td><strong>Aplicada por:</strong></td><td>' . $solicitud->aplicadaPor?->name . '</td></tr>';
            $html .= '<tr><td><strong>Fecha aplicación:</strong></td><td>' . ($solicitud->aplicada_en ? $solicitud->aplicada_en->format('d/m/Y H:i') : 'N/A') . '</td></tr>';
        }

        if ($solicitud->estado === 'rechazada') {
            $html .= '<tr><td><strong>Rechazada por:</strong></td><td>' . $solicitud->rechazadaPor?->name . '</td></tr>';
            $html .= '<tr><td><strong>Fecha rechazo:</strong></td><td>' . ($solicitud->rechazada_en ? $solicitud->rechazada_en->format('d/m/Y H:i') : 'N/A') . '</td></tr>';
        }

        // Reserva de stock
        if ($solicitud->tiene_reserva_stock && $solicitud->reserva_expira_en) {
            $expirada = $solicitud->reserva_expira_en->isPast();
            $badgeClass = $expirada ? 'bg-danger' : 'bg-info';
            $texto = $expirada ? 'Expirada' : 'Activa';
            $html .= '<tr><td><strong>Reserva Stock:</strong></td><td><span class="badge ' . $badgeClass . '">' . $texto . '</span> — Expira: ' . $solicitud->reserva_expira_en->format('d/m/Y H:i') . '</td></tr>';
        } elseif ($solicitud->reserva_liberada_en) {
            $liberadaPorNombre = '';
            $reservaLiberada = $solicitud->reservas->first(fn($r) => $r->liberada_por !== null);
            if ($reservaLiberada && $reservaLiberada->liberadaPor) {
                $liberadaPorNombre = ' por <strong>' . e($reservaLiberada->liberadaPor->name) . '</strong>';
            }
            $html .= '<tr><td><strong>Reserva Stock:</strong></td><td><span class="badge bg-secondary">Liberada</span> — ' . $solicitud->reserva_liberada_en->format('d/m/Y H:i') . $liberadaPorNombre . '</td></tr>';
        }

        // Forma de pago y fecha de vencimiento
        if ($solicitud->forma_pago_factura) {
            $html .= '<tr><td><strong>Forma de Pago:</strong></td><td>' . e($solicitud->forma_pago_factura) . '</td></tr>';
        }
        if ($solicitud->fecha_vencimiento) {
            $html .= '<tr><td><strong>Fecha Vencimiento:</strong></td><td>' . $solicitud->fecha_vencimiento->format('d/m/Y') . '</td></tr>';
        }

        // Sucursal de entrega
        if ($solicitud->sucursal) {
            $detalleSucursal = e($solicitud->sucursal->nombre);
            $extras = [];
            if ($solicitud->sucursal->direccion) {
                $extras[] = e($solicitud->sucursal->direccion);
            }
            if ($solicitud->sucursal->ciudad) {
                $extras[] = e($solicitud->sucursal->ciudad->nombre);
            }
            if ($solicitud->sucursal->telefono) {
                $extras[] = 'Tel: ' . e($solicitud->sucursal->telefono);
            }
            if ($solicitud->sucursal->contacto) {
                $extras[] = 'Contacto: ' . e($solicitud->sucursal->contacto);
            }
            if (!empty($extras)) {
                $detalleSucursal .= '<br><small class="text-muted">' . implode(' — ', $extras) . '</small>';
            }
            $html .= '<tr><td><strong>Sucursal de Entrega:</strong></td><td>' . $detalleSucursal . '</td></tr>';
        }

        $html .= '</table>';
        $html .= '</div>';
        
        // Notas del cliente
        if ($solicitud->notas_cliente) {
            $nombreCliente = e($solicitud->cliente->nombre_contacto ?? 'Cliente');
            $html .= '<div class="col-12 mb-3">';
            $html .= '<h6>Notas del Cliente <small class="text-muted">— ' . $nombreCliente . '</small></h6>';
            $html .= '<div class="alert alert-info">' . nl2br(e($solicitud->notas_cliente)) . '</div>';
            $html .= '</div>';
        }

        // Observaciones del vendedor
        if ($solicitud->observaciones_vendedor) {
            $nombreVendedor = e($solicitud->createdBy?->name ?? $solicitud->cliente->vendedor?->name ?? 'Vendedor');
            $html .= '<div class="col-12 mb-3">';
            $html .= '<h6>Observaciones del Vendedor <small class="text-muted">— ' . $nombreVendedor . '</small></h6>';
            $html .= '<div class="alert alert-warning">' . nl2br(e($solicitud->observaciones_vendedor)) . '</div>';
            $html .= '</div>';
        }

        // Items de la cotización
        $html .= '<div class="col-12">';
        $html .= '<h6>Productos Cotizados</h6>';
        $html .= '<div class="table-responsive">';
        $html .= '<table class="table table-striped">';
        $html .= '<thead>';
        $html .= '<tr>';
        $html .= '<th>#</th>';
        $html .= '<th>Referencia</th>';
        $html .= '<th>Producto</th>';
        $html .= '<th>Variante</th>';
        $html .= '<th>Cantidad</th>';
        $html .= '<th>Precio Unit.</th>';
        $html .= '<th>Subtotal</th>';
        $html .= '<th>Observación</th>';
        if ($solicitud->estado === 'pendiente') {
            $html .= '<th>Stock Disponible</th>';
        }
        $html .= '</tr>';
        $html .= '</thead>';
        $html .= '<tbody>';

        foreach ($solicitud->items as $index => $item) {
            $html .= '<tr>';
            $html .= '<td>' . ($index + 1) . '</td>';
            $html .= '<td><code>' . $item->referencia_producto . '</code></td>';
            $html .= '<td>' . $item->nombre_producto . '</td>';
            $html .= '<td>' . ($item->info_variante ?: '-') . '</td>';
            $html .= '<td>' . $item->cantidad . '</td>';
            $html .= '<td>$' . number_format($item->precio_unitario, 2) . '</td>';
            $html .= '<td>$' . number_format($item->precio_total, 2) . '</td>';
            $html .= '<td>' . ($item->observacion ? '<small class="text-muted">' . e($item->observacion) . '</small>' : '-') . '</td>';

            // Mostrar stock disponible solo si está pendiente
            if ($solicitud->estado === 'pendiente') {
                $stockInfo = $this->obtenerStockItem($item);
                $html .= '<td>' . $stockInfo . '</td>';
            }

            $html .= '</tr>';
        }
        
        $html .= '</tbody>';
        $html .= '<tfoot>';

        $colspanTotal = ($solicitud->estado === 'pendiente') ? 8 : 7;

        // Calcular subtotal de items
        $subtotal = $solicitud->items->sum('precio_total');
        $flete = $solicitud->valor_flete ?? 0;
        $descuento = $solicitud->descuento_total ?? 0;

        // Mostrar subtotal
        $html .= '<tr>';
        $html .= '<th colspan="' . $colspanTotal . '" class="text-end">Subtotal:</th>';
        $html .= '<th>$' . number_format($subtotal, 2) . '</th>';
        $html .= '</tr>';

        // Mostrar flete si existe valor o si el cliente aplica flete (aunque sea sin valor)
        $aplicaFleteCliente = (bool) $solicitud->cliente->aplica_flete;
        if ($flete > 0) {
            $html .= '<tr>';
            $html .= '<td colspan="' . $colspanTotal . '" class="text-end">Flete:</td>';
            $html .= '<td>$' . number_format($flete, 2) . '</td>';
            $html .= '</tr>';
        } elseif ($aplicaFleteCliente) {
            $html .= '<tr>';
            $html .= '<td colspan="' . $colspanTotal . '" class="text-end">Flete:</td>';
            $html .= '<td><span class="badge bg-info">Aplica flete</span></td>';
            $html .= '</tr>';
        }

        // Mostrar descuento si existe
        if ($descuento > 0) {
            $html .= '<tr>';
            $html .= '<td colspan="' . $colspanTotal . '" class="text-end">Descuento:</td>';
            $html .= '<td class="text-danger">-$' . number_format($descuento, 2) . '</td>';
            $html .= '</tr>';
        }

        // Mostrar IVA si existe
        $porcentajeIva = $solicitud->porcentaje_iva ?? 0;
        $valorIva = $solicitud->valor_iva ?? 0;
        if ($porcentajeIva > 0 && $valorIva > 0) {
            $html .= '<tr>';
            $html .= '<td colspan="' . $colspanTotal . '" class="text-end">IVA (' . number_format($porcentajeIva, 0) . '%):</td>';
            $html .= '<td>$' . number_format($valorIva, 2) . '</td>';
            $html .= '</tr>';
        }

        // Total final (monto_total + IVA)
        $totalConIva = $solicitud->monto_total + $valorIva;
        $html .= '<tr class="table-primary">';
        $html .= '<th colspan="' . $colspanTotal . '" class="text-end">Total:</th>';
        $html .= '<th>$' . number_format($totalConIva, 2) . '</th>';
        $html .= '</tr>';

        $html .= '</tfoot>';
        $html .= '</table>';
        $html .= '</div>';
        $html .= '</div>';
        
        // Observaciones del admin (si está aplicada)
        if ($solicitud->estado === 'aplicada' && $solicitud->observaciones_admin) {
            $nombreAprobador = e($solicitud->aplicadaPor?->name ?? 'Administrador');
            $html .= '<div class="col-12 mt-3">';
            $html .= '<h6>Observaciones del Administrador <small class="text-muted">— ' . $nombreAprobador . '</small></h6>';
            $html .= '<div class="alert alert-secondary">' . nl2br(e($solicitud->observaciones_admin)) . '</div>';
            $html .= '</div>';
        }

        // Motivo de rechazo (si está rechazada)
        if ($solicitud->estado === 'rechazada' && $solicitud->motivo_rechazo) {
            $nombreRechazador = e($solicitud->rechazadaPor?->name ?? 'Administrador');
            $html .= '<div class="col-12 mt-3">';
            $html .= '<h6>Motivo del Rechazo <small class="text-muted">— ' . $nombreRechazador . '</small></h6>';
            $html .= '<div class="alert alert-warning">' . nl2br(e($solicitud->motivo_rechazo)) . '</div>';
            $html .= '</div>';
        }


        // Historial de pagos (si tiene pagos registrados)
        if ($solicitud->pagos->count() > 0) {
            $metodosPago = SolicitudCotizacion::METODOS_PAGO;

            $html .= '<div class="col-12 mt-3">';
            $html .= '<hr>';
            $html .= '<h6><i class="bi bi-clock-history me-1"></i> Historial de Pagos</h6>';

            // Resumen de pago
            $html .= '<div class="row mb-3">';
            $html .= '<div class="col-md-4 text-center">';
            $html .= '<small class="text-muted d-block">Total con IVA</small>';
            $html .= '<strong class="text-primary">$ ' . number_format($solicitud->monto_total_con_iva, 0, ',', '.') . '</strong>';
            $html .= '</div>';
            $html .= '<div class="col-md-4 text-center">';
            $html .= '<small class="text-muted d-block">Pagado (aprobado)</small>';
            $html .= '<strong class="text-success">$ ' . number_format($solicitud->monto_pagado, 0, ',', '.') . '</strong>';
            $html .= '</div>';
            $html .= '<div class="col-md-4 text-center">';
            $html .= '<small class="text-muted d-block">Saldo Pendiente</small>';
            $html .= '<strong class="text-danger">$ ' . number_format($solicitud->saldo_pendiente, 0, ',', '.') . '</strong>';
            $html .= '</div>';
            $html .= '</div>';

            // Información de forma de pago / crédito
            if ($solicitud->forma_pago_factura) {
                $esCredito = str_contains($solicitud->forma_pago_factura, 'Crédito');
                $esMixto = str_contains($solicitud->forma_pago_factura, 'Mixto');
                $bgClass = $esCredito ? 'alert-info' : 'alert-light';
                $colSize = $esMixto ? '4' : ($solicitud->fecha_vencimiento ? '6' : '12');
                $html .= '<div class="alert ' . $bgClass . ' py-2 mb-3">';
                $html .= '<div class="row text-center">';
                $html .= '<div class="col-md-' . $colSize . '">';
                $html .= '<small class="text-muted d-block">Forma de Pago</small>';
                $html .= '<strong>' . e($solicitud->forma_pago_factura) . '</strong>';
                $html .= '</div>';
                if ($esMixto && $solicitud->monto_credito) {
                    $html .= '<div class="col-md-4">';
                    $html .= '<small class="text-muted d-block">Valor a Crédito</small>';
                    $html .= '<strong class="text-info">$ ' . number_format($solicitud->monto_credito, 0, ',', '.') . '</strong>';
                    $html .= '</div>';
                }
                if ($solicitud->fecha_vencimiento) {
                    $diasRestantes = now()->diffInDays($solicitud->fecha_vencimiento, false);
                    $colorDias = $diasRestantes < 0 ? 'text-danger' : ($diasRestantes <= 7 ? 'text-warning' : 'text-success');
                    $html .= '<div class="col-md-' . ($esMixto ? '4' : '6') . '">';
                    $html .= '<small class="text-muted d-block">Fecha de Vencimiento</small>';
                    $html .= '<strong>' . $solicitud->fecha_vencimiento->format('d/m/Y') . '</strong>';
                    $html .= ' <span class="' . $colorDias . ' small">';
                    if ($diasRestantes < 0) {
                        $html .= '(Vencido hace ' . abs((int) $diasRestantes) . ' días)';
                    } else {
                        $html .= '(' . (int) $diasRestantes . ' días restantes)';
                    }
                    $html .= '</span>';
                    $html .= '</div>';
                }
                $html .= '</div>';
                $html .= '</div>';
            }

            // Alerta de pagos pendientes de aprobación
            $pagosPendientesAprobacion = $solicitud->pagos->where('estado', 'pendiente');
            if ($pagosPendientesAprobacion->count() > 0) {
                $montoPendienteAprobacion = $pagosPendientesAprobacion->sum('monto');
                $html .= '<div class="alert alert-warning py-2 text-center small mb-3">';
                $html .= '<i class="bi bi-clock me-1"></i>';
                $html .= $pagosPendientesAprobacion->count() . ' pago(s) pendiente(s) de aprobación por ';
                $html .= '<strong>$ ' . number_format($montoPendienteAprobacion, 0, ',', '.') . '</strong>';
                $html .= '</div>';
            }

            // Tabla de pagos
            $html .= '<div class="table-responsive">';
            $html .= '<table class="table table-sm table-striped">';
            $html .= '<thead class="table-light">';
            $html .= '<tr>';
            $html .= '<th>#</th>';
            $html .= '<th>Fecha</th>';
            $html .= '<th>Monto</th>';
            $html .= '<th>Método</th>';
            $html .= '<th>Registrado por</th>';
            $html .= '<th>Estado</th>';
            $html .= '<th>Comprobante</th>';
            $html .= '<th>Acciones</th>';
            $html .= '</tr>';
            $html .= '</thead>';
            $html .= '<tbody>';

            foreach ($solicitud->pagos->sortBy('created_at') as $index => $pago) {
                $html .= '<tr>';
                $html .= '<td>' . ($index + 1) . '</td>';
                $html .= '<td>' . $pago->created_at->format('d/m/Y H:i') . '</td>';
                $html .= '<td><strong>$ ' . number_format($pago->monto, 0, ',', '.') . '</strong></td>';
                $html .= '<td>' . ($metodosPago[$pago->metodo_pago] ?? $pago->metodo_pago) . '</td>';
                $html .= '<td>' . ($pago->registradoPor?->name ?? '-') . '</td>';

                // Estado del pago
                $html .= '<td>';
                $html .= '<span class="badge bg-' . $pago->color_estado . '">' . $pago->etiqueta_estado . '</span>';
                if ($pago->estaAprobado() && $pago->aprobadoPor) {
                    $html .= '<br><small class="text-muted">por ' . e($pago->aprobadoPor->name) . '</small>';
                }
                if ($pago->estaRechazado() && $pago->aprobadoPor) {
                    $html .= '<br><small class="text-muted">por ' . e($pago->aprobadoPor->name) . '</small>';
                }
                $html .= '</td>';

                $html .= '<td>';
                if ($pago->comprobante) {
                    $comprobantes = is_string($pago->comprobante) ? [$pago->comprobante] : (is_array($pago->comprobante) ? $pago->comprobante : []);
                    foreach ($comprobantes as $idx => $comp) {
                        $label = count($comprobantes) > 1 ? 'Archivo ' . ($idx + 1) : 'Descargar';
                        $html .= '<a href="/solicitudes/' . $solicitud->id . '/pagos/' . $pago->id . '/comprobante?index=' . $idx . '" target="_blank" class="btn btn-sm btn-outline-primary mb-1">';
                        $html .= '<i class="bi bi-download me-1"></i> ' . $label;
                        $html .= '</a> ';
                    }
                } else {
                    $html .= '<span class="text-muted">-</span>';
                }
                $html .= '</td>';

                // Acciones
                $html .= '<td>';
                if ($pago->estaPendiente() && $user->hasAnyRole(['admin', 'auxiliar_administrativo', 'facturacion']) && !$isAuxiliar) {
                    $html .= '<button type="button" class="btn btn-sm btn-success me-1" ';
                    $html .= 'onclick="aprobarPago(' . $solicitud->id . ', ' . $pago->id . ')" ';
                    $html .= 'title="Aprobar Pago">';
                    $html .= '<i class="bi bi-check-circle"></i>';
                    $html .= '</button>';
                    $html .= '<button type="button" class="btn btn-sm btn-danger" ';
                    $html .= 'onclick="rechazarPago(' . $solicitud->id . ', ' . $pago->id . ')" ';
                    $html .= 'title="Rechazar Pago">';
                    $html .= '<i class="bi bi-x-circle"></i>';
                    $html .= '</button>';
                } elseif ($pago->estaPendiente()) {
                    $html .= '<span class="text-muted small">Esperando aprobación</span>';
                } else {
                    $html .= '-';
                }
                $html .= '</td>';

                $html .= '</tr>';

                // Fila de notas debajo del pago
                if ($pago->notas) {
                    $html .= '<tr>';
                    $html .= '<td colspan="8" class="py-1 px-3 bg-light border-0" style="border-top: none !important;">';
                    $html .= '<small><i class="bi bi-chat-text me-1 text-primary"></i><strong>Nota:</strong> ' . e($pago->notas) . '</small>';
                    $html .= '</td>';
                    $html .= '</tr>';
                }
            }

            $html .= '</tbody>';
            $html .= '</table>';
            $html .= '</div>';

            // Badge de estado de pago
            $html .= '<div class="text-end">';
            if ($solicitud->color_estado_pago === 'pink') {
                $html .= '<span class="badge fs-6" style="background-color:#FF84D5;color:#fff;">';
            } else {
                $html .= '<span class="badge bg-' . $solicitud->color_estado_pago . ' fs-6">';
            }
            $html .= 'Estado de Pago: ' . $solicitud->etiqueta_estado_pago;
            $html .= '</span>';
            $html .= '</div>';

            $html .= '</div>';
        }

        // Sección de descuento de stock (solo para cotizaciones aplicadas)
        if ($solicitud->estado === 'aplicada') {
            $html .= '<div class="col-12 mt-3">';
            $html .= '<hr>';
            $html .= '<h6><i class="bi bi-box-seam me-1"></i> Descuento de Stock</h6>';

            if (!$solicitud->stock_descontado) {
                // Botón para descontar stock
                $html .= '<div class="alert alert-warning d-flex align-items-center justify-content-between">';
                $html .= '<div>';
                $html .= '<i class="bi bi-exclamation-triangle me-2"></i>';
                $html .= '<strong>Stock pendiente de descontar.</strong> ';
                $html .= '<span class="text-muted">El stock de los productos no ha sido descontado del inventario.</span>';
                $html .= '</div>';
                if ($user->hasAnyRole(['admin', 'auxiliar_administrativo', 'auxiliar_inventario'])) {
                    $html .= '<button type="button" class="btn btn-warning btn-sm ms-3" onclick="confirmarDescontarStock(' . $solicitud->id . ')">';
                    $html .= '<i class="bi bi-box-arrow-down me-1"></i> Descontar de Stock';
                    $html .= '</button>';
                }
                $html .= '</div>';
            } else {
                // Info de quién descontó y cuándo
                $html .= '<div class="alert alert-success">';
                $html .= '<i class="bi bi-check-circle me-2"></i>';
                $html .= '<strong>Stock descontado</strong> por <strong>' . ($solicitud->stockDescontadoPor?->name ?? 'Sistema') . '</strong>';
                $html .= ' el ' . $solicitud->stock_descontado_en->format('d/m/Y H:i');
                $html .= '</div>';
            }

            $html .= '</div>';
        }

        // Sección de Información de Envío (solo para cotizaciones aplicadas)
        if ($solicitud->estado === 'aplicada') {
            $estadosEnvio = SolicitudCotizacion::estadosEnvio();
            $html .= '<div class="col-12 mt-3">';
            $html .= '<hr>';
            $html .= '<h6><i class="bi bi-truck me-1"></i> Información de Envío</h6>';
            $html .= '<div class="row">';

            // Estado de envío
            $html .= '<div class="col-md-3 mb-2">';
            $html .= '<small class="text-muted d-block">Estado de Envío</small>';
            $html .= '<span class="badge bg-' . $solicitud->color_estado_envio . '">';
            $html .= '<i class="bi ' . $solicitud->icono_estado_envio . ' me-1"></i>';
            $html .= $solicitud->etiqueta_estado_envio . '</span>';
            $html .= '</div>';

            // Transportadora
            $html .= '<div class="col-md-3 mb-2">';
            $html .= '<small class="text-muted d-block">Transportadora</small>';
            $html .= '<span>' . ($solicitud->transportadora ? e($solicitud->transportadora) : '<span class="text-muted">-</span>') . '</span>';
            $html .= '</div>';

            // Número de guía
            $html .= '<div class="col-md-3 mb-2">';
            $html .= '<small class="text-muted d-block">Número de Guía</small>';
            $html .= '<span>' . ($solicitud->numero_guia ? '<code>' . e($solicitud->numero_guia) . '</code>' : '<span class="text-muted">-</span>') . '</span>';
            $html .= '</div>';

            // Despachado por/cuando
            if ($solicitud->despachado_en) {
                $html .= '<div class="col-md-3 mb-2">';
                $html .= '<small class="text-muted d-block">Despachado</small>';
                $despachadoPor = $solicitud->despachado_por ? User::find($solicitud->despachado_por)?->name : null;
                $html .= '<span>' . ($despachadoPor ? e($despachadoPor) . ' — ' : '') . $solicitud->despachado_en->format('d/m/Y H:i') . '</span>';
                $html .= '</div>';
            }

            // Entregado cuando
            if ($solicitud->entregado_en) {
                $html .= '<div class="col-md-3 mb-2">';
                $html .= '<small class="text-muted d-block">Entregado</small>';
                $html .= '<span>' . $solicitud->entregado_en->format('d/m/Y H:i') . '</span>';
                $html .= '</div>';
            }

            $html .= '</div>';

            // Preview de archivo de guía
            if ($solicitud->archivo_guia) {
                $esImagen = preg_match('/\.(jpg|jpeg|png|webp)$/i', $solicitud->archivo_guia);
                $html .= '<div class="mt-2 mb-3">';
                $html .= '<small class="text-muted d-block mb-1">Archivo de Guía:</small>';
                if ($esImagen) {
                    $html .= '<img src="/' . e($solicitud->archivo_guia) . '" class="img-thumbnail" style="max-height: 200px;">';
                    $html .= ' <a href="/' . e($solicitud->archivo_guia) . '" target="_blank" class="btn btn-sm btn-outline-primary ms-2">';
                    $html .= '<i class="bi bi-eye me-1"></i>Ver completa</a>';
                } else {
                    $html .= '<a href="/' . e($solicitud->archivo_guia) . '" target="_blank" class="btn btn-sm btn-outline-danger">';
                    $html .= '<i class="bi bi-file-earmark-pdf me-1"></i>Ver PDF de guía</a>';
                }
                $html .= '</div>';
            }

            $html .= '</div>';
        }

        // Historial completo de cambios de estado
        $historial = $solicitud->historialEstados;
        if ($historial->count() > 0) {
            $html .= '<div class="col-12 mt-3">';
            $html .= '<hr>';
            $html .= '<h6><i class="bi bi-clock-history me-1"></i> Historial de Cambios</h6>';
            $html .= '<div style="max-height: 300px; overflow-y: auto;">';

            $iconosTipo = [
                'estado' => 'bi-flag-fill text-warning',
                'envio' => 'bi-truck text-info',
                'pago' => 'bi-credit-card text-success',
            ];
            $etiquetasTipo = [
                'estado' => 'Estado',
                'envio' => 'Envío',
                'pago' => 'Pago',
            ];

            foreach ($historial as $h) {
                $icono = $iconosTipo[$h->tipo_cambio] ?? 'bi-circle text-secondary';
                $etiquetaTipo = $etiquetasTipo[$h->tipo_cambio] ?? $h->tipo_cambio;
                $usuario = $h->usuario?->name ?? 'Sistema';
                $fecha = $h->created_at->format('d/m/Y H:i');

                $html .= '<div class="d-flex align-items-start mb-2 small border-start border-2 ps-3">';
                $html .= '<div class="flex-grow-1">';
                $html .= '<i class="bi ' . $icono . ' me-1"></i>';
                $html .= '<span class="badge bg-light text-dark me-1">' . $etiquetaTipo . '</span>';
                $html .= '<strong>' . e($usuario) . '</strong> cambió de ';
                $html .= '<span class="badge bg-secondary">' . ($h->estado_anterior ?? '-') . '</span>';
                $html .= ' a <span class="badge bg-primary">' . $h->estado_nuevo . '</span>';

                // Mostrar datos adicionales
                $datos = $h->datos_adicionales;
                if (!empty($datos)) {
                    $detalles = [];
                    if (!empty($datos['transportadora'])) $detalles[] = 'Transportadora: ' . e($datos['transportadora']);
                    if (!empty($datos['numero_guia'])) $detalles[] = 'Guía: ' . e($datos['numero_guia']);
                    if (!empty($datos['archivo_guia'])) {
                        $esImg = preg_match('/\.(jpg|jpeg|png|webp)$/i', $datos['archivo_guia']);
                        if ($esImg) {
                            $detalles[] = '<a href="/' . e($datos['archivo_guia']) . '" target="_blank"><i class="bi bi-image me-1"></i>Ver imagen</a>';
                        } else {
                            $detalles[] = '<a href="/' . e($datos['archivo_guia']) . '" target="_blank"><i class="bi bi-file-pdf me-1"></i>Ver PDF</a>';
                        }
                    }
                    if (!empty($detalles)) {
                        $html .= '<br><small class="text-muted">' . implode(' | ', $detalles) . '</small>';
                    }
                }

                if ($h->observaciones) {
                    $html .= '<br><small class="text-muted"><i class="bi bi-chat-text me-1"></i>' . e($h->observaciones) . '</small>';
                }

                $html .= '<br><small class="text-muted">' . $fecha . '</small>';
                $html .= '</div>';
                $html .= '</div>';
            }

            $html .= '</div>';
            $html .= '</div>';
        }

        $html .= '</div>';

        // Sección de Garantías
        $garantiasPendientes = \App\Models\Garantia::with(['producto', 'variante', 'items.producto', 'items.variante', 'documentos'])
            ->where('cliente_id', $solicitud->cliente_id)
            ->pendientes()
            ->orderBy('created_at', 'desc')
            ->get();

        $garantiasLiberadasEnEsta = \App\Models\Garantia::with([
                'producto', 'variante', 'items.producto', 'items.variante', 'usuarioLiberador',
                'productosLiberacion.producto',
                'productosLiberacion.variante',
                'productosLiberacion.ubicacionRelacion',
            ])
            ->where('solicitud_cotizacion_id', $solicitud->id)
            ->liberadas()
            ->orderBy('liberado_en', 'desc')
            ->get();

        $puedeLiberar = $user->hasAnyRole(['admin', 'garantias']);

        $html .= view('garantias._seccion_solicitud', [
            'garantiasPendientes' => $garantiasPendientes,
            'garantiasLiberadasEnEsta' => $garantiasLiberadasEnEsta,
            'puedeLiberar' => $puedeLiberar,
            'solicitudId' => $solicitud->id,
        ])->render();

        // Si es AJAX (modal del index), retornar HTML raw
        if ($request->ajax()) {
            return response($html);
        }

        // Si es acceso directo (desde dashboard), retornar vista Blade con estilo Miracle
        return view('solicitudes.detalle', compact('solicitud'));
    }

    /**
     * Obtener información de stock para un item
     */
    private function obtenerStockItem($item)
    {
        $producto = $item->producto;
        
        // Si no controla stock
        if (!$producto->controlar_stock) {
            return '<span class="badge bg-success">Stock ilimitado</span>';
        }
        
        if ($item->variante_producto_id) {
            // Producto con variante
            $stock = StockProducto::where('producto_id', $producto->id)
                                  ->where('variante_producto_id', $item->variante_producto_id)
                                  ->first();
        } else {
            // Producto sin variante
            $stock = StockProducto::where('producto_id', $producto->id)
                                  ->whereNull('variante_producto_id')
                                  ->first();
        }
        
        if (!$stock) {
            if ($producto->permitir_venta_sin_stock) {
                return '<span class="badge bg-warning">Sin stock (se permite)</span>';
            } else {
                return '<span class="badge bg-danger">Sin stock</span>';
            }
        }
        
        $disponible = $stock->cantidad_disponible - $stock->cantidad_reservada;
        $solicitado = $item->cantidad;
        
        if ($disponible >= $solicitado) {
            return '<span class="badge bg-success">' . $disponible . ' disponibles</span>';
        } elseif ($producto->permitir_venta_sin_stock) {
            return '<span class="badge bg-warning">' . $disponible . ' disponibles (se permite déficit)</span>';
        } else {
            return '<span class="badge bg-danger">Insuficiente (' . $disponible . ' de ' . $solicitado . ')</span>';
        }
    }
    
    public function aplicar(Request $request, SolicitudCotizacion $solicitud)
    {
        $user = Auth::user();

        // Verificar que sea admin, vendedor o facturación (inventarios solo descuenta stock)
        if (!$user->hasAnyRole(['admin', 'auxiliar_administrativo', 'vendedor', 'facturacion'])) {
            return response()->json([
                'success' => false,
                'mensaje' => 'No tiene permisos para aplicar esta solicitud'
            ], 403);
        }

        // Si es vendedor (y NO es admin ni facturación), verificar que la solicitud sea de uno de sus clientes asignados
        if ($user->hasRole('vendedor') && !$user->hasAnyRole(['admin', 'auxiliar_administrativo', 'facturacion'])) {
            if ($solicitud->cliente->vendedor_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'mensaje' => 'No tiene permisos para aplicar esta solicitud'
                ], 403);
            }
        }

        // Verificar que esté pendiente
        if ($solicitud->estado !== 'pendiente') {
            return response()->json([
                'success' => false,
                'mensaje' => 'Esta solicitud ya fue aplicada'
            ], 400);
        }

        $request->validate([
            'observaciones' => 'nullable|string|max:1000',
        ]);

        DB::beginTransaction();

        try {
            // Re-obtener con bloqueo pesimista para evitar race condition (doble-click)
            $solicitud = SolicitudCotizacion::where('id', $solicitud->id)->lockForUpdate()->first();

            if ($solicitud->estado !== 'pendiente') {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'mensaje' => 'Esta solicitud ya fue procesada'
                ], 400);
            }

            // Marcar como aplicada
            $observaciones = $request->observaciones;
            $solicitud->marcarComoAplicada($user->id, $observaciones);

            // Aplicar reservas de stock (descuenta disponible y libera reservado)
            $this->reservaService->aplicarReservas($solicitud);

            // Marcar stock como descontado (aplicarReservas ya descontó stock)
            // Esto previene que descontarStock() lo descuente de nuevo
            $solicitud->update([
                'stock_descontado' => true,
                'stock_descontado_en' => now(),
                'stock_descontado_por' => $user->id,
            ]);

            // Cargar relaciones necesarias para el PDF
            $solicitud->load([
                'cliente',
                'cliente.listaPrecio',
                'cliente.vendedor',
                'cliente.ciudad',
                'cliente.ciudad.departamento',
                'cliente.pais',
                'items.producto.imagenPrincipal',
                'sucursal.ciudad',
                'aplicadaPor'
            ]);

            $withGarantias = [
                'producto', 'variante', 'items.producto', 'items.variante', 'usuarioCreador', 'usuarioLiberador',
                'documentos', 'productosLiberacion.producto', 'productosLiberacion.variante', 'productosLiberacion.ubicacionRelacion',
            ];
            $garantiasVinculadas = \App\Models\Garantia::with($withGarantias)
                ->where('cliente_id', $solicitud->cliente_id)->pendientes()
                ->orderBy('created_at', 'desc')->get()
                ->concat(
                    \App\Models\Garantia::with($withGarantias)
                        ->where('solicitud_cotizacion_id', $solicitud->id)->liberadas()
                        ->orderBy('liberado_en', 'desc')->get()
                );

            // Generar PDF con nuevo formato
            $pdf = PDF::loadView('pdf.cotizacion-excel-format', compact('solicitud', 'garantiasVinculadas'));
            $pdf->setPaper('letter', 'portrait');
            
            // Envío de email al cliente cuando se aprueba la cotización
            $mensajeEmail = '';
            try {
                Mail::to($solicitud->cliente->email)
                    ->cc($solicitud->cliente->emails_adicionales_array)
                    ->send(new SolicitudAplicada($solicitud, $pdf));

                $mensajeEmail = ' Se ha enviado el PDF por correo electrónico al cliente.';
            } catch (\Exception $e) {
                // Log del error pero no fallar la aplicación
                Log::error('Error al enviar email de solicitud aplicada: ' . $e->getMessage());
                $mensajeEmail = ' (No se pudo enviar el correo: ' . $e->getMessage() . ')';
            }

            // Enviar alertas a vendedor y administradores
            $this->enviarAlertasCotizacionAceptada($solicitud);

            DB::commit();
            
            $mensaje = 'Solicitud marcada como aplicada exitosamente.' . $mensajeEmail;
            
            return response()->json([
                'success' => true,
                'mensaje' => $mensaje
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'mensaje' => 'Error al aplicar la solicitud: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Rechazar solicitud de cotización
     */
    public function rechazar(Request $request, SolicitudCotizacion $solicitud)
    {
        $user = Auth::user();

        // Verificar que sea admin, vendedor o facturación (inventarios solo descuenta stock)
        if (!$user->hasAnyRole(['admin', 'auxiliar_administrativo', 'vendedor', 'facturacion'])) {
            return response()->json([
                'success' => false,
                'mensaje' => 'No tiene permisos para rechazar esta solicitud'
            ], 403);
        }

        // Si es vendedor (y NO es admin ni facturación), verificar que la solicitud sea de uno de sus clientes asignados
        if ($user->hasRole('vendedor') && !$user->hasAnyRole(['admin', 'auxiliar_administrativo', 'facturacion'])) {
            if ($solicitud->cliente->vendedor_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'mensaje' => 'No tiene permisos para rechazar esta solicitud'
                ], 403);
            }
        }

        // Verificar que esté pendiente
        if ($solicitud->estado !== 'pendiente') {
            return response()->json([
                'success' => false,
                'mensaje' => 'Esta solicitud ya fue procesada'
            ], 400);
        }

        $request->validate([
            'motivo_rechazo' => 'required|string|max:1000'
        ]);

        DB::beginTransaction();

        try {
            // Re-obtener con bloqueo pesimista para evitar race condition (doble-click)
            $solicitud = SolicitudCotizacion::where('id', $solicitud->id)->lockForUpdate()->first();

            if ($solicitud->estado !== 'pendiente') {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'mensaje' => 'Esta solicitud ya fue procesada'
                ], 400);
            }

            // Marcar como rechazada
            $solicitud->marcarComoRechazada($user->id, $request->motivo_rechazo);

            // Cargar relaciones necesarias para el email y PDF
            $solicitud->load([
                'cliente',
                'cliente.vendedor',
                'cliente.ciudad',
                'cliente.ciudad.departamento',
                'items.producto.imagenPrincipal',
                'sucursal.ciudad',
                'rechazadaPor'
            ]);

            // Envío de email al cliente cuando se rechaza la cotización
            $mensajeEmail = '';
            try {
                // Generar PDF
                $pdf = PDF::loadView('pdf.cotizacion-excel-format', compact('solicitud'));
                $pdf->setPaper('letter', 'portrait');

                Mail::to($solicitud->cliente->email)
                    ->cc($solicitud->cliente->emails_adicionales_array)
                    ->send(new \App\Mail\SolicitudRechazada($solicitud, $pdf));

                $mensajeEmail = ' Se ha enviado notificación por correo electrónico al cliente.';
            } catch (\Exception $e) {
                // Log del error pero no fallar el rechazo
                Log::error('Error al enviar email de solicitud rechazada: ' . $e->getMessage());
                $mensajeEmail = ' (No se pudo enviar el correo: ' . $e->getMessage() . ')';
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'mensaje' => 'Solicitud rechazada exitosamente.' . $mensajeEmail
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'mensaje' => 'Error al rechazar la solicitud: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Procesar stock de un item individual
     */
    /**
     * Descontar stock de una cotización aplicada (acción independiente)
     */
    public function descontarStock(Request $request, SolicitudCotizacion $solicitud)
    {
        $user = Auth::user();

        if (!$user->hasAnyRole(['admin', 'auxiliar_administrativo', 'vendedor', 'facturacion', 'auxiliar_inventario'])) {
            return response()->json([
                'success' => false,
                'mensaje' => 'No tiene permisos para descontar stock'
            ], 403);
        }

        if ($solicitud->estado !== 'aplicada') {
            return response()->json([
                'success' => false,
                'mensaje' => 'La cotización debe estar aplicada para descontar stock'
            ], 400);
        }

        if ($solicitud->stock_descontado) {
            return response()->json([
                'success' => false,
                'mensaje' => 'El stock ya fue descontado para esta cotización'
            ], 400);
        }

        DB::beginTransaction();

        try {
            // Re-obtener con bloqueo pesimista para evitar race condition (doble-click)
            $solicitud = SolicitudCotizacion::where('id', $solicitud->id)->lockForUpdate()->first();

            if ($solicitud->estado !== 'aplicada') {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'mensaje' => 'La cotización debe estar aplicada para descontar stock'
                ], 400);
            }

            if ($solicitud->stock_descontado) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'mensaje' => 'El stock ya fue descontado para esta cotización'
                ], 400);
            }

            $stockProcesado = [];
            $stockInsuficiente = [];

            foreach ($solicitud->items as $item) {
                $resultado = $this->procesarStockItem($item, $user->id, $solicitud->id);

                if ($resultado['procesado']) {
                    $stockProcesado[] = $resultado['mensaje'];
                } elseif ($resultado['error']) {
                    $stockInsuficiente[] = $resultado['mensaje'];
                }
            }

            if (!empty($stockInsuficiente)) {
                $errorMsg = "No se puede descontar stock por stock insuficiente:\n" . implode("\n", $stockInsuficiente);
                throw new \Exception($errorMsg);
            }

            // Liberar reservas activas de esta cotización (ya se descontó el stock real)
            // Decrementar cantidad_reservada correctamente para cada reserva
            $reservasActivas = $solicitud->reservas()
                ->where('estado', 'activa')
                ->get();

            foreach ($reservasActivas as $reserva) {
                $reserva->update([
                    'estado' => 'aplicada',
                    'updated_at' => now(),
                ]);

                // Decrementar la cantidad reservada del stock con guardia contra negativos
                $stock = StockProducto::where('id', $reserva->stock_producto_id)->lockForUpdate()->first();
                if ($stock && $stock->cantidad_reservada > 0) {
                    $cantidadALiberar = min($reserva->cantidad_reservada, $stock->cantidad_reservada);
                    $stock->decrement('cantidad_reservada', $cantidadALiberar);
                }
            }

            // Marcar stock como descontado
            $solicitud->update([
                'stock_descontado' => true,
                'stock_descontado_en' => now(),
                'stock_descontado_por' => $user->id,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'mensaje' => 'Stock descontado correctamente por ' . $user->name . '.'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'mensaje' => 'Error al descontar stock: ' . $e->getMessage()
            ], 500);
        }
    }

    private function procesarStockItem($item, $usuarioId, $solicitudId)
    {
        $producto = $item->producto;
        
        // Si no controla stock, no hacer nada
        if (!$producto->controlar_stock) {
            return [
                'procesado' => false,
                'error' => false,
                'mensaje' => $producto->nombre . ' - No controla stock'
            ];
        }
        
        $descripcion = $producto->nombre . ($item->variante_producto_id ? ' - ' . $item->info_variante : '');
        $cantidadRequerida = (int) $item->cantidad;
        $numeroSolicitud = $item->solicitudCotizacion->numero_solicitud;

        // Candidatos de stock SOLO en ubicaciones de BODEGA (nunca el registro "fantasma" sin
        // ubicación, ni tiendas/ferias como Centro de Experiencia o Nail Fest): las cotizaciones
        // mayoristas se descuentan desde la bodega (CEDI), igual que se reservan. Si la bodega
        // está en 0, debe bloquear (stock insuficiente) y no "caer" al stock de las tiendas.
        $candidatos = StockProducto::with('ubicacionRelacion')
            ->where('producto_id', $producto->id)
            ->whereNotNull('ubicacion_id')
            ->whereHas('ubicacionRelacion', function ($u) {
                $u->where('tipo', '!=', 'tienda');
            })
            ->when($item->variante_producto_id, function ($q) use ($item) {
                $q->where('variante_producto_id', $item->variante_producto_id);
            }, function ($q) {
                $q->whereNull('variante_producto_id');
            })
            ->lockForUpdate()
            ->get()
            ->sortByDesc(function ($s) {
                // [bodega principal primero, luego mayor disponible]
                return [optional($s->ubicacionRelacion)->es_principal ? 1 : 0, $s->cantidad_disponible];
            })
            ->values();

        // Reservas activas de ESTA cotización para este ítem (por registro de stock): la cotización
        // SÍ puede consumir lo que ella misma reservó, pero NO lo reservado por traslados u otras
        // cotizaciones. Disponible para mí = disponible - reservado + mi_propia_reserva.
        $reservasPropias = ReservaStock::where('solicitud_cotizacion_id', $solicitudId)
            ->where('item_solicitud_id', $item->id)
            ->where('estado', 'activa')
            ->get()
            ->groupBy('stock_producto_id')
            ->map(fn($g) => (int) $g->sum('cantidad_reservada'));

        $dispParaMi = function ($stock) use ($reservasPropias) {
            $propia = (int) ($reservasPropias[$stock->id] ?? 0);
            return max(0, (int) $stock->cantidad_disponible - (int) $stock->cantidad_reservada + $propia);
        };

        $disponibleTotal = (int) $candidatos->sum($dispParaMi);

        // Verificar disponibilidad real total (sumando todas las ubicaciones válidas)
        if ($disponibleTotal < $cantidadRequerida && !$producto->permitir_venta_sin_stock) {
            return [
                'procesado' => false,
                'error' => true,
                'mensaje' => $descripcion . ' - Stock insuficiente (disponible en ubicaciones: ' . $disponibleTotal . ', solicitado: ' . $cantidadRequerida . ')'
            ];
        }

        // Descontar de forma greedy: bodega principal primero, luego las demás con stock.
        $pendiente = $cantidadRequerida;
        $detalle = [];

        foreach ($candidatos as $stock) {
            if ($pendiente <= 0) break;
            $libreAqui = $dispParaMi($stock);
            if ($libreAqui <= 0) continue;

            $aDescontar = min($pendiente, $libreAqui);
            $this->ejecutarSalidaDesdeUbicacion($stock, $aDescontar, $usuarioId, $solicitudId, $numeroSolicitud);
            $pendiente -= $aDescontar;
            $detalle[] = $aDescontar . ' en ' . (optional($stock->ubicacionRelacion)->nombre ?? 'ubicación');
        }

        // Si aún queda pendiente y el producto permite venta sin stock, descontar el resto
        // de la bodega principal (primer candidato) dejándola en negativo.
        if ($pendiente > 0 && $producto->permitir_venta_sin_stock && $candidatos->isNotEmpty()) {
            $stock = $candidatos->first();
            $this->ejecutarSalidaDesdeUbicacion($stock, $pendiente, $usuarioId, $solicitudId, $numeroSolicitud);
            $detalle[] = $pendiente . ' en ' . (optional($stock->ubicacionRelacion)->nombre ?? 'ubicación') . ' (sin stock)';
            $pendiente = 0;
        }

        if ($pendiente > 0) {
            return [
                'procesado' => false,
                'error' => true,
                'mensaje' => $descripcion . ' - Stock insuficiente (disponible en ubicaciones: ' . $disponibleTotal . ', solicitado: ' . $cantidadRequerida . ')'
            ];
        }

        // Conciliar las reservas activas de este item (aplicarlas), sin importar a qué
        // registro de stock apunten: el descuento real ya se hizo en la(s) ubicación(es) correcta(s).
        $this->aplicarReservasItem($item, $solicitudId);

        return [
            'procesado' => true,
            'error' => false,
            'mensaje' => $descripcion . ' - Descontado: ' . $cantidadRequerida . ' (' . implode(', ', $detalle) . ')'
        ];
    }

    /**
     * Ejecuta la salida de una cantidad desde un registro de stock concreto (con su ubicación),
     * registrando el movimiento con la ubicación correcta. No toca cantidad_reservada: las
     * reservas se concilian aparte en aplicarReservasItem().
     */
    private function ejecutarSalidaDesdeUbicacion($stock, $cantidad, $usuarioId, $solicitudId, $numeroSolicitud)
    {
        $stockAnterior = (int) $stock->cantidad_disponible;
        $stockNuevo = $stockAnterior - $cantidad;

        $stock->cantidad_disponible = $stockNuevo;
        $stock->save();

        MovimientoStock::create([
            'producto_id' => $stock->producto_id,
            'variante_producto_id' => $stock->variante_producto_id,
            'ubicacion_id' => $stock->ubicacion_id,
            'tipo_movimiento' => 'salida',
            'cantidad' => $cantidad,
            'stock_anterior' => $stockAnterior,
            'stock_nuevo' => $stockNuevo,
            'referencia_documento' => $numeroSolicitud,
            'origen' => 'venta',
            'motivo' => 'Venta aplicada desde solicitud de cotización',
            'usuario_id' => $usuarioId,
            'solicitud_cotizacion_id' => $solicitudId,
        ]);
    }

    /**
     * Marca como aplicadas las reservas activas del item y libera su cantidad_reservada del
     * registro de stock al que apuntan (con guardia contra negativos).
     */
    private function aplicarReservasItem($item, $solicitudId)
    {
        $reservas = ReservaStock::where('solicitud_cotizacion_id', $solicitudId)
            ->where('item_solicitud_id', $item->id)
            ->where('estado', 'activa')
            ->get();

        foreach ($reservas as $reserva) {
            $reserva->update(['estado' => 'aplicada']);

            $stock = StockProducto::where('id', $reserva->stock_producto_id)->lockForUpdate()->first();
            if ($stock && $stock->cantidad_reservada > 0) {
                $cantidad = min($reserva->cantidad_reservada, $stock->cantidad_reservada);
                $stock->decrement('cantidad_reservada', $cantidad);
            }
        }
    }
    
    /**
     * Descargar PDF de solicitud
     */
    public function descargarPdf(SolicitudCotizacion $solicitud)
    {
        $user = Auth::user();

        // Verificar que sea admin, vendedor, facturación, auxiliar inventario o garantías
        if (!$user->hasAnyRole(['admin', 'auxiliar_administrativo', 'vendedor', 'facturacion', 'inventarios', 'auxiliar_inventario', 'garantias'])) {
            abort(403, 'No tiene permisos para descargar este PDF');
        }

        // Vendedor puede descargar PDF de cualquier cotización que creó
        if ($user->hasRole('vendedor') && !$user->hasAnyRole(['admin', 'auxiliar_administrativo', 'facturacion'])) {
            $esCreador = $solicitud->created_by == $user->id;
            $esClienteSuyo = $solicitud->cliente && $solicitud->cliente->vendedor_id == $user->id;
            if (!$esCreador && !$esClienteSuyo) {
                abort(403, 'No tiene permisos para descargar este PDF');
            }
        }

        // Cargar relaciones necesarias
        $solicitud->load([
            'cliente',
            'cliente.listaPrecio',
            'cliente.vendedor',
            'cliente.ciudad',
            'cliente.ciudad.departamento',
            'cliente.pais',
            'items.producto.imagenPrincipal',
            'sucursal.ciudad',
            'aplicadaPor',
            'createdBy',
            'pagos.registradoPor'
        ]);

        // Mismo criterio que el detalle en pantalla: garantías PENDIENTES del cliente
        // + garantías LIBERADAS ligadas a esta cotización.
        $withGarantias = [
            'producto', 'variante', 'items.producto', 'items.variante', 'usuarioCreador', 'usuarioLiberador',
            'documentos', 'productosLiberacion.producto', 'productosLiberacion.variante', 'productosLiberacion.ubicacionRelacion',
        ];
        $garantiasVinculadas = \App\Models\Garantia::with($withGarantias)
            ->where('cliente_id', $solicitud->cliente_id)->pendientes()
            ->orderBy('created_at', 'desc')->get()
            ->concat(
                \App\Models\Garantia::with($withGarantias)
                    ->where('solicitud_cotizacion_id', $solicitud->id)->liberadas()
                    ->orderBy('liberado_en', 'desc')->get()
            );

        $pdf = PDF::loadView('pdf.cotizacion-excel-format', compact('solicitud', 'garantiasVinculadas'));
        $pdf->setPaper('letter', 'portrait');

        return $pdf->download('cotizacion-' . $solicitud->numero_solicitud . '.pdf');
    }
    
    /**
     * Exportar solicitudes a Excel
     */
    public function exportarExcel(Request $request)
    {
        // Verificar que sea admin, facturación o inventarios
        if (!Auth::user()->hasAnyRole(['admin', 'auxiliar_administrativo', 'facturacion', 'inventarios'])) {
            abort(403, 'No tiene permisos para exportar solicitudes');
        }

        // Aplicar filtros
        $query = SolicitudCotizacion::with([
            'cliente',
            'cliente.vendedor',
            'items.producto',
            'items.varianteProducto',
            'aplicadaPor'
        ]);
        
        // Filtros opcionales
        if ($request->has('estado') && $request->estado) {
            $query->where('estado', $request->estado);
        }
        
        if ($request->has('fecha_desde') && $request->fecha_desde) {
            $query->whereDate('created_at', '>=', $request->fecha_desde);
        }
        
        if ($request->has('fecha_hasta') && $request->fecha_hasta) {
            $query->whereDate('created_at', '<=', $request->fecha_hasta);
        }
        
        $solicitudes = $query->orderBy('created_at', 'desc')->get();

        return Excel::download(
            new SolicitudesExport($solicitudes),
            'solicitudes-cotizacion-' . now()->format('Y-m-d-His') . '.xlsx'
        );
    }

    // ==========================================
    // NUEVOS MÉTODOS CRUD - FASE 5
    // ==========================================

    /**
     * Mostrar formulario de edición de cotización
     */
    public function edit(SolicitudCotizacion $solicitud)
    {
        $user = Auth::user();

        $esVendedorPuro = $user->hasRole('vendedor')
            && !$user->hasAnyRole(['admin', 'auxiliar_administrativo', 'facturacion', 'inventarios']);

        if ($esVendedorPuro) {
            $esSuya = $solicitud->created_by == $user->id
                || ($solicitud->cliente && $solicitud->cliente->vendedor_id == $user->id);

            if (!$esSuya || !$solicitud->puedeEditarVendedor()) {
                abort(403, 'No tiene permisos para editar esta cotización');
            }
        } else {
            // Verificar permisos - admin, facturación e inventarios pueden editar
            if (!$user->hasAnyRole(['admin', 'auxiliar_administrativo', 'facturacion', 'inventarios'])) {
                abort(403, 'No tiene permisos para editar cotizaciones');
            }

            // Verificar que sea editable (facturación y auxiliar_administrativo pueden editar siempre)
            if (!$user->hasAnyRole(['facturacion', 'auxiliar_administrativo']) && !$solicitud->esEditable()) {
                return redirect()->route('solicitudes')
                               ->with('error', 'Esta cotización no puede ser editada');
            }
        }

        $solicitud->load(['cliente', 'cliente.listaPrecio', 'items.producto', 'items.varianteProducto', 'reservas']);

        // Obtener productos para el selector
        $productos = Producto::activos()
                            ->with(['variantes', 'imagenPrincipal'])
                            ->orderBy('nombre')
                            ->get();

        return view('solicitudes.edit', compact('solicitud', 'productos'));
    }

    /**
     * Actualizar cotización
     */
    public function update(ActualizarSolicitudRequest $request, SolicitudCotizacion $solicitud)
    {
        $resultado = $this->cotizacionService->actualizar(
            $solicitud,
            $request->validated(),
            Auth::id()
        );

        if (!$resultado['exito']) {
            return response()->json([
                'success' => false,
                'mensaje' => implode(', ', $resultado['errores'])
            ], 400);
        }

        $mensaje = 'Cotización actualizada exitosamente';
        if (!empty($resultado['advertencias'])) {
            $mensaje .= '. Advertencias: ' . implode(', ', $resultado['advertencias']);
        }

        return response()->json([
            'success' => true,
            'mensaje' => $mensaje,
            'solicitud' => $resultado['solicitud']
        ]);
    }

    /**
     * Eliminar cotización
     */
    public function destroy(SolicitudCotizacion $solicitud)
    {
        $user = Auth::user();

        // Verificar permisos - admin, facturación e inventarios pueden eliminar
        if (!$user->hasAnyRole(['admin', 'auxiliar_administrativo', 'facturacion', 'inventarios'])) {
            return response()->json([
                'success' => false,
                'mensaje' => 'No tiene permisos para eliminar cotizaciones'
            ], 403);
        }

        // Verificar si es vendedor que sea su cliente (no aplica a admin ni facturación)
        if ($user->hasRole('vendedor') && !$user->hasAnyRole(['admin', 'auxiliar_administrativo', 'facturacion'])) {
            if ($solicitud->cliente->vendedor_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'mensaje' => 'No tiene permisos para eliminar esta cotización'
                ], 403);
            }
        }

        $resultado = $this->cotizacionService->eliminar($solicitud, Auth::id());

        if (!$resultado['exito']) {
            return response()->json([
                'success' => false,
                'mensaje' => implode(', ', $resultado['errores'])
            ], 400);
        }

        return response()->json([
            'success' => true,
            'mensaje' => 'Cotización eliminada exitosamente'
        ]);
    }

    /**
     * Clonar cotización
     */
    public function clonar(SolicitudCotizacion $solicitud)
    {
        $user = Auth::user();

        // Verificar permisos
        if (!$user->hasAnyRole(['admin', 'auxiliar_administrativo', 'vendedor', 'facturacion', 'inventarios'])) {
            return response()->json([
                'success' => false,
                'mensaje' => 'No tiene permisos para clonar cotizaciones'
            ], 403);
        }

        // Verificar si es vendedor que sea su cliente (no aplica a admin ni facturación)
        if ($user->hasRole('vendedor') && !$user->hasAnyRole(['admin', 'auxiliar_administrativo', 'facturacion'])) {
            if ($solicitud->cliente->vendedor_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'mensaje' => 'No tiene permisos para clonar esta cotización'
                ], 403);
            }
        }

        $resultado = $this->cotizacionService->clonar($solicitud, Auth::id());

        if (!$resultado['exito']) {
            return response()->json([
                'success' => false,
                'mensaje' => implode(', ', $resultado['errores'])
            ], 400);
        }

        $mensaje = 'Cotización clonada exitosamente';
        if (!empty($resultado['advertencias'])) {
            $mensaje .= '. Advertencias: ' . implode(', ', $resultado['advertencias']);
        }

        return response()->json([
            'success' => true,
            'mensaje' => $mensaje,
            'solicitud' => $resultado['solicitud'],
            'redirect_url' => route('solicitudes.edit', $resultado['solicitud']->id)
        ]);
    }

    /**
     * Cambiar estado de cotización (aprobar/rechazar unificado)
     */
    public function cambiarEstado(CambiarEstadoSolicitudRequest $request, SolicitudCotizacion $solicitud)
    {
        $user = Auth::user();

        // Verificar permisos
        if (!$user->hasAnyRole(['admin', 'auxiliar_administrativo', 'vendedor', 'facturacion', 'inventarios'])) {
            return response()->json([
                'success' => false,
                'mensaje' => 'No tiene permisos para cambiar el estado de cotizaciones'
            ], 403);
        }

        // Verificar si es vendedor que sea su cliente (no aplica a admin ni facturación)
        if ($user->hasRole('vendedor') && !$user->hasAnyRole(['admin', 'auxiliar_administrativo', 'facturacion'])) {
            if ($solicitud->cliente->vendedor_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'mensaje' => 'No tiene permisos para cambiar el estado de esta cotización'
                ], 403);
            }
        }

        $resultado = $this->cotizacionService->cambiarEstado(
            $solicitud,
            $request->nuevo_estado,
            $request->validated(),
            Auth::id()
        );

        if (!$resultado['exito']) {
            return response()->json([
                'success' => false,
                'mensaje' => implode(', ', $resultado['errores'])
            ], 400);
        }

        $accion = $request->nuevo_estado === 'aplicada' ? 'aprobada' : 'rechazada';

        return response()->json([
            'success' => true,
            'mensaje' => "Cotización {$accion} exitosamente",
            'solicitud' => $resultado['solicitud']
        ]);
    }

    /**
     * Renovar reserva de stock
     */
    public function renovarReserva(SolicitudCotizacion $solicitud)
    {
        $user = Auth::user();

        // Verificar permisos
        if (!$user->hasAnyRole(['admin', 'auxiliar_administrativo', 'vendedor', 'facturacion', 'inventarios'])) {
            return response()->json([
                'success' => false,
                'mensaje' => 'No tiene permisos'
            ], 403);
        }

        if ($solicitud->estado !== SolicitudCotizacion::ESTADO_PENDIENTE) {
            return response()->json([
                'success' => false,
                'mensaje' => 'Solo se pueden renovar reservas de cotizaciones pendientes'
            ], 400);
        }

        $exito = $this->reservaService->renovarReservas($solicitud, 24);

        if (!$exito) {
            return response()->json([
                'success' => false,
                'mensaje' => 'No se pudo renovar la reserva'
            ], 400);
        }

        return response()->json([
            'success' => true,
            'mensaje' => 'Reserva renovada por 24 horas',
            'nueva_expiracion' => $solicitud->fresh()->reserva_expira_en->format('d/m/Y H:i')
        ]);
    }

    /**
     * Liberar reserva de stock manualmente
     */
    public function liberarReserva(SolicitudCotizacion $solicitud)
    {
        $user = Auth::user();

        // Verificar permisos
        if (!$user->hasAnyRole(['admin', 'auxiliar_administrativo', 'vendedor', 'facturacion', 'inventarios'])) {
            return response()->json([
                'success' => false,
                'mensaje' => 'No tiene permisos'
            ], 403);
        }

        if (!$solicitud->tiene_reserva_stock) {
            return response()->json([
                'success' => false,
                'mensaje' => 'Esta cotización no tiene reserva de stock activa'
            ], 400);
        }

        $liberadas = $this->reservaService->liberarReservasCotizacion(
            $solicitud,
            'Liberación manual por usuario',
            Auth::id()
        );

        return response()->json([
            'success' => true,
            'mensaje' => "Se liberaron {$liberadas} reservas de stock"
        ]);
    }

    /**
     * Obtener productos para el selector de edición
     */
    public function getProductos(Request $request)
    {
        $query = Producto::activos()
                        ->with(['variantes', 'imagenPrincipal', 'precios']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('nombre', 'like', "%{$search}%")
                  ->orWhere('referencia', 'like', "%{$search}%");
            });
        }

        $productos = $query->limit(50)->get();

        return response()->json($productos);
    }

    /**
     * Obtener variantes de un producto
     */
    public function getVariantes(Producto $producto)
    {
        $variantes = $producto->variantes()
                             ->with(['precios', 'stock'])
                             ->get();

        return response()->json($variantes);
    }

    /**
     * Obtener precio de producto/variante para un cliente
     */
    public function getPrecio(Request $request)
    {
        $request->validate([
            'producto_id' => 'required|exists:productos,id',
            'variante_id' => 'nullable|exists:variantes_productos,id',
            'cliente_id' => 'required|exists:clientes,id',
        ]);

        $producto = Producto::with(['precios', 'variantes.precios'])->find($request->producto_id);
        $cliente = Cliente::find($request->cliente_id);
        $listaPrecioId = $cliente->lista_precio_id;

        $precio = 0;

        if ($request->filled('variante_id')) {
            $variante = $producto->variantes->find($request->variante_id);
            if ($variante) {
                $precioVariante = $variante->precios->where('lista_precio_id', $listaPrecioId)->first();
                $precio = $precioVariante?->precio ?? 0;
            }
        }

        if ($precio == 0) {
            $precioProducto = $producto->precios->where('lista_precio_id', $listaPrecioId)->first();
            $precio = $precioProducto?->precio ?? 0;
        }

        return response()->json([
            'precio' => $precio,
            'lista_precio' => $cliente->listaPrecio?->nombre
        ]);
    }

    // ==========================================
    // FACTURACIÓN
    // ==========================================

    /**
     * Generar factura para una cotización
     */
    public function generarFactura(Request $request, SolicitudCotizacion $solicitud)
    {
        $user = Auth::user();

        // Verificar permisos
        if (!$user->hasAnyRole(['admin', 'auxiliar_administrativo', 'facturacion', 'inventarios'])) {
            return response()->json([
                'success' => false,
                'mensaje' => 'No tiene permisos para generar facturas'
            ], 403);
        }

        // Validar datos (el IVA ya está guardado en la cotización desde la edición)
        $validated = $request->validate([
            'forma_pago' => 'nullable|string|max:50',
            'dias_vencimiento' => 'nullable|integer|min:0|max:365',
        ]);

        $resultado = $this->facturacionService->generarFactura(
            $solicitud,
            $user->id,
            $validated['forma_pago'] ?? 'Contado',
            $validated['dias_vencimiento'] ?? 0
        );

        if (!$resultado['exito']) {
            return response()->json([
                'success' => false,
                'mensaje' => implode(', ', $resultado['errores'])
            ], 400);
        }

        return response()->json([
            'success' => true,
            'mensaje' => "Factura {$resultado['solicitud']->numero_factura} generada exitosamente",
            'numero_factura' => $resultado['solicitud']->numero_factura
        ]);
    }

    /**
     * Descargar PDF de factura
     */
    public function descargarFactura(SolicitudCotizacion $solicitud)
    {
        $user = Auth::user();

        // Verificar permisos
        if (!$user->hasAnyRole(['admin', 'auxiliar_administrativo', 'facturacion', 'vendedor', 'inventarios', 'cliente'])) {
            abort(403, 'No tiene permisos para descargar facturas');
        }

        if (!$solicitud->tieneFactura()) {
            abort(404, 'Esta cotización no tiene factura generada');
        }

        try {
            $pdf = $this->facturacionService->generarPdfFactura($solicitud);
            $nombreArchivo = $this->facturacionService->getNombreArchivoFactura($solicitud);

            return $pdf->download($nombreArchivo);

        } catch (\Exception $e) {
            Log::error("Error al generar PDF de factura: " . $e->getMessage());
            abort(500, 'Error al generar el PDF de factura');
        }
    }

    // ==========================================
    // GESTIÓN DE ENVÍOS - FASE 7
    // ==========================================

    /**
     * Actualizar estado de envío de una cotización
     */
    public function actualizarEnvio(Request $request, SolicitudCotizacion $solicitud)
    {
        $user = Auth::user();

        // Verificar permisos (admin, facturación, inventarios, auxiliar inventario)
        if (!$user->hasAnyRole(['admin', 'auxiliar_administrativo', 'facturacion', 'inventarios', 'auxiliar_inventario'])) {
            return response()->json([
                'success' => false,
                'mensaje' => 'No tiene permisos para gestionar envíos'
            ], 403);
        }

        // Validar que la cotización esté aplicada
        if ($solicitud->estado !== SolicitudCotizacion::ESTADO_APLICADA) {
            return response()->json([
                'success' => false,
                'mensaje' => 'Solo se puede actualizar el envío de cotizaciones aplicadas'
            ], 400);
        }

        // Validar datos
        $validated = $request->validate([
            'estado_envio' => 'required|in:pendiente,preparando,despachado,en_transito,entregado',
            'numero_guia' => 'nullable|string|max:100',
            'transportadora' => 'nullable|string|max:100',
            'archivo_guia' => 'nullable|file|mimes:pdf,jpg,jpeg,png,webp|max:5120', // Max 5MB
        ]);

        try {
            // Procesar archivo de guía si se envía
            $archivoGuia = null;
            if ($request->hasFile('archivo_guia')) {
                $archivo = $request->file('archivo_guia');
                $extension = $archivo->getClientOriginalExtension();
                $nombreArchivo = 'guia-' . $solicitud->numero_solicitud . '-' . time() . '.' . $extension;

                // Crear directorio si no existe
                $directorioGuias = public_path('uploads/guias');
                if (!file_exists($directorioGuias)) {
                    mkdir($directorioGuias, 0755, true);
                }

                // Guardar directamente en public/uploads/guias/
                $archivo->move($directorioGuias, $nombreArchivo);
                $archivoGuia = 'uploads/guias/' . $nombreArchivo;
            }

            $estadoAnterior = $solicitud->estado_envio;

            $solicitud->actualizarEstadoEnvio(
                $validated['estado_envio'],
                $validated['numero_guia'] ?? null,
                $validated['transportadora'] ?? null,
                $archivoGuia,
                $user->id
            );

            // Registrar en historial
            HistorialEstadoSolicitud::create([
                'solicitud_cotizacion_id' => $solicitud->id,
                'tipo_cambio' => HistorialEstadoSolicitud::TIPO_ENVIO,
                'estado_anterior' => $estadoAnterior,
                'estado_nuevo' => $validated['estado_envio'],
                'datos_adicionales' => array_filter([
                    'numero_guia' => $validated['numero_guia'] ?? null,
                    'transportadora' => $validated['transportadora'] ?? null,
                    'archivo_guia' => $archivoGuia,
                ]),
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => true,
                'mensaje' => 'Estado de envío actualizado a: ' . SolicitudCotizacion::estadosEnvio()[$validated['estado_envio']],
                'estado_envio' => $validated['estado_envio']
            ]);

        } catch (\Exception $e) {
            Log::error("Error al actualizar envío: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'mensaje' => 'Error al actualizar el estado de envío'
            ], 500);
        }
    }

    /**
     * Subir guía de envío
     */
    public function subirGuia(Request $request, SolicitudCotizacion $solicitud)
    {
        $user = Auth::user();

        // Verificar permisos
        if (!$user->hasAnyRole(['admin', 'auxiliar_administrativo', 'facturacion', 'inventarios', 'auxiliar_inventario'])) {
            return response()->json([
                'success' => false,
                'mensaje' => 'No tiene permisos para subir guías de envío'
            ], 403);
        }

        // Validar archivo
        $request->validate([
            'archivo_guia' => 'required|file|mimes:pdf,jpg,jpeg,png,webp|max:5120', // Max 5MB
        ]);

        try {
            // Guardar archivo directamente en public/uploads/guias/
            $archivo = $request->file('archivo_guia');
            $extension = $archivo->getClientOriginalExtension();
            $nombreArchivo = 'guia-' . $solicitud->numero_solicitud . '-' . time() . '.' . $extension;

            // Crear directorio si no existe
            $directorioGuias = public_path('uploads/guias');
            if (!file_exists($directorioGuias)) {
                mkdir($directorioGuias, 0755, true);
            }

            $archivo->move($directorioGuias, $nombreArchivo);

            // Actualizar solicitud
            $solicitud->update([
                'archivo_guia' => 'uploads/guias/' . $nombreArchivo,
            ]);

            return response()->json([
                'success' => true,
                'mensaje' => 'Guía de envío subida exitosamente',
                'archivo_guia' => $solicitud->archivo_guia
            ]);

        } catch (\Exception $e) {
            Log::error("Error al subir guía: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'mensaje' => 'Error al subir la guía de envío'
            ], 500);
        }
    }

    /**
     * Obtener historial de envío para una solicitud (JSON)
     */
    public function historialEnvio(SolicitudCotizacion $solicitud)
    {
        $historial = $solicitud->historialEstados()
            ->where('tipo_cambio', HistorialEstadoSolicitud::TIPO_ENVIO)
            ->with('usuario')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'historial' => $historial->map(fn($h) => [
                'estado_anterior' => $h->estado_anterior,
                'estado_nuevo' => $h->estado_nuevo,
                'datos_adicionales' => $h->datos_adicionales,
                'usuario' => $h->usuario?->name ?? 'Sistema',
                'fecha' => $h->created_at->format('d/m/Y H:i'),
            ]),
        ]);
    }

    /**
     * Enviar alertas de cotización aceptada a vendedor y administradores
     */
    private function enviarAlertasCotizacionAceptada(SolicitudCotizacion $solicitud): void
    {
        try {
            $solicitud->load(['cliente', 'cliente.vendedor', 'items']);

            // 1. Enviar al vendedor asignado al cliente
            $vendedor = $solicitud->cliente->vendedor;
            if ($vendedor && $vendedor->email) {
                Mail::to($vendedor->email)->queue(
                    new AlertaCotizacionAceptada($solicitud, 'vendedor')
                );
                Log::info("Alerta de cotización enviada al vendedor: {$vendedor->email}");
            }

            // 2. Enviar a todos los administradores (excepto el vendedor si también es admin)
            $admins = User::role('admin')
                ->where('activo', true)
                ->when($vendedor, function($query) use ($vendedor) {
                    return $query->where('id', '!=', $vendedor->id);
                })
                ->get();

            foreach ($admins as $admin) {
                if ($admin->email) {
                    Mail::to($admin->email)->queue(
                        new AlertaCotizacionAceptada($solicitud, 'admin')
                    );
                    Log::info("Alerta de cotización enviada al admin: {$admin->email}");
                }
            }

        } catch (\Exception $e) {
            // No interrumpir el flujo si falla el envío de alertas
            Log::error("Error al enviar alertas de cotización aceptada: " . $e->getMessage());
        }
    }

    public function toggleMarcada(SolicitudCotizacion $solicitud)
    {
        if (!auth()->user()->hasRole('inventarios')) {
            return response()->json(['success' => false, 'mensaje' => 'No autorizado'], 403);
        }

        $solicitud->update(['marcada_inventario' => !$solicitud->marcada_inventario]);

        return response()->json([
            'success' => true,
            'marcada' => $solicitud->marcada_inventario,
        ]);
    }

    /**
     * Obtener logs de una cotización
     */
    public function logs(SolicitudCotizacion $solicitud)
    {
        $logs = LogSolicitudCotizacion::where('solicitud_cotizacion_id', $solicitud->id)
            ->with('usuario')
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($log) {
                return [
                    'id' => $log->id,
                    'accion' => $log->accion,
                    'accion_label' => $log->accion_label,
                    'accion_color' => $log->accion_color,
                    'accion_icon' => $log->accion_icon,
                    'usuario' => $log->usuario->name ?? 'Sistema',
                    'detalle' => $log->detalle,
                    'fecha' => $log->created_at->format('d/m/Y h:i:s A'),
                    'fecha_relativa' => $log->created_at->diffForHumans(),
                ];
            });

        return response()->json($logs);
    }

    /**
     * Buscar clientes para el cambio de cliente en edición
     */
    public function buscarClientes(Request $request)
    {
        $search = $request->get('search', '');

        if (strlen($search) < 2) {
            return response()->json([]);
        }

        $query = Cliente::where('activo', true)
            ->where(function ($q) use ($search) {
                $q->where('nombre_contacto', 'like', "%{$search}%")
                  ->orWhere('razon_social', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('nit', 'like', "%{$search}%");
            });

        // Vendedores solo ven sus clientes
        $user = auth()->user();
        if ($user->hasRole('vendedor') && !$user->hasAnyRole(['admin', 'auxiliar_administrativo', 'facturacion'])) {
            $query->where('vendedor_id', $user->id);
        }

        $clientes = $query->with('listaPrecio')
            ->limit(20)
            ->get()
            ->map(function ($cliente) {
                return [
                    'id' => $cliente->id,
                    'nombre_contacto' => $cliente->nombre_contacto,
                    'razon_social' => $cliente->razon_social,
                    'email' => $cliente->email,
                    'nit' => $cliente->nit,
                    'lista_precio' => $cliente->listaPrecio?->nombre ?? 'Sin lista',
                ];
            });

        return response()->json($clientes);
    }
}