<?php
declare(strict_types=1);

require_once __DIR__ . '/PdfWriter.php';

/**
 * Renders one application (candidate or employer) as an A4 sheet.
 *
 * Every sheet carries the record id and the submission timestamp, so a printed
 * stack stays traceable back to the JSON store.
 */
final class ApplicationPdf
{
    private const BRAND = [0.086, 0.231, 0.192];      // #163b31
    private const ACCENT = [0.706, 0.878, 0.796];
    private const INK = [0.106, 0.129, 0.122];
    private const MUTED = [0.400, 0.443, 0.424];

    private PdfWriter $pdf;
    private float $contentWidth;

    public function __construct(string $regularFont, string $boldFont)
    {
        $this->pdf = new PdfWriter($regularFont, $boldFont);
        $this->contentWidth = $this->pdf->contentWidth();
    }

    /** @param array<string,mixed> $record */
    public function render(array $record): string
    {
        $kind = ($record['kind'] ?? 'candidate') === 'employer' ? 'employer' : 'candidate';
        $submitted = $this->greekDateTime((string) ($record['createdAt'] ?? 'now'));

        $this->header($kind, (string) ($record['id'] ?? ''), $submitted);
        $this->detailsSection($kind, $record);
        $this->descriptionSection((string) ($record['details'] ?? ''));
        $this->consentSection($record, $submitted);
        $this->footer((string) ($record['id'] ?? ''));

        return $this->pdf->build();
    }

    private function header(string $kind, string $id, string $submitted): void
    {
        $top = PdfWriter::PAGE_HEIGHT;
        $this->pdf->drawRect(0, $top - 118, PdfWriter::PAGE_WIDTH, 118, self::BRAND);
        $this->pdf->drawRect(0, $top - 124, PdfWriter::PAGE_WIDTH, 6, self::ACCENT);

        $this->pdf->moveTo($top - 42);
        $this->pdf->line('HUMAN FORCE SOLUTIONS', 22, 'F2', 19);
        $this->pdf->line(
            $kind === 'employer' ? 'Αίτηση εργοδότη' : 'Αίτηση υποψηφίου',
            20,
            'F1',
            12
        );

        // Meta line: protocol number on the left, submission time on the right.
        $right = PdfWriter::PAGE_WIDTH - PdfWriter::MARGIN_RIGHT;
        $metaY = $top - 100;
        $this->pdf->moveTo($metaY);
        $this->pdf->drawText(PdfWriter::MARGIN_LEFT, 'Αρ. πρωτοκόλλου: ' . $id, 'F1', 8.5);
        $submittedText = 'Υποβλήθηκε: ' . $submitted;
        $this->pdf->drawText(
            $right - $this->pdf->textWidth($submittedText, 'F1', 8.5),
            $submittedText,
            'F1',
            8.5
        );

        $this->pdf->moveTo($top - 150);
    }

    /** @param array<string,mixed> $record */
    private function detailsSection(string $kind, array $record): void
    {
        $this->sectionTitle($kind === 'employer' ? 'Στοιχεία επιχείρησης' : 'Στοιχεία επικοινωνίας');

        $rows = [
            ['Ονοματεπώνυμο', (string) ($record['name'] ?? '—')],
            ['Περιοχή', (string) ($record['area'] ?? '—')],
            ['Email', (string) ($record['email'] ?? '—')],
            ['Τηλέφωνο', (string) ($record['phone'] ?? '—')],
        ];

        if ($kind === 'employer') {
            $rows[] = ['Επωνυμία επιχείρησης', (string) ($record['company'] ?? '—')];
            $rows[] = ['Ζητούμενα άτομα', (string) ($record['count'] ?? '—')];
        }

        if (!empty($record['licence']) && $record['licence'] !== 'Δεν διαθέτω / Δεν απαιτείται') {
            $rows[] = ['Κατηγορία διπλώματος', (string) $record['licence']];
        }

        if (!empty($record['jobTitle'])) {
            $rows[] = ['Θέση', (string) $record['jobTitle'] . (!empty($record['jobArea']) ? ' · ' . $record['jobArea'] : '')];
        }

        $this->fieldTable($rows);
    }

    private function descriptionSection(string $details): void
    {
        $this->pdf->moveDown(6);
        $this->sectionTitle('Περιγραφή & διαθεσιμότητα');

        $text = trim($details);
        if ($text === '') {
            $text = 'Δεν δόθηκε περιγραφή.';
        }
        $this->pdf->paragraph($text, $this->contentWidth, 15, 'F1', 10.5);
    }

