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
     * @param \Shopware\Core\Checkout\Cart\Event\CartChangedEvent $event
     * 
     * @return void
     */
    public function onLineItemAdded(AfterLineItemAddedEvent $event): void
    {
        $lineItems = $event->getLineItems();

        if (count($lineItems) === 0) {
            return;
        }

        foreach ($lineItems as $lineItem) {
            if (!$lineItem->getPayload()) {
                $this->updateBundleProduct($lineItem, $event);

                continue;
            }

            $this->addBundleProduct($lineItem, $event);            
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

        if (count($items) === 0 ) {
            return ;
        }

        $cart = $event->getCart();
        foreach ($items as $item) {
            $lineItem = $this->getLineItemById($item['id'], $cart);

            $bundleProduct = $this->getBundleProductByLineItem($lineItem);

            if ($bundleProduct === null) {
                continue;
            }

            $lineItem = $this->getLineItemByReferencedId($bundleProduct->getId(), $cart);

            if ($lineItem === null) {
                continue;
            }

            $lineItem->setQuantity($item['quantity']);
            $cart->markModified();
            $this->cartService->recalculate($cart, $event->getSalesChannelContext());
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

        foreach ($lineItems as $lineItem) {
            $bundleProduct = $this->getBundleProductByLineItem($lineItem);

            if (!$bundleProduct) {
                continue;
            }

            foreach ($cart->getLineItems() as $index => $item) {

                if ($item->getReferencedId() !== $bundleProduct->getId()) {
                    continue;                                       
                }

                $cart->remove($index);
                $cart->markModified();
                    
                $this->cartService->recalculate($cart, $event->getSalesChannelContext()); 

            }
        }
    }

    /**
     * @param \Shopware\Core\Checkout\Cart\LineItem\LineItem $lineItem
     * @param \Shopware\Core\Checkout\Cart\Event\AfterLineItemRemovedEvent $event
     * 
     * @return void
     */
    protected function addBundleProduct(LineItem $lineItem, AfterLineItemAddedEvent $event): void 
    {
        $bundleProduct = $this->getBundleProductByLineItem($lineItem);

        if ($bundleProduct == null) {
            return;
        }

        $bundleProductLineItem = $this->createLineItem($bundleProduct, $lineItem->getQuantity());

        $cart = $event->getCart();
        $cart->add($bundleProductLineItem);
        $cart->markModified();

        $this->cartService->recalculate($cart, $event->getSalesChannelContext());
    }

    /**
     * @param \Shopware\Core\Checkout\Cart\LineItem\LineItem $lineItem
     * @param \Shopware\Core\Checkout\Cart\Event\AfterLineItemRemovedEvent $event
     * 
     * @return void
     */
    protected function updateBundleProduct(LineItem $lineItem, AfterLineItemAddedEvent $event): void
    {
        if (!$lineItem->getReferencedId()) {
            return;
        }

        $bundleProduct = $this->getBundleProductByProductId($lineItem->getReferencedId());

        if ($bundleProduct == null) {
            return;
        }

        $cart = $event->getCart();

        $bundleProductLineItem = $this->getLineItemByReferencedId($bundleProduct->getId(), $cart);

        if ($bundleProductLineItem === null) {
            return;
        }

        $productLineItem = $this->getLineItemByReferencedId($lineItem->getReferencedId(), $cart);
        $bundleProductLineItem->setQuantity($productLineItem->getQuantity());

        $cart->markModified();
        
        $this->cartService->recalculate($cart, $event->getSalesChannelContext());
    }

    /**
     * @param string $lineItemId
     * @param \Shopware\Core\Checkout\Cart\Cart $cart
     * 
     * @return \Shopware\Core\Checkout\Cart\LineItem\LineItem
     */
    protected function getLineItemById(string $lineItemId, Cart $cart): ?LineItem 
    {
        foreach ($cart->getLineItems() as $lineItem) {
            if ($lineItem->getId() !== $lineItemId) {
                continue;
            }

            return $lineItem;
        }

        return null;
    }

    /**
     * @param string $referencedId
     * @param \Shopware\Core\Checkout\Cart\Cart $cart
     * 
     * @return \Shopware\Core\Checkout\Cart\LineItem\LineItem
     */
    protected function getLineItemByReferencedId(string $referencedId, Cart $cart): ?LineItem  
    {
        $lineItems = $cart->getLineItems();

        foreach ($cart->getLineItems() as $lineItem) {
            if ($lineItem->getReferencedId() !== $referencedId) {
                continue;
            }

            return $lineItem;
        }

        return null;
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
     * @param \Shopware\Core\Checkout\Cart\LineItem\LineItem $lineItem
     * 
     * @return \Shopware\Core\Content\Product\ProductEntity
     */
    protected function getBundleProductByLineItem(LineItem $lineItem): ?ProductEntity
    {
        $customFields = $lineItem->getPayloadValue(static::KEY_CUSTOM_FIELDS);

        if (!$customFields) {
            return null;
        }

        if (!array_key_exists(static::CUSTOM_FIELD_VERSANDKARTON, $customFields) 
            || !isset($customFields[static::CUSTOM_FIELD_VERSANDKARTON])
        ) {
            return null;
        }

        $bundleProduct =  $this->productReader
            ->getProductByProductNumber($customFields[static::CUSTOM_FIELD_VERSANDKARTON]);

        if ($bundleProduct == null) {
            return null;
        }

        return $bundleProduct;
    }

    /**
     * @param string $productId
     * 
     * @return \Shopware\Core\Content\Product\ProductEntity
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
