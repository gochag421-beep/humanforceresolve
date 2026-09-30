<?php
declare(strict_types=1);

require_once __DIR__ . '/TtfFont.php';

/**
 * Small PDF writer with embedded TrueType (Identity-H) fonts.
 *
 * Only what the application sheets need: pages, filled rectangles, rules and
 * Unicode text in two weights. Greek is a first-class citizen, so the font is
 * embedded as a subset rather than relying on the standard 14 fonts, which have
 * no Greek coverage. mbstring is deliberately not used: this runs on hosts where
 * it may be absent.
 */
final class PdfWriter
{
    public const PAGE_WIDTH = 595.28;   // A4 at 72dpi
    public const PAGE_HEIGHT = 841.89;
    public const MARGIN_LEFT = 56.0;
    public const MARGIN_RIGHT = 56.0;
    public const MARGIN_TOP = 56.0;
    public const MARGIN_BOTTOM = 60.0;

    /** @var array<int,string> object number => body */
    private array $objects = [];

    /** @var array<int,string> page content streams in order */
    private array $pages = [];

    private string $content = '';
    private float $cursorY;
    private int $objectCounter = 0;
    private int $pagesObjectId = 0;

    private TtfFont $regular;
    private TtfFont $bold;

    /** @var array<string,int> font key => PDF object number */
    private array $fontObjectIds = [];

    private string $currentFont = 'F1';
    private float $currentSize = 10.0;

    public function __construct(string $regularPath, string $boldPath)
    {
        $this->regular = new TtfFont($regularPath);
        $this->bold = new TtfFont($boldPath);
        $this->cursorY = self::PAGE_HEIGHT - self::MARGIN_TOP;
    }

    public function contentWidth(): float
    {
        return self::PAGE_WIDTH - self::MARGIN_LEFT - self::MARGIN_RIGHT;
    }

    public function left(): float
    {
        return self::MARGIN_LEFT;
    }

    public function cursorY(): float
    {
        return $this->cursorY;
    }

    public function bottomLimit(): float
    {
        return self::MARGIN_BOTTOM;
    }

    public function setFont(string $key, float $size): void
    {
        $this->currentFont = $key;
        $this->currentSize = $size;
    }

    public function moveDown(float $points): void
    {
        $this->cursorY -= $points;
    }

    public function moveTo(float $y): void
    {
        $this->cursorY = $y;
    }

    /** True when the cursor has fallen below the printable area. */
    public function needsNewPage(): bool
    {
        return $this->cursorY < self::MARGIN_BOTTOM;
    }

    /** Start a new page, keeping any content already written. */
    public function addPage(): void
    {
        $this->flushPage();
    }

    public function textWidth(string $text, ?string $fontKey = null, ?float $size = null): float
    {
        $font = $fontKey ?? $this->currentFont;
        $fontSize = $size ?? $this->currentSize;
        $ttf = $this->ttfFor($font);
        $total = 0;
        foreach ($this->codepoints($text) as $cp) {
            $total += $ttf->advanceWidth($ttf->glyphId($cp));
        }

        return $total * $fontSize / max(1, $ttf->unitsPerEm());
    }

    public function drawText(float $x, string $text, ?string $fontKey = null, ?float $size = null): void
    {
        if ($text === '') {
            return;
        }
        $font = $fontKey ?? $this->currentFont;
        $fontSize = $size ?? $this->currentSize;
        $this->content .= sprintf(
            "BT /%s %.3F Tf %.3F %.3F Td <%s> Tj ET\n",
            $font,
            $fontSize,
            $x,
            $this->cursorY,
            $this->hexString($text, $font)
        );
    }

    /** Draw a line of text and advance the cursor. */
    public function line(string $text, float $lineHeight, ?string $fontKey = null, ?float $size = null, ?float $x = null): void
    {
        $this->drawText($x ?? self::MARGIN_LEFT, $text, $fontKey, $size);
        $this->cursorY -= $lineHeight;
    }

    public function drawRect(float $x, float $y, float $w, float $h, array $rgb): void
    {
        [$r, $g, $b] = $rgb;
        $this->content .= sprintf("%.4F %.4F %.4F rg %.3F %.3F %.3F %.3F re f\n", $r, $g, $b, $x, $y, $w, $h);
    }

