<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\SaleFilter\Model\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private function config(array $values = [], array $flags = [], int $currentStore = 3): Config
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn(string $path) => $values[$path] ?? null
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn(string $path) => (bool) ($flags[$path] ?? false)
        );

        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn($currentStore);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new Config($scopeConfig, $storeManager);
    }

    public function testLabelsFallBackToDefaultsWhenEmpty(): void
    {
        $config = $this->config([Config::XML_PATH_FILTER_LABEL => '']);

        $this->assertSame('Sale Status', $config->getFilterLabel(1));
        $this->assertSame('On Sale', $config->getOnSaleOptionLabel(1));
        $this->assertSame('Regular Price', $config->getNotOnSaleOptionLabel(1));
    }

    public function testConfiguredLabelsAreReturned(): void
    {
        $config = $this->config([
            Config::XML_PATH_FILTER_LABEL             => 'Deals',
            Config::XML_PATH_OPTION_LABEL_ON_SALE     => 'Discounted',
            Config::XML_PATH_OPTION_LABEL_NOT_ON_SALE => 'Full Price',
        ]);

        $this->assertSame('Deals', $config->getFilterLabel(1));
        $this->assertSame('Discounted', $config->getOnSaleOptionLabel(1));
        $this->assertSame('Full Price', $config->getNotOnSaleOptionLabel(1));
    }

    public function testFlagsReflectScopeConfig(): void
    {
        $config = $this->config([], [
            Config::XML_PATH_ENABLED               => true,
            Config::XML_PATH_SHOW_COUNT            => true,
            Config::XML_PATH_INCLUDE_CATALOG_RULES => true,
        ]);

        $this->assertTrue($config->isEnabled(1));
        $this->assertTrue($config->isShowCount(1));
        $this->assertTrue($config->isIncludeCatalogRules(1));
        $this->assertFalse($config->isIncludeSpecialPrices(1));
        $this->assertFalse($config->isShowNotOnSaleOption(1));
    }

    public function testPositionDefaultsTo100AndCastsValues(): void
    {
        $this->assertSame(100, $this->config()->getPosition(1));
        $this->assertSame(100, $this->config([Config::XML_PATH_POSITION => ''])->getPosition(1));
        $this->assertSame(0, $this->config([Config::XML_PATH_POSITION => '0'])->getPosition(1));
        $this->assertSame(25, $this->config([Config::XML_PATH_POSITION => '25'])->getPosition(1));
    }

    public function testNullStoreIdResolvesCurrentStore(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->with(Config::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, 7)
            ->willReturn(true);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn('7');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $this->assertTrue((new Config($scopeConfig, $storeManager))->isEnabled());
    }

    public function testExplicitStoreIdSkipsStoreManager(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects($this->once())
            ->method('getValue')
            ->with(Config::XML_PATH_POSITION, ScopeInterface::SCOPE_STORE, 4)
            ->willReturn('12');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->expects($this->never())->method('getStore');

        $this->assertSame(12, (new Config($scopeConfig, $storeManager))->getPosition(4));
    }

    public static function filterValues(): array
    {
        return [
            'int one'        => [1, 1],
            'string one'     => ['1', 1],
            'padded one'     => [' 1 ', 1],
            'int zero'       => [0, 0],
            'string zero'    => ['0', 0],
            'bool true'      => [true, 1],
            'bool false'     => [false, null],
            'two'            => ['2', null],
            'word'           => ['yes', null],
            'empty'          => ['', null],
            'null'           => [null, null],
            'array'          => [['1'], null],
            'decimal string' => ['1.0', null],
        ];
    }

    #[DataProvider('filterValues')]
    public function testNormalizeFilterValue(mixed $raw, ?int $expected): void
    {
        $this->assertSame($expected, $this->config()->normalizeFilterValue($raw));
    }
}
