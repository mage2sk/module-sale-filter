<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Test\Unit\Block\Adminhtml;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\UrlInterface;
use Panth\SaleFilter\Block\Adminhtml\Button\Reindex;
use Panth\SaleFilter\Block\Adminhtml\Help\Page;
use PHPUnit\Framework\TestCase;

class BlocksTest extends TestCase
{
    private ?ObjectManagerInterface $previousObjectManager = null;

    protected function setUp(): void
    {
        $property = new \ReflectionProperty(ObjectManager::class, '_instance');
        $this->previousObjectManager = $property->getValue();

        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturnCallback(fn(string $class) => $this->createStub($class));
        ObjectManager::setInstance($objectManager);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(ObjectManager::class, '_instance'))->setValue(null, $this->previousObjectManager);
    }

    public function testReindexButtonConfirmsAndPostsToReindexUrl(): void
    {
        $url = $this->createMock(UrlInterface::class);
        $url->expects($this->once())->method('getUrl')->with('panth_salefilter/index/reindex')
            ->willReturn('https://admin.test/panth_salefilter/index/reindex/');

        $data = (new Reindex($url))->getButtonData();

        $this->assertSame('Refresh Index', (string) $data['label']);
        $this->assertSame('primary', $data['class']);
        $this->assertSame(10, $data['sort_order']);
        $this->assertSame(
            "deleteConfirm('Rebuild the sale filter index now?', "
            . "'https://admin.test/panth_salefilter/index/reindex/', {\"data\": {}})",
            $data['on_click']
        );
    }

    private function page(array $modules): Page
    {
        $moduleList = $this->createStub(ModuleListInterface::class);
        $moduleList->method('getOne')->willReturnCallback(static fn($name) => $modules[$name] ?? null);
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn($route, $params = []) => '/' . $route . ($params ? '?' . http_build_query($params) : '')
        );
        $context = $this->createStub(Context::class);
        $context->method('getUrlBuilder')->willReturn($url);

        return new Page($context, $moduleList);
    }

    public function testModuleVersions(): void
    {
        $page = $this->page([
            'Panth_SaleFilter'     => ['setup_version' => '2.3.1'],
            'Panth_SaleFilterHyva' => ['name' => 'Panth_SaleFilterHyva'],
        ]);

        $this->assertSame('2.3.1', $page->getModuleVersion());
        $this->assertSame('1.0.x', $page->getHyvaModuleVersion());
    }

    public function testMissingModulesFallBack(): void
    {
        $page = $this->page([]);

        $this->assertSame('1.0.x', $page->getModuleVersion());
        $this->assertSame('not installed', $page->getHyvaModuleVersion());
    }

    public function testAdminUrls(): void
    {
        $page = $this->page([]);

        $this->assertSame('/adminhtml/system_config/edit?section=panth_salefilter', $page->getConfigUrl());
        $this->assertSame('/panth_salefilter/index/index', $page->getGridUrl());
        $this->assertSame('/panth_salefilter/index/reindex', $page->getReindexUrl());
        $this->assertSame('/catalog_rule/promo_catalog/index', $page->getCatalogRulesUrl());
        $this->assertSame('/indexer/indexer/list', $page->getIndexerUrl());
    }
}
