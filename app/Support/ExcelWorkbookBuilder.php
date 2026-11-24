<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * Lightweight, zero-dependency Office Open XML (.xlsx) workbook generator.
 *
 * Produces standards-compliant Excel workbooks with:
 * - Full UTF-8 & RTL worksheet support for Arabic reports
 * - Styled brand-navy header rows, borders, zebra rows, auto-filter, and auto-fitted widths
 * - OWASP spreadsheet formula-injection neutralization (=, +, -, @, tab, CR)
 * - Multi-sheet support
 */
class ExcelWorkbookBuilder
{
    /**
     * @var list<array{name: string, headers: list<string>, rows: list<list<mixed>>, rtl: bool}>
     */
    private array $sheets = [];

    /**
     * Add a worksheet to the workbook.
     *
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $rows
     */
    public function addSheet(string $name, array $headers, array $rows, bool $rtl = true): self
    {
        $cleanName = $this->sanitizeSheetName($name);
        $this->sheets[] = [
            'name' => $cleanName,
            'headers' => array_values($headers),
            'rows' => array_values($rows),
            'rtl' => $rtl,
        ];

        return $this;
    }

    /**
     * Build the .xlsx binary payload in memory using a temporary archive file.
     */
    public function buildBinary(): string
    {
        if (empty($this->sheets)) {
            $this->addSheet('Sheet1', ['Data'], []);
        }

        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP ZipArchive extension is required for Excel (.xlsx) export.');
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'mota_xlsx_');
        if ($tmpFile === false) {
            throw new RuntimeException('Unable to create temporary workbook file.');
        }

