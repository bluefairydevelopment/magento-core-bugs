<?php
/**
 * @namespace   BlueFairy
 * @module      CoreBugs
 * @brief       Tests for Bug #2 — downloadable link status with a null order-item id
 */
declare(strict_types=1);

namespace BlueFairy\CoreBugs\Test\Unit\Observer\Downloadable;

use BlueFairy\CoreBugs\Observer\Downloadable\SetLinkStatusObserver;
use Magento\Downloadable\Model\Link\Purchased\Item;
use Magento\Downloadable\Model\Product\Type;
use Magento\Downloadable\Model\ResourceModel\Link\Purchased\Item\Collection;
use Magento\Downloadable\Model\ResourceModel\Link\Purchased\Item\CollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \BlueFairy\CoreBugs\Observer\Downloadable\SetLinkStatusObserver
 */
class SetLinkStatusObserverTest extends TestCase
{
    /** @var array<int, mixed> every addFieldToFilter() condition, in order */
    private array $filters = [];

    /** @var DataObject[] purchased links the collection returns, keyed by nothing */
    private array $links = [];

    /** @var array<int, array{0: string, 1: mixed}> */
    private array $configReads = [];

    private int $enableStatus = OrderItem::STATUS_PENDING;

    private function observer(): SetLinkStatusObserver
    {
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnCallback(function (string $path, string $scope, $storeId) {
            $this->configReads[] = [$path, $scope, $storeId];

            return $this->enableStatus;
        });

        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturnCallback(function () {
            $collection = $this->createStub(Collection::class);
            $collection->method('addFieldToFilter')->willReturnCallback(function ($field, $condition) {
                $this->filters[] = [$field, $condition];

                return new \ArrayIterator($this->links);
            });

            return $collection;
        });

        return new SetLinkStatusObserver($config, $factory);
    }

