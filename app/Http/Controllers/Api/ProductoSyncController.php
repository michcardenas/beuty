<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ListaPrecio;
use App\Models\Producto;
use App\Models\VarianteProducto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * API de SOLO LECTURA del catálogo de productos de Miracle.
 *
 * Expone, autenticado por token, todos los productos con sus precios
 * (Centro de Experiencia y Distribuidor), stock, imágenes y variantes,
 * para que la tienda Belleza Áurea se conecte por URL y se mantenga
 * sincronizada.
 *
 * 100% aditivo: no modifica ni toca ningún flujo del ERP.
 */
class ProductoSyncController extends Controller
{
    /**
     * GET /api/v1/aurea/productos
     *
     * Parámetros opcionales:
     *   token=...          Token de acceso (o header X-Sync-Token / Authorization: Bearer).
     *   referencia=ABC     Devuelve solo ese producto (útil para pruebas).
     *   solo_activos=0     Incluye también inactivos (por defecto solo activos).
     *   per_page=100       Pagina los resultados (por defecto: según config, 0 = todo).
     *   page=2             Página a devolver cuando hay paginación.
     *   solo_listas=1      Diagnóstico: devuelve las listas de precios disponibles.
     */
    public function productos(Request $request): JsonResponse
    {
        // --- 1) Autenticación por token -------------------------------------
        $esperado = (string) config('aurea.sync_token');

        if ($esperado === '') {
            return response()->json([
                'success' => false,
                'message' => 'Sincronización no configurada: falta definir AUREA_SYNC_TOKEN.',
            ], 503);
        }

        if (! $this->tokenValido($request, $esperado)) {
            return response()->json([
                'success' => false,
                'message' => 'Token inválido o ausente.',
            ], 401);
        }

        // --- 2) Diagnóstico de listas de precios ----------------------------
        if ($request->boolean('solo_listas')) {
            return response()->json([
                'success' => true,
                'listas'  => ListaPrecio::orderBy('orden')
                    ->get(['id', 'codigo', 'nombre', 'activo']),
            ]);
        }

        // --- 3) Resolver las dos listas de precios --------------------------
        $centroId = $this->resolverLista(config('aurea.lista_centro_experiencia'));
        $distId   = $this->resolverLista(config('aurea.lista_distribuidor'));

        // --- 4) Consulta de productos con eager loading (evita N+1) ---------
        $query = Producto::query()
            ->where('eliminado', false)
            ->with([
                'categoria:id,nombre,slug',
                'precios',
                'imagenes',
                'stock',
                'variantes',            // la relación ya filtra activo = true
                'variantes.stock',
                'variantes.imagenes',
                'variantes.precios',
            ])
            ->orderBy('id');

        if ($request->boolean('solo_activos', true)) {
            $query->where('activo', true);
        }

        if ($ref = $request->query('referencia')) {
            $query->where('referencia', $ref);
        }

        // --- 5) Paginación opcional -----------------------------------------
        $porPagina = (int) config('aurea.por_pagina', 0);
        if ($request->filled('per_page')) {
            $porPagina = max(0, (int) $request->query('per_page'));
        }

        $meta = [];
        if ($porPagina > 0) {
            $paginator = $query->paginate($porPagina);
            $items = $paginator->getCollection();
            $meta = [
                'pagina'        => $paginator->currentPage(),
                'por_pagina'    => $paginator->perPage(),
                'total_paginas' => $paginator->lastPage(),
                'total'         => $paginator->total(),
            ];
        } else {
            $items = $query->get();
            $meta = ['total' => $items->count()];
        }

        // --- 6) Mapeo a JSON -------------------------------------------------
        $data = $items->map(fn (Producto $p) => $this->mapProducto($p, $centroId, $distId))->values();

        return response()->json([
            'success'      => true,
            'generado_en'  => now()->toIso8601String(),
            'listas'       => [
                'centro_experiencia' => $centroId !== null,
                'distribuidor'       => $distId !== null,
            ],
            'meta'         => $meta,
            'data'         => $data,
        ]);
    }

    // ========================================================================
    //  Helpers
    // ========================================================================

    private function tokenValido(Request $request, string $esperado): bool
    {
        $provisto = $request->query('token')
            ?? $request->header('X-Sync-Token')
            ?? Str::after((string) $request->header('Authorization'), 'Bearer ');

        $provisto = is_string($provisto) ? trim($provisto) : '';

        return $provisto !== '' && hash_equals($esperado, $provisto);
    }

    private function resolverLista($codigo): ?int
    {
        if (empty($codigo)) {
            return null;
        }

        $lista = ListaPrecio::where('codigo', $codigo)->first()
            ?? ListaPrecio::where('nombre', 'like', '%' . $codigo . '%')->first();

        return $lista?->id;
    }

    private function urlImagen(?string $ruta): ?string
    {
        if (empty($ruta)) {
            return null;
        }

        if (Str::startsWith($ruta, ['http://', 'https://'])) {
            return $ruta;
        }

        return asset(ltrim($ruta, '/'));
    }

