<?php

namespace App\Accounting\Reports;

/**
 * A financial report as data: sections of account rows with subtotals, then
 * totals that combine sections. The screen, the PDF and the Excel export are
 * all drawn from one of these, so they always agree.
 */
final class Report
{
    /**
     * @var list<array{key: string, title: ?string, rows: list<array{code: ?string, name: string, values: list<float>, account_id?: ?int, note?: ?string}>, total_label: ?string}>
     */
    public array $sections = [];

    /**
     * Totals below the sections, each a signed combination of section totals.
     *
     * @var list<array{label: string, combine: array<string, int>, grand: bool}>
     */
    public array $totals = [];

    /** @var list<string> */
    public array $notes = [];

    /** Whose report this is (a landlord); null = Primora. */
    public ?string $entity = null;

    /** A line under the entity name, e.g. "TIN 1000123456". */
    public ?string $entityLine = null;

    /** A check shown under the report, e.g. "Debits equal credits": [label, ok]. */
    public ?array $check = null;

    /**
     * @param  list<string>  $columns  value column headings
     */
    public function __construct(
        public string $slug,
        public string $title,
        public string $period,
        public array $columns,
        public bool $showCodes = true,
    ) {
    }

    public function section(string $key, ?string $title, array $rows, ?string $totalLabel = null): self
    {
        $this->sections[] = ['key' => $key, 'title' => $title, 'rows' => array_values($rows), 'total_label' => $totalLabel];

        return $this;
    }

    public function total(string $label, array $combine, bool $grand = false): self
    {
        $this->totals[] = ['label' => $label, 'combine' => $combine, 'grand' => $grand];

        return $this;
    }

    public function note(string $note): self
    {
        $this->notes[] = $note;

        return $this;
    }

    /** Sum of one value column in a section. */
    public function sectionTotal(string $key, int $column): float
    {
        foreach ($this->sections as $section) {
            if ($section['key'] === $key) {
                return round(array_sum(array_map(fn ($row) => (float) ($row['values'][$column] ?? 0), $section['rows'])), 2);
            }
        }

        return 0.0;
    }

    public function totalValue(array $total, int $column): float
    {
        $sum = 0.0;
        foreach ($total['combine'] as $key => $sign) {
            $sum += $sign * $this->sectionTotal($key, $column);
        }

        return round($sum, 2);
    }

    public function filename(string $extension): string
    {
        return \Illuminate\Support\Str::slug(($this->entity ?? config('app.name')).' '.$this->title.' '.$this->period).'.'.$extension;
    }
}