    /**
     * @param DataObject[] $items
     */
    private function order(?int $id, string $state, array $items, bool $canceled = false): DataObject
    {
        return new class (['id' => $id, 'state' => $state, 'store_id' => 3], $items, $canceled) extends DataObject {
            /**
             * @param array<string, mixed> $data
             * @param DataObject[] $items
             */
            public function __construct(array $data, private array $items, private bool $canceled)
            {
                parent::__construct($data);
            }

            public function isCanceled(): bool
            {
                return $this->canceled;
            }

            /**
             * @return DataObject[]
             */
            public function getAllItems(): array
            {
                return $this->items;
            }
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private function item(?int $id, array $data = []): DataObject
    {
        return new DataObject($data + [
            'id' => $id,
            'product_type' => Type::TYPE_DOWNLOADABLE,
            'status_id' => OrderItem::STATUS_INVOICED,
            'qty_ordered' => 1,
            'qty_refunded' => 0,
        ]);
    }

    private function link(int $orderItemId, string $status = Item::LINK_STATUS_PENDING): DataObject
    {
        return new class (['order_item_id' => $orderItemId, 'status' => $status]) extends DataObject {
            public int $saves = 0;

            public function save(): self
            {
                $this->saves++;

                return $this;
            }
        };
    }

    private function dispatch(DataObject $order): SetLinkStatusObserver
    {
        $observer = $this->observer();
        $result = $observer->execute(new Observer(['event' => new Event(['order' => $order])]));
        self::assertSame($observer, $result);

        return $observer;
    }

    public function testAnUnsavedOrderIsLeftAlone(): void
    {
        $this->dispatch($this->order(null, Order::STATE_NEW, [$this->item(5)]));

        self::assertSame([], $this->filters);
        self::assertSame([], $this->configReads);
    }

    public function testANewOrderWithANullItemIdDoesNotFailAndSkipsThatItem(): void
    {
        $this->links = [$this->link(7)];

        $this->dispatch($this->order(1, Order::STATE_NEW, [$this->item(null), $this->item(7)]));

        self::assertSame([['order_item_id', ['in' => [7]]]], $this->filters);
        self::assertSame(Item::LINK_STATUS_AVAILABLE, $this->links[0]->getStatus());
        self::assertSame(1, $this->links[0]->saves);
        self::assertSame([[Item::XML_PATH_ORDER_ITEM_STATUS, ScopeInterface::SCOPE_STORE, 3]], $this->configReads);
    }

    public function testANewOrderOnlyEnablesItemsInAnEnablingStatus(): void
    {
        $this->links = [$this->link(7)];

        $this->dispatch($this->order(1, Order::STATE_NEW, [
            $this->item(7, ['status_id' => OrderItem::STATUS_PENDING + 100]),
            $this->item(8, ['product_type' => 'simple']),
        ]));

        self::assertSame([], $this->filters, 'no item qualifies, so no collection is loaded');
    }

    public function testARealProductTypeOfDownloadableCounts(): void
    {
        $this->links = [$this->link(7)];

        $this->dispatch($this->order(1, Order::STATE_NEW, [
            $this->item(7, ['product_type' => 'configurable', 'real_product_type' => Type::TYPE_DOWNLOADABLE]),
        ]));

        self::assertSame(Item::LINK_STATUS_AVAILABLE, $this->links[0]->getStatus());
    }

    public function testABackorderedItemIsEnabledWhenPendingItemsAreConfiguredToEnable(): void
    {
        $this->links = [$this->link(7)];

        $this->dispatch($this->order(1, Order::STATE_PROCESSING, [
            $this->item(7, ['status_id' => OrderItem::STATUS_BACKORDERED]),
            $this->item(8, ['status_id' => OrderItem::STATUS_BACKORDERED]),
        ]));

        self::assertSame(['order_item_id', ['in' => [7, 8]]], $this->filters[0]);
        self::assertSame(Item::LINK_STATUS_AVAILABLE, $this->links[0]->getStatus());
    }

    public function testABackorderedItemIsNotEnabledWhenInvoicedItemsAreConfiguredToEnable(): void
    {
        $this->enableStatus = OrderItem::STATUS_INVOICED;

        $this->dispatch($this->order(1, Order::STATE_PROCESSING, [
            $this->item(7, ['status_id' => OrderItem::STATUS_BACKORDERED]),
        ]));

        self::assertSame([], $this->filters);
    }

    public function testAFullyRefundedItemExpiresItsLinks(): void
    {
        $this->links = [$this->link(7)];

        $this->dispatch($this->order(1, Order::STATE_PROCESSING, [
            $this->item(7, ['status_id' => OrderItem::STATUS_REFUNDED, 'qty_ordered' => 2, 'qty_refunded' => 2]),
        ]));

        self::assertSame([['order_item_id', ['in' => [7]]]], $this->filters);
        self::assertSame(Item::LINK_STATUS_EXPIRED, $this->links[0]->getStatus());
    }

    public function testAHeldOrderSetsEveryDownloadableLinkPendingAndSkipsANullId(): void
    {
        $this->links = [$this->link(7, Item::LINK_STATUS_AVAILABLE)];

        $this->dispatch($this->order(1, Order::STATE_HOLDED, [$this->item(null), $this->item(7)]));

        self::assertSame([['order_item_id', ['in' => [7]]]], $this->filters);
        self::assertSame(Item::LINK_STATUS_PENDING, $this->links[0]->getStatus());
    }

    public function testPendingPaymentAndPaymentReviewSetTheirOwnStatus(): void
    {
        $this->links = [$this->link(7)];
        $this->dispatch($this->order(1, Order::STATE_PENDING_PAYMENT, [$this->item(7)]));
        self::assertSame(Item::LINK_STATUS_PENDING_PAYMENT, $this->links[0]->getStatus());

        $this->links = [$this->link(7)];
        $this->dispatch($this->order(1, Order::STATE_PAYMENT_REVIEW, [$this->item(7)]));
        self::assertSame(Item::LINK_STATUS_PAYMENT_REVIEW, $this->links[0]->getStatus());
    }

    public function testAStatusOrderWithNoDownloadableItemLoadsNothing(): void
    {
        $this->dispatch($this->order(1, Order::STATE_HOLDED, [$this->item(7, ['product_type' => 'simple'])]));

        self::assertSame([], $this->filters);
    }

    public function testACanceledOrderExpiresItsLinksAndSkipsANullId(): void
    {
        $this->links = [$this->link(7)];

        $this->dispatch($this->order(1, Order::STATE_CANCELED, [$this->item(null), $this->item(7)], true));

        self::assertSame([['order_item_id', ['in' => [7]]]], $this->filters);
        self::assertSame(Item::LINK_STATUS_EXPIRED, $this->links[0]->getStatus());
    }

    public function testACompleteOrderMakesInvoicedLinksAvailableAndRefundedOnesExpired(): void
    {
        $available = $this->link(7);
        $refunded = $this->link(8);
        $this->links = [$available, $refunded];

        $this->dispatch($this->order(1, Order::STATE_COMPLETE, [
            $this->item(7),
            $this->item(8, ['status_id' => OrderItem::STATUS_REFUNDED]),
            $this->item(9, ['product_type' => 'simple']),
        ]));

        self::assertSame(['order_item_id', ['in' => [7, 8]]], $this->filters[0]);
        self::assertSame(Item::LINK_STATUS_AVAILABLE, $available->getStatus());
        self::assertSame(Item::LINK_STATUS_EXPIRED, $refunded->getStatus());
    }

    public function testAClosedOrderTakesTheSameBranch(): void
    {
        $this->links = [$this->link(7)];

        $this->dispatch($this->order(1, Order::STATE_CLOSED, [
            $this->item(7, ['status_id' => OrderItem::STATUS_CANCELED]),
        ]));

        self::assertSame(Item::LINK_STATUS_EXPIRED, $this->links[0]->getStatus());
    }

    public function testAnExpiredLinkIsNeverRevivedAndALinkWithNoNewStatusIsNotSaved(): void
    {
        $expired = $this->link(7, Item::LINK_STATUS_EXPIRED);
        $stranger = $this->link(99);
        $this->links = [$expired, $stranger];

        $this->dispatch($this->order(1, Order::STATE_NEW, [$this->item(7)]));

        self::assertSame(Item::LINK_STATUS_EXPIRED, $expired->getStatus());
        self::assertSame(0, $expired->saves);
        self::assertSame(Item::LINK_STATUS_PENDING, $stranger->getStatus());
        self::assertSame(0, $stranger->saves);
    }
}
