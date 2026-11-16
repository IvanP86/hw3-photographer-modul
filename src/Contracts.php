<?php

interface ArtsRepository
{
    public function load();

    public function save(ArtsSnapshot $snapshot, array $lines);
}

interface ProductCatalog
{
    public function find($articleCode, $sourceName);

    public function findMany(array $articleCodes);
}

interface ExportWriter
{
    public function write($fileName, $contents);

    public function listFiles();
}
