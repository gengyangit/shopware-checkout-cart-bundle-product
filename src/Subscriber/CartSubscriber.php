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
            CartChangedEvent::class => 'onCartChanged',
            AfterLineItemRemovedEvent::class => 'onLineItemRemoved'
        ];
    }

    /**
     * @param \Shopware\Core\Checkout\Cart\Event\CartChangedEvent $event
     */
    public function onCartChanged(CartChangedEvent $event): void
    {
        $cart = $event->getCart();
        $lineItems = $cart->getLineItems();

        if (count($lineItems) === 0) {
            return;
        }

        foreach ($lineItems as $lineItem) {
	        $customFields = $lineItem->getPayloadValue('customFields');
            if (!$customFields) {
                continue;
	        }

            if (!array_key_exists('custom_sl_artikel_VERSANDKARTON', $customFields)) {
                continue;
            }

            $bundleProduct =  $this->getProduct($customFields['custom_sl_artikel_VERSANDKARTON']);

            if ($bundleProduct == null) {
                continue;
            }

            foreach ($lineItems as $lineItem2) {

                if (
                    $lineItem2->getPayloadValue('productNumber') === $bundleProduct->getProductnumber()
                    && $lineItem2->getQuantity() != $lineItem->getQuantity()
                ) {
                    $lineItem2->setQuantity($lineItem->getQuantity());
                    $cart->markModified();
                    $this->cartService->recalculate($cart, $event->getContext());

                    return;
                }
            }

            $bundleProductLineItem = $this->createLineItem($bundleProduct, $lineItem->getQuantity());

            $cart->add($bundleProductLineItem);
            $cart->markModified();

            $this->cartService->recalculate($cart, $event->getContext());

        }
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

            if (!array_key_exists('custom_sl_artikel_VERSANDKARTON', $customFields)) {
                continue;
            }

            foreach ($cart->getLineItems() as $index => $lineItem2) {
                $bundleProduct =  $this->getProduct($customFields['custom_sl_artikel_VERSANDKARTON']);

                if (!$bundleProduct) {
                    continue;
                }

                if ($bundleProduct->getProductnumber() === $customFields['custom_sl_artikel_VERSANDKARTON']) {
                    $cart->remove($index);
                    $cart->markModified();
                    $this->cartService->recalculate($cart, $event->getSalesChannelContext());                    
                }
            }
        }
    }


    private function getProduct(string $productNumber): ?ProductEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('productNumber', $productNumber));

        $product = $this->productRepository->search($criteria, $this->context)->first();

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