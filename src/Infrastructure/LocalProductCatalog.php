<?php

class LocalProductCatalog implements ProductCatalog
{
    public function find($articleCode, $sourceName)
    {
        return array(
            'article' => (string) $articleCode,
            'name' => 'Демо-товар ' . $articleCode,
            'image' => '/no-photo.jpg',
            'found' => true,
        );
    }

    public function findMany(array $articleCodes)
    {
        $products = array();
        foreach ($articleCodes as $articleCode) {
            $products[(string) $articleCode] = $this->find($articleCode, '');
        }

        return $products;
    }
}
