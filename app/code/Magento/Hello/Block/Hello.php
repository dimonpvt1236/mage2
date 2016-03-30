<?php
namespace Magento\Hello\Block;

use Magento\Framework\View\Element\Template;

class Hello extends Template
{
    /**
     * @var \Magento\Hello\Model\Test
     */
    protected $test;

    /**
     * Test factory
     *
     * @var \Magento\Hello\Model\TestFactory
     */
    protected $testFactory;

    /**
     * @var \Magento\Hello\Model\ResourceModel\Test\CollectionFactory
     */
    protected $itemCollectionFactory;

    /**
     * @var \Magento\Hello\Model\ResourceModel\Test\Collection
     */
    protected $items;

    /**
     * Test constructor.
     *
     * @param \Magento\Framework\View\Element\Template\Context $context
     * @param \Magento\Hello\Model\Test $test
     * @param \Magento\Hello\Model\TestFactory $testFactory
     * @param array $data
     */
    public function __construct(
        Template\Context $context,
        \Magento\Hello\Model\Test $test,
        \Magento\Hello\Model\TestFactory $testFactory,
        \Magento\Hello\Model\ResourceModel\Test\CollectionFactory $itemCollectionFactory,
        array $data = []
    ) {
        $this->test = $test;
        $this->testFactory = $testFactory;
        $this->itemCollectionFactory = $itemCollectionFactory;
        parent::__construct($context, $data);
    }

    /**
     * Retrieve Test instance
     *
     * @return \Magento\Hello\Model\Test
     */
    public function getTestModel()
    {
        if (!$this->hasData('test')) {
            if ($this->getTestId()) {
                /** @var \Magento\Hello\Model\Test $test */
                $test = $this->testFactory->create();
                $test->load($this->getTestId());
            } else {
                $test = $this->test;
            }
            $this->setData('test', $test);
        }
        return $this->getData('test');
    }

    /**
     * Get items
     *
     * @return bool|\Magento\Hello\Model\ResourceModel\Test\Collection
     */
    public function getItems()
    {
        if (!$this->items) {
            $this->items = $this->itemCollectionFactory->create()->addFieldToSelect(
                '*'
            )->addFieldToFilter(
                'is_active',
                ['eq' => \Magento\Hello\Model\Test::STATUS_ENABLED]
            )->setOrder(
                'creation_time',
                'desc'
            );
        }
        return $this->items;
    }

    /**
     * Get Test Id
     *
     * @return int
     */
    public function getTestId()
    {
        return 1;
    }

    /**
     * Return identifiers for produced content
     *
     * @return array
     */
    public function getIdentities()
    {
        return [\Magento\Hello\Model\Test::CACHE_TAG . '_' . $this->getTestModel()->getId()];
    }

    /**
     * Test function
     *
     * @return string
     */
    public function getTest()
    {
        return 'This is a test function for some logic...';
    }
}