<?php
/**
 * @namespace   BlueFairy
 * @module      CoreBugs
 * @author      bluefairydevelopment.com
 * @email       staff@bluefairydevelopment.com
 * @brief       Fix for Bug #4 — product page stock message throws for a SKU not assigned to the website's stock
 */
declare(strict_types=1);

namespace BlueFairy\CoreBugs\Plugin\InventorySalesFrontendUi;

use Magento\CatalogInventory\Block\Stockqty\AbstractStockqty;
use Magento\InventoryConfigurationApi\Exception\SkuIsNotAssignedToStockException;

/**
 * Overrides MSI's AbstractStockqtyPlugin so a SKU that is not assigned to the website's stock hides the
 * "Only X left" message instead of throwing.
 *
 * aroundIsMsgVisible() asks GetStockItemConfiguration for the SKU's configuration on the website's stock
 * before anything else, and that service throws SkuIsNotAssignedToStockException by contract when the
 * SKU has no source item on any of that stock's sources. On a store whose website is served by a custom
 * stock, every product page for such a SKU then logs the exception at CRITICAL, drops the stock block
 * and, because the layout renders it as a non-cached element that failed, is never saved to the full
 * page cache.
 *
 * The same fix as magento/inventory#3475 (magento/inventory#3474): the message is simply not shown.
 *
 * @see \Magento\InventorySalesFrontendUi\Plugin\Block\Stockqty\AbstractStockqtyPlugin
 * @see https://github.com/magento/inventory/issues/3474
 */
class AbstractStockqtyPlugin extends \Magento\InventorySalesFrontendUi\Plugin\Block\Stockqty\AbstractStockqtyPlugin
{
    /**
     * Hide the message for a SKU that is not assigned to the website's stock.
     *
     * @param AbstractStockqty $subject
     * @param callable $proceed
     * @return bool
     */
    public function aroundIsMsgVisible(AbstractStockqty $subject, callable $proceed): bool
    {
        try {
            return parent::aroundIsMsgVisible($subject, $proceed);
        } catch (SkuIsNotAssignedToStockException $exception) {
            return false;
        }
    }
}
