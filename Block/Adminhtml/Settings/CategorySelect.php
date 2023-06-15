<?php

namespace Tudn\SystemCategoriesField\Block\Adminhtml\Settings;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Catalog\Model\Category as CategoryModel;
use Magento\Catalog\Model\Locator\LocatorInterface;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\Categories;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\App\Cache\Type\Block;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\DB\Helper as DbHelper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Serialize\SerializerInterface;

class CategorySelect extends Field
{
    private LocatorInterface $locator;
    private SerializerInterface $serializer;
    private CategoryCollectionFactory $categoryCollectionFactory;
    private DbHelper $dbHelper;
    private CacheInterface $cache;
    private Session $session;
    private Json $jsonSerializer;

    /**
     * @param Context $context
     * @param LocatorInterface $locator
     * @param SerializerInterface $serializer
     * @param CategoryCollectionFactory $categoryCollectionFactory
     * @param DbHelper $dbHelper
     * @param CacheInterface $cache
     * @param Session $session
     * @param Json $jsonSerializer
     * @param array $data
     */
    public function __construct(
        Context             $context,
        LocatorInterface $locator,
        SerializerInterface $serializer,
        Json $jsonSerializer,
        CategoryCollectionFactory $categoryCollectionFactory,
        DbHelper $dbHelper,
        CacheInterface $cache,
        Session $session,
        array $data = []
    ) {
        $this->locator = $locator;
        $this->serializer = $serializer;
        $this->jsonSerializer = $jsonSerializer;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->dbHelper = $dbHelper;
        $this->cache = $cache;
        $this->session = $session;
        parent::__construct($context, $data);
    }

    /**
     * @param AbstractElement $element
     * @return string
     * @throws LocalizedException
     */
    public function _getElementHtml(AbstractElement $element)
    {
        $html = '<div class="hidden">';
        $html .= parent::_getElementHtml(($element));
        $html .= '</div>';

        $jsConfig = $this->jsonSerializer->serialize($this->getComponentJsConfig($element));

        $block = $this->getLayout()
            ->createBlock(Template::class)
            ->setTemplate('Tudn_SystemCategoriesField::category_select.phtml')
            ->setData('html_id', $element->getHtmlId())
            ->setJsConfig($jsConfig);

        $html .= $block->toHtml();
        return $html;
    }

    /**
     * @param AbstractElement $element
     * @return array
     * @throws LocalizedException
     */
    public function getComponentJsConfig($element)
    {
        $value = $element->getValue() ? explode(",", $element->getValue()) : [];

        return [
            'component' => 'Tudn_SystemCategoriesField/js/components/select-category',
            "template" => "ui/form/field",
            'elementTmpl' => 'ui/grid/filters/elements/ui-select',
            'dataType' => 'text',
            'formElement' => 'select',
            'label' => __('Categories'),
            'componentType' => 'field',
            'filterOptions' => true,
            'chipsEnabled' => true,
            'disableLabel' => true,
            "labelVisible" => false,
            "levelsVisibility" => "1",
            "elementSelectorId" => "#". $element->getHtmlId(),
            'options' => $this->getCategoriesTree(),
            "value" => $value ?? [],
        ];
    }

    /**
     * Retrieve categories tree
     *
     * @param string|null $filter
     * @return array
     * @throws LocalizedException
     */
    public function getCategoriesTree($filter = null)
    {
        $storeId = (int)$this->locator->getStore()->getId();

        $cachedCategoriesTree = $this->cache->load($this->getCategoriesTreeCacheId($storeId, (string) $filter));
        if (!empty($cachedCategoriesTree)) {
            return $this->serializer->unserialize($cachedCategoriesTree);
        }

        $categoriesTree = $this->retrieveCategoriesTree(
            $storeId,
            $this->retrieveShownCategoriesIds($storeId, (string) $filter)
        );

        $this->cache->save(
            $this->serializer->serialize($categoriesTree),
            $this->getCategoriesTreeCacheId($storeId, (string) $filter),
            [
                CategoryModel::CACHE_TAG,
                Block::CACHE_TAG
            ]
        );

        return $categoriesTree;
    }

    /**
     * Retrieve tree of categories with attributes.
     *
     * @param int $storeId
     * @param array $shownCategoriesIds
     * @return array|null
     * @throws LocalizedException
     */
    private function retrieveCategoriesTree(int $storeId, array $shownCategoriesIds) : ?array
    {
        $collection = $this->categoryCollectionFactory->create();

        $collection->addAttributeToFilter('entity_id', ['in' => array_keys($shownCategoriesIds)])
            ->addAttributeToSelect(['name', 'is_active', 'parent_id'])
            ->setStoreId($storeId);

        $categoryById = [
            CategoryModel::TREE_ROOT_ID => [
                'value' => CategoryModel::TREE_ROOT_ID,
                'optgroup' => null,
            ],
        ];

        foreach ($collection as $category) {
            foreach ([$category->getId(), $category->getParentId()] as $categoryId) {
                if (!isset($categoryById[$categoryId])) {
                    $categoryById[$categoryId] = ['value' => $categoryId];
                }
            }

            $categoryById[$category->getId()]['is_active'] = $category->getIsActive();
            $categoryById[$category->getId()]['label'] = $category->getName();
            $categoryById[$category->getId()]['__disableTmpl'] = true;
            $categoryById[$category->getParentId()]['optgroup'][] = &$categoryById[$category->getId()];
        }

        return $categoryById[CategoryModel::TREE_ROOT_ID]['optgroup'];
    }

    /**
     * Retrieve filtered list of categories id.
     *
     * @param int $storeId
     * @param string $filter
     * @return array
     * @throws LocalizedException
     */
    private function retrieveShownCategoriesIds(int $storeId, string $filter = '') : array
    {
        $matchingNamesCollection = $this->categoryCollectionFactory->create();
        if (!empty($filter)) {
            $matchingNamesCollection->addAttributeToFilter(
                'name',
                ['like' => $this->dbHelper->addLikeEscape($filter, ['position' => 'any'])]
            );
        }

        $matchingNamesCollection->addAttributeToSelect('path')
            ->addAttributeToFilter('entity_id', ['neq' => CategoryModel::TREE_ROOT_ID])
            ->setStoreId($storeId);

        $shownCategoriesIds = [];
        /** @var CategoryModel $category */
        foreach ($matchingNamesCollection as $category) {
            foreach (explode('/', $category->getPath()) as $parentId) {
                $shownCategoriesIds[$parentId] = 1;
            }
        }

        return $shownCategoriesIds;
    }

    /**
     * Get cache id for categories tree.
     *
     * @param int $storeId
     * @param string $filter
     * @return string
     */
    private function getCategoriesTreeCacheId(int $storeId, string $filter = ''): string
    {
        if ($this->session->getUser() !== null) {
            return Categories::CATEGORY_TREE_ID
                . '_' . (string)$storeId
                . '_' . $this->session->getUser()->getAclRole()
                . '_' . $filter;
        }
        return Categories::CATEGORY_TREE_ID
            . '_' . (string)$storeId
            . '_' . $filter;
    }
}
