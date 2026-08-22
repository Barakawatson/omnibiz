<?php
// ============================================================
// Minimal PDF writer - enough for tabular reports, nothing more
// ------------------------------------------------------------
// WHY THIS EXISTS RATHER THAN A LIBRARY
// This installation has no Composer and no vendor directory, so any
// library would have to be committed into the project by hand. That
// means carrying third-party code inside a system that handles a
// shop's money, and keeping it patched forever. The reports here are
// plain text in columns - no images beyond an optional logo, no
// styling, no unicode scripts - which is a small enough target to
// write directly against the PDF specification.
//
// It also keeps the shop working offline, which matters: the till
// already depends on CDNs for its stylesheets, and adding another
// download to produce a month-end report would be one more thing to
// fail when the connection drops.
//
// WHAT IT SUPPORTS
//   * A4, portrait or landscape
//   * The 14 standard PDF fonts (Helvetica regular and bold), so no
//     font file has to be embedded
//   * Text, horizontal rules, filled rectangles
//   * Automatic pagination with the table header repeated
//
// WHAT IT DOES NOT SUPPORT
//   Images, transparency, embedded fonts, unicode beyond WinAnsi,
//   or anything decorative. If a report ever needs those, that is
//   the point to reconsider a library.
// ============================================================

class SimplePdf
{
    /** @var string[] finished page content streams */
    private array $pages = [];
    private string $buf = '';          // current page's stream
    private float $w;                  // page width in points
    private float $h;                  // page height in points
    private float $margin = 28.0;      // ~10mm
    private float $y;                  // current cursor, from the top
    private array $fontStack = ['F1', 9.0];

    // A4 at 72dpi.
    private const A4_W = 595.28;
    private const A4_H = 841.89;

    public function __construct(bool $landscape = false)
    {
        $this->w = $landscape ? self::A4_H : self::A4_W;
        $this->h = $landscape ? self::A4_W : self::A4_H;
        $this->y = $this->margin;
    }

    public function pageWidth(): float  { return $this->w; }
    public function usableWidth(): float { return $this->w - (2 * $this->margin); }
    public function left(): float       { return $this->margin; }
    public function cursorY(): float    { return $this->y; }
    public function setCursorY(float $y): void { $this->y = $y; }
    public function advance(float $by): void   { $this->y += $by; }

    /** True when $need more points would run off the bottom. */
    public function wouldOverflow(float $need): bool
    {
        return ($this->y + $need) > ($this->h - $this->margin - 24);
    }

    public function newPage(): void
    {
        if ($this->buf !== '') { $this->pages[] = $this->buf; }
        $this->buf = '';
        $this->y = $this->margin;
    }

    /**
     * Escape a string for a PDF literal and fold it to WinAnsi.
     *
     * PDF literals are delimited by parentheses, so those and the
     * backslash must be escaped. Anything outside WinAnsi (the shop's
     * data is Latin, but a name could carry something else) is
     * transliterated rather than emitted raw, which would corrupt the
     * stream.
     */
    private function esc(string $s): string
    {
        if (function_exists('iconv')) {
            $conv = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
            if ($conv !== false) { $s = $conv; }
        }
        // Strip anything still unprintable so a stray byte cannot break
        // the stream.
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }

    /** Width of a string in points - approximate, but good enough to fit columns. */
    public function textWidth(string $s, float $size, bool $bold = false): float
    {
        // Helvetica averages ~0.5em; bold a little wider. Measuring
        // properly would mean shipping the font metrics tables, which is
        // more weight than column fitting justifies.
        $factor = $bold ? 0.55 : 0.50;
        return strlen($s) * $size * $factor;
    }

    /** Truncate to fit a column, with an ellipsis when it does not. */
    public function fit(string $s, float $maxWidth, float $size, bool $bold = false): string
    {
        if ($this->textWidth($s, $size, $bold) <= $maxWidth) { return $s; }
        $factor = $bold ? 0.55 : 0.50;
        $chars  = max(1, (int)floor($maxWidth / ($size * $factor)) - 1);
        return substr($s, 0, $chars) . '.';
    }

    public function text(string $s, float $x, ?float $y = null, float $size = 9.0, bool $bold = false, array $rgb = [0, 0, 0]): void
    {
        $y = $y ?? $this->y;
        $font = $bold ? 'F2' : 'F1';
        // PDF's origin is bottom-left; the cursor here counts from the top.
        $py = $this->h - $y;
        $this->buf .= sprintf("BT /%s %.2F Tf %.3F %.3F %.3F rg %.2F %.2F Td (%s) Tj ET\n",
            $font, $size, $rgb[0], $rgb[1], $rgb[2], $x, $py, $this->esc($s));
    }

    /** Right-align a string so its RIGHT edge sits at $xRight. */
    public function textRight(string $s, float $xRight, ?float $y = null, float $size = 9.0, bool $bold = false, array $rgb = [0, 0, 0]): void
    {
        $this->text($s, $xRight - $this->textWidth($s, $size, $bold), $y, $size, $bold, $rgb);
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.5, array $rgb = [0.7, 0.7, 0.7]): void
    {
        $this->buf .= sprintf("%.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S\n",
            $rgb[0], $rgb[1], $rgb[2], $width,
            $x1, $this->h - $y1, $x2, $this->h - $y2);
    }

    public function rect(float $x, float $y, float $w, float $h, array $rgb = [0.93, 0.96, 0.97]): void
    {
        $this->buf .= sprintf("%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n",
            $rgb[0], $rgb[1], $rgb[2], $x, $this->h - $y - $h, $w, $h);
    }

    /**
     * Assemble the document.
     *
     * Object layout: 1 catalogue, 2 pages tree, 3 font regular,
     * 4 font bold, then a page object and a content stream per page.
     * The xref table records each object's BYTE OFFSET, which is why
     * the buffer length is tracked as objects are appended.
     */
    public function output(): string
    {
        if ($this->buf !== '') { $this->pages[] = $this->buf; $this->buf = ''; }
        if (!$this->pages) { $this->pages[] = ''; }

        $nPages   = count($this->pages);
        $objects  = [];
        $firstPg  = 5;                               // first page object id
        $kids     = [];
        for ($i = 0; $i < $nPages; $i++) { $kids[] = ($firstPg + $i * 2) . ' 0 R'; }

        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[2] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count $nPages >>";
        $objects[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objects[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        foreach ($this->pages as $i => $content) {
            $pageId   = $firstPg + $i * 2;
            $streamId = $pageId + 1;
            $objects[$pageId] = sprintf(
                "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] "
              . "/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>",
                $this->w, $this->h, $streamId);
            $objects[$streamId] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";
        }

        ksort($objects);
        $out     = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= "$id 0 obj\n$body\nendobj\n";
        }

        $xrefPos = strlen($out);
        $maxId   = max(array_keys($objects));
        $out .= "xref\n0 " . ($maxId + 1) . "\n";
        $out .= "0000000000 65535 f \n";
        for ($id = 1; $id <= $maxId; $id++) {
            $out .= isset($offsets[$id])
                ? sprintf("%010d 00000 n \n", $offsets[$id])
                : "0000000000 65535 f \n";
        }
        $out .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R >>\n";
        $out .= "startxref\n$xrefPos\n%%EOF";

        return $out;
    }
}
