<?php
declare(strict_types=1);

namespace Yireo\GoogleTagManager2\Util;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Category\Collection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Yireo\GoogleTagManager2\Exception\NotUsingSetProductSkusException;

class CategoryProvider
{
    /**
     * @var array<int, int[]> Requested category ids, keyed by store id
     */
    private array $categoryIds = [];

    /**
     * @var array<int, CategoryInterface[]> Resolved categories, keyed by store id then category id
     */
    private array $loadedCategories = [];

    /**
     * @var array<int, array<int, bool>> Ids already looked up, resolved or not, keyed by store id
     */
    private array $attemptedCategoryIds = [];

    private CollectionFactory $categoryCollectionFactory;
    private StoreManagerInterface $storeManager;

    /**
     * @param CollectionFactory $categoryCollectionFactory
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        CollectionFactory $categoryCollectionFactory,
        StoreManagerInterface $storeManager
    ) {
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->storeManager = $storeManager;
    }

    /**
     * @param int[] $categoryIds
     * @return void
     * @throws NoSuchEntityException
     */
    public function addCategoryIds(array $categoryIds)
    {
        $categoryIds = $this->filterCategoryIds($categoryIds);
        if (empty($categoryIds)) {
            return;
        }

        $storeId = $this->getStoreId();
        $this->categoryIds[$storeId] = array_values(
            array_unique(array_merge($this->categoryIds[$storeId] ?? [], $categoryIds))
        );
    }

    /**
     * @param int $categoryId
     * @return CategoryInterface
     * @throws NoSuchEntityException
     */
    public function getById(int $categoryId): CategoryInterface
    {
        $categories = $this->getLoadedCategories();
        if (isset($categories[$categoryId])) {
            return $categories[$categoryId];
        }

        throw new NotUsingSetProductSkusException('Using getCategoryById() delivers no result');
    }

    /**
     * @return CategoryInterface[]
     * @throws NoSuchEntityException
     */
    public function getLoadedCategories(): array
    {
        $storeId = $this->getStoreId();
        if (empty($this->categoryIds[$storeId])) {
            throw new NotUsingSetProductSkusException('Using getCategories() before setCategoryIds()');
        }

        $loadCategoryIds = array_diff(
            $this->categoryIds[$storeId],
            array_keys($this->attemptedCategoryIds[$storeId] ?? [])
        );

        if (count($loadCategoryIds) > 0) {
            // Remember every id that was asked for, not only the ones that came back. An id that
            // resolves to nothing - a category outside this store's root, for instance - would
            // otherwise be looked up again for every following product.
            foreach ($loadCategoryIds as $categoryId) {
                $this->attemptedCategoryIds[$storeId][$categoryId] = true;
            }

            foreach ($this->loadCategoriesByIds($loadCategoryIds) as $category) {
                $this->loadedCategories[$storeId][(int)$category->getId()] = $category;
            }
        }

        return array_filter(
            $this->loadedCategories[$storeId] ?? [],
            static function (CategoryInterface $category) {
                return $category->getIsActive();
            }
        );
    }

    /**
     * @param ProductInterface $product
     * @return CategoryInterface
     * @throws NoSuchEntityException
     */
    public function getFirstByProduct(ProductInterface $product): CategoryInterface
    {
        /** @var Product $product */
        $productCategoryIds = $this->filterCategoryIds($product->getCategoryIds());
        if (empty($productCategoryIds)) {
            throw new NoSuchEntityException(__('Product "%1" has no categories', $product->getSku()));
        }

        $this->addCategoryIds($productCategoryIds);
        $categories = $this->getLoadedCategories();

        foreach ($productCategoryIds as $productCategoryId) {
            if (isset($categories[$productCategoryId])) {
                return $categories[$productCategoryId];
            }
        }

        throw new NoSuchEntityException(__('Product "%1" has no categories', $product->getSku()));
    }

    /**
     * @param ProductInterface $product
     * @return CategoryInterface[]
     * @throws NoSuchEntityException
     */
    public function getAllByProduct(ProductInterface $product): array
    {
        /** @var Product $product */
        $productCategoryIds = $this->filterCategoryIds($product->getCategoryIds());
        if (empty($productCategoryIds)) {
            throw new NoSuchEntityException(__('Product "%1" has no categories', $product->getSku()));
        }

        $this->addCategoryIds($productCategoryIds);
        $categories = $this->getLoadedCategories();

        // Ordered by the product's own category ids, so a product reports the same categories
        // wherever it is rendered. Ordering by the order the categories happened to be loaded in
        // makes the result depend on which other products were mapped earlier in the same request.
        $productCategories = [];
        foreach ($productCategoryIds as $productCategoryId) {
            if (isset($categories[$productCategoryId])) {
                $productCategories[$productCategoryId] = $categories[$productCategoryId];
            }
        }

        return $productCategories;
    }

    /**
     * Load every requested category in a single query, selecting only the attributes that the data
     * layer reads.
     *
     * @param int[] $categoryIds
     * @return CategoryInterface[]
     * @throws NoSuchEntityException
     */
    private function loadCategoriesByIds(array $categoryIds): array
    {
        /** @var Collection $collection */
        $collection = $this->categoryCollectionFactory->create();
        $collection->setStoreId($this->getStoreId());
        $collection->addAttributeToSelect(['name', 'is_active']);
        $collection->addIdFilter($categoryIds);
        $collection->addFieldToFilter('path', ['like' => '1/' . $this->getRootCategoryId() . '/%']);

        return $collection->getItems();
    }

    /**
     * Drop the root category and anything that is not a usable id, and normalise to unique integers.
     *
     * @param array $categoryIds
     * @return int[]
     * @throws NoSuchEntityException
     */
    private function filterCategoryIds(array $categoryIds): array
    {
        $rootCategoryId = $this->getRootCategoryId();

        $filteredCategoryIds = [];
        foreach ($categoryIds as $categoryId) {
            $categoryId = (int)$categoryId;
            if ($categoryId > 0 && $categoryId !== $rootCategoryId) {
                $filteredCategoryIds[] = $categoryId;
            }
        }

        return array_values(array_unique($filteredCategoryIds));
    }

    /**
     * @return int
     * @throws NoSuchEntityException
     */
    private function getRootCategoryId(): int
    {
        /** @var Store $store */
        $store = $this->storeManager->getStore();

        return (int)$store->getRootCategoryId();
    }

    /**
     * @return int
     * @throws NoSuchEntityException
     */
    private function getStoreId(): int
    {
        return (int)$this->storeManager->getStore()->getId();
    }
}
