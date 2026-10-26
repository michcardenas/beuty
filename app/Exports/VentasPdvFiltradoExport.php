<?php

namespace App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

/**
 * Exporta a Excel el historial de ventas del PdV APLICANDO LOS MISMOS FILTROS
 * que la tabla de /pdv/ventas (estado, caja, método de pago, rango de fechas y
 * el alcance por caja del usuario). Es 100% solo lectura.
 */
class VentasPdvFiltradoExport implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize, WithStyles, WithTitle
{
    protected Builder $query;

    public function __construct(Builder $query)
    {
        $this->query = $query;
    }

    public function query()
    {
        return $this->query->with('usuario', 'cliente', 'caja', 'facturaSiigo')
            ->orderByDesc('created_at');
    }

    public function headings(): array
    {
        return [
            'Nro. Venta',
            'Fecha',
            'Caja',
            'Cliente',
            'Cajero',
            'Método de pago',
            'Estado',
            'Factura N°',
            'Estado factura',
            'Total',
        ];
    }

    public function map($v): array
    {
        $cliente = $v->nombre_cliente
            ?: ($v->cliente ? ($v->cliente->razon_social ?: $v->cliente->nombre_contacto) : 'Consumidor Final');

        $facturaNumero = $v->facturaSiigo->numero_factura ?? '';
        $facturaEstado = $v->facturaSiigo ? ($v->facturaSiigo->estado ?? '') : 'Sin factura';

        return [
            $v->numero_venta,
            optional($v->created_at)->format('d/m/Y H:i'),
            $v->caja->nombre ?? '-',
            $cliente,
            $v->usuario->name ?? '-',
            ucfirst((string) $v->metodo_pago),
            ucfirst((string) $v->estado),
            $facturaNumero,
            $facturaEstado,
            (float) $v->total,
        ];
    }

    public function title(): string
    {
        return 'Ventas PdV';
    }

    public function styles(Worksheet $sheet)
    {
        // Formato de moneda en la columna Total (J)
        $sheet->getStyle('J2:J' . max(2, $sheet->getHighestRow()))
            ->getNumberFormat()->setFormatCode('#,##0.00');

        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'FF84D5'], // Miracle Pink
                ],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
        ];
    }
}
