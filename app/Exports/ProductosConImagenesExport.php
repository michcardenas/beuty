<?php

namespace App\Exports;

use App\Models\Producto;
use App\Models\ListaPrecio;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithDrawings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use Illuminate\Support\Collection;

/**
 * Export de productos con imágenes para reportes visuales
 */
class ProductosConImagenesExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles, WithEvents
{
    protected $productos;
    protected $incluirImagenes;
    protected $categoriaId;
    protected $listasPrecios;

    public function __construct($categoriaId = null, $incluirImagenes = true)
    {
        $this->categoriaId = $categoriaId;
        $this->incluirImagenes = $incluirImagenes;
        $this->listasPrecios = ListaPrecio::where('activo', true)->orderBy('orden')->get();
    }

    public function collection()
    {
        $query = Producto::with(['categoria', 'imagenPrincipal', 'precios.listaPrecio', 'stockPrincipal'])
            ->where('activo', true)
            ->where('eliminado', false);

        if ($this->categoriaId) {
            $query->where('categoria_id', $this->categoriaId);
        }

        $this->productos = $query->orderBy('categoria_id')
            ->orderBy('nombre')
            ->get();

        return $this->productos;
    }

    public function map($producto): array
    {
        // Indexar precios por lista_precio_id
        $preciosPorLista = [];
        foreach ($producto->precios as $precio) {
            $preciosPorLista[$precio->lista_precio_id] = $precio->precio;
        }

        // Columna para imagen (placeholder, la imagen se inserta con eventos)
        $imagenPlaceholder = $producto->imagenPrincipal ? '[IMG]' : '-';

        $row = [
            $imagenPlaceholder,
            $producto->referencia,
            $producto->nombre,
            $producto->categoria->nombre ?? 'Sin categoría',
            $producto->descripcion ? substr($producto->descripcion, 0, 100) . '...' : '-',
            $producto->unidad_venta,
        ];

        // Agregar precio de cada lista dinámicamente
        foreach ($this->listasPrecios as $lista) {
            $row[] = isset($preciosPorLista[$lista->id])
                ? number_format($preciosPorLista[$lista->id], 2)
                : '-';
        }

        $row[] = $producto->controlar_stock ? 'Sí' : 'No';

        // Stock Máximo (el mismo valor del input del formulario: registro principal sin variante)
        $stockMaximo = optional($producto->stockPrincipal)->stock_maximo;
        $row[] = ($stockMaximo !== null) ? $stockMaximo : '-';

        return $row;
    }

    public function headings(): array
    {
        $headings = [
            'Imagen',
            'Referencia',
            'Nombre',
            'Categoría',
            'Descripción',
            'Unidad',
        ];

        // Agregar nombre de cada lista de precios dinámicamente
        foreach ($this->listasPrecios as $lista) {
            $headings[] = $lista->nombre;
        }

        $headings[] = 'Control Stock';
        $headings[] = 'Stock Máximo';

        return $headings;
    }

    public function styles(Worksheet $sheet)
    {
        // Ajustar ancho de columna de imagen
        $sheet->getColumnDimension('A')->setWidth(15);

        return [
            // Encabezados con estilo Miracle
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'FF84D5'] // miracle-pink
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ]
            ],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function(AfterSheet $event) {
                if (!$this->incluirImagenes) {
                    return;
                }

                $sheet = $event->sheet->getDelegate();
                $row = 2; // Empezar desde la fila 2 (después de encabezados)

                // Calcular última columna dinámicamente
                $totalColumnas = 6 + $this->listasPrecios->count() + 2; // 6 fijas + listas + control stock + stock máximo
                $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($totalColumnas);

                foreach ($this->productos as $producto) {
                    // Ajustar altura de fila para la imagen
                    $sheet->getRowDimension($row)->setRowHeight(60);

                    if ($producto->imagenPrincipal) {
                        $imagePath = public_path($producto->imagenPrincipal->ruta_imagen);

                        if (file_exists($imagePath)) {
                            try {
                                $drawing = new Drawing();
                                $drawing->setName($producto->nombre);
                                $drawing->setDescription($producto->nombre);
                                $drawing->setPath($imagePath);
                                $drawing->setHeight(55);
                                $drawing->setCoordinates('A' . $row);
                                $drawing->setOffsetX(5);
                                $drawing->setOffsetY(2);
                                $drawing->setWorksheet($sheet);
                            } catch (\Exception $e) {
                                // Si falla la imagen, dejar el placeholder
                                $sheet->setCellValue('A' . $row, 'Error img');
                            }
                        }
                    }

                    $row++;
                }

                // Centrar contenido de todas las celdas
                $lastRow = $row - 1;
                $sheet->getStyle('A2:' . $lastCol . $lastRow)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            },
        ];
    }
}
