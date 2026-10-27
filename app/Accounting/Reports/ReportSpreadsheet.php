<?php

namespace App\Accounting\Reports;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A financial report as a formatted Excel workbook: branded title block,
 * styled header, section headings, accounting number formats, and live
 * SUM formulas for subtotals and totals (so edits recalculate).
 */
class ReportSpreadsheet
{
    protected const NAVY = '1C2230';
    protected const TERRACOTTA = 'C1704A';
    protected const SECTION_FILL = 'F3F4F6';
    protected const TOTAL_FILL = 'FAF3EE';
    protected const NUMBER = '#,##0.00;(#,##0.00);"–"';

    public function download(Report $report): StreamedResponse
    {
        $book = $this->build($report);

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
        }, $report->filename('xlsx'), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    public function build(Report $report): Spreadsheet
    {
        $book = new Spreadsheet();
        $book->getProperties()
            ->setCreator(config('app.name'))
            ->setCompany($report->entity ?? config('app.name'))
            ->setTitle($report->title)
            ->setSubject($report->title.' · '.$report->period)
            ->setDescription('Generated '.now()->format('d M Y H:i').' from '.config('app.name').' books.');

        $this->fill($book, $book->getActiveSheet(), $report);

        return $book;
    }

    /** Several reports in one workbook, one sheet each (e.g. an annual tax pack). */
    public function downloadMany(array $reports, string $filename): StreamedResponse
    {
        $book = $this->build($reports[0]);
        foreach (array_slice($reports, 1) as $report) {
            $this->fill($book, $book->createSheet(), $report);
        }
        $book->setActiveSheetIndex(0);

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'max-age=0']);
    }

    protected function fill(Spreadsheet $book, Worksheet $sheet, Report $report): void
    {
        $sheet->setTitle(mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', '', $report->title), 0, 31));
        $book->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);

        $codeCol = $report->showCodes ? 1 : null;
        $nameCol = $report->showCodes ? 2 : 1;
        $firstValueCol = $nameCol + 1;
        $lastCol = $firstValueCol + count($report->columns) - 1;
        $letter = fn (int $col) => Coordinate::stringFromColumnIndex($col);
        $lastLetter = $letter($lastCol);

        // --- Title block
        $sheet->setCellValue('A1', mb_strtoupper($report->entity ?? config('app.name')));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(9)->getColor()->setRGB(self::TERRACOTTA);
        $sheet->setCellValue('A2', $report->title);
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB(self::NAVY);
        $sheet->setCellValue('A3', $report->period.($report->entityLine ? ' · '.$report->entityLine : ''));
        $sheet->getStyle('A3')->getFont()->setSize(10)->getColor()->setRGB('6B7280');
        $textRows = [1, 2, 3];
        foreach ($textRows as $r) {
            $sheet->mergeCells("A{$r}:{$lastLetter}{$r}");
        }
        $sheet->getRowDimension(2)->setRowHeight(24);

        // --- Column headings
        $row = 5;
        if ($codeCol) {
            $sheet->setCellValue($letter($codeCol).$row, 'Code');
        }
        $sheet->setCellValue($letter($nameCol).$row, 'Account');
        foreach ($report->columns as $i => $heading) {
            $sheet->setCellValue($letter($firstValueCol + $i).$row, $heading);
        }
        $sheet->getStyle("A{$row}:{$lastLetter}{$row}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::NAVY]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getStyle($letter($firstValueCol).$row.':'.$lastLetter.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getRowDimension($row)->setRowHeight(22);
        $headerRow = $row;
        $row++;

        // --- Sections
        $sectionTotalRows = [];
        $sectionRows = [];
        foreach ($report->sections as $section) {
            if ($section['title']) {
                $sheet->setCellValue("A{$row}", $section['title']);
                $sheet->mergeCells("A{$row}:{$lastLetter}{$row}");
                $textRows[] = $row;
                $sectionRows[] = $row;
                $sheet->getStyle("A{$row}:{$lastLetter}{$row}")->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => self::NAVY]],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::SECTION_FILL]],
                ]);
                $row++;
            }

            $first = $row;
            foreach ($section['rows'] as $line) {
                if ($codeCol) {
                    $sheet->setCellValueExplicit($letter($codeCol).$row, (string) $line['code'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }
                $sheet->setCellValue($letter($nameCol).$row, $line['name']);
                foreach ($line['values'] as $i => $value) {
                    $sheet->setCellValue($letter($firstValueCol + $i).$row, (float) $value);
                }
                $row++;
            }
            $last = $row - 1;

            if ($section['total_label']) {
                $sheet->setCellValue($letter($nameCol).$row, $section['total_label']);
                foreach ($report->columns as $i => $_) {
                    $col = $letter($firstValueCol + $i);
                    $sheet->setCellValue("{$col}{$row}", $last >= $first ? "=SUM({$col}{$first}:{$col}{$last})" : 0);
                }
                $sheet->getStyle("A{$row}:{$lastLetter}{$row}")->applyFromArray([
                    'font' => ['bold' => true],
                    'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '9CA3AF']]],
                ]);
                $sectionTotalRows[$section['key']] = $row;
                $row++;
            }
            $row++; // breathing space
        }

        // --- Totals combining sections (e.g. net profit = income − expenses)
        foreach ($report->totals as $total) {
            $sheet->setCellValue($letter($nameCol).$row, $total['label']);
            foreach ($report->columns as $i => $_) {
                $col = $letter($firstValueCol + $i);
                $parts = [];
                foreach ($total['combine'] as $key => $sign) {
                    if (isset($sectionTotalRows[$key])) {
                        $parts[] = ($sign < 0 ? '-' : '+').$col.$sectionTotalRows[$key];
                    }
                }
                $sheet->setCellValue("{$col}{$row}", '='.ltrim(implode('', $parts) ?: '0', '+'));
            }
            $sheet->getStyle("A{$row}:{$lastLetter}{$row}")->applyFromArray([
                'font' => ['bold' => true, 'size' => $total['grand'] ? 11 : 10, 'color' => ['rgb' => self::NAVY]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::TOTAL_FILL]],
                'borders' => $total['grand']
                    ? ['top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::NAVY]], 'bottom' => ['borderStyle' => Border::BORDER_DOUBLE, 'color' => ['rgb' => self::NAVY]]]
                    : ['top' => ['borderStyle' => Border::BORDER_THIN]],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(20);
            $row += 2;
        }

        // Number format and alignment for every value cell.
        $sheet->getStyle($letter($firstValueCol).($headerRow + 1).':'.$lastLetter.$row)->getNumberFormat()->setFormatCode(self::NUMBER);
        $sheet->getStyle($letter($firstValueCol).($headerRow + 1).':'.$lastLetter.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        if ($codeCol) {
            $sheet->getStyle($letter($codeCol).($headerRow + 1).':'.$letter($codeCol).$row)->getFont()->getColor()->setRGB('6B7280');
            foreach ($sectionRows as $r) { // section headings share the code column: keep them navy
                $sheet->getStyle("A{$r}")->getFont()->getColor()->setRGB(self::NAVY);
            }
        }

        // --- Check and notes
        if ($report->check) {
            [$label, $ok] = $report->check;
            $sheet->setCellValue("A{$row}", ($ok ? '✓ ' : '✗ ').$label);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true)->getColor()->setRGB($ok ? '15803D' : 'DC2626');
            $sheet->mergeCells("A{$row}:{$lastLetter}{$row}");
            $textRows[] = $row;
            $row += 2;
        }
        foreach ($report->notes as $note) {
            $sheet->setCellValue("A{$row}", $note);
            $sheet->mergeCells("A{$row}:{$lastLetter}{$row}");
            $textRows[] = $row;
            $sheet->getStyle("A{$row}")->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB('6B7280');
            $sheet->getStyle("A{$row}")->getAlignment()->setWrapText(true);
            $sheet->getRowDimension($row)->setRowHeight(26);
            $row++;
        }
        $row++;
        $sheet->setCellValue("A{$row}", 'Generated '.now()->format('d M Y, H:i').($report->entity ? ' · prepared with '.config('app.name') : ' · '.config('app.name').' books'));
        $sheet->getStyle("A{$row}")->getFont()->setSize(8)->getColor()->setRGB('9CA3AF');
        $sheet->mergeCells("A{$row}:{$lastLetter}{$row}");
        $textRows[] = $row;

        // Merged text rows (titles, section headings, notes) read left to right.
        foreach ($textRows as $r) {
            $sheet->getStyle("A{$r}:{$lastLetter}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        }

        // --- Layout
        if ($codeCol) {
            $sheet->getColumnDimension($letter($codeCol))->setWidth(9);
        }
        $sheet->getColumnDimension($letter($nameCol))->setWidth(44);
        for ($c = $firstValueCol; $c <= $lastCol; $c++) {
            $sheet->getColumnDimension($letter($c))->setWidth(20);
        }
        $sheet->freezePane('A'.($headerRow + 1));
        $sheet->setShowGridlines(false);
        $sheet->setSelectedCell('A1');

        $setup = $sheet->getPageSetup();
        $setup->setPaperSize(PageSetup::PAPERSIZE_A4)->setOrientation(PageSetup::ORIENTATION_PORTRAIT)
            ->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd($headerRow, $headerRow);
        $sheet->getPageMargins()->setTop(0.6)->setBottom(0.6)->setLeft(0.5)->setRight(0.5);
        $sheet->getHeaderFooter()
            ->setOddHeader('&L&8'.($report->entity ?? config('app.name')).'&R&8'.$report->title.' · '.$report->period)
            ->setOddFooter('&L&8Generated '.now()->format('d M Y').'&R&8Page &P of &N');

    }
}