    private function mapProducto(Producto $p, ?int $centroId, ?int $distId): array
    {
        $precioCentro = $this->precioProducto($p, $centroId);
        $precioDist   = $this->precioProducto($p, $distId);

        // Imágenes a nivel de producto (variante_producto_id null), principal primero.
        $imgsProd = $p->imagenes
            ->whereNull('variante_producto_id')
            ->sortBy([['es_principal', 'desc'], ['orden', 'asc']])
            ->values();

        // Galería unificada: TODAS las imágenes del producto (nivel producto +
        // nivel variante), principal primero, deduplicadas. Áurea guarda una
        // galería plana por producto, así que esto garantiza que nunca se quede
        // sin imágenes aunque en Miracle estén colgadas de las variantes.
        $galeria = $p->imagenes
            ->sortBy([['es_principal', 'desc'], ['orden', 'asc']])
            ->map(fn ($i) => $this->urlImagen($i->ruta_imagen))
            ->filter()
            ->unique()
            ->values();

        $principal = $imgsProd->firstWhere('es_principal', true)
            ?? $imgsProd->first()
            ?? $p->imagenes->firstWhere('es_principal', true)
            ?? $p->imagenes->first();

        // Stock agregado desde la colección ya cargada (sin consultas extra).
        $stockTotal = (int) $p->stock->sum('cantidad_disponible');
        $stockDisp  = (int) $p->stock->sum(
            fn ($s) => ($s->cantidad_disponible - $s->cantidad_reservada)
        );

        return [
            'referencia'               => $p->referencia,
            'nombre'                   => $p->nombre,
            'descripcion'              => $p->descripcion,
            'marca'                    => $p->marca,
            'codigo_barras'            => $p->codigo_barras,
            'unidad_venta'             => $p->unidad_venta,
            'unidad_empaque'           => $p->unidad_empaque,
            'extension'                => $p->extension,
            'fecha_vencimiento'        => optional($p->fecha_vencimiento)->toDateString(),
            'categoria'                => $p->categoria?->nombre,
            'categoria_slug'           => $p->categoria?->slug,
            'activo'                   => (bool) $p->activo,
            'tiene_variantes'          => (bool) $p->tiene_variantes,
            'controlar_stock'          => (bool) $p->controlar_stock,
            'permitir_venta_sin_stock' => (bool) $p->permitir_venta_sin_stock,
            'precios'                  => [
                'centro_experiencia' => $precioCentro,
                'distribuidor'       => $precioDist,
            ],
            'stock'                    => [
                'total'      => $stockTotal,
                'disponible' => $stockDisp,
            ],
            'imagen_principal'         => $this->urlImagen($principal?->ruta_imagen),
            'galeria'                  => $galeria,
            'imagenes'                 => $imgsProd->map(fn ($i) => [
                'url'       => $this->urlImagen($i->ruta_imagen),
                'alt'       => $i->texto_alternativo,
                'principal' => (bool) $i->es_principal,
                'orden'     => (int) $i->orden,
            ])->values(),
            'variantes'                => $p->variantes->map(
                fn (VarianteProducto $v) => $this->mapVariante($v, $centroId, $distId, $precioCentro, $precioDist)
            )->values(),
            'actualizado_en'           => optional($p->updated_at)->toIso8601String(),
        ];
    }

    private function mapVariante(VarianteProducto $v, ?int $centroId, ?int $distId, ?float $baseCentro, ?float $baseDist): array
    {
        $s    = $v->stock; // hasOne
        $tot  = $s ? (int) $s->cantidad_disponible : 0;
        $disp = $s ? (int) ($s->cantidad_disponible - $s->cantidad_reservada) : 0;

        return [
            'referencia_variante' => $v->referencia_variante,
            'color'               => $v->color,
            'sku'                 => $v->sku,
            'codigo_barras'       => $v->codigo_barras,
            'precios'             => [
                'centro_experiencia' => $this->precioVariante($v, $centroId, $baseCentro),
                'distribuidor'       => $this->precioVariante($v, $distId, $baseDist),
            ],
            'stock'               => [
                'total'      => $tot,
                'disponible' => $disp,
            ],
            'imagenes'            => $v->imagenes->map(fn ($i) => [
                'url'       => $this->urlImagen($i->ruta_imagen),
                'alt'       => $i->texto_alternativo,
                'principal' => (bool) $i->es_principal,
            ])->values(),
        ];
    }

    private function precioProducto(Producto $p, ?int $listaId): ?float
    {
        if ($listaId === null) {
            return null;
        }

        $precio = $p->precios->first(
            fn ($x) => (int) $x->lista_precio_id === $listaId && $x->activo
        );

        return $precio ? (float) $precio->precio : null;
    }

    private function precioVariante(VarianteProducto $v, ?int $listaId, ?float $base): ?float
    {
        if ($base === null || $listaId === null) {
            return $base;
        }

        $ajuste = $v->precios->first(
            fn ($x) => (int) $x->lista_precio_id === $listaId && $x->activo
        );

        return $base + ($ajuste ? (float) $ajuste->ajuste_precio : 0.0);
    }
}
