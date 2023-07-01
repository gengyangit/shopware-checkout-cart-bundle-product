<?php declare(strict_types=1);

namespace Yanduu\CheckoutCartBundleProduct\Service\Product;

use Shopware\Core\Content\Product\ProductEntity;

interface ProductReaderInterface
{
    /**
     * @var string $productNumber
     * 
     * @return \Shopware\Core\Content\Product\ProductEntity|null
     */
    public function getProductByProductNumber(string $productNumber): ?ProductEntity;

    /**
     * @var string $productId
     * 
     * @return \Shopware\Core\Content\Product\ProductEntity|null
     */
    public function getProductById(string $productId): ?ProductEntity;    
}