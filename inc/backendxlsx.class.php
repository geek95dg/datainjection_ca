<?php

/**
 * -------------------------------------------------------------------------
 * DataInjection plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * Minimal pure-PHP XLSX backend.
 *
 * .xlsx files are ZIP archives containing OOXML. We parse the first
 * worksheet plus the shared-strings table with ZipArchive + SimpleXML —
 * no external dependency is required.
 *
 * Cells whose type is not "s" (shared string) or "inlineStr" are read as
 * raw values; that means dates appear as their serial number. Users that
 * need a specific date format should format the cell as text in their
 * spreadsheet before exporting.
 * -------------------------------------------------------------------------
 */

class PluginDatainjectionBackendxlsx extends PluginDatainjectionBackend implements PluginDatainjectionBackendInterface
{
    private bool $isHeaderPresent = true;

    /** @var array<int, array<int, string>>|null */
    private ?array $rows = null;

    /** Position of the next line that getNextLine() will return. */
    private int $cursor = 0;

    public function __construct()
    {
        $this->errmsg = '';
    }

    public function getDelimiter()
    {
        return $this->delimiter;
    }

    public function isHeaderPresent()
    {
        return $this->isHeaderPresent;
    }

    public function setDelimiter($delimiter)
    {
        $this->delimiter = $delimiter;
    }

    public function setHeaderPresent($present = true)
    {
        $this->isHeaderPresent = (bool) $present;
    }

    public function init($newfile, $encoding)
    {
        $this->file     = $newfile;
        $this->encoding = $encoding;
    }

    public function read($numberOfLines = 1)
    {
        $injectionData = new PluginDatainjectionData();
        $this->loadWorkbook();

        $total = is_array($this->rows) ? count($this->rows) : 0;
        for ($index = 0; ($numberOfLines === -1 || $index < $numberOfLines) && $index < $total; $index++) {
            $line = $this->rows[$index];
            // Skip blank rows.
            if ($this->isBlankRow($line)) {
                continue;
            }
            $injectionData->addToData([$line]);
        }

        return $injectionData;
    }

    public function storeNumberOfLines()
    {
        $this->loadWorkbook();
        $count = 0;
        foreach ($this->rows ?? [] as $row) {
            if (!$this->isBlankRow($row)) {
                $count++;
            }
        }
        if ($this->isHeaderPresent && $count > 0) {
            $count--;
        }
        $this->numberOfLines = $count;
    }

    public function getNumberOfLines()
    {
        return $this->numberOfLines;
    }

    public function getNextLine()
    {
        $this->loadWorkbook();
        $total = is_array($this->rows) ? count($this->rows) : 0;
        while ($this->cursor < $total) {
            $line = $this->rows[$this->cursor];
            $this->cursor++;
            if ($this->isBlankRow($line)) {
                continue;
            }
            return [$line];
        }
        return false;
    }

    public function deleteFile()
    {
        if (is_string($this->file) && is_file($this->file)) {
            @unlink($this->file);
        }
    }

    /**
     * Parse the .xlsx archive into $this->rows. Lazy and idempotent.
     */
    private function loadWorkbook(): void
    {
        if ($this->rows !== null) {
            return;
        }
        $this->rows = [];

        if (!is_string($this->file) || !is_file($this->file)) {
            throw new RuntimeException('XLSX file is missing or unreadable');
        }
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('PHP zip extension is required to read .xlsx files');
        }

        $zip = new ZipArchive();
        if ($zip->open($this->file) !== true) {
            throw new RuntimeException('Unable to open XLSX archive');
        }