        $zip = new ZipArchive;
        if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tmpFile);
            throw new RuntimeException('Unable to initialize Excel (.xlsx) archive.');
        }

        try {
            $zip->addFromString('[Content_Types].xml', $this->buildContentTypesXml());
            $zip->addFromString('_rels/.rels', $this->buildRootRelsXml());
            $zip->addFromString('xl/workbook.xml', $this->buildWorkbookXml());
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->buildWorkbookRelsXml());
            $zip->addFromString('xl/styles.xml', $this->buildStylesXml());

            foreach ($this->sheets as $idx => $sheet) {
                $sheetIndex = $idx + 1;
                $zip->addFromString(
                    "xl/worksheets/sheet{$sheetIndex}.xml",
                    $this->buildWorksheetXml($sheet['headers'], $sheet['rows'], $sheet['rtl'])
                );
            }

            $zip->close();
            $binary = file_get_contents($tmpFile);
            if ($binary === false || $binary === '') {
                throw new RuntimeException('Failed to read generated Excel workbook.');
            }

            return $binary;
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * Neutralize Excel formula injection (OWASP CSV/XLSX Injection defense) and invalid XML control bytes.
     */
    public static function sanitizeCellString(mixed $value): string
    {
        $str = (string) ($value ?? '');
        // Strip disallowed XML 1.0 control characters (keep tab/LF/CR)
        $str = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $str) ?? $str;

        if ($str !== '' && preg_match('/^[=+\-@\t\r]/', $str) === 1) {
            $str = "'".$str;
        }

        return $str;
    }

    private function sanitizeSheetName(string $raw): string
    {
        $clean = preg_replace('/[\\\\\/\?\*\[\]:]/u', ' ', trim($raw)) ?? 'Sheet';
        $clean = trim($clean);
        if ($clean === '') {
            $clean = 'Sheet'.(count($this->sheets) + 1);
        }

        // Excel limits sheet names to 31 characters
        $clean = mb_substr($clean, 0, 31);

        $existing = array_column($this->sheets, 'name');
        $candidate = $clean;
        $suffix = 2;
        while (in_array($candidate, $existing, true)) {
            $tag = " ({$suffix})";
            $candidate = mb_substr($clean, 0, 31 - mb_strlen($tag)).$tag;
            $suffix++;
        }

        return $candidate;
    }

    private function buildContentTypesXml(): string
    {
        $overrides = [
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>',
            '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>',
        ];

        foreach ($this->sheets as $idx => $_sheet) {
            $num = $idx + 1;
            $overrides[] = "<Override PartName=\"/xl/worksheets/sheet{$num}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .implode('', $overrides)
            .'</Types>';
    }

    private function buildRootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function buildWorkbookXml(): string
    {
        $sheetTags = [];
        foreach ($this->sheets as $idx => $sheet) {
            $num = $idx + 1;
            $escapedName = $this->xmlEscape($sheet['name']);
            $sheetTags[] = "<sheet name=\"{$escapedName}\" sheetId=\"{$num}\" r:id=\"rId{$num}\"/>";
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.implode('', $sheetTags).'</sheets>'
            .'</workbook>';
    }

    private function buildWorkbookRelsXml(): string
    {
        $rels = [];
        foreach ($this->sheets as $idx => $_sheet) {
            $num = $idx + 1;
            $rels[] = "<Relationship Id=\"rId{$num}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet{$num}.xml\"/>";
        }

        $styleRelId = count($this->sheets) + 1;
        $rels[] = "<Relationship Id=\"rId{$styleRelId}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles\" Target=\"styles.xml\"/>";

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .implode('', $rels)
            .'</Relationships>';
    }

    /**
     * Stylesheet:
     * - style 0: default
     * - style 1: header (bold white on Motafawweq Navy #06254E, centered, wrapped)
     * - style 2: even data row (white fill, crisp border)
     * - style 3: odd data row (soft slate tint #F8FAFC, crisp border)
     */
    private function buildStylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2">'
            .'<font><sz val="11"/><color rgb="FF0F172A"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            .'</fonts>'
            .'<fills count="4">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF06254E"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFF8FAFC"/><bgColor indexed="64"/></patternFill></fill>'
            .'</fills>'
            .'<borders count="2">'
            .'<border><left/><right/><top/><bottom/><diagonal/></border>'
            .'<border>'
            .'<left style="thin"><color rgb="FFE2E8F0"/></left>'
            .'<right style="thin"><color rgb="FFE2E8F0"/></right>'
            .'<top style="thin"><color rgb="FFE2E8F0"/></top>'
            .'<bottom style="thin"><color rgb="FFE2E8F0"/></bottom>'
            .'<diagonal/>'
            .'</border>'
            .'</borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="4">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>'
            .'</cellXfs>'
            .'</styleSheet>';
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $rows
     */
    private function buildWorksheetXml(array $headers, array $rows, bool $rtl): string
    {
        $colCount = max(1, count($headers));
        $colWidths = array_fill(0, $colCount, 14);

        foreach ($headers as $cIdx => $header) {
            $colWidths[$cIdx] = max($colWidths[$cIdx], min(44, mb_strlen((string) $header) + 6));
        }

        $rowXmlParts = [];

        // Row 1: Styled header
        $headerCells = [];
        foreach ($headers as $cIdx => $header) {
            $ref = $this->colLetter($cIdx).'1';
            $text = $this->xmlEscape(self::sanitizeCellString($header));
            $headerCells[] = "<c r=\"{$ref}\" s=\"1\" t=\"inlineStr\"><is><t>{$text}</t></is></c>";
        }
        $rowXmlParts[] = '<row r="1" ht="26" customHeight="1">'.implode('', $headerCells).'</row>';

        // Data rows
        foreach ($rows as $rIdx => $row) {
            $excelRow = $rIdx + 2;
            $styleId = ($rIdx % 2 === 0) ? 2 : 3;
            $cells = [];

            for ($cIdx = 0; $cIdx < $colCount; $cIdx++) {
                $val = $row[$cIdx] ?? '';
                $ref = $this->colLetter($cIdx).$excelRow;

                if (is_int($val) || is_float($val)) {
                    $numStr = (string) $val;
                    $colWidths[$cIdx] = max($colWidths[$cIdx], min(44, strlen($numStr) + 4));
                    $cells[] = "<c r=\"{$ref}\" s=\"{$styleId}\" t=\"n\"><v>{$numStr}</v></c>";
                } else {
                    $clean = self::sanitizeCellString($val);
                    $colWidths[$cIdx] = max($colWidths[$cIdx], min(44, mb_strlen($clean) + 4));
                    $escaped = $this->xmlEscape($clean);
                    $cells[] = "<c r=\"{$ref}\" s=\"{$styleId}\" t=\"inlineStr\"><is><t xml:space=\"preserve\">{$escaped}</t></is></c>";
                }
            }

            $rowXmlParts[] = "<row r=\"{$excelRow}\" ht=\"21\" customHeight=\"1\">".implode('', $cells).'</row>';
        }

        // Column widths XML
        $colsXml = '<cols>';
        foreach ($colWidths as $cIdx => $width) {
            $colNum = $cIdx + 1;
            $colsXml .= "<col min=\"{$colNum}\" max=\"{$colNum}\" width=\"{$width}\" customWidth=\"1\"/>";
        }
        $colsXml .= '</cols>';

        $lastCol = $this->colLetter($colCount - 1);
        $lastRow = max(1, count($rows) + 1);
        $rtlAttr = $rtl ? ' rightToLeft="1"' : '';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            ."<sheetViews><sheetView workbookViewId=\"0\"{$rtlAttr}><pane ySplit=\"1\" topLeftCell=\"A2\" activePane=\"bottomLeft\" state=\"frozen\"/></sheetView></sheetViews>"
            .'<sheetFormatPr defaultRowHeight="20"/>'
            .$colsXml
            .'<sheetData>'.implode('', $rowXmlParts).'</sheetData>'
            ."<autoFilter ref=\"A1:{$lastCol}{$lastRow}\"/>"
            .'</worksheet>';
    }

    private function colLetter(int $zeroBasedIndex): string
    {
        $letter = '';
        $n = $zeroBasedIndex;
        while ($n >= 0) {
            $letter = chr(65 + ($n % 26)).$letter;
            $n = intdiv($n, 26) - 1;
        }

        return $letter;
    }

    private function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
