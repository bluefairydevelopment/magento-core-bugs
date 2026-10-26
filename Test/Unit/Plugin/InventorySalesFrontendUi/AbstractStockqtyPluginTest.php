<?php
/**
 * @namespace   BlueFairy
 * @module      CoreBugs
 * @brief       Tests for Bug #4 — stock message for a SKU not assigned to the website's stock
 */
declare(strict_types=1);

namespace BlueFairy\CoreBugs\Test\Unit\Plugin\InventorySalesFrontendUi;

use BlueFairy\CoreBugs\Plugin\InventorySalesFrontendUi\AbstractStockqtyPlugin;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Block\Stockqty\AbstractStockqty;
use Magento\Framework\Exception\LocalizedException;
use Magento\InventoryApi\Api\Data\StockInterface;
use Magento\InventoryCatalogFrontendUi\Model\IsSalableQtyAvailableForDisplaying;
use Magento\InventoryConfigurationApi\Api\Data\StockItemConfigurationInterface;
use Magento\InventoryConfigurationApi\Api\GetStockItemConfigurationInterface;
use Magento\InventoryConfigurationApi\Exception\SkuIsNotAssignedToStockException;
use Magento\InventoryConfigurationApi\Model\IsSourceItemManagementAllowedForProductTypeInterface;
use Magento\InventorySalesApi\Api\GetProductSalableQtyInterface;
use Magento\InventorySalesApi\Model\StockByWebsiteIdResolverInterface;
use Magento\Store\Model\Store;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * The collaborators are shared mocks; each test sets expectations only on the ones its path reaches.
 */
#[AllowMockObjectsWithoutExpectations]
class AbstractStockqtyPluginTest extends TestCase
{
    /**
     * @var GetStockItemConfigurationInterface&MockObject
     */
    private MockObject $getStockItemConfiguration;
    /**
     * @var GetProductSalableQtyInterface&MockObject
     */
    private MockObject $getProductSalableQty;
    /**
     * @var IsSalableQtyAvailableForDisplaying&MockObject
     */
    private MockObject $qtyLeftChecker;
    /**
     * @var AbstractStockqtyPlugin
     */
    private AbstractStockqtyPlugin $plugin;
    /**
     * @var AbstractStockqty&Stub
     */
    private Stub $subject;

    protected function setUp(): void
    {
        $stock = $this->createStub(StockInterface::class);
        $stock->method('getStockId')->willReturn(2);
        $stockByWebsite = $this->createStub(StockByWebsiteIdResolverInterface::class);
        $stockByWebsite->method('execute')->willReturn($stock);
        $typeAllowed = $this->createStub(IsSourceItemManagementAllowedForProductTypeInterface::class);
        $typeAllowed->method('execute')->willReturn(true);

        $this->getStockItemConfiguration = $this->createMock(GetStockItemConfigurationInterface::class);
        $this->getProductSalableQty = $this->createMock(GetProductSalableQtyInterface::class);
        $this->qtyLeftChecker = $this->createMock(IsSalableQtyAvailableForDisplaying::class);

        $this->plugin = new AbstractStockqtyPlugin(
            $stockByWebsite,
            $this->getStockItemConfiguration,
            $this->getProductSalableQty,
            $typeAllowed,
            $this->qtyLeftChecker
        );

        $store = $this->createStub(Store::class);
        $store->method('getWebsiteId')->willReturn(1);
        $product = $this->createStub(Product::class);
        $product->method('getTypeId')->willReturn('simple');
        $product->method('getSku')->willReturn('not-on-the-farm');
        $product->method('getStore')->willReturn($store);
        $this->subject = $this->createStub(AbstractStockqty::class);
        $this->subject->method('getProduct')->willReturn($product);
    }

    public function testHidesTheMessageForASkuNotAssignedToTheWebsiteStock(): void
    {
        $this->getStockItemConfiguration->expects($this->once())->method('execute')
            ->with('not-on-the-farm', 2)
            ->willThrowException(
                new SkuIsNotAssignedToStockException(__('The requested sku is not assigned to given stock.'))
            );
        $this->getProductSalableQty->expects($this->never())->method('execute');

        $this->assertFalse($this->plugin->aroundIsMsgVisible($this->subject, static fn () => true));
    }

    public function testStillShowsTheMessageForAnAssignedSkuWithLowStock(): void
    {
        $config = $this->createStub(StockItemConfigurationInterface::class);
        $config->method('isManageStock')->willReturn(true);
        $this->getStockItemConfiguration->method('execute')->willReturn($config);
        $this->getProductSalableQty->expects($this->once())->method('execute')->willReturn(2.0);
        $this->qtyLeftChecker->expects($this->once())->method('execute')->with(2.0)->willReturn(true);

        $this->assertTrue($this->plugin->aroundIsMsgVisible($this->subject, static fn () => false));
    }

    public function testOtherExceptionsStillPropagate(): void
    {
        $this->getStockItemConfiguration->method('execute')
            ->willThrowException(new LocalizedException(__('Something else went wrong.')));

        $this->expectException(LocalizedException::class);
        $this->plugin->aroundIsMsgVisible($this->subject, static fn () => true);
    }
}
