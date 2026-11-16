<?php

class BitrixBootstrap
{
    public function load($projectRoot)
    {
        $configuredPath = getenv('BITRIX_PROLOG_PATH');
        $candidate = $configuredPath
            ? $configuredPath
            : $projectRoot . '/bitrix/modules/main/include/prolog_before.php';

        if (!is_file($candidate)) {
            return false;
        }

        require_once $candidate;

        if (!class_exists('CModule') || !CModule::IncludeModule('iblock')) {
            throw new RuntimeException('Не удалось подключить модуль инфоблоков Bitrix.');
        }

        if (!class_exists('CIBlockElement') || !class_exists('CFile')) {
            throw new RuntimeException('Ядро Bitrix доступно не полностью: не найден API каталога или файлов.');
        }

        return true;
    }
}
