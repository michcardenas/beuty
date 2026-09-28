<?php

namespace App\Exports;

use App\Models\User;
use App\Services\SeguimientoComercialService;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Exportación del Seguimiento Comercial del vendedor (Etapa 6) — con diseño de
 * documento de presentación (marca Miracle, colores pastel, formato de moneda).
 * Datos del mismo SeguimientoComercialService, siempre por vendedor.
 */
class SeguimientoVendedorExport implements WithMultipleSheets
{
    protected int $vendedorId;
    protected Carbon $fechaInicio;
    protected Carbon $fechaFin;
    protected string $nombreVendedor;

    public function __construct(int $vendedorId, Carbon $fechaInicio, Carbon $fechaFin)
    {
        $this->vendedorId = $vendedorId;
        $this->fechaInicio = $fechaInicio;
        $this->fechaFin = $fechaFin;
        $this->nombreVendedor = optional(User::find($vendedorId))->name ?? 'Vendedor';
    }

    public function sheets(): array
    {
        $svc = app(SeguimientoComercialService::class);
        $args = [$svc, $this->vendedorId, $this->fechaInicio, $this->fechaFin, $this->nombreVendedor];

        return [
            new HojaResumenVendedor(...$args),
            new HojaClientesVendedor(...$args),
            new HojaContadoCredito(...$args),
        ];
    }
}

/**
 * Paleta y estilos de marca Miracle para las hojas de Excel.
 * Las hojas empiezan en A5: filas 1-3 se reservan para el encabezado de marca.
 */
trait DisenoHojaMiracle
{
    // Colores (hex sin #)
    protected string $cDark = '382E65';    // morado oscuro
    protected string $cPink = 'F7A8DC';    // rosa pastel
    protected string $cLilac = 'BCA9F5';   // lila
    protected string $cLilacSoft = 'F3EEFB'; // lila muy claro (zebra)
    protected string $cBorder = 'E8E1FA';  // borde suave

    protected SeguimientoComercialService $svc;
    protected int $vendedorId;
    protected Carbon $fechaInicio;
    protected Carbon $fechaFin;
    protected string $nombreVendedor;

    public function __construct(
        SeguimientoComercialService $svc,
        int $vendedorId,
        Carbon $fechaInicio,
        Carbon $fechaFin,
        string $nombreVendedor
    ) {
        $this->svc = $svc;
        $this->vendedorId = $vendedorId;
        $this->fechaInicio = $fechaInicio;
        $this->fechaFin = $fechaFin;
        $this->nombreVendedor = $nombreVendedor;
    }

    public function startCell(): string
    {
        return 'A5';
    }

    abstract protected function subtitulo(): string;
    abstract protected function ultimaColumna(): string;

    protected function columnasMoneda(): array
    {
        return [];
    }

    protected function columnasPorcentaje(): array
    {
        return [];
    }

    protected function periodoTexto(): string
    {
        return $this->fechaInicio->isoFormat('D [de] MMMM YYYY') . '  —  ' . $this->fechaFin->isoFormat('D [de] MMMM YYYY');
    }

    /**
     * Previene inyección de fórmulas (CSV/Excel injection): antepone comilla simple
     * a los textos que empiezan por un carácter que Excel interpretaría como fórmula.
     */
    protected function safeText($valor): string
    {
        $valor = (string) $valor;
        if ($valor !== '' && in_array($valor[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $valor;
        }
        return $valor;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $last = $this->ultimaColumna();
                $headRow = 5;
                $firstData = 6;
                $lastRow = $sheet->getHighestRow();

                // ---- Encabezado de marca (filas 1-3) ----
                $sheet->setCellValue('A1', 'MIRACLE BEAUTY EXPERTS');
                $sheet->mergeCells("A1:{$last}1");
                $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB('FFFFFF');
                $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($this->cDark);
                $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
                $sheet->getRowDimension(1)->setRowHeight(30);

                $sheet->setCellValue('A2', $this->subtitulo());
                $sheet->mergeCells("A2:{$last}2");
                $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12)->getColor()->setRGB($this->cDark);
                $sheet->getStyle('A2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($this->cPink);
                $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
                $sheet->getRowDimension(2)->setRowHeight(22);

                $sheet->setCellValue('A3', 'Período:  ' . $this->periodoTexto());
                $sheet->mergeCells("A3:{$last}3");
                $sheet->getStyle('A3')->getFont()->setItalic(true)->setSize(10)->getColor()->setRGB('7A6FA0');
                $sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getRowDimension(4)->setRowHeight(6);

                // ---- Cabecera de la tabla (fila 5) ----
                $headRange = "A{$headRow}:{$last}{$headRow}";
                $sheet->getStyle($headRange)->getFont()->setBold(true)->setSize(11)->getColor()->setRGB($this->cDark);
                $sheet->getStyle($headRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($this->cLilac);
                $sheet->getStyle($headRange)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
                $sheet->getRowDimension($headRow)->setRowHeight(20);

                // ---- Datos: bordes + zebra ----
                if ($lastRow >= $firstData) {
                    $dataRange = "A{$headRow}:{$last}{$lastRow}";
                    $sheet->getStyle($dataRange)->getBorders()->getAllBorders()
                        ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB($this->cBorder);

                    for ($row = $firstData; $row <= $lastRow; $row++) {
                        if ($row % 2 === 0) {
                            $sheet->getStyle("A{$row}:{$last}{$row}")->getFill()
                                ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($this->cLilacSoft);
                        }
                    }

                    // Formato de moneda
                    foreach ($this->columnasMoneda() as $col) {
                        $sheet->getStyle("{$col}{$firstData}:{$col}{$lastRow}")
                            ->getNumberFormat()->setFormatCode('"$"#,##0');
                        $sheet->getStyle("{$col}{$firstData}:{$col}{$lastRow}")
                            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                    }

                    // Formato de porcentaje (valores enteros tipo 100 => 100%)
                    foreach ($this->columnasPorcentaje() as $col) {
                        $sheet->getStyle("{$col}{$firstData}:{$col}{$lastRow}")
                            ->getNumberFormat()->setFormatCode('0"%"');
                        $sheet->getStyle("{$col}{$firstData}:{$col}{$lastRow}")
                            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    }
                }

                // Congelar el encabezado de tabla
                $sheet->freezePane("A{$firstData}");
            },
        ];
    }
}

class HojaResumenVendedor implements FromArray, WithHeadings, WithTitle, WithCustomStartCell, ShouldAutoSize, WithEvents
{
    use DisenoHojaMiracle;

