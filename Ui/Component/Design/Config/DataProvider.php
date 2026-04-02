<?php
/**
 * @namespace   Blue
 * @module      CoreBugs
 * @author      bluefairydevelopment.com
 * @email       staff@bluefairydevelopment.com
 * @brief       Fix for Bug #1 — Design Config DataProvider ignores table prefix
 */
declare(strict_types=1);

namespace Blue\Ui\Component\Design\Config;

use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\ReportingInterface;
use Magento\Framework\Api\Search\SearchCriteriaBuilder;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use function Blue\CoreBugs\Ui\Component\Design\Config\__;

/**
 * Overrides core DataProvider to fix table prefix not being applied.
 *
 * The core class calls $connection->getTableName() which in Magento 2.4.x only
 * shortens index/trigger names — it does NOT prepend the table prefix.
 * The correct method is $resourceConnection->getTableName() on the ResourceConnection
 * object, which reads the prefix from env.php and prepends it.
 *
 * Because getCoreConfigData() is private in the parent, getData() must be fully
 * reimplemented here to substitute the corrected table name lookup.
 *
 * @see \Magento\Theme\Ui\Component\Design\Config\DataProvider
 */
class DataProvider extends \Magento\Theme\Ui\Component\Design\Config\DataProvider
{
    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resourceConnection;

    /**
     * @param string $name
     * @param string $primaryFieldName
     * @param string $requestFieldName
     * @param ReportingInterface $reporting
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param RequestInterface $request
     * @param FilterBuilder $filterBuilder
     * @param StoreManagerInterface $storeManager
     * @param array $meta
     * @param array $data
     * @param ResourceConnection|null $resourceConnection
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        ReportingInterface $reporting,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        RequestInterface $request,
        FilterBuilder $filterBuilder,
        StoreManagerInterface $storeManager,
        array $meta = [],
        array $data = [],
        ?ResourceConnection $resourceConnection = null
    ) {
        parent::__construct(
            $name,
            $primaryFieldName,
            $requestFieldName,
            $reporting,
            $searchCriteriaBuilder,
            $request,
            $filterBuilder,
            $storeManager,
            $meta,
            $data,
            $resourceConnection
        );

        $this->resourceConnection = $resourceConnection
            ?: ObjectManager::getInstance()->get(ResourceConnection::class);
    }

    /**
     * Get data.
     *
     * Reimplemented to replace the private getCoreConfigData() call in the parent
     * with a corrected version that uses $resourceConnection->getTableName() so
     * that the configured table prefix (e.g. mgh0_) is applied to the query.
     *
     * @return array
     */
    public function getData(): array
    {
        if ($this->storeManager->isSingleStoreMode()) {
            $websites = $this->storeManager->getWebsites();
            $singleStoreWebsite = array_shift($websites);

            $this->addFilter(
                $this->filterBuilder->setField('store_website_id')
                    ->setValue($singleStoreWebsite->getId())
                    ->create()
            );
            $this->addFilter(
                $this->filterBuilder->setField('store_group_id')
                    ->setConditionType('null')
                    ->create()
            );
        }

        $themeConfigData = $this->getCoreConfigDataFixed();
        $data = \Magento\Framework\View\Element\UiComponent\DataProvider\DataProvider::getData();

        foreach ($data['items'] as &$item) {
            $item += ['default' => __('Global')];

            $scope = ($item['store_id']) ? 'stores' : (($item['store_website_id']) ? 'websites' : 'default');
            $scopeId = (int) ($item['store_website_id'] ?? 0);
            $themeId = (int) ($item['theme_theme_id'] ?? 0);

            $criteria = ['scope' => $scope, 'scope_id' => $scopeId, 'value' => $themeId];
            $configData = array_filter($themeConfigData, function ($themeConfig) use ($criteria) {
                return array_intersect_assoc($criteria, $themeConfig) === $criteria;
            });

            $item += ['short_description' => !$configData ? __('Using Default Theme') : ''];
        }

        return $data;
    }

    /**
     * Get the core config data related to theme.
     *
     * Uses $resourceConnection->getTableName() which correctly prepends the table
     * prefix from env.php, unlike $connection->getTableName() which does not.
     *
     * @return array
     */
    private function getCoreConfigDataFixed(): array
    {
        $connection = $this->resourceConnection->getConnection();
        return $connection->fetchAll(
            $connection->select()
                ->from($this->resourceConnection->getTableName('core_config_data'))
                ->where('path = ?', 'design/theme/theme_id')
        );
    }
}
