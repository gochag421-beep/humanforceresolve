<?php
declare(strict_types=1);

/**
 * Minimal TrueType reader.
 *
 * The PDF writer needs three things out of a font file: a codepoint -> glyph id
 * map, per-glyph advance widths, and per-glyph bounding boxes (for the
 * FontDescriptor). The font program itself is embedded verbatim, so no table
 * rebuilding happens here.
 */
final class TtfFont
{
    private string $data;
    private int $length;

    /** @var array<string,int> tag => table offset */
    private array $tables = [];

    private int $unitsPerEm = 1000;
    private int $indexToLocFormat = 0;
    private int $numGlyphs = 0;
    private int $numberOfHMetrics = 0;
    private int $ascender = 0;
    private int $descender = 0;

    private int $hmtxOffset = 0;
    private int $locaOffset = 0;
    private int $glyfOffset = 0;
    private int $locaEntries = 0;

    /** @var array<int,int> codepoint => glyph id */
    private array $cmap = [];

    /** @var array<int,int> glyph id => advance width */
    private array $widths = [];

    /** @var array<int,array{0:int,1:int,2:int,3:int}|null> */
    private array $bboxCache = [];

    /** @var array<int,int> resolved composite glyph -> raw bytes offset cache */
    private array $glyphOffsetCache = [];

    public function __construct(string $path)
    {
        $raw = @file_get_contents($path);
        if ($raw === false || strlen($raw) < 12) {
            throw new RuntimeException('Font file unreadable: ' . $path);
        }
        $this->data = $raw;
        $this->length = strlen($raw);
        $this->parseDirectory();
        $this->parseHead();
        $this->parseHhea();
        $this->parseMaxp();
        $this->parseCmap();
        $this->parseWidths();
    }

    public function unitsPerEm(): int
    {
        return $this->unitsPerEm;
    }

    public function ascender(): int
    {
        return $this->ascender;
    }

    public function descender(): int
    {
        return $this->descender;
    }

    public function numGlyphs(): int
    {
        return $this->numGlyphs;
    }

    public function rawData(): string
    {
        return $this->data;
    }

    public function glyphId(int $codepoint): int
    {
        return $this->cmap[$codepoint] ?? 0;
    }

    /**
     * Glyph id => codepoint, used to build the PDF /ToUnicode CMap so the text
     * stays searchable and copy-pasteable. When several codepoints share a
     * glyph the lowest one wins, which keeps the mapping stable.
     *
     * @return array<int,int>
     */
    public function unicodeMap(): array
    {
        $inverse = [];
        foreach ($this->cmap as $codepoint => $glyphId) {
            if (!isset($inverse[$glyphId]) || $codepoint < $inverse[$glyphId]) {
                $inverse[$glyphId] = $codepoint;
            }
        }
        ksort($inverse);

        return $inverse;
    }

    public function advanceWidth(int $glyphId): int
    {
        return $this->widths[$glyphId] ?? 0;
    }

    /** Advance width in 1000-unit glyph space, as PDF widths require. */
    public function scaledWidth(int $glyphId): int
    {
        return (int) round($this->advanceWidth($glyphId) * 1000 / max(1, $this->unitsPerEm));
    }

    public function scaledMetric(int $value): int
    {
        return (int) round($value * 1000 / max(1, $this->unitsPerEm));
    }

    /**
     * Glyph bounding box in font units, with composites resolved to an outline
     * union. Returns null for blank glyphs.
     *
     * @return array{0:int,1:int,2:int,3:int}|null [xMin, yMin, xMax, yMax]
     */
    public function bbox(int $glyphId): ?array
    {
        if (array_key_exists($glyphId, $this->bboxCache)) {
            return $this->bboxCache[$glyphId];
        }

        $box = $this->computeBbox($glyphId, 0);
        $this->bboxCache[$glyphId] = $box;

        return $box;
    }

    /** Union of every glyph bbox, used for the FontDescriptor. */
    public function fontBBox(): array
    {
        $minX = $minY = PHP_INT_MAX;
        $maxX = $maxY = PHP_INT_MIN;
        for ($gid = 0; $gid < $this->numGlyphs; $gid++) {
            $box = $this->bbox($gid);
            if ($box === null) {
                continue;
            }
            $minX = min($minX, $box[0]);
            $minY = min($minY, $box[1]);
            $maxX = max($maxX, $box[2]);
            $maxY = max($maxY, $box[3]);
        }
        if ($minX === PHP_INT_MAX) {
            return [0, -200, 1000, 800];
        }

        return [$minX, $minY, $maxX, $maxY];
    }