    public function array(): array
    {
        $r = $this->svc->resumenVendedor($this->vendedorId, $this->fechaInicio, $this->fechaFin);
        $e = $this->svc->cotizacionesPorEstado($this->vendedorId, $this->fechaInicio, $this->fechaFin);

        $money = fn ($v) => '$' . number_format($v, 0, ',', '.');

        return [
            ['Total ventas del período', $money($r['total_ventas'])],
            ['Pedidos (cotizaciones aplicadas)', number_format($r['total_pedidos'], 0, ',', '.')],
            ['Ticket promedio', $money(round($r['ticket_promedio']))],
            ['Ventas de contado', $money($e['contado']['monto']) . '   (' . $e['contado']['cantidad'] . ' ventas)'],
            ['Ventas a crédito', $money($e['credito']['monto']) . '   (' . $e['credito']['cantidad'] . ' ventas)'],
            ['Participación de contado', ($e['contado']['participacion'] ?? 0) . '%'],
            ['Cotizaciones pendientes', number_format($e['pendientes']['cantidad'], 0, ',', '.')],
            ['Tasa de conversión', ($e['tasa_conversion'] ?? 0) . '%'],
        ];
    }

    public function headings(): array
    {
        return ['Indicador', 'Valor'];
    }

    protected function subtitulo(): string
    {
        return 'Resumen de Ventas  ·  ' . $this->nombreVendedor;
    }

    protected function ultimaColumna(): string
    {
        return 'B';
    }

    public function title(): string
    {
        return 'Resumen';
    }
}

class HojaClientesVendedor implements FromArray, WithHeadings, WithTitle, WithCustomStartCell, ShouldAutoSize, WithEvents
{
    use DisenoHojaMiracle;

    public function array(): array
    {
        $clientes = $this->svc->rankingClientes($this->vendedorId, $this->fechaInicio, $this->fechaFin);

        $filas = [];
        foreach ($clientes as $i => $c) {
            $filas[] = [
                $i + 1,
                $this->safeText($c['cliente']),
                $c['pedidos'],
                $c['total_facturado'],
                $c['ultima_compra'] ? $c['ultima_compra']->isoFormat('D MMM YYYY') : '—',
            ];
        }
        return $filas;
    }

    public function headings(): array
    {
        return ['#', 'Cliente', 'Pedidos', 'Total facturado', 'Última compra'];
    }

    protected function subtitulo(): string
    {
        return 'Ventas por Cliente  ·  ' . $this->nombreVendedor;
    }

    protected function ultimaColumna(): string
    {
        return 'E';
    }

    protected function columnasMoneda(): array
    {
        return ['D'];
    }

    public function title(): string
    {
        return 'Ventas por Cliente';
    }
}

class HojaContadoCredito implements FromArray, WithHeadings, WithTitle, WithCustomStartCell, ShouldAutoSize, WithEvents
{
    use DisenoHojaMiracle;

    public function array(): array
    {
        $e = $this->svc->cotizacionesPorEstado($this->vendedorId, $this->fechaInicio, $this->fechaFin);

        return [
            ['Contado', $e['contado']['cantidad'], $e['contado']['monto'], $e['contado']['participacion']],
            ['Crédito', $e['credito']['cantidad'], $e['credito']['monto'], $e['credito']['participacion']],
        ];
    }

    public function headings(): array
    {
        return ['Tipo de operación', 'Ventas', 'Monto', 'Participación'];
    }

    protected function subtitulo(): string
    {
        return 'Contado vs Crédito  ·  ' . $this->nombreVendedor;
    }

    protected function ultimaColumna(): string
    {
        return 'D';
    }

    protected function columnasMoneda(): array
    {
        return ['C'];
    }

    protected function columnasPorcentaje(): array
    {
        return ['D'];
    }

    public function title(): string
    {
        return 'Contado-Credito';
    }
}
