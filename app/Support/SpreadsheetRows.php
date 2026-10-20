<?php

namespace App\Support;

/** Rows (arrays of cell strings) from an uploaded CSV or the first sheet of an .xlsx. */
class SpreadsheetRows
{
    public static function read(string $path, string $ext): array
    {
        $ext = strtolower($ext);
        $rows = in_array($ext, ['xlsx', 'xls'], true) && class_exists('ZipArchive') ? self::xlsx($path) : self::csv($path);

        // Drop fully blank rows; strip a UTF-8 BOM from the first cell.
        $rows = array_values(array_filter($rows, fn ($r) => implode('', array_map('trim', $r)) !== ''));
        if (isset($rows[0][0])) {
            $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $rows[0][0]);
        }

        return $rows;
    }

    private static function csv(string $path): array
    {
        $rows = [];
        if (($fh = fopen($path, 'r')) === false) {
            return $rows;
        }
        while (($row = fgetcsv($fh, 0, ',')) !== false) {
            $rows[] = array_map(fn ($v) => (string) $v, $row);
        }
        fclose($fh);

        return $rows;
    }

    private static function xlsx(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return self::csv($path);
        }
        $shared = [];
        if (($sstXml = $zip->getFromName('xl/sharedStrings.xml')) !== false && ($sst = simplexml_load_string($sstXml))) {
            foreach ($sst->si as $si) {
                if (isset($si->t)) {
                    $shared[] = (string) $si->t;
                } else {
                    $text = '';
                    foreach ($si->r ?? [] as $r) {
                        $text .= (string) ($r->t ?? '');
                    }
                    $shared[] = $text;
                }
            }
        }
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if ($sheetXml === false || ! ($sheet = simplexml_load_string($sheetXml))) {
            return [];
        }

        $rows = [];
        foreach ($sheet->sheetData->row ?? [] as $row) {
            $cells = [];
            $max = -1;
            foreach ($row->c as $cell) {
                if (! preg_match('/^([A-Z]+)\d+$/', (string) $cell['r'], $m)) {
                    continue;
                }
                $col = 0;
                foreach (str_split($m[1]) as $ch) {
                    $col = $col * 26 + (ord($ch) - 64);
                }
                $col--;
                $type = (string) ($cell['t'] ?? '');
                $cells[$col] = match ($type) {
                    's' => $shared[(int) (string) ($cell->v ?? 0)] ?? '',
                    'inlineStr' => (string) ($cell->is->t ?? ''),
                    default => (string) ($cell->v ?? ''),
                };
                $max = max($max, $col);
            }
            $filled = [];
            for ($i = 0; $i <= $max; $i++) {
                $filled[] = $cells[$i] ?? '';
            }
            $rows[] = $filled;
        }

        return $rows;
    }
}