    public function drawLine(float $x1, float $y1, float $x2, float $y2, array $rgb, float $width = 0.6): void
    {
        [$r, $g, $b] = $rgb;
        $this->content .= sprintf(
            "%.4F %.4F %.4F RG %.2F w %.3F %.3F m %.3F %.3F l S\n",
            $r,
            $g,
            $b,
            $width,
            $x1,
            $y1,
            $x2,
            $y2
        );
    }

    /** Word-wrapped paragraph starting at the current cursor. */
    public function paragraph(string $text, float $maxWidth, float $lineHeight, ?string $fontKey = null, ?float $size = null, ?float $x = null): void
    {
        foreach ($this->wrap($text, $maxWidth, $fontKey, $size) as $lineText) {
            $this->line($lineText, $lineHeight, $fontKey, $size, $x);
        }
    }

    /** @return array<int,string> */
    public function wrap(string $text, float $maxWidth, ?string $fontKey = null, ?float $size = null): array
    {
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                $lines[] = '';
                continue;
            }
            $words = preg_split('/\s+/', $paragraph) ?: [];
            $current = '';
            foreach ($words as $word) {
                if ($this->textWidth($word, $fontKey, $size) > $maxWidth && $current === '') {
                    $lines[] = $word;   // unbreakable token longer than the column
                    continue;
                }
                $candidate = $current === '' ? $word : $current . ' ' . $word;
                if ($this->textWidth($candidate, $fontKey, $size) <= $maxWidth) {
                    $current = $candidate;
                } else {
                    $lines[] = $current;
                    $current = $word;
                }
            }
            if ($current !== '') {
                $lines[] = $current;
            }
        }

        return $lines;
    }

    public function build(): string
    {
        if ($this->content !== '' || $this->pages === []) {
            $this->flushPage();
        }

        // Reserved first so page objects can point at their parent.
        $this->pagesObjectId = $this->allocate('');

        $this->registerFonts();

        $pageIds = [];
        foreach ($this->pages as $pageContent) {
            $contentId = $this->allocate(
                '<< /Length ' . strlen($pageContent) . " >>\nstream\n" . $pageContent . "\nendstream"
            );
            $pageIds[] = $this->allocate(sprintf(
                '<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 %d 0 R /F2 %d 0 R >> >> /Contents %d 0 R >>',
                $this->pagesObjectId,
                self::PAGE_WIDTH,
                self::PAGE_HEIGHT,
                $this->fontObjectIds['F1'],
                $this->fontObjectIds['F2'],
                $contentId
            ));
        }

        $this->objects[$this->pagesObjectId] = '<< /Type /Pages /Count ' . count($pageIds)
            . ' /Kids [' . implode(' ', array_map(static fn (int $id): string => $id . ' 0 R', $pageIds)) . '] >>';

        $catalogId = $this->allocate(sprintf('<< /Type /Catalog /Pages %d 0 R >>', $this->pagesObjectId));

        return $this->serialise($catalogId);
    }

    private function registerFonts(): void
    {
        foreach (['F1' => $this->regular, 'F2' => $this->bold] as $key => $font) {
            $subsetTag = 'AAAAAA+DejaVuSans' . ($key === 'F2' ? '-Bold' : '');
            $raw = $font->rawData();

            $fontFileId = $this->allocate(
                '<< /Length ' . strlen($raw) . ' /Length1 ' . strlen($raw) . " >>\nstream\n" . $raw . "\nendstream"
            );

            $bbox = $font->fontBBox();
            $scale = static fn (int $v): int => (int) round($v * 1000 / max(1, $font->unitsPerEm()));

            $descriptorId = $this->allocate(sprintf(
                '<< /Type /FontDescriptor /FontName /%s /Flags 32 /FontBBox [%d %d %d %d] /ItalicAngle 0 /Ascent %d /Descent %d /CapHeight %d /StemV %d /FontFile2 %d 0 R >>',
                $subsetTag,
                $scale($bbox[0]),
                $scale($bbox[1]),
                $scale($bbox[2]),
                $scale($bbox[3]),
                $scale($font->ascender()),
                $scale($font->descender()),
                $scale($font->ascender()),
                $key === 'F2' ? 120 : 80,
                $fontFileId
            ));

            $widths = [];
            for ($gid = 0; $gid < $font->numGlyphs(); $gid++) {
                $widths[] = $font->scaledWidth($gid);
            }

            $cidFontId = $this->allocate(sprintf(
                '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /%s /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor %d 0 R /W [0 [%s]] /CIDToGIDMap /Identity >>',
                $subsetTag,
                $descriptorId,
                implode(' ', $widths)
            ));

            $toUnicodeId = $this->allocate($this->toUnicodeCMap($font));

            $this->fontObjectIds[$key] = $this->allocate(sprintf(
                '<< /Type /Font /Subtype /Type0 /BaseFont /%s /Encoding /Identity-H /DescendantFonts [%d 0 R] /ToUnicode %d 0 R >>',
                $subsetTag,
                $cidFontId,
                $toUnicodeId
            ));
        }
    }

    /**
     * Build a /ToUnicode CMap so viewers can extract, search and copy the text.
     * Glyph ids above 0xFFFF are skipped; the embedded subsets never reach that.
     */
    private function toUnicodeCMap(TtfFont $font): string
    {
        $entries = [];
        foreach ($font->unicodeMap() as $glyphId => $codepoint) {
            if ($glyphId < 1 || $glyphId > 0xFFFF) {
                continue;
            }
            $entries[] = sprintf('<%04X> <%04X>', $glyphId, $codepoint);
        }

        $body = "/CIDInit /ProcSet findresource begin\n"
            . "12 dict begin\nbegincmap\n"
            . "/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
            . "/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n"
            . "1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n";

        // begincmap sections are capped at 100 entries each.
        foreach (array_chunk($entries, 100) as $chunk) {
            $body .= count($chunk) . " beginbfchar\n" . implode("\n", $chunk) . "\nendbfchar\n";
        }

        $body .= "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";

        return '<< /Length ' . strlen($body) . " >>\nstream\n" . $body . "\nendstream";
    }

    private function flushPage(): void
    {
        $this->pages[] = $this->content;
        $this->content = '';
        $this->cursorY = self::PAGE_HEIGHT - self::MARGIN_TOP;
    }

    private function ttfFor(string $fontKey): TtfFont
    {
        return $fontKey === 'F2' ? $this->bold : $this->regular;
    }

    private function hexString(string $text, string $fontKey): string
    {
        $ttf = $this->ttfFor($fontKey);
        $hex = '';
        foreach ($this->codepoints($text) as $cp) {
            $hex .= sprintf('%04X', $ttf->glyphId($cp));
        }

        return $hex;
    }

    /**
     * Decode UTF-8 into codepoints without mbstring.
     *
     * @return array<int,int>
     */
    private function codepoints(string $text): array
    {
        $out = [];
        $len = strlen($text);
        for ($i = 0; $i < $len;) {
            $byte = ord($text[$i]);
            if ($byte < 0x80) {
                $out[] = $byte;
                $i++;
            } elseif (($byte & 0xE0) === 0xC0 && $i + 1 < $len) {
                $out[] = (($byte & 0x1F) << 6) | (ord($text[$i + 1]) & 0x3F);
                $i += 2;
            } elseif (($byte & 0xF0) === 0xE0 && $i + 2 < $len) {
                $out[] = (($byte & 0x0F) << 12) | ((ord($text[$i + 1]) & 0x3F) << 6) | (ord($text[$i + 2]) & 0x3F);
                $i += 3;
            } elseif (($byte & 0xF8) === 0xF0 && $i + 3 < $len) {
                $out[] = (($byte & 0x07) << 18) | ((ord($text[$i + 1]) & 0x3F) << 12)
                    | ((ord($text[$i + 2]) & 0x3F) << 6) | (ord($text[$i + 3]) & 0x3F);
                $i += 4;
            } else {
                $out[] = 0x3F;   // '?'
                $i++;
            }
        }

        return $out;
    }

    private function allocate(string $body): int
    {
        $id = ++$this->objectCounter;
        $this->objects[$id] = $body;

        return $id;
    }

    private function serialise(int $catalogId): string
    {
        $maxId = max(array_keys($this->objects));
        $out = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        for ($id = 1; $id <= $maxId; $id++) {
            if (!isset($this->objects[$id])) {
                continue;
            }
            $offsets[$id] = strlen($out);
            $out .= $id . " 0 obj\n" . $this->objects[$id] . "\nendobj\n";
        }

        $xrefOffset = strlen($out);
        $out .= "xref\n0 " . ($maxId + 1) . "\n0000000000 65535 f \n";
        for ($id = 1; $id <= $maxId; $id++) {
            $out .= isset($offsets[$id])
                ? sprintf("%010d 00000 n \n", $offsets[$id])
                : "0000000000 65535 f \n";
        }
        $out .= "trailer\n<< /Size " . ($maxId + 1) . ' /Root ' . $catalogId . " 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF\n";

        return $out;
    }
}
