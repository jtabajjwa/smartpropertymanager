<?php

namespace App\Accounting\Reports;

use Barryvdh\DomPDF\Facade\Pdf;

/** A financial report as an A4 PDF (fonts subset, so files stay small; "Page x of y" on every page). */
class ReportPdf
{
    public function make(Report $report): \Barryvdh\DomPDF\PDF
    {
        return $this->makeMany([$report]);
    }

    /** Several reports in one PDF, each starting on a new page (e.g. an annual tax pack). */
    public function makeMany(array $reports, ?string $packTitle = null): \Barryvdh\DomPDF\PDF
    {
        $pdf = Pdf::setOption(['isFontSubsettingEnabled' => true, 'defaultFont' => 'DejaVu Sans'])
            ->loadView('pdfs.books-report', ['reports' => array_values($reports), 'packTitle' => $packTitle])
            ->setPaper('a4');

        // The page total is only known after layout, so stamp it onto each page.
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() - 112, $canvas->get_height() - 40, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7, [0.61, 0.64, 0.69]);

        return $pdf;
    }
}
