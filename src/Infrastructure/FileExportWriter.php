<?php

class FileExportWriter implements ExportWriter
{
    private $directory;

    public function __construct($directory)
    {
        $this->directory = $directory;
    }

    public function write($fileName, $contents)
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]*\.txt$/i', $fileName)) {
            throw new InvalidArgumentException('Недопустимое имя экспортного файла.');
        }

        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Не удалось создать каталог экспортных файлов.');
        }

        $path = $this->directory . DIRECTORY_SEPARATOR . $fileName;
        $written = file_put_contents($path, $contents, LOCK_EX);
        if ($written === false || $written !== strlen($contents)) {
            throw new RuntimeException('Не удалось записать экспортный файл.');
        }

        return $fileName;
    }

    public function listFiles()
    {
        if (!is_dir($this->directory)) {
            return array();
        }

        $paths = glob($this->directory . DIRECTORY_SEPARATOR . '*.txt');
        if ($paths === false) {
            throw new RuntimeException('Не удалось получить список экспортных файлов.');
        }

        return array_map('basename', $paths);
    }
}