    /** @param array<string,mixed> $record */
    private function consentSection(array $record, string $submitted): void
    {
        $this->pdf->moveDown(8);
        $this->sectionTitle('Συναίνεση & τεχνικά στοιχεία');

        $rows = [
            ['Συναίνεση επεξεργασίας', !empty($record['consent']) ? 'Ναι' : 'Όχι'],
            ['Χρόνος υποβολής', $submitted],
        ];
        if (!empty($record['ip'])) {
            $rows[] = ['Διεύθυνση IP', (string) $record['ip']];
        }
        if (!empty($record['userAgent'])) {
            $rows[] = ['Περιηγητής', substr((string) $record['userAgent'], 0, 120)];
        }
        if (!empty($record['status'])) {
            $rows[] = ['Κατάσταση', (string) $record['status']];
        }
        if (!empty($record['notes'])) {
            $rows[] = ['Εσωτερικές σημειώσεις', (string) $record['notes']];
        }

        $this->fieldTable($rows);
    }

    private function footer(string $id): void
    {
        $this->pdf->moveTo(46);
        $this->pdf->drawLine(
            PdfWriter::MARGIN_LEFT,
            52,
            PdfWriter::PAGE_WIDTH - PdfWriter::MARGIN_RIGHT,
            52,
            self::ACCENT,
            0.8
        );
        $this->pdf->moveTo(40);
        $this->pdf->line(
            'HUMAN FORCE SOLUTIONS GROUP · contact@humanforcesolutions.com · +30 2107499385',
            11,
            'F1',
            8
        );
        $this->pdf->moveTo(40);
        $this->pdf->drawText(
            PdfWriter::PAGE_WIDTH - PdfWriter::MARGIN_RIGHT - $this->pdf->textWidth($id, 'F1', 8),
            $id,
            'F1',
            8
        );
    }

    private function sectionTitle(string $title): void
    {
        $this->pdf->drawRect(PdfWriter::MARGIN_LEFT, $this->pdf->cursorY() - 3, 22, 2.4, self::BRAND);
        $this->pdf->moveDown(13);
        $this->pdf->line($title, 19, 'F2', 12.5);
        $this->pdf->moveDown(2);
    }

    /**
     * Two-column label/value rows with a hairline between them.
     *
     * @param array<int,array{0:string,1:string}> $rows
     */
    private function fieldTable(array $rows): void
    {
        $labelWidth = 150.0;
        $valueWidth = $this->contentWidth - $labelWidth;

        foreach ($rows as [$label, $value]) {
            $valueLines = $this->pdf->wrap($value, $valueWidth, 'F1', 10.5);
            $rowHeight = max(16.0, count($valueLines) * 14.0 + 2);

            if ($this->pdf->cursorY() - $rowHeight < PdfWriter::MARGIN_BOTTOM + 30) {
                $this->pdf->addPage();
            }

            $baseline = $this->pdf->cursorY();
            $this->pdf->drawText(PdfWriter::MARGIN_LEFT, $label, 'F2', 9.5);
            $this->pdf->moveTo($baseline);
            $this->pdf->paragraph($value, $valueWidth, 14, 'F1', 10.5, PdfWriter::MARGIN_LEFT + $labelWidth);

            $this->pdf->moveTo(min($baseline, $this->pdf->cursorY()) - 3);
            $this->pdf->drawLine(
                PdfWriter::MARGIN_LEFT,
                $this->pdf->cursorY(),
                PdfWriter::PAGE_WIDTH - PdfWriter::MARGIN_RIGHT,
                $this->pdf->cursorY(),
                [0.85, 0.88, 0.86],
                0.4
            );
            $this->pdf->moveDown(5);
        }
    }

    private function greekDateTime(string $iso): string
    {
        try {
            $date = new DateTimeImmutable($iso);
        } catch (Exception) {
            $date = new DateTimeImmutable('now');
        }
        $date = $date->setTimezone(new DateTimeZone('Europe/Athens'));

        $months = [
            1 => 'Ιανουαρίου', 'Φεβρουαρίου', 'Μαρτίου', 'Απριλίου', 'Μαΐου', 'Ιουνίου',
            'Ιουλίου', 'Αυγούστου', 'Σεπτεμβρίου', 'Οκτωβρίου', 'Νοεμβρίου', 'Δεκεμβρίου',
        ];

        return sprintf(
            '%d %s %d, %s',
            (int) $date->format('j'),
            $months[(int) $date->format('n')],
            (int) $date->format('Y'),
            $date->format('H:i')
        );
    }
}
