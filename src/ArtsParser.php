<?php

class ArtsParser
{
    public function parse(array $lines)
    {
        $records = array();
        $warehouse = null;
        $inNotFoundSection = false;

        foreach ($lines as $lineIndex => $line) {
            if (trim($line) === '---') {
                continue;
            }

            if (preg_match('/^\s*Не найдено в базе\s*$/u', $line)) {
                $warehouse = null;
                $inNotFoundSection = true;
                continue;
            }

            if (preg_match('/^\s*Склад\s*№\s*(.+?)\s*$/u', $line, $matches)) {
                $warehouse = trim($matches[1]);
                $inNotFoundSection = false;
                continue;
            }

            if (!$inNotFoundSection && $warehouse === null) {
                continue;
            }

            if (!preg_match('/^\s*([A-Za-z0-9_-]+)\s*(?:;(.*))?\s*$/u', $line, $matches)) {
                continue;
            }

            $records[] = array(
                'line' => $lineIndex,
                'article' => trim($matches[1]),
                'name' => isset($matches[2]) ? trim($matches[2]) : '',
                'warehouse' => $inNotFoundSection ? null : $warehouse,
                'section' => $inNotFoundSection ? 'not_found' : 'warehouse',
                'raw' => $line,
            );
        }

        return $records;
    }

    public function findRecord(array $records, $lineIndex)
    {
        foreach ($records as $record) {
            if ((int) $record['line'] === (int) $lineIndex) {
                return $record;
            }
        }

        return null;
    }

    public function updateSummaryCount(array $lines, $count)
    {
        foreach ($lines as $lineIndex => $line) {
            if (preg_match('/^(\s*Коды без фото:\s*)\d+(\s+из\s+[\d\s]+)$/u', $line, $matches)) {
                $lines[$lineIndex] = $matches[1] . (int) $count . $matches[2];
                break;
            }
        }

        return $lines;
    }
}