    private function computeBbox(int $glyphId, int $depth): ?array
    {
        if ($depth > 5) {
            return null;
        }
        $offset = $this->glyphOffset($glyphId);
        if ($offset === null) {
            return null;
        }
        $contours = $this->s16($offset);
        if ($contours >= 0) {
            if ($contours === 0) {
                return null;
            }

            return [$this->s16($offset + 2), $this->s16($offset + 4), $this->s16($offset + 6), $this->s16($offset + 8)];
        }

        // Composite glyph: walk the component records and union their boxes.
        $p = $offset + 10;
        $box = null;
        while (true) {
            if ($p + 4 > $this->length) {
                break;
            }
            $flags = $this->u16($p);
            $componentGid = $this->u16($p + 2);
            $p += 4;

            $words = ($flags & 0x0001) !== 0;
            $xyValues = ($flags & 0x0002) !== 0;

            if ($words) {
                $arg1 = $xyValues ? $this->s16($p) : $this->u16($p);
                $arg2 = $xyValues ? $this->s16($p + 2) : $this->u16($p + 2);
                $p += 4;
            } else {
                $arg1 = $xyValues ? $this->s8($p) : $this->u8($p);
                $arg2 = $xyValues ? $this->s8($p + 1) : $this->u8($p + 1);
                $p += 2;
            }

            $dx = $dy = 0;
            $a = $d = 1.0;
            $b = $c = 0.0;
            if ($xyValues) {
                $dx = $arg1;
                $dy = $arg2;
            }
            if (($flags & 0x0008) !== 0) {
                $a = $d = $this->f2dot14($p);
                $p += 2;
            } elseif (($flags & 0x0040) !== 0) {
                $a = $this->f2dot14($p);
                $d = $this->f2dot14($p + 2);
                $p += 4;
            } elseif (($flags & 0x0080) !== 0) {
                $a = $this->f2dot14($p);
                $b = $this->f2dot14($p + 2);
                $c = $this->f2dot14($p + 4);
                $d = $this->f2dot14($p + 6);
                $p += 8;
            }

            $child = $this->computeBbox($componentGid, $depth + 1);
            if ($child !== null) {
                $corners = [
                    [$child[0], $child[1]], [$child[2], $child[1]],
                    [$child[2], $child[3]], [$child[0], $child[3]],
                ];
                $xs = [];
                $ys = [];
                foreach ($corners as [$cx, $cy]) {
                    $xs[] = $a * $cx + $c * $cy + $dx;
                    $ys[] = $b * $cx + $d * $cy + $dy;
                }
                $candidate = [(int) floor(min($xs)), (int) floor(min($ys)), (int) ceil(max($xs)), (int) ceil(max($ys))];
                $box = $box === null ? $candidate : [
                    min($box[0], $candidate[0]), min($box[1], $candidate[1]),
                    max($box[2], $candidate[2]), max($box[3], $candidate[3]),
                ];
            }

            if (($flags & 0x0020) === 0) {
                break;
            }
        }

        return $box;
    }

    private function glyphOffset(int $glyphId): ?int
    {
        if (isset($this->glyphOffsetCache[$glyphId])) {
            return $this->glyphOffsetCache[$glyphId];
        }
        if ($glyphId < 0 || $glyphId >= $this->numGlyphs) {
            return null;
        }
        if ($this->locaOffset === 0 || $this->glyfOffset === 0) {
            return null;
        }
        $start = $this->readLoca($glyphId);
        $end = $this->readLoca($glyphId + 1);
        if ($start === $end) {
            return null;
        }
        $offset = $this->glyfOffset + $start;
        $this->glyphOffsetCache[$glyphId] = $offset;

        return $offset;
    }

    private function readLoca(int $index): int
    {
        if ($this->indexToLocFormat === 0) {
            return $this->u16($this->locaOffset + $index * 2) * 2;
        }

        return $this->u32($this->locaOffset + $index * 4);
    }

    private function parseDirectory(): void
    {
        $numTables = $this->u16(4);
        for ($i = 0; $i < $numTables; $i++) {
            $record = 12 + $i * 16;
            if ($record + 16 > $this->length) {
                break;
            }
            $tag = substr($this->data, $record, 4);
            $this->tables[$tag] = $this->u32($record + 8);
        }
        foreach (['head', 'hhea', 'maxp', 'hmtx', 'cmap'] as $required) {
            if (!isset($this->tables[$required])) {
                throw new RuntimeException('Font is missing required table: ' . $required);
            }
        }
        $this->locaOffset = $this->tables['loca'] ?? 0;
        $this->glyfOffset = $this->tables['glyf'] ?? 0;
    }

    private function parseHead(): void
    {
        $o = $this->tables['head'];
        $this->unitsPerEm = $this->u16($o + 18);
        $this->indexToLocFormat = $this->s16($o + 50);
    }

