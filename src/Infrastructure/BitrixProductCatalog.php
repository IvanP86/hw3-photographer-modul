<?php

class BitrixProductCatalog implements ProductCatalog
{
    public function find($articleCode, $sourceName)
    {
        $products = $this->findMany(array($articleCode));
        return isset($products[(string) $articleCode]) ? $products[(string) $articleCode] : null;
    }

    public function findMany(array $articleCodes)
    {
        $articleCodes = array_values(array_unique(array_filter(array_map('strval', $articleCodes), 'strlen')));
        if (count($articleCodes) === 0) {
            return array();
        }

        if (!class_exists('CIBlockElement') || !class_exists('CFile')) {
            throw new RuntimeException('Ядро Bitrix доступно не полностью: не найден API каталога или файлов.');
        }

        if (class_exists('CModule') && !CModule::IncludeModule('iblock')) {
            throw new RuntimeException('Не удалось подключить модуль инфоблоков Bitrix.');
        }

        $query = CIBlockElement::GetList(
            array(),
            array('PROPERTY_59' => $articleCodes),
            false,
            false,
            array('ID', 'NAME', 'DETAIL_PICTURE', 'PROPERTY_59')
        );

        $products = array();
        while ($product = $query->Fetch()) {
            if (empty($product['PROPERTY_59_VALUE'])) {
                throw new RuntimeException('Bitrix не вернул значение свойства артикула 59.');
            }

            $articleCode = (string) $product['PROPERTY_59_VALUE'];
            $image = '/no-photo.jpg';
            if (!empty($product['DETAIL_PICTURE'])) {
                $resized = CFile::ResizeImageGet(
                    $product['DETAIL_PICTURE'],
                    array('width' => 300, 'height' => 300),
                    BX_RESIZE_IMAGE_PROPORTIONAL,
                    false
                );

                if (is_array($resized) && !empty($resized['src'])) {
                    $image = $resized['src'];
                }
            }

            $products[$articleCode] = array(
                'article' => $articleCode,
                'name' => isset($product['NAME']) ? (string) $product['NAME'] : $articleCode,
                'image' => $image,
                'found' => true,
            );
        }

        return $products;
    }
}
