<?php

$root = dirname(__DIR__);
require_once $root . '/src/ArtsSnapshot.php';
require_once $root . '/src/Contracts.php';
require_once $root . '/src/ArtsParser.php';
require_once $root . '/src/AccessPolicy.php';
require_once $root . '/src/Infrastructure/ArtsFileRepository.php';
require_once $root . '/src/Infrastructure/FileExportWriter.php';
require_once $root . '/src/Infrastructure/LocalProductCatalog.php';
require_once $root . '/src/PhotoReviewService.php';

class MemoryArtsRepository implements ArtsRepository
{
    private $snapshot;

    public function __construct(array $lines)
    {
        $contents = implode("\n", $lines) . "\n";
        $this->snapshot = new ArtsSnapshot($lines, "\n", true, hash('sha256', $contents));
    }

    public function load()
    {
        return $this->snapshot;
    }

    public function save(ArtsSnapshot $snapshot, array $lines)
    {
        if ($snapshot->hash !== $this->snapshot->hash) {
            throw new RuntimeException('Unexpected in-memory source change.');
        }

        $contents = $snapshot->contentsWithLines($lines);
        $this->snapshot = new ArtsSnapshot(
            $lines,
            $snapshot->lineEnding,
            $snapshot->hasFinalLineEnding,
            hash('sha256', $contents)
        );
    }
}

class MemoryExportWriter implements ExportWriter
{
    public $files = array();

    public function write($fileName, $contents)
    {
        $this->files[$fileName] = $contents;
        return $fileName;
    }

    public function listFiles()
    {
        return array_keys($this->files);
    }
}

function assertSameValue($expected, $actual, $message)
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$fixture = array(
    'Коды без фото: 5 из 12',
    '---',
    'Не найдено в базе',
    '999;',
    '998;',
    '---',
    'Склад №2',
    '123;Тестовый товар без фотографии',
    '124;Другой товар',
    '---',
    'Склад №7',
    '700;Товар другого склада',
);

$parser = new ArtsParser();
$records = $parser->parse($fixture);
assertSameValue(5, count($records), 'Parser should find warehouse and missing-section entries');
assertSameValue('not_found', $records[0]['section'], 'The not-found block should be classified separately');
assertSameValue('2', $records[2]['warehouse'], 'Warehouse association should be preserved');
assertSameValue('Тестовый товар без фотографии', $records[2]['name'], 'Warehouse names should be parsed');

$sourceRepository = new ArtsFileRepository($root . '/arts/arts.txt');
$sourceSnapshot = $sourceRepository->load();
$sourceRecords = $parser->parse($sourceSnapshot->lines);
if (preg_match('/^Коды без фото:\s*(\d+)/u', $sourceSnapshot->lines[0], $summaryMatches)) {
    assertSameValue(
        (int) $summaryMatches[1],
        count($sourceRecords),
        'The source summary should equal the parsed warehouse and missing article count'
    );
}

$repository = new MemoryArtsRepository($fixture);
$writer = new MemoryExportWriter();
$service = new PhotoReviewService($repository, $parser, new LocalProductCatalog(), $writer);
$review = $service->loadReview('2');
assertSameValue(2, count($review['warehouse_records']), 'Selected warehouse should show every item in that warehouse');
assertSameValue(array('2' => 2, '7' => 1), $review['warehouses'], 'Warehouse list should include per-warehouse counts');
assertSameValue(2, count($review['missing_records']), 'Missing list should not include warehouse items without a photo');
assertSameValue(2, count($review['products']), 'Selected warehouse products should be fetched as a batch');

$product = $service->getProduct($review['warehouse_records'][0]);
assertSameValue('/no-photo.jpg', $product['image'], 'Local catalog should use the placeholder image');

$warehouseFiles = $service->exportWarehouses();
assertSameValue(2, count($warehouseFiles), 'One export file should be generated per warehouse');
assertSameValue(
    "123;Тестовый товар без фотографии\r\n124;Другой товар\r\n",
    $writer->files['warehouse-2.txt'],
    'Warehouse export should preserve the source rows'
);

$singleWarehouseRepository = new MemoryArtsRepository($fixture);
$singleWarehouseWriter = new MemoryExportWriter();
$singleWarehouseService = new PhotoReviewService(
    $singleWarehouseRepository,
    $parser,
    new LocalProductCatalog(),
    $singleWarehouseWriter
);
$originalWarehouseHash = $singleWarehouseRepository->load()->hash;
assertSameValue('warehouse-2.txt', $singleWarehouseService->exportWarehouse('2'), 'Selected warehouse export should return its file name');
assertSameValue(array('warehouse-2.txt'), array_keys($singleWarehouseWriter->files), 'Selected warehouse export should not create other warehouse files');
assertSameValue(
    "123;Тестовый товар без фотографии\r\n124;Другой товар\r\n",
    $singleWarehouseWriter->files['warehouse-2.txt'],
    'Selected warehouse export should contain only that warehouse rows'
);
assertSameValue($originalWarehouseHash, $singleWarehouseRepository->load()->hash, 'Export should not change the source list');

$unknownWarehouseRejected = false;
try {
    $singleWarehouseService->exportWarehouse('99');
} catch (InvalidArgumentException $exception) {
    $unknownWarehouseRejected = true;
}
assertSameValue(true, $unknownWarehouseRejected, 'Selected warehouse export should reject warehouses without records');
assertSameValue(array('warehouse-2.txt'), array_keys($singleWarehouseWriter->files), 'Rejected export should not create an extra file');

