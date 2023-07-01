<?php declare(strict_types=1);

namespace Yanduu\CheckoutCartBundleProduct\Service\Product;

use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\Context;


class ProductReader implements ProductReaderInterface
{
    /**
     * @var \Shopware\Core\Framework\Context
     */
    protected $context;

    /**
     * @var EntityRepositoryInterface
     */
    protected $productRepository;

     /**
     * Constructor 
     * 
     * @param \Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface $productRepository
     */
    public function __construct(
        EntityRepositoryInterface $productRepository
    ) {
        $this->productRepository = $productRepository;

        $this->context = Context::createDefaultContext();
    }


    /**
     * @var string $productNumber
     * 
     * @return \Shopware\Core\Content\Product\ProductEntity|null
     */
    public function getProductByProductNumber(string $productNumber): ?ProductEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('productNumber', $productNumber));

        $entities = $this->productRepository
            ->search($criteria, $this->context)
            ->getEntities();

        if (count($entities) == 0) {
            return null;
        }

        return $entities->first();
    }

    /**
     * @var string $productId
     * 
     * @return \Shopware\Core\Content\Product\ProductEntity|null
     */
    public function getProductById(string $productId): ?ProductEntity
    {
        return $this->productRepository
            ->search(new Criteria([$productId]), $this->context)->first();
    }
    
}