    private function parseHhea(): void
    {
        $o = $this->tables['hhea'];
        $this->ascender = $this->s16($o + 4);
        $this->descender = $this->s16($o + 6);
        $this->numberOfHMetrics = $this->u16($o + 34);
    }

    private function parseMaxp(): void
    {
        $this->numGlyphs = $this->u16($this->tables['maxp'] + 4);
        $this->locaEntries = $this->numGlyphs + 1;
    }

    private function parseWidths(): void
    {
        $this->hmtxOffset = $this->tables['hmtx'];
        $last = 0;
        for ($gid = 0; $gid < $this->numGlyphs; $gid++) {
            if ($gid < $this->numberOfHMetrics) {
                $last = $this->u16($this->hmtxOffset + $gid * 4);
            }
            $this->widths[$gid] = $last;
        }
    }

    private function parseCmap(): void
    {
        $base = $this->tables['cmap'];
        $numTables = $this->u16($base + 2);
        $chosen = null;
        $chosenScore = -1;

        for ($i = 0; $i < $numTables; $i++) {
            $rec = $base + 4 + $i * 8;
            $platform = $this->u16($rec);
            $encoding = $this->u16($rec + 2);
            $offset = $base + $this->u32($rec + 4);
            $format = $this->u16($offset);

            $score = -1;
            if ($platform === 3 && $encoding === 10 && $format === 12) {
                $score = 100;
            } elseif ($platform === 0 && $format === 12) {
                $score = 90;
            } elseif ($platform === 3 && $encoding === 1 && $format === 4) {
                $score = 80;
            } elseif ($platform === 0 && $format === 4) {
                $score = 70;
            } elseif ($format === 4) {
                $score = 40;
            } elseif ($format === 12) {
                $score = 50;
            }

            if ($score > $chosenScore) {
                $chosenScore = $score;
                $chosen = [$offset, $format];
            }
        }

        if ($chosen === null) {
            throw new RuntimeException('Font has no usable cmap subtable.');
        }
        [$offset, $format] = $chosen;
        if ($format === 4) {
            $this->parseCmapFormat4($offset);
        } elseif ($format === 12) {
            $this->parseCmapFormat12($offset);
        } else {
            throw new RuntimeException('Unsupported cmap format: ' . $format);
        }
    }

    private function parseCmapFormat4(int $offset): void
    {
        $segCountX2 = $this->u16($offset + 6);
        $segCount = intdiv($segCountX2, 2);
        $endBase = $offset + 14;
        $startBase = $endBase + $segCountX2 + 2;
        $deltaBase = $startBase + $segCountX2;
        $rangeBase = $deltaBase + $segCountX2;

        for ($s = 0; $s < $segCount; $s++) {
            $end = $this->u16($endBase + $s * 2);
            $start = $this->u16($startBase + $s * 2);
            $delta = $this->u16($deltaBase + $s * 2);
            $rangeOffset = $this->u16($rangeBase + $s * 2);
            if ($start === 0xFFFF) {
                continue;
            }
            for ($code = $start; $code <= $end && $code !== 0x10000; $code++) {
                if ($rangeOffset === 0) {
                    $gid = ($code + $delta) & 0xFFFF;
                } else {
                    $addr = $rangeBase + $s * 2 + $rangeOffset + ($code - $start) * 2;
                    if ($addr + 2 > $this->length) {
                        continue;
                    }
                    $gid = $this->u16($addr);
                    if ($gid !== 0) {
                        $gid = ($gid + $delta) & 0xFFFF;
                    }
                }
                if ($gid !== 0) {
                    $this->cmap[$code] = $gid;
                }
            }
        }
    }

    private function parseCmapFormat12(int $offset): void
    {
        $numGroups = $this->u32($offset + 12);
        $p = $offset + 16;
        for ($i = 0; $i < $numGroups; $i++, $p += 12) {
            $start = $this->u32($p);
            $end = $this->u32($p + 4);
            $startGid = $this->u32($p + 8);
            for ($code = $start, $gid = $startGid; $code <= $end; $code++, $gid++) {
                $this->cmap[$code] = $gid;
            }
        }
    }

    private function u8(int $o): int
    {
        return ord($this->data[$o] ?? "\0");
    }

    private function s8(int $o): int
    {
        $v = $this->u8($o);

        return $v >= 0x80 ? $v - 0x100 : $v;
    }

    private function u16(int $o): int
    {
        if ($o + 2 > $this->length) {
            return 0;
        }

        return unpack('n', substr($this->data, $o, 2))[1];
    }

    private function s16(int $o): int
    {
        $v = $this->u16($o);

        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    private function u32(int $o): int
    {
        if ($o + 4 > $this->length) {
            return 0;
        }

        return unpack('N', substr($this->data, $o, 4))[1];
    }

    private function f2dot14(int $o): float
    {
        return $this->s16($o) / 16384.0;
    }
}