$missingFile = $service->exportMissingArticles();
assertSameValue('not-found.txt', $missingFile, 'Missing articles should use a separate export');
assertSameValue("999\r\n998\r\n", $writer->files['not-found.txt'], 'Missing export should contain only article codes');

$current = $repository->load();
$currentRecords = $parser->parse($current->lines);
$firstMissing = $parser->findRecord($currentRecords, $currentRecords[0]['line']);
$service->removeMissingArticle($firstMissing['line'], $firstMissing['article'], $current->hash);
$afterOneDelete = $service->loadReview('2');
assertSameValue(1, count($afterOneDelete['missing_records']), 'Deleting one missing article should retain the others');
assertSameValue(2, count($afterOneDelete['warehouse_records']), 'Deleting a missing article must not touch warehouse rows');
assertSameValue('Коды без фото: 4 из 12', $afterOneDelete['snapshot']->lines[0], 'Summary count should reflect remaining source entries');

$service->removeAllMissing($afterOneDelete['snapshot']->hash);
$afterBulkDelete = $service->loadReview('2');
assertSameValue(0, count($afterBulkDelete['missing_records']), 'Bulk deletion should clear the not-found section');
assertSameValue(2, count($afterBulkDelete['warehouse_records']), 'Bulk deletion must leave all warehouse rows intact');
assertSameValue('Коды без фото: 3 из 12', $afterBulkDelete['snapshot']->lines[0], 'Bulk deletion should update the summary count');

$warehouseRepository = new MemoryArtsRepository($fixture);
$warehouseService = new PhotoReviewService(
    $warehouseRepository,
    $parser,
    new LocalProductCatalog(),
    new MemoryExportWriter()
);
$warehouseReview = $warehouseService->loadReview('2');
$warehouseProduct = $warehouseReview['warehouse_records'][0];
$warehouseService->removeWarehouseArticle(
    $warehouseProduct['line'],
    $warehouseProduct['article'],
    $warehouseReview['snapshot']->hash
);
$afterWarehouseDelete = $warehouseService->loadReview('2');
assertSameValue(1, count($afterWarehouseDelete['warehouse_records']), 'Removing an accepted product should affect only the selected warehouse');
assertSameValue(2, count($afterWarehouseDelete['missing_records']), 'Removing a warehouse product must not touch the not-found section');
assertSameValue('Коды без фото: 4 из 12', $afterWarehouseDelete['snapshot']->lines[0], 'Warehouse deletion should update the summary count');

$crossSectionDeleteRejected = false;
try {
    $current = $warehouseRepository->load();
    $currentRecords = $parser->parse($current->lines);
    $warehouseRecord = null;
    foreach ($currentRecords as $record) {
        if ($record['section'] === 'warehouse') {
            $warehouseRecord = $record;
            break;
        }
    }
    $warehouseService->removeMissingArticle(
        $warehouseRecord['line'],
        $warehouseRecord['article'],
        $current->hash
    );
} catch (InvalidArgumentException $exception) {
    $crossSectionDeleteRejected = true;
}
assertSameValue(true, $crossSectionDeleteRejected, 'The not-found action must reject warehouse records');

$accessPolicy = new AccessPolicy();
assertSameValue(true, $accessPolicy->isAllowed('local', false), 'Local development should allow unauthenticated access');
assertSameValue(false, $accessPolicy->isAllowed('production', false), 'Production without Bitrix should fail closed');

$temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'photographer-test-' . uniqid('', true);
if (!mkdir($temporaryDirectory, 0777, true)) {
    throw new RuntimeException('Unable to create a temporary test directory.');
}

$temporaryFile = $temporaryDirectory . DIRECTORY_SEPARATOR . 'arts.txt';
$originalContents = implode("\r\n", $fixture) . "\r\n";
if (file_put_contents($temporaryFile, $originalContents) === false) {
    throw new RuntimeException('Unable to create a temporary arts file.');
}

$fileRepository = new ArtsFileRepository($temporaryFile);
$fileSnapshot = $fileRepository->load();
$changedLines = $fileSnapshot->lines;
array_splice($changedLines, 3, 1);
$fileRepository->save($fileSnapshot, $changedLines);
$changedContents = file_get_contents($temporaryFile);
assertSameValue(
    implode("\r\n", $changedLines) . "\r\n",
    $changedContents,
    'File repository should preserve line endings and replace the source content'
);

$staleSnapshot = $fileRepository->load();
file_put_contents($temporaryFile, $changedContents . "external-change\r\n");
$staleWriteRejected = false;
try {
    $fileRepository->save($staleSnapshot, $staleSnapshot->lines);
} catch (RuntimeException $exception) {
    $staleWriteRejected = true;
}
assertSameValue(true, $staleWriteRejected, 'File repository should reject a stale snapshot');

unlink($temporaryFile);
if (is_file($temporaryFile . '.lock')) {
    unlink($temporaryFile . '.lock');
}
rmdir($temporaryDirectory);

$exportDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'photographer-export-' . uniqid('', true);
$fileExportWriter = new FileExportWriter($exportDirectory);
$fileExportWriter->write('warehouse-2.txt', "123;Test\r\n");
assertSameValue(array('warehouse-2.txt'), $fileExportWriter->listFiles(), 'Export writer should list generated files');
assertSameValue(
    "123;Test\r\n",
    file_get_contents($exportDirectory . DIRECTORY_SEPARATOR . 'warehouse-2.txt'),
    'Export writer should persist the requested content'
);
unlink($exportDirectory . DIRECTORY_SEPARATOR . 'warehouse-2.txt');
rmdir($exportDirectory);

echo "All tests passed.\n";
