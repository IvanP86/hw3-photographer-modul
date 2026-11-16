<?php

class PhotoReviewService
{
    private $artsRepository;
    private $parser;
    private $catalog;
    private $exportWriter;

    public function __construct(
        ArtsRepository $artsRepository,
        ArtsParser $parser,
        ProductCatalog $catalog,
        ExportWriter $exportWriter
    ) {
        $this->artsRepository = $artsRepository;
        $this->parser = $parser;
        $this->catalog = $catalog;
        $this->exportWriter = $exportWriter;
    }

    public function loadReview($selectedWarehouse = null)
    {
        $snapshot = $this->artsRepository->load();
        $records = $this->parser->parse($snapshot->lines);
        $warehouseGroups = array();
        $missingRecords = array();

        foreach ($records as $record) {
            if ($record['section'] === 'not_found') {
                $missingRecords[] = $record;
            } else {
                $warehouse = (string) $record['warehouse'];
                if (!isset($warehouseGroups[$warehouse])) {
                    $warehouseGroups[$warehouse] = array();
                }
                $warehouseGroups[$warehouse][] = $record;
            }
        }

        if ($selectedWarehouse === null || !isset($warehouseGroups[(string) $selectedWarehouse])) {
            $warehouseKeys = array_keys($warehouseGroups);
            $selectedWarehouse = count($warehouseKeys) > 0 ? $warehouseKeys[0] : null;
        } else {
            $selectedWarehouse = (string) $selectedWarehouse;
        }

        $warehouseRecords = $selectedWarehouse !== null
            ? $warehouseGroups[$selectedWarehouse]
            : array();
        $articleCodes = array_map(function ($record) {
            return $record['article'];
        }, $warehouseRecords);

        return array(
            'snapshot' => $snapshot,
            'warehouse_records' => $warehouseRecords,
            'missing_records' => $missingRecords,
            'warehouses' => array_map('count', $warehouseGroups),
            'selected_warehouse' => $selectedWarehouse,
            'products' => $this->catalog->findMany($articleCodes),
        );
    }

    public function getProduct(array $record)
    {
        return $this->catalog->find($record['article'], $record['name']);
    }

    public function removeWarehouseArticle($lineIndex, $articleCode, $expectedHash)
    {
        return $this->removeRecord($lineIndex, $articleCode, 'warehouse', $expectedHash);
    }

    public function removeMissingArticle($lineIndex, $articleCode, $expectedHash)
    {
        return $this->removeRecord($lineIndex, $articleCode, 'not_found', $expectedHash);
    }

    public function removeAllMissing($expectedHash)
    {
        $snapshot = $this->artsRepository->load();
        $this->assertExpectedHash($snapshot, $expectedHash);
        $records = $this->parser->parse($snapshot->lines);
        $linesToRemove = array();

        foreach ($records as $record) {
            if ($record['section'] === 'not_found') {
                $linesToRemove[(int) $record['line']] = true;
            }
        }

        $removedCount = count($linesToRemove);
        if ($removedCount === 0) {
            return 0;
        }

        $newLines = array();
        foreach ($snapshot->lines as $lineIndex => $line) {
            if (!isset($linesToRemove[$lineIndex])) {
                $newLines[] = $line;
            }
        }

        $newLines = $this->updateSummaryCount($newLines);
        $this->artsRepository->save($snapshot, $newLines);
        return $removedCount;
    }

    public function exportWarehouses()
    {
        $records = $this->loadRecords();
        $grouped = array();

        foreach ($records as $record) {
            if ($record['section'] !== 'warehouse') {
                continue;
            }

            $warehouse = (string) $record['warehouse'];
            if (!isset($grouped[$warehouse])) {
                $grouped[$warehouse] = array();
            }

            $grouped[$warehouse][] = $record['raw'];
        }

        $files = array();
        foreach ($grouped as $warehouse => $rows) {
            $files[] = $this->writeWarehouseExport($warehouse, $rows);
        }

        return $files;
    }

    public function exportWarehouse($warehouse)
    {
        $warehouse = trim((string) $warehouse);
        if ($warehouse === '') {
            throw new InvalidArgumentException('Выберите склад для экспорта.');
        }

        $rows = array();
        foreach ($this->loadRecords() as $record) {
            if ($record['section'] === 'warehouse' && (string) $record['warehouse'] === $warehouse) {
                $rows[] = $record['raw'];
            }
        }

        if (count($rows) === 0) {
            throw new InvalidArgumentException('В выбранном складе нет товаров для экспорта.');
        }

        return $this->writeWarehouseExport($warehouse, $rows);
    }

    public function exportMissingArticles()
    {
        $records = $this->loadRecords();
        $articles = array();

        foreach ($records as $record) {
            if ($record['section'] === 'not_found') {
                $articles[] = $record['article'];
            }
        }

        if (count($articles) === 0) {
            return null;
        }

        return $this->exportWriter->write('not-found.txt', implode("\r\n", $articles) . "\r\n");
    }

    private function removeRecord($lineIndex, $articleCode, $expectedSection, $expectedHash)
    {
        $snapshot = $this->artsRepository->load();
        $this->assertExpectedHash($snapshot, $expectedHash);
        $records = $this->parser->parse($snapshot->lines);
        $record = $this->parser->findRecord($records, $lineIndex);

        if (
            $record === null
            || $record['section'] !== $expectedSection
            || $record['article'] !== (string) $articleCode
        ) {
            throw new InvalidArgumentException('Запись не найдена или не относится к выбранному действию.');
        }

        $newLines = $snapshot->lines;
        array_splice($newLines, (int) $record['line'], 1);
        $newLines = $this->updateSummaryCount($newLines);
        $this->artsRepository->save($snapshot, $newLines);

        return $record;
    }

    private function updateSummaryCount(array $lines)
    {
        return $this->parser->updateSummaryCount($lines, count($this->parser->parse($lines)));
    }

    private function loadRecords()
    {
        $snapshot = $this->artsRepository->load();
        return $this->parser->parse($snapshot->lines);
    }

    private function writeWarehouseExport($warehouse, array $rows)
    {
        $safeWarehouse = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $warehouse);
        $fileName = 'warehouse-' . trim($safeWarehouse, '-') . '.txt';
        return $this->exportWriter->write($fileName, implode("\r\n", $rows) . "\r\n");
    }

    private function assertExpectedHash(ArtsSnapshot $snapshot, $expectedHash)
    {
        if (!is_string($expectedHash) || !hash_equals($snapshot->hash, $expectedHash)) {
            throw new RuntimeException('Файл списка изменился. Обновите страницу и повторите действие.');
        }
    }
}