        try {
            $sharedStrings = $this->readSharedStrings($zip);
            $sheetPath     = $this->resolveFirstSheetPath($zip);
            if ($sheetPath === null) {
                throw new RuntimeException('XLSX archive contains no worksheet');
            }

            $sheetXml = $zip->getFromName($sheetPath);
            if ($sheetXml === false) {
                throw new RuntimeException('Unable to read worksheet from XLSX archive');
            }

            $this->rows = $this->parseSheet($sheetXml, $sharedStrings);
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array<int, string>
     */
    private function readSharedStrings(ZipArchive $zip): array
    {
        $raw = $zip->getFromName('xl/sharedStrings.xml');
        if ($raw === false) {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($raw);
        } finally {
            libxml_use_internal_errors($previous);
        }
        if ($xml === false) {
            return [];
        }

        $strings = [];
        foreach ($xml->si as $si) {
            // <si><t>value</t></si> OR <si><r><t>part1</t></r><r><t>part2</t></r></si>
            if (isset($si->t)) {
                $strings[] = (string) $si->t;
                continue;
            }
            if (isset($si->r)) {
                $buffer = '';
                foreach ($si->r as $run) {
                    if (isset($run->t)) {
                        $buffer .= (string) $run->t;
                    }
                }
                $strings[] = $buffer;
                continue;
            }
            $strings[] = '';
        }
        return $strings;
    }

    private function resolveFirstSheetPath(ZipArchive $zip): ?string
    {
        // The workbook lists sheets and references them via r:id which maps
        // to a path through xl/_rels/workbook.xml.rels. Resolve correctly to
        // pick the first sheet rather than guessing sheet1.xml.
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels     = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook !== false && $rels !== false) {
            $previous = libxml_use_internal_errors(true);
            try {
                $wbXml   = simplexml_load_string($workbook);
                $relsXml = simplexml_load_string($rels);
            } finally {
                libxml_use_internal_errors($previous);
            }
            if ($wbXml !== false && $relsXml !== false) {
                $firstRid = null;
                foreach ($wbXml->sheets->sheet ?? [] as $sheet) {
                    foreach ($sheet->attributes('r', true) as $name => $value) {
                        if ($name === 'id') {
                            $firstRid = (string) $value;
                            break 2;
                        }
                    }
                }
                if ($firstRid !== null) {
                    foreach ($relsXml->Relationship as $relationship) {
                        if ((string) $relationship['Id'] === $firstRid) {
                            $target = (string) $relationship['Target'];
                            // Targets are relative to xl/.
                            if (strpos($target, '/') === 0) {
                                return ltrim($target, '/');
                            }
                            return 'xl/' . $target;
                        }
                    }
                }
            }
        }

        // Fallback: pick whichever xl/worksheets/sheet*.xml exists first.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (is_string($name) && str_starts_with($name, 'xl/worksheets/sheet') && str_ends_with($name, '.xml')) {
                return $name;
            }
        }
        return null;
    }

    /**
     * Parse a worksheet XML payload into a list of rows (each row is a list
     * of column values, padded so that gaps in the spreadsheet preserve
     * column alignment).
     *
     * @return array<int, array<int, string>>
     */
    private function parseSheet(string $sheetXml, array $sharedStrings): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($sheetXml);
        } finally {
            libxml_use_internal_errors($previous);
        }
        if ($xml === false || !isset($xml->sheetData)) {
            return [];
        }

        $rows       = [];
        $maxColumns = 0;
        foreach ($xml->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $cell) {
                $reference = (string) ($cell['r'] ?? '');
                $colIndex  = $reference !== '' ? $this->columnLetterToIndex($reference) : count($cells);
                $type      = (string) ($cell['t'] ?? '');
                $value     = '';

                if ($type === 's') {
                    $idx = (int) ((string) ($cell->v ?? ''));
                    $value = $sharedStrings[$idx] ?? '';
                } elseif ($type === 'inlineStr') {
                    if (isset($cell->is->t)) {
                        $value = (string) $cell->is->t;
                    } elseif (isset($cell->is->r)) {
                        $buffer = '';
                        foreach ($cell->is->r as $run) {
                            if (isset($run->t)) {
                                $buffer .= (string) $run->t;
                            }
                        }
                        $value = $buffer;
                    }
                } elseif ($type === 'b') {
                    $value = ((string) ($cell->v ?? '')) === '1' ? '1' : '0';
                } else {
                    $value = (string) ($cell->v ?? '');
                }

                $cells[$colIndex] = $value;
            }

            if ($cells !== []) {
                $maxIndex = max(array_keys($cells));
                if ($maxIndex + 1 > $maxColumns) {
                    $maxColumns = $maxIndex + 1;
                }
            }
            $rows[] = $cells;
        }

        // Normalise to dense, ordered arrays so the rest of the plugin can
        // address columns by integer index just like with CSV.
        $normalised = [];
        foreach ($rows as $row) {
            $line = [];
            for ($i = 0; $i < $maxColumns; $i++) {
                $line[] = isset($row[$i]) ? trim((string) $row[$i]) : '';
            }
            $normalised[] = $line;
        }
        return $normalised;
    }

    private function isBlankRow(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== '' && $value !== null) {
                return false;
            }
        }
        return true;
    }

    /**
     * Convert an Excel column reference (e.g. "C12") to a zero-based column
     * index (2 for "C").
     */
    private function columnLetterToIndex(string $reference): int
    {
        $letters = '';
        $length  = strlen($reference);
        for ($i = 0; $i < $length; $i++) {
            $ch = $reference[$i];
            if (ctype_alpha($ch)) {
                $letters .= strtoupper($ch);
            } else {
                break;
            }
        }
        $index = 0;
        $length = strlen($letters);
        for ($i = 0; $i < $length; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - ord('A') + 1);
        }
        return $index - 1;
    }
}
