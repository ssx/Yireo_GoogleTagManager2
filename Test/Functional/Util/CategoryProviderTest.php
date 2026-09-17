<?php
declare(strict_types=1);

namespace Yireo\GoogleTagManager2\Test\Functional\Util;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\ObjectManager;
use PHPUnit\Framework\TestCase;
use Yireo\GoogleTagManager2\Exception\NotUsingSetProductSkusException;
use Yireo\GoogleTagManager2\Util\CategoryProvider;

class CategoryProviderTest extends TestCase
{
    public function testGetCategoriesWithException()
    {
        $this->expectException(NotUsingSetProductSkusException::class);
        $categoryProvider = ObjectManager::getInstance()->get(CategoryProvider::class);
        $categoryProvider->getLoadedCategories();
    }

    public function testGetById()
    {
        $categoryProvider = ObjectManager::getInstance()->get(CategoryProvider::class);
        $categoryProvider->addCategoryIds([11, 38]);
        $category = $categoryProvider->getById(11);
        $this->assertEquals(11, $category->getId());
    }

    public function testGetCategories()
    {
        $categoryProvider = ObjectManager::getInstance()->get(CategoryProvider::class);
        $categoryProvider->addCategoryIds([11, 38]);
        $categories = $categoryProvider->getLoadedCategories();
        $this->assertEquals(2, count($categories));

        $categoryProvider->addCategoryIds(['12', 14]);
        $categories = $categoryProvider->getLoadedCategories();
        $this->assertEquals(4, count($categories));

        $categoryProvider->addCategoryIds([12, 14]);
        $categories = $categoryProvider->getLoadedCategories();
        $this->assertEquals(4, count($categories));
    }

    public function testGetByProduct()
    {
        $productRepository = ObjectManager::getInstance()->get(ProductRepositoryInterface::class);
        $product = $productRepository->getById(1);

        $categoryProvider = ObjectManager::getInstance()->get(CategoryProvider::class);
        $categoryProvider->addCategoryIds([11, 38]);
        $categories = $categoryProvider->getAllByProduct($product);
        $this->assertEquals(2, count($categories));
    }

    public function testGetAllByProductFollowsTheOrderOfTheProductCategoryIds()
    {
        $productRepository = ObjectManager::getInstance()->get(ProductRepositoryInterface::class);
        $product = $productRepository->getById(1);

        $categoryProvider = ObjectManager::getInstance()->create(CategoryProvider::class);
        $categories = $categoryProvider->getAllByProduct($product);

        $productCategoryIds = array_map('intval', $product->getCategoryIds());
        $expectedOrder = array_values(array_intersect($productCategoryIds, array_keys($categories)));

        $this->assertSame($expectedOrder, array_keys($categories));
    }

    public function testGetAllByProductIsNotAffectedByEarlierLookups()
    {
        $productRepository = ObjectManager::getInstance()->get(ProductRepositoryInterface::class);
        $product = $productRepository->getById(1);

        $freshProvider = ObjectManager::getInstance()->create(CategoryProvider::class);
        $expected = $freshProvider->getAllByProduct($product);

        $usedProvider = ObjectManager::getInstance()->create(CategoryProvider::class);
        $usedProvider->addCategoryIds([11, 38, 12, 14]);
        $usedProvider->getLoadedCategories();
        $actual = $usedProvider->getAllByProduct($product);

        $this->assertSame(array_keys($expected), array_keys($actual));
    }
}
