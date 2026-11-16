<?php

$projectRoot = dirname(__DIR__);

require_once $projectRoot . '/src/ArtsSnapshot.php';
require_once $projectRoot . '/src/Contracts.php';
require_once $projectRoot . '/src/ArtsParser.php';
require_once $projectRoot . '/src/Infrastructure/ArtsFileRepository.php';
require_once $projectRoot . '/src/Infrastructure/FileExportWriter.php';
require_once $projectRoot . '/src/Infrastructure/LocalProductCatalog.php';
require_once $projectRoot . '/src/Infrastructure/BitrixProductCatalog.php';
require_once $projectRoot . '/src/Infrastructure/BitrixBootstrap.php';
require_once $projectRoot . '/src/AccessPolicy.php';
require_once $projectRoot . '/src/PhotoReviewService.php';

try {
    $bitrixAvailable = (new BitrixBootstrap())->load($projectRoot);
} catch (Exception $exception) {
    http_response_code(500);
    echo 'Не удалось инициализировать Bitrix: ' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$environment = getenv('APP_ENV');
if ($environment === false || $environment === '') {
    $environment = $bitrixAvailable ? 'production' : 'local';
}

$accessPolicy = new AccessPolicy();
if (!$accessPolicy->isAllowed($environment, $bitrixAvailable)) {
    http_response_code(403);
    echo 'Доступ запрещен.';
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$catalog = $bitrixAvailable ? new BitrixProductCatalog() : new LocalProductCatalog();
$service = new PhotoReviewService(
    new ArtsFileRepository($projectRoot . '/arts/arts.txt'),
    new ArtsParser(),
    $catalog,
    new FileExportWriter($projectRoot . '/exports')
);

if (!function_exists('h')) {
    function h($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

function redirectToWarehouse($warehouse)
{
    $query = $warehouse === null ? '' : '?warehouse=' . rawurlencode((string) $warehouse);
    header('Location: /' . $query, true, 303);
    exit;
}

$message = isset($_SESSION['flash_message']) ? $_SESSION['flash_message'] : null;
unset($_SESSION['flash_message']);
$error = null;
$requestedWarehouse = isset($_GET['warehouse']) ? (string) $_GET['warehouse'] : null;

if (isset($_GET['action']) && $_GET['action'] === 'download') {
    $fileName = isset($_GET['file']) ? basename((string) $_GET['file']) : '';
    if (!preg_match('/^[a-z0-9][a-z0-9_-]*\.txt$/i', $fileName)) {
        http_response_code(400);
        echo 'Недопустимое имя файла.';
        exit;
    }

    $filePath = $projectRoot . '/exports/' . $fileName;
    if (!is_file($filePath) || !is_readable($filePath)) {
        http_response_code(404);
        echo 'Файл не найден.';
        exit;
    }

    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if (!hash_equals($csrfToken, $postedToken)) {
        http_response_code(403);
        $error = 'Срок действия формы истек. Обновите страницу и повторите действие.';
    } else {
        $requestedWarehouse = isset($_POST['warehouse']) ? (string) $_POST['warehouse'] : $requestedWarehouse;

        try {
            $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

            if ($action === 'remove_warehouse') {
                $service->removeWarehouseArticle(
                    isset($_POST['line']) ? (int) $_POST['line'] : -1,
                    isset($_POST['article']) ? (string) $_POST['article'] : '',
                    isset($_POST['source_hash']) ? (string) $_POST['source_hash'] : ''
                );
                $_SESSION['flash_message'] = 'Артикул удален из текущего списка. Хе-хе-хе';
                redirectToWarehouse($requestedWarehouse);
            } elseif ($action === 'remove_missing') {
                $service->removeMissingArticle(
                    isset($_POST['line']) ? (int) $_POST['line'] : -1,
                    isset($_POST['article']) ? (string) $_POST['article'] : '',
                    isset($_POST['source_hash']) ? (string) $_POST['source_hash'] : ''
                );
                $_SESSION['flash_message'] = 'Ненайденный артикул удален из текущего списка. Хе-хе-хе';
                redirectToWarehouse($requestedWarehouse);
            } elseif ($action === 'remove_all_missing') {
                $count = $service->removeAllMissing(
                    isset($_POST['source_hash']) ? (string) $_POST['source_hash'] : ''
                );
                $_SESSION['flash_message'] = $count > 0
                    ? 'Удалено ненайденных артикулов: ' . $count . '. Хе-хе-хе'
                    : 'Ненайденных артикулов для удаления нет.';
                redirectToWarehouse($requestedWarehouse);
            } elseif ($action === 'export_warehouse') {
                $service->exportWarehouse($requestedWarehouse);
                $_SESSION['flash_message'] = 'Сформирован TXT для склада №' . $requestedWarehouse . '.';
                redirectToWarehouse($requestedWarehouse);
            } elseif ($action === 'export_warehouses') {
                $files = $service->exportWarehouses();
                $_SESSION['flash_message'] = count($files) > 0
                    ? 'Сформировано складских файлов: ' . count($files) . '.'
                    : 'В списке нет складских товаров для экспорта.';
                redirectToWarehouse($requestedWarehouse);
            } elseif ($action === 'export_missing') {
                $file = $service->exportMissingArticles();
                $_SESSION['flash_message'] = $file !== null
                    ? 'Сформирован файл ненайденных артикулов.'
                    : 'Ненайденных артикулов для экспорта нет.';
                redirectToWarehouse($requestedWarehouse);
            } else {
                throw new InvalidArgumentException('Неизвестное действие.');
            }
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        }
    }
}

try {
    $review = $service->loadReview($requestedWarehouse);
    $selectedWarehouse = $review['selected_warehouse'];
    $exportFiles = (new FileExportWriter($projectRoot . '/exports'))->listFiles();
} catch (RuntimeException $exception) {
    http_response_code(500);
    $error = $exception->getMessage();
    $review = array(
        'snapshot' => null,
        'warehouse_records' => array(),
        'missing_records' => array(),
        'warehouses' => array(),
        'selected_warehouse' => null,
        'products' => array(),
    );
    $selectedWarehouse = null;
    $exportFiles = array();
}

$snapshot = $review['snapshot'];
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Подбор товаров для перефотографирования</title>
    <link rel="stylesheet" href="/assets/app.css">
    <script src="/assets/app.js" defer></script>
</head>
<body>
<main class="page">
    <header class="page-header">
        <div>
            <h1>Товары для перефотографирования</h1>
            <p>Выберите склад, чтобы просмотреть все его товары на одной странице.</p>
        </div>
        <div class="counter">
            <?php
            echo $selectedWarehouse !== null
                ? 'Склад №' . h($selectedWarehouse) . ' · ' . count($review['warehouse_records']) . ' товаров'
                : 'Складских товаров нет';
            ?>
        </div>
    </header>

    <?php if ($message !== null): ?>
        <div class="notice" role="status"><?php echo h($message); ?></div>
    <?php endif; ?>
    <?php if ($error !== null): ?>
        <div class="notice notice-error" role="alert"><?php echo h($error); ?></div>
    <?php endif; ?>

    <?php if (count($review['warehouses']) > 0): ?>
        <nav class="warehouse-nav" aria-label="Выбор склада">
            <?php foreach ($review['warehouses'] as $warehouse => $count): ?>
                <a class="warehouse-link<?php echo (string) $warehouse === (string) $selectedWarehouse ? ' is-active' : ''; ?>"
                   href="/?warehouse=<?php echo rawurlencode((string) $warehouse); ?>">
                    Склад №<?php echo h($warehouse); ?>
                    <span><?php echo h($count); ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <section class="review-section">
        <?php if (count($review['warehouse_records']) > 0): ?>
            <div class="products-grid">
                <?php foreach ($review['warehouse_records'] as $record): ?>
                    <?php $product = isset($review['products'][$record['article']]) ? $review['products'][$record['article']] : null; ?>
                    <article class="product-card">
                        <div class="product-image">
                            <img src="<?php echo h($product && !empty($product['image']) ? $product['image'] : '/no-photo.jpg'); ?>"
                                 alt="Изображение товара"
                                 loading="lazy">
                        </div>
                        <div class="product-details">
                            <p class="eyebrow">Артикул</p>
                            <h2><?php echo h($record['article']); ?></h2>
                            <p class="product-name">
                                <?php echo h($product && !empty($product['name']) ? $product['name'] : 'Не найдено в базе'); ?>
                            </p>
                            <?php if ($product === null): ?>
                                <p class="muted">Артикул не найден в Bitrix; он остается в исходном складском разделе.</p>
                            <?php endif; ?>
                            <form method="post" class="action-form"
                                  data-confirm="Убрать артикул <?php echo h($record['article']); ?> из текущего списка?">
                                <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                                <input type="hidden" name="action" value="remove_warehouse">
                                <input type="hidden" name="line" value="<?php echo h($record['line']); ?>">
                                <input type="hidden" name="article" value="<?php echo h($record['article']); ?>">
                                <input type="hidden" name="source_hash" value="<?php echo $snapshot ? h($snapshot->hash) : ''; ?>">
                                <input type="hidden" name="warehouse" value="<?php echo h($selectedWarehouse); ?>">
                                <button class="button button-danger" type="submit">
                                    <?php echo $product === null ? 'Удалить из этого склада' : 'Изображение подходит — убрать'; ?>
                                </button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">В этом складе нет товаров.</div>
        <?php endif; ?>
        <?php if ($selectedWarehouse !== null): ?>
            <div class="warehouse-export">
                <p>Сформируйте отдельный TXT только для выбранного склада.</p>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                    <input type="hidden" name="action" value="export_warehouse">
                    <input type="hidden" name="source_hash" value="<?php echo $snapshot ? h($snapshot->hash) : ''; ?>">
                    <input type="hidden" name="warehouse" value="<?php echo h($selectedWarehouse); ?>">
                    <button class="button button-primary" type="submit">
                        Сформировать TXT только для склада №<?php echo h($selectedWarehouse); ?>
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Не найдено в базе</h2>
                <p>Эти артикулы управляются отдельно и не входят в складскую очередь.</p>
            </div>
            <span class="counter"><?php echo count($review['missing_records']); ?></span>
        </div>

        <div class="toolbar">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                <input type="hidden" name="action" value="export_missing">
                <input type="hidden" name="source_hash" value="<?php echo $snapshot ? h($snapshot->hash) : ''; ?>">
                <input type="hidden" name="warehouse" value="<?php echo h($selectedWarehouse); ?>">
                <button class="button button-primary" type="submit">Сформировать TXT ненайденных</button>
            </form>
            <?php if (count($review['missing_records']) > 0): ?>
                <form method="post" data-confirm="Удалить все <?php echo count($review['missing_records']); ?> артикулов только из секции «Не найдено в базе»?">
                    <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                    <input type="hidden" name="action" value="remove_all_missing">
                    <input type="hidden" name="source_hash" value="<?php echo $snapshot ? h($snapshot->hash) : ''; ?>">
                    <input type="hidden" name="warehouse" value="<?php echo h($selectedWarehouse); ?>">
                    <button class="button button-danger" type="submit">Удалить все ненайденные</button>
                </form>
            <?php endif; ?>
        </div>

        <?php if (count($review['missing_records']) > 0): ?>
            <ul class="missing-list">
                <?php foreach ($review['missing_records'] as $missing): ?>
                    <li>
                        <code><?php echo h($missing['article']); ?></code>
                        <form method="post" data-confirm="Удалить артикул <?php echo h($missing['article']); ?> из списка ненайденных?">
                            <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
                            <input type="hidden" name="action" value="remove_missing">
                            <input type="hidden" name="line" value="<?php echo h($missing['line']); ?>">
                            <input type="hidden" name="article" value="<?php echo h($missing['article']); ?>">
                            <input type="hidden" name="source_hash" value="<?php echo $snapshot ? h($snapshot->hash) : ''; ?>">
                            <input type="hidden" name="warehouse" value="<?php echo h($selectedWarehouse); ?>">
                            <button class="button button-small button-muted" type="submit">Удалить</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="muted">Ненайденных артикулов нет.</p>
        <?php endif; ?>
    </section>

    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Экспорт всех складов</h2>
                <p>Будет создан отдельный TXT-файл для каждого склада.</p>
            </div>
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
            <input type="hidden" name="action" value="export_warehouses">
            <input type="hidden" name="source_hash" value="<?php echo $snapshot ? h($snapshot->hash) : ''; ?>">
            <input type="hidden" name="warehouse" value="<?php echo h($selectedWarehouse); ?>">
            <button class="button button-primary" type="submit">Сформировать TXT всех складов</button>
        </form>
        <?php if (count($exportFiles) > 0): ?>
            <ul class="export-list">
                <?php foreach ($exportFiles as $exportFile): ?>
                    <li><a href="/?action=download&amp;file=<?php echo rawurlencode($exportFile); ?>"><?php echo h($exportFile); ?></a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
