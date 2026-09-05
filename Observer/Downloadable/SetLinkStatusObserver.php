<?php
/**
 * @namespace   BlueFairy
 * @module      CoreBugs
 * @author      bluefairydevelopment.com
 * @email       staff@bluefairydevelopment.com
 * @brief       Fix for Bug #2 — SetLinkStatusObserver crashes on a null order-item id
 */
declare(strict_types=1);

namespace BlueFairy\CoreBugs\Observer\Downloadable;

use Magento\Downloadable\Model\Link\Purchased\Item;
use Magento\Downloadable\Model\Product\Type;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;

/**
 * Overrides core SetLinkStatusObserver to guard against a null order-item id.
 *
 * The core class already guards this exact hazard in ONE of its four branches
 * (the canceled/closed/complete branch: `$itemId = $item->getId(); if ($itemId
 * !== null) { ... }`), but not in the other three, including the default
 * branch every normal order placement takes. $item->getId() is null there
 * for this Magento/PHP combination at the point sales_order_save_after fires,
 * and PHP 8.1+ deprecates using null as an array offset. This Magento
 * instance's registered error handler promotes that deprecation to a fatal
 * Exception, which aborts order placement entirely - every order containing
 * a downloadable item fails with a generic "server error" from the checkout
 * API, with the real cause visible only in exception.log.
 *
 * Because execute() has no smaller extractable method to override cleanly,
 * it is fully reimplemented here, applying the same null-id guard the core
 * class already uses in its other branch consistently across all of them.
 *
 * @see \Magento\Downloadable\Observer\SetLinkStatusObserver
 * @see https://bluefairydevelopment.atlassian.net/browse/M2MAP-190
 */
class SetLinkStatusObserver extends \Magento\Downloadable\Observer\SetLinkStatusObserver
{
    /**
     * Set status of link.
     *
     * Reimplemented from the core class to guard every `$item->getId()` used
     * as an array key against null, not just the one branch the core class
     * already guards.
     *
     * @param Observer $observer
     * @return $this
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();

        if (!$order->getId()) {
            // order not saved in the database
            return $this;
        }

        /* @var $order Order */
        $status = '';
        $linkStatuses = [
            'pending' => Item::LINK_STATUS_PENDING,
            'expired' => Item::LINK_STATUS_EXPIRED,
            'avail' => Item::LINK_STATUS_AVAILABLE,
            'payment_pending' => Item::LINK_STATUS_PENDING_PAYMENT,
            'payment_review' => Item::LINK_STATUS_PAYMENT_REVIEW,
        ];
        $expiredOrderItemIds = [];

        $downloadableItemsStatuses = [];
        $orderItemStatusToEnable = $this->_scopeConfig->getValue(
            Item::XML_PATH_ORDER_ITEM_STATUS,
            ScopeInterface::SCOPE_STORE,
            $order->getStoreId()
        );

        if ($order->getState() == Order::STATE_HOLDED) {
            $status = $linkStatuses['pending'];
        } elseif ($order->isCanceled()
            || $order->getState() == Order::STATE_CLOSED
            || $order->getState() == Order::STATE_COMPLETE
        ) {
            $expiredStatuses = [
                \Magento\Sales\Model\Order\Item::STATUS_CANCELED,
                \Magento\Sales\Model\Order\Item::STATUS_REFUNDED,
            ];
            foreach ($order->getAllItems() as $item) {
                if ($item->getProductType() == Type::TYPE_DOWNLOADABLE
                    || $item->getRealProductType() == Type::TYPE_DOWNLOADABLE
                ) {
                    $itemId = $item->getId();
                    if ($itemId !== null) {
                        if ($order->isCanceled() || in_array($item->getStatusId(), $expiredStatuses)) {
                            $downloadableItemsStatuses[$itemId] = $linkStatuses['expired'];
                        } else {
                            $downloadableItemsStatuses[$itemId] = $linkStatuses['avail'];
                        }
                    }
                }
            }
        } elseif ($order->getState() == Order::STATE_PENDING_PAYMENT) {
            $status = $linkStatuses['payment_pending'];
        } elseif ($order->getState() == Order::STATE_PAYMENT_REVIEW) {
            $status = $linkStatuses['payment_review'];
        } else {
            $availableStatuses = [$orderItemStatusToEnable, \Magento\Sales\Model\Order\Item::STATUS_INVOICED];
            foreach ($order->getAllItems() as $item) {
                if ($item->getProductType() == Type::TYPE_DOWNLOADABLE
                    || $item->getRealProductType() == Type::TYPE_DOWNLOADABLE
                ) {
                    if ($item->getStatusId() == \Magento\Sales\Model\Order\Item::STATUS_BACKORDERED
                        && $orderItemStatusToEnable == \Magento\Sales\Model\Order\Item::STATUS_PENDING
                        && !in_array(
                            \Magento\Sales\Model\Order\Item::STATUS_BACKORDERED,
                            $availableStatuses,
                            true
                        )
                    ) {
                        $availableStatuses[] = \Magento\Sales\Model\Order\Item::STATUS_BACKORDERED;
                    }

                    // THE FIX: $item->getId() can be null here at the point this
                    // observer fires during order placement - guard it before use
                    // as an array key, the same way the branch above already does.
                    $itemId = $item->getId();
                    if ($itemId === null) {
                        continue;
                    }

                    if (in_array($item->getStatusId(), $availableStatuses)) {
                        $downloadableItemsStatuses[$itemId] = $linkStatuses['avail'];
                    }

                    if ($item->getQtyOrdered() - $item->getQtyRefunded() == 0) {
                        $expiredOrderItemIds[] = $itemId;
                    }
                }
            }
        }
        if (!$downloadableItemsStatuses && $status) {
            foreach ($order->getAllItems() as $item) {
                if ($item->getProductType() == Type::TYPE_DOWNLOADABLE
                    || $item->getRealProductType() == Type::TYPE_DOWNLOADABLE
                ) {
                    $itemId = $item->getId();
                    if ($itemId !== null) {
                        $downloadableItemsStatuses[$itemId] = $status;
                    }
                }
            }
        }

        if ($downloadableItemsStatuses) {
            $linkPurchased = $this->_createItemsCollection()->addFieldToFilter(
                'order_item_id',
                ['in' => array_keys($downloadableItemsStatuses)]
            );
            foreach ($linkPurchased as $link) {
                if ($link->getStatus() != $linkStatuses['expired']
                    && !empty($downloadableItemsStatuses[$link->getOrderItemId()])
                ) {
                    $link->setStatus($downloadableItemsStatuses[$link->getOrderItemId()])->save();
                }
            }
        }

        if ($expiredOrderItemIds) {
            $linkPurchased = $this->_createItemsCollection()->addFieldToFilter(
                'order_item_id',
                ['in' => $expiredOrderItemIds]
            );
            foreach ($linkPurchased as $link) {
                $link->setStatus(Item::LINK_STATUS_EXPIRED)->save();
            }
        }

        return $this;
    }
}
