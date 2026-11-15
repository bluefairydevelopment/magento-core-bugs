<?php
/**
 * @namespace   BlueFairy
 * @module      CoreBugs
 * @brief       Tests for Bug #1 — the design config grid reads core_config_data through the table prefix
 */
declare(strict_types=1);

namespace BlueFairy\CoreBugs\Test\Unit\Ui\Component\Design\Config;

use BlueFairy\CoreBugs\Ui\Component\Design\Config\DataProvider;
use Magento\Framework\Api\AttributeValue;
use Magento\Framework\Api\Filter;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\Document;
use Magento\Framework\Api\Search\ReportingInterface;
use Magento\Framework\Api\Search\SearchCriteria;
use Magento\Framework\Api\Search\SearchCriteriaBuilder;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \BlueFairy\CoreBugs\Ui\Component\Design\Config\DataProvider
 */
class DataProviderTest extends TestCase
{
    /** @var array<int, array<string, mixed>> what the filter builder was given, one entry per filter */
    private array $filters = [];

    /** @var array<string, mixed> */
    private array $building = [];

    /** @var string[] */
    private array $tablesRead = [];

    /** @var string[] */
    private array $wheres = [];

    private bool $singleStore = false;

    /** @var array<int, array<string, mixed>> rows the grid search returns */
    private array $rows = [];

    /** @var array<int, array<string, mixed>> core_config_data rows for design/theme/theme_id */
    private array $themeConfig = [];

    private ?object $previousObjectManager = null;

    protected function setUp(): void
    {
        $property = new \ReflectionProperty(ObjectManager::class, '_instance');
        $this->previousObjectManager = $property->getValue();
    }

    protected function tearDown(): void
    {
        $property = new \ReflectionProperty(ObjectManager::class, '_instance');
        $property->setValue(null, $this->previousObjectManager);
    }

    private function resourceConnection(): ResourceConnection
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnCallback(function ($table) use ($select) {
            $this->tablesRead[] = $table;

            return $select;
        });
        $select->method('where')->willReturnCallback(function ($condition, $value = null) use ($select) {
            $this->wheres[] = $condition . ' ' . $value;

            return $select;
        });

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('getTableName')->willReturnArgument(0);
        $connection->method('fetchAll')->willReturnCallback(fn (): array => $this->themeConfig);

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnCallback(fn (string $name): string => 'mgh0_' . $name);

        return $resource;
    }

    private function provider(?ResourceConnection $resource): DataProvider
    {
        $filterBuilder = $this->createStub(FilterBuilder::class);
        foreach (['setField' => 'field', 'setValue' => 'value', 'setConditionType' => 'condition_type'] as $method => $key) {
            $filterBuilder->method($method)->willReturnCallback(function ($value) use ($filterBuilder, $key) {
                $this->building[$key] = $value;

                return $filterBuilder;
            });
        }
        $filterBuilder->method('create')->willReturnCallback(function (): Filter {
            $this->filters[] = $this->building;
            $this->building = [];

            return new Filter();
        });

        $website = $this->createStub(WebsiteInterface::class);
        $website->method('getId')->willReturn(4);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('isSingleStoreMode')->willReturnCallback(fn (): bool => $this->singleStore);
        $storeManager->method('getWebsites')->willReturn([4 => $website]);

        $criteria = $this->createStub(SearchCriteria::class);
        $criteriaBuilder = $this->createStub(SearchCriteriaBuilder::class);
        $criteriaBuilder->method('create')->willReturn($criteria);

        $reporting = $this->createStub(ReportingInterface::class);
        $reporting->method('search')->willReturnCallback(function (): SearchResultInterface {
            $documents = [];
            foreach ($this->rows as $row) {
                $attributes = [];
                foreach ($row as $code => $value) {
                    $attributes[] = new AttributeValue(['attribute_code' => $code, 'value' => $value]);
                }
                $documents[] = new Document(['custom_attributes' => $attributes]);
            }
            $result = $this->createStub(SearchResultInterface::class);
            $result->method('getItems')->willReturn($documents);
            $result->method('getTotalCount')->willReturn(count($documents));

            return $result;
        });

        return new DataProvider(
            'design_config_listing_data_source',
            'entity_id',
            'id',
            $reporting,
            $criteriaBuilder,
            $this->createStub(RequestInterface::class),
            $filterBuilder,
            $storeManager,
            [],
            [],
            $resource
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $storeId, int $websiteId, int $themeId): array
    {
        return ['store_id' => $storeId, 'store_website_id' => $websiteId, 'theme_theme_id' => $themeId];
    }

    public function testThemeConfigIsReadFromThePrefixedTable(): void
    {
        $this->rows = [$this->row(0, 0, 3)];

        $this->provider($this->resourceConnection())->getData();

        self::assertSame(['mgh0_core_config_data'], $this->tablesRead);
        self::assertSame(['path = ? design/theme/theme_id'], $this->wheres);
    }

    public function testEachRowIsLabelledWithItsScopeAndWhetherItUsesTheDefaultTheme(): void
    {
        $this->rows = [$this->row(0, 0, 3), $this->row(0, 1, 5), $this->row(2, 1, 6), $this->row(0, 0, 9)];
        $this->themeConfig = [
            ['scope' => 'default', 'scope_id' => '0', 'value' => '3', 'path' => 'design/theme/theme_id'],
            ['scope' => 'websites', 'scope_id' => '1', 'value' => '5', 'path' => 'design/theme/theme_id'],
            ['scope' => 'stores', 'scope_id' => '1', 'value' => '6', 'path' => 'design/theme/theme_id'],
        ];

        $data = $this->provider($this->resourceConnection())->getData();

        self::assertSame(4, $data['totalRecords']);
        $descriptions = array_map(fn (array $item): string => (string) $item['short_description'], $data['items']);
        self::assertSame(['', '', '', 'Using Default Theme'], $descriptions);
        foreach ($data['items'] as $item) {
            self::assertSame('Global', (string) $item['default']);
        }
    }

    public function testAStoreRowMatchesOnlyAStoresScopeEntry(): void
    {
        $this->rows = [$this->row(2, 1, 6)];
        $this->themeConfig = [['scope' => 'websites', 'scope_id' => '1', 'value' => '6']];

        $data = $this->provider($this->resourceConnection())->getData();

        self::assertSame('Using Default Theme', (string) $data['items'][0]['short_description']);
    }

    public function testMultiStoreModeAddsNoFilter(): void
    {
        $this->provider($this->resourceConnection())->getData();

        self::assertSame([], $this->filters);
    }

    public function testSingleStoreModeFiltersToItsWebsiteAndToRowsWithoutAStoreGroup(): void
    {
        $this->singleStore = true;

        $this->provider($this->resourceConnection())->getData();

        self::assertSame(
            [
                ['field' => 'store_website_id', 'value' => '4'],
                ['field' => 'store_group_id', 'condition_type' => 'null'],
            ],
            $this->filters
        );
    }

    public function testAnOmittedResourceConnectionComesFromTheObjectManager(): void
    {
        $resource = $this->resourceConnection();
        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturnCallback(
            fn (string $type) => $type === ResourceConnection::class ? $resource : new \stdClass()
        );
        ObjectManager::setInstance($objectManager);
        $this->rows = [$this->row(0, 0, 3)];

        $this->provider(null)->getData();

        self::assertSame(['mgh0_core_config_data'], $this->tablesRead);
    }
}
