<?php

namespace Yanduu\CheckoutCartBundleProduct\Subscriber;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Event\CartChangedEvent;
use Shopware\Core\Checkout\Cart\Event\AfterLineItemRemovedEvent;
use Shopware\Core\Checkout\Cart\Event\AfterLineItemAddedEvent;
use Shopware\Core\Checkout\Cart\Event\AfterLineItemQuantityChangedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Yanduu\CheckoutCartBundleProduct\Service\Product\ProductReaderInterface;

class CartSubscriber implements EventSubscriberInterface
{
    protected const KEY_CUSTOM_FIELDS = 'customFields';
    protected const CUSTOM_FIELD_VERSANDKARTON = 'custom_sl_artikel_VERSANDKARTON';

    /**
     * @var \Shopware\Core\Checkout\Cart\SalesChannel\CartService
     */
    protected $cartService;

    /**
     * @var \Yanduu\CheckoutCartBundleProduct\Service\Product\ProductReaderInterface
     */
    protected $productReader;

    /**
     * @var \Shopware\Core\Framework\Context $context
     */
    protected $context;

    /**
     * @param \Yanduu\CheckoutCartBundleProduct\Service\Product\ProductReaderInterface $productReader
     * @param \Shopware\Core\Checkout\Cart\SalesChannel\CartService $cartService
     */
    public function __construct(ProductReaderInterface $productReader, CartService $cartService)
    {
        $this->cartService = $cartService;
        $this->productReader = $productReader;

        $this->context = Context::createDefaultContext();
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            AfterLineItemAddedEvent::class => 'onLineItemAdded',
            AfterLineItemQuantityChangedEvent::class => 'onLineItemQuantityChanged',
            AfterLineItemRemovedEvent::class => 'onLineItemRemoved'
        ];
    }

    /**
     * @param \Shopware\Core\Checkout\Cart\Event\AfterLineItemAddedEvent $event
     * 
     * @return void
     */
    public function onLineItemAdded(AfterLineItemAddedEvent $event): void
    {
        $lineItems = $event->getLineItems();
        $cart = $event->getCart();
        $salesChannelContext = $event->getSalesChannelContext();

        foreach ($lineItems as $lineItem) {
            $bundleProduct = $this->getBundleProductByProductId($lineItem->getId());

            if (!$bundleProduct) {
                continue;
            }

            $this->executeSaveBundleProduct($cart, $lineItem, $bundleProduct, $salesChannelContext);
        }
    }

    /**
     * @param \Shopware\Core\Checkout\Cart\Event\AfterLineItemQuantityChangedEvent AfterLineItemQuantityChangedEvent
     * 
     * @return void
     */
    public function onLineItemQuantityChanged(AfterLineItemQuantityChangedEvent  $event): void 
    {
        $items = $event->getItems();
        $cart = $event->getCart();
        $salesChannelContext = $event->getSalesChannelContext();
        foreach ($items as $item) {
            $lineItem = $this->getLineItemById($cart, $item['id']);
            $bundleProduct = $this->getBundleProductByProductId($lineItem->getId());

            if ($bundleProduct === null) {
                continue;
            }

            $bundleProductLineItem = $this->getLineItemById($cart, $bundleProduct->getId());
            
            if ($bundleProductLineItem === null) {
                $this->addBundleProduct($lineItem, $cart, $bundleProduct, $salesChannelContext);
            }

            $this->updateBundleProduct($lineItem, $bundleProductLineItem, $cart, $salesChannelContext);
        }
    }

    /**
     * @param \Shopware\Core\Checkout\Cart\Event\AfterLineItemRemovedEvent $event
     * 
     * @return void
     */
    public function onLineItemRemoved(AfterLineItemRemovedEvent $event): void
    {
        $cart = $event->getCart();
        $lineItems = $event->getLineItems();
        $salesChannelContext = $event->getSalesChannelContext();
        foreach ($lineItems as $lineItem) {
            $bundleProduct = $this->getBundleProductByProductId($lineItem->getId());

            if (!$bundleProduct) {
                continue;
            }

            $this->removeBundleProduct($cart, $bundleProduct, $salesChannelContext);
        }
    }

    /**
     * @param \Shopware\Core\Checkout\Cart\Cart $cart
     * @param \Shopware\Core\Checkout\Cart\LineItem\LineItem $lineItem
     * @param \Shopware\Core\Checkout\Cart\LineItem\LineItem $bundleProductLineItem
     * @param \Shopware\Core\System\SalesChannel\SalesChannelContext $salesChannelContext
     * 
     * @return void
     */
    protected function executeSaveBundleProduct(
        Cart $cart, 
        LineItem $lineItem, 
        ProductEntity $bundleProduct,
        SalesChannelContext $salesChannelContext
    ): void {
        $bundleProductLineItem = $this->getLineItemById($cart, $bundleProduct->getId());
        
        if (!$bundleProductLineItem) {
            $this->addBundleProduct($lineItem, $cart, $bundleProduct, $salesChannelContext);

            return;
        }

        $productLineItem = $this->getLineItemById($cart, $lineItem->getId());
        $lineItem->setQuantity($productLineItem->getQuantity());

        $this->updateBundleProduct($lineItem, $bundleProductLineItem, $cart, $salesChannelContext);
    }

    /**
     * @param \Shopware\Core\Checkout\Cart\Cart $cart
     * @param string $id
     * 
     * @return \Shopware\Core\Checkout\Cart\LineItem\LineItem|null
     */
    protected function getLineItemById(Cart $cart, string $id) 
    {
        foreach ($cart->getLineItems() as $lineItem) {

            if ($lineItem->getId() === $id) {
                return $lineItem;
            }
        }

        return null;
    }

    /**
     * @param \Shopware\Core\Checkout\Cart\LineItem\LineItem $lineItem
     * @param \Shopware\Core\Checkout\Cart\Cart $cart
     * @param \Shopware\Core\Content\Product\ProductEntity $bundleProduct
     * @param \Shopware\Core\System\SalesChannel\SalesChannelContext $salesChannelContext
     * 
     * @return void
     */
    protected function addBundleProduct(
        LineItem $lineItem, 
        Cart $cart,
        ProductEntity $bundleProduct,
        SalesChannelContext $salesChannelContext
    ): void {
        $bundleProductLineItem = $this->createLineItem($bundleProduct, $lineItem->getQuantity());

        $cart->add($bundleProductLineItem);
        $cart->markModified();

        $this->cartService->recalculate($cart, $salesChannelContext);
    }

    /**
     * @param \Shopware\Core\Checkout\Cart\LineItem\LineItem $lineItem
     * @param \Shopware\Core\Checkout\Cart\LineItem\LineItem $bundleProductLineItem
     * @param \Shopware\Core\Checkout\Cart\Cart $cart
     * @param \Shopware\Core\System\SalesChannel\SalesChannelContext $salesChannelContext
     * 
     * @return void
     */
    protected function updateBundleProduct(
        LineItem $lineItem, 
        LineItem $bundleProductLineItem, 
        Cart $cart,
        SalesChannelContext $salesChannelContext
    ): void {
        $bundleProductLineItem->setQuantity($lineItem->getQuantity());

        $cart->markModified();
        
        $this->cartService->recalculate($cart, $salesChannelContext);
    }

    /**
     * @param \Shopware\Core\Checkout\Cart\Cart $cart
     * @param \Shopware\Core\Content\Product\ProductEntity $bundleProduct
     * @param \Shopware\Core\System\SalesChannel\SalesChannelContext $salesChannelContext
     * 
     * @return void
     */
    protected function removeBundleProduct(
        Cart $cart,
        ProductEntity $bundleProduct,
        SalesChannelContext $salesChannelContext
    ): void {
        foreach ($cart->getLineItems() as $index => $item) {

            if ($item->getReferencedId() !== $bundleProduct->getId()) {
                continue;                                       
            }

            $cart->remove($index);
            $cart->markModified();
                
            $this->cartService->recalculate($cart, $salesChannelContext); 
        }
    }

    /**
     * @param \Shopware\Core\Content\Product\ProductEntity $product
     * @param int $quantity
     * 
     * @return \Shopware\Core\Checkout\Cart\LineItem\LineItem
     */
    protected function createLineItem(ProductEntity $product, int $quantity): LineItem 
    {
        $lineItem = new LineItem($product->getId(),  LineItem::PRODUCT_LINE_ITEM_TYPE);

        return $lineItem
            ->setLabel($product->getTranslation('name'))
            ->setStackable(true)
            ->setRemovable(true)
            ->setReferencedId($product->getId())
            ->setQuantity($quantity);
    }

    /**
     * @param string $productId
     * 
     * @return \Shopware\Core\Content\Product\ProductEntity|null
     */
    protected function getBundleProductByProductId(string $productId): ?ProductEntity
    {
        $product =  $this->productReader->getProductById($productId);
        $customFields = $product->getCustomFields();

        if (!array_key_exists(static::CUSTOM_FIELD_VERSANDKARTON, $customFields) 
            || !isset($customFields[static::CUSTOM_FIELD_VERSANDKARTON])
        ) {
            return null;
        }

        return $this->productReader
            ->getProductByProductNumber($customFields[static::CUSTOM_FIELD_VERSANDKARTON]);
    }

}