<?php

namespace Yanduu\CheckoutCartBundleProduct\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Checkout\Cart\Event\CartChangedEvent;
use Shopware\Core\Checkout\Cart\Event\AfterLineItemRemovedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Event\AfterLineItemAddedEvent;
use Shopware\Core\Checkout\Cart\Event\AfterLineItemQuantityChangedEvent;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityLoadedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeletedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityUpdatedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntitySearchResultLoadedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

class CartSubscriber implements EventSubscriberInterface
{
    private $logger;

    protected $productRepository;

    protected $context;


    protected $cartService;

    public function __construct(
        EntityRepositoryInterface $productRepository,
        CartService $cartService,
        LoggerInterface $logger
    ){
        $this->productRepository = $productRepository;

        $this->logger = $logger;

        $this->cartService = $cartService;

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
     */
    public function onLineItemAdded(AfterLineItemAddedEvent $event): void
    {
        $lineItems = $event->getLineItems();

        if (count($lineItems) === 0) {
            return;
        }

        foreach ($lineItems as $lineItem) {
            if (!$lineItem->getPayload()) {
                $this->updateBundleProductQuantity($lineItem, $event);

                continue;
            }

            $this->addBundleProduct($lineItem, $event);            
        }
    }

    public function onLineItemQuantityChanged(AfterLineItemQuantityChangedEvent  $event) 
    {
        $items = $event->getItems();

        if (count($items) === 0 ) {
            return ;
        }

        $cart = $event->getCart();
        foreach ($items as $item) {
            $lineItem = $this->getLineItemById($item['id'], $cart);

            $customFields = $lineItem->getPayloadValue('customFields');

            if (!$customFields) {
                return;
            }

            if (!array_key_exists('custom_sl_artikel_VERSANDKARTON', $customFields) 
                || !isset($customFields['custom_sl_artikel_VERSANDKARTON'])
            ) {
                return;
            }

            $bundleProduct =  $this->getProductBySku($customFields['custom_sl_artikel_VERSANDKARTON']);

            $lineItem = $this->getLineItemByReferencedId(
                $bundleProduct->getId(), 
                $event->getCart()
            );

            if ($lineItem === null) {
                continue;
            }

            $lineItem->setQuantity($item['quantity']);
            $cart->markModified();
            $this->cartService->recalculate($cart, $event->getSalesChannelContext());
        }

    }

    /**
     * 
     */
    protected function getLineItemById(string $lineItemId, $cart) 
    {
        $lineItems = $cart->getLineItems();

        foreach ($lineItems as $lineItem) {
            if ($lineItem->getId() !== $lineItemId) {
                continue;
            }

            return $lineItem;
        }

        return null;
    }

    protected function getLineItemByReferencedId(string $referencedId, $cart) 
    {
        $lineItems = $cart->getLineItems();

        foreach ($lineItems as $lineItem) {
            if ($lineItem->getReferencedId() !== $referencedId) {
                continue;
            }

            return $lineItem;
        }

        return null;
    }

    /**
     * 
     */
    protected function addBundleProduct(LineItem $lineItem, $event): void 
    {
        $cart = $event->getCart();
        
        $customFields = $lineItem->getPayloadValue('customFields');

        if (!$customFields) {
            return;
        }

        if (!array_key_exists('custom_sl_artikel_VERSANDKARTON', $customFields) 
            || !isset($customFields['custom_sl_artikel_VERSANDKARTON'])
        ) {
            return;
        }

        $bundleProduct =  $this->getProductBySku($customFields['custom_sl_artikel_VERSANDKARTON']);

        if ($bundleProduct == null) {
            return;
        }

        $bundleProductLineItem = $this->createLineItem($bundleProduct, $lineItem->getQuantity());

        $cart->add($bundleProductLineItem);
        $cart->markModified();

        $this->cartService->recalculate($cart, $event->getSalesChannelContext());
    }

    /**
     * 
     */
    protected function updateBundleProductQuantity(LineItem $lineItem, $event): void
    {
        if (!$lineItem->getReferencedId()) {
            return;
        }

        $product =  $this->getProductById($lineItem->getReferencedId());
        $customFields = $product->getCustomFields();

        if (!array_key_exists('custom_sl_artikel_VERSANDKARTON', $customFields) 
            || !isset($customFields['custom_sl_artikel_VERSANDKARTON'])
        ) {
            return;
        }

        $cart = $event->getCart();
        $bundleProduct =  $this->getProductBySku($customFields['custom_sl_artikel_VERSANDKARTON']);
        
        $lineItemBundleProduct = $this->getLineItemByReferencedId($bundleProduct->getId(), $cart);

        if ($lineItemBundleProduct === null) {
            return;
        }

        $lineItemProduct = $this->getLineItemByReferencedId($lineItem->getReferencedId(), $cart);
        $lineItemBundleProduct->setQuantity($lineItemProduct->getQuantity());
        $cart->markModified();
        $this->cartService->recalculate($cart, $event->getSalesChannelContext());

    }

     /**
     * @param \Shopware\Core\Checkout\Cart\Event\AfterLineItemRemovedEvent $event
     */
    public function onLineItemRemoved(AfterLineItemRemovedEvent $event): void
    {
        $cart = $event->getCart();
        $lineItems= $event->getLineItems();

        foreach ($lineItems as $lineItem) {
            $customFields = $lineItem->getPayloadValue('customFields');
            if (!$customFields) {
                continue;
            }

            if (!array_key_exists('custom_sl_artikel_VERSANDKARTON', $customFields)
                || !isset($customFields['custom_sl_artikel_VERSANDKARTON'])
            ) {
                continue;
            }

            $bundleProduct =  $this->getProductBySku($customFields['custom_sl_artikel_VERSANDKARTON']);

            if (!$bundleProduct) {
                continue;
            }

            foreach ($cart->getLineItems() as $index => $lineItem2) {
                if ($lineItem2->getReferencedId() === $bundleProduct->getId()) {
                    $cart->remove($index);
                    $cart->markModified();
                    $this->cartService->recalculate($cart, $event->getSalesChannelContext());                    
                }
            }
        }
    }


    private function getProductBySku(string $productNumber): ?ProductEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('productNumber', $productNumber));

        $product = $this->productRepository->search($criteria, $this->context)->first();

        return $product;
    }

    private function getProductById(string $productId): ?ProductEntity
    {
        $product = $this->productRepository
            ->search(new Criteria([$productId]), $this->context)->first();

        return $product;
    }

    /**
     * 
     */
    protected function createLineItem($product, int $quantity): LineItem 
    {
        $lineItem = new LineItem($product->getId(),  LineItem::PRODUCT_LINE_ITEM_TYPE);

        return $lineItem
            ->setLabel($product->getTranslation('name'))
            ->setStackable(true)
            ->setRemovable(true)
            ->setReferencedId($product->getId())
            ->setQuantity($quantity);
    }

}
