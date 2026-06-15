<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * Builds a minimal valid .xlsx file without external dependencies.
 */
class SpreadsheetXlsxBuilder
{
    /** @var list<list<array{value: mixed, style?: string, type?: string}>> */
    private array $rows = [];

    private string $sheetName;

    private ?int $freezeBelowRow = null;

    public function __construct(string $sheetName = 'Sheet1')
    {
        $this->sheetName = $this->sanitizeSheetName($sheetName);
    }

    /**
     * @param  list<list<array{value: mixed, style?: string, type?: string}|scalar|null>>  $rows
     */
    public static function fromRows(array $rows, string $sheetName = 'Sheet1'): self
    {
        $builder = new self($sheetName);

        foreach ($rows as $row) {
            $builder->addRow($row);
        }

        return $builder;
    }

    /**
     * @param  list<array{value: mixed, style?: string, type?: string}|scalar|null>  $cells
     */
    public function addRow(array $cells): self
    {
        $normalized = [];
        foreach ($cells as $cell) {
            if (is_array($cell)) {
                $normalized[] = $cell;
                continue;
            }

            $normalized[] = ['value' => $cell];
        }

        $this->rows[] = $normalized;

        return $this;
    }

    public function freezeBelowRow(int $row): self
    {
        $this->freezeBelowRow = $row;

        return $this;
    }

    public function build(): string
    {
        if ($this->rows === []) {
            throw new RuntimeException('Cannot build an empty spreadsheet.');
        }

        $styleMap = [
            'default' => 0,
            'header' => 1,
            'title' => 2,
            'meta' => 3,
            'data' => 4,
            'dataAlt' => 5,
            'number' => 6,
        ];

        $sharedStrings = [];
        $stringIndex = static function (string $value) use (&$sharedStrings): int {
            if (! array_key_exists($value, $sharedStrings)) {
                $sharedStrings[$value] = count($sharedStrings);
            }

            return $sharedStrings[$value];
        };

        $sheetRows = '';
        foreach ($this->rows as $rowIndex => $row) {
            $rowNumber = $rowIndex + 1;
            $sheetRows .= '<row r="'.$rowNumber.'">';

            foreach ($row as $colIndex => $cell) {
                $value = $cell['value'] ?? '';
                $styleKey = $cell['style'] ?? 'default';
                $styleId = $styleMap[$styleKey] ?? 0;
                $cellRef = $this->columnLetter($colIndex).$rowNumber;

                if (($cell['type'] ?? null) === 'number' && is_numeric($value)) {
                    $sheetRows .= '<c r="'.$cellRef.'" s="'.$styleId.'"><v>'.(float) $value.'</v></c>';
                    continue;
                }

                if (is_int($value) || is_float($value)) {
                    $sheetRows .= '<c r="'.$cellRef.'" s="'.$styleId.'"><v>'.(float) $value.'</v></c>';
                    continue;
                }

                $text = (string) $value;
                $sheetRows .= '<c r="'.$cellRef.'" t="s" s="'.$styleId.'"><v>'.$stringIndex($text).'</v></c>';
            }

            $sheetRows .= '</row>';
        }

        $ssXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $ssXml .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' count="'.count($sharedStrings).'" uniqueCount="'.count($sharedStrings).'">';
        foreach ($sharedStrings as $text => $_) {
            $ssXml .= '<si><t xml:space="preserve">'.htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></si>';
        }
        $ssXml .= '</sst>';

        $colCount = max(array_map(static fn (array $row): int => count($row), $this->rows));
        $lastCol = $this->columnLetter(max(0, $colCount - 1));
        $lastRow = count($this->rows);

        $sheetViews = '';
        if ($this->freezeBelowRow !== null && $this->freezeBelowRow > 0) {
            $topLeft = 'A'.($this->freezeBelowRow + 1);
            $sheetViews = '<sheetViews><sheetView workbookViewId="0"><pane ySplit="'.$this->freezeBelowRow
                .'" topLeftCell="'.$topLeft.'" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        }

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<dimension ref="A1:'.$lastCol.$lastRow.'"/>'
            .$sheetViews
            .'<sheetFormatPr defaultRowHeight="15"/>'
            .'<sheetData>'.$sheetRows.'</sheetData>'
            .'</worksheet>';

        $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="3">'
            .'<font><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="14"/><name val="Calibri"/></font>'
            .'</fonts>'
            .'<fills count="4">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF217346"/></fgColor></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFF2F2F2"/></fgColor></patternFill></fill>'
            .'</fills>'
            .'<borders count="2">'
            .'<border><left/><right/><top/><bottom/><diagonal/></border>'
            .'<border>'
            .'<left style="thin"><color rgb="FF000000"/></left>'
            .'<right style="thin"><color rgb="FF000000"/></right>'
            .'<top style="thin"><color rgb="FF000000"/></top>'
            .'<bottom style="thin"><color rgb="FF000000"/></bottom>'
            .'</border>'
            .'</borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="7">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"><alignment wrapText="1"/></xf>'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"><alignment vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0"><alignment vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>'
            .'</cellXfs>'
            .'</styleSheet>';

        $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx_');
        @unlink($tmpFile);
        $tmpFile .= '.xlsx';

        $zip = new ZipArchive();
        if ($zip->open($tmpFile, ZipArchive::CREATE) !== true) {
            throw new RuntimeException('Could not create XLSX archive in temp directory.');
        }

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>');

        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');

        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
            .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>');

        $safeSheetName = htmlspecialchars($this->sheetName, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.$safeSheetName.'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>');

        $zip->addFromString('xl/sharedStrings.xml', $ssXml);
        $zip->addFromString('xl/styles.xml', $stylesXml);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->close();

        $content = file_get_contents($tmpFile);
        @unlink($tmpFile);

        if ($content === false) {
            throw new RuntimeException('Could not read generated XLSX file.');
        }

        return $content;
    }

    private function columnLetter(int $index): string
    {
        $index++;
        $letters = '';

        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letters = chr(65 + $mod).$letters;
            $index = intdiv($index - 1, 26);
        }

        return $letters;
    }

    private function sanitizeSheetName(string $name): string
    {
        $name = preg_replace('/[\\\\\\/?*\\[\\]:]/', '-', $name) ?? 'Sheet1';
        $name = trim($name) !== '' ? trim($name) : 'Sheet1';

        return mb_substr($name, 0, 31);
    }
}
