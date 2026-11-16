<?php

class ArtsFileRepository implements ArtsRepository
{
    private $path;

    public function __construct($path)
    {
        $this->path = $path;
    }

    public function load()
    {
        if (!is_file($this->path) || !is_readable($this->path)) {
            throw new RuntimeException('Не удалось прочитать файл списка товаров.');
        }

        $contents = file_get_contents($this->path);
        if ($contents === false) {
            throw new RuntimeException('Ошибка чтения файла списка товаров.');
        }

        $lineEnding = strpos($contents, "\r\n") !== false ? "\r\n" : "\n";
        $hasFinalLineEnding = preg_match('/(?:\r\n|\n|\r)$/', $contents) === 1;
        $lines = preg_split('/\r\n|\n|\r/', $contents);

        if ($hasFinalLineEnding && count($lines) > 0 && end($lines) === '') {
            array_pop($lines);
        }

        if ($contents === '') {
            $lines = array();
        }

        return new ArtsSnapshot(
            $lines,
            $lineEnding,
            $hasFinalLineEnding,
            hash('sha256', $contents)
        );
    }

    public function save(ArtsSnapshot $snapshot, array $lines)
    {
        $directory = dirname($this->path);
        $lockPath = $this->path . '.lock';
        $lock = fopen($lockPath, 'c');

        if ($lock === false) {
            throw new RuntimeException('Не удалось открыть блокировку файла списка.');
        }

        $temporaryPath = null;
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Не удалось заблокировать файл списка.');
            }

            $currentContents = file_get_contents($this->path);
            if ($currentContents === false || hash('sha256', $currentContents) !== $snapshot->hash) {
                throw new RuntimeException('Файл списка изменился после загрузки. Обновите страницу и повторите действие.');
            }

            $temporaryPath = tempnam($directory, '.arts-');
            if ($temporaryPath === false) {
                throw new RuntimeException('Не удалось создать временный файл для списка.');
            }

            $newContents = $snapshot->contentsWithLines($lines);
            $written = file_put_contents($temporaryPath, $newContents, LOCK_EX);
            if ($written === false || $written !== strlen($newContents)) {
                throw new RuntimeException('Не удалось полностью записать обновленный список.');
            }

            if (!rename($temporaryPath, $this->path)) {
                throw new RuntimeException('Не удалось заменить файл списка.');
            }
            $temporaryPath = null;
        } finally {
            if ($temporaryPath !== null && is_file($temporaryPath)) {
                unlink($temporaryPath);
            }

            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
