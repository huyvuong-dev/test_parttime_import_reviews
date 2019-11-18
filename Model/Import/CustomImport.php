<?php

namespace Magenest\ImportReviews\Model\Import;

use Exception;
use Magento\Customer\Model\ResourceModel\Customer;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Json\Helper\Data as JsonHelper;
use Magento\ImportExport\Helper\Data as ImportHelper;
use Magento\ImportExport\Model\Import;
use Magento\ImportExport\Model\Import\Entity\AbstractEntity;
use Magento\ImportExport\Model\Import\ErrorProcessing\ProcessingErrorAggregatorInterface;
use Magento\ImportExport\Model\ResourceModel\Helper;
use Magento\ImportExport\Model\ResourceModel\Import\Data;

$GLOBALS['arrReviewId'] = array();

/**
 * Class Courses
 */
class CustomImport extends AbstractEntity
{
    const ENTITY_CODE = 'reviews';
    const TABLE = 'learning_courses';
    const ENTITY_ID_COLUMN = 'entity_id';
    const RATING_ID = 4;
    const APPEND = 'append';
    const REPLACE = 'replace';
    const DELETE = 'delete';
    protected $ratings;
    /**
     * If we should check column names
     */
    protected $needColumnCheck = true;

    /**
     * Need to log in import history
     */
    protected $logInHistory = true;

    /**
     * Permanent entity columns.
     */
    protected $_permanentAttributes = [
        'entity_id'
    ];

    /**
     * Valid column names
     */
    protected $validColumnNames = [
        'entity_id',
        'product_id',
        'customer_id',
        'status',
        'title',
        'detail',
        'nick_name',
        'rating',
        'created_at'
    ];

    /**
     * @var AdapterInterface
     */
    protected $connection;

    /**
     * @var ResourceConnection
     */
    private $resource;

    private $_reviewFactory;
    private $_reviewCollectionFactory;
    private $_ratingFactory;
    private $_productCollectionFactory;
    private $_storeManager;
    private $_customerCollectionFactory;

    /**
     * Courses constructor.
     *
     * @param JsonHelper $jsonHelper
     * @param ImportHelper $importExportData
     * @param Data $importData
     * @param ResourceConnection $resource
     * @param Helper $resourceHelper
     * @param ProcessingErrorAggregatorInterface $errorAggregator
     */
    public function __construct(
        JsonHelper $jsonHelper,
        ImportHelper $importExportData,
        Data $importData,
        ResourceConnection $resource,
        Helper $resourceHelper,
        ProcessingErrorAggregatorInterface $errorAggregator,
        \Magento\Review\Model\ReviewFactory $reviewFactory,
        \Magento\Review\Model\ResourceModel\Review\CollectionFactory $reviewCollectionFactory,
        \Magento\Review\Model\RatingFactory $ratingFactory,
        \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory $productCollectionFactory,
        \Magento\Customer\Model\ResourceModel\Customer\CollectionFactory $customerCollectionFactory,
        \Magento\Store\Model\StoreManagerInterface $storeManager

    )
    {
        $this->_customerCollectionFactory = $customerCollectionFactory;
        $this->_storeManager = $storeManager;
        $this->_reviewFactory = $reviewFactory;
        $this->_reviewCollectionFactory = $reviewCollectionFactory;
        $this->_ratingFactory = $ratingFactory;
        $this->_productCollectionFactory = $productCollectionFactory;
        $this->jsonHelper = $jsonHelper;
        $this->_importExportData = $importExportData;
        $this->_resourceHelper = $resourceHelper;
        $this->_dataSourceModel = $importData;
        $this->resource = $resource;
        $this->connection = $resource->getConnection(ResourceConnection::DEFAULT_CONNECTION);
        $this->errorAggregator = $errorAggregator;
    }

    /**
     * Entity type code getter.
     *
     * @return string
     */
    public function getEntityTypeCode()
    {
        return static::ENTITY_CODE;
    }

    /**
     * Get available columns
     *
     * @return array
     */
    public function getValidColumnNames(): array
    {
        return $this->validColumnNames;
    }

    public function myValidateData($date)
    {
        $format = 'Y-m-d';
        $d = \DateTime::createFromFormat($format, $date);
        // The Y ( 4 digits year ) returns TRUE for any integer with any number of digits so changing the comparison from == to === fixes the issue.
        return $d && $d->format($format) === $date;
    }

    function array_is_unique($array)
    {
        return array_unique($array) == $array;
    }

    /**
     * Row validation
     *
     * @param array $rowData
     * @param int $rowNum
     *
     * @return bool
     */
    public function validateRow(array $rowData, $rowNum): bool
    {
        $param = $this->getParameters();
        if ($param['behavior'] == self::DELETE) {
            $review_id = (int)$rowData['entity_id'] ?? 0;
            array_push($GLOBALS['arrReviewId'], $review_id);
            $uniqueId = $this->array_is_unique($GLOBALS['arrReviewId']);
            $ids = $this->_reviewCollectionFactory->create()->getAllIds();
            if (!$review_id || !in_array($review_id, $ids) || !$uniqueId) {
                $this->addRowError('IdIsRequired', $rowNum);
            }
        } else {
            $reviewIds = $this->_reviewCollectionFactory->create()->getAllIds();
            $productIds = $this->_productCollectionFactory->create()->getAllIds();
            $customerIds = $this->_customerCollectionFactory->create()->getAllIds();
            $col = $this->_productCollectionFactory->create();
            $arrConfigurable = array();
            foreach ($col as $data) {
                if ($data['type_id'] == 'configurable')
                    $arrConfigurable[] = $data['entity_id'];
            }
            //validate for Update csv
            if (isset($rowData['entity_id']) && $rowData['entity_id'] != 0) {
                $review_id = (int)$rowData['entity_id'] ?? 0;
                if (!$review_id || !in_array($review_id, $reviewIds)) {
                    $this->addRowError('ReviewIdNotExistIsRequired', $rowNum);
                }
            }
            //
            $product_id = (int)$rowData['product_id'] ?? 0;
            $customer_id = (int)$rowData['customer_id'] ?? 0;
            $status = (int)$rowData['status'] ?? 0;
            $title = $rowData['title'] ?? '';
            $detail = $rowData['detail'] ?? '';
            $nick_name = $rowData['nick_name'] ?? '';
            $rating = (int)$rowData['rating'] ?? 0;
            $created_at = $rowData['created_at'] ?? '';
            if (!$product_id || !in_array($product_id, $productIds)) {
                $this->addRowError('ProductIdNotExistIsRequired', $rowNum);
            }
            if (in_array($product_id, $arrConfigurable)) {
                $this->addRowError('ConfigurableProductIsRequired', $rowNum);
            }
            if (!in_array($customer_id, $customerIds) && $customer_id != 0) {
                $this->addRowError('CustomerIdNotExistIsRequired', $rowNum);
            }
            if ($status < 1 || $status > 3) {
                $this->addRowError('StatusIsRequired', $rowNum);
            }
            if (!$title) {
                $this->addRowError('TitleIsRequired', $rowNum);
            }
            if (!$detail) {
                $this->addRowError('DetailIsRequired', $rowNum);
            }
            if (!$nick_name) {
                $this->addRowError('NickNameIsRequired', $rowNum);
            }
            if ($rating < 1 || $rating > 5) {
                $this->addRowError('RatingIsRequired', $rowNum);
            }
            if ($created_at != "" && $this->myValidateData($created_at) != true) {
                // it's not a date
                $this->addRowError('CreatedAtIsRequired', $rowNum);
            }

        }
        if (isset($this->_validatedRows[$rowNum])) {
            return !$this->getErrorAggregator()->isRowInvalid($rowNum);
        }
        $this->_validatedRows[$rowNum] = true;

        return !$this->getErrorAggregator()->isRowInvalid($rowNum);
    }

    /**
     * Import data
     *
     * @return bool
     *
     * @throws Exception
     */
    protected function _importData(): bool
    {
        switch ($this->getBehavior()) {
            case Import::BEHAVIOR_DELETE:
                $this->deleteEntity();
                break;
            case Import::BEHAVIOR_REPLACE:
                $this->saveAndReplaceEntity();
                break;
            case Import::BEHAVIOR_APPEND:
                $this->saveAndReplaceEntity();
                break;
        }

        return true;
    }

    /**
     * Delete entities
     *
     * @return bool
     */
    private function deleteEntity(): bool
    {
        $rows = [];
        while ($bunch = $this->_dataSourceModel->getNextBunch()) {
            foreach ($bunch as $rowNum => $rowData) {
                $this->validateRow($rowData, $rowNum);

                if (!$this->getErrorAggregator()->isRowInvalid($rowNum)) {
                    $rowId = $rowData[static::ENTITY_ID_COLUMN];
                    $rows[] = $rowId;
                }

                if ($this->getErrorAggregator()->hasToBeTerminated()) {
                    $this->getErrorAggregator()->addRowToSkip($rowNum);
                }
            }
        }

        if ($rows) {
            return $this->deleteEntityFinish(array_unique($rows));
        }

        return false;
    }

    /**
     * Save and replace entities
     *
     * @return void
     */
    private function saveAndReplaceEntity()
    {
        $behavior = $this->getBehavior();
        $rows = [];
        while ($bunch = $this->_dataSourceModel->getNextBunch()) {
            $entityList = [];

            foreach ($bunch as $rowNum => $row) {
                if (!$this->validateRow($row, $rowNum)) {
                    continue;
                }

                if ($this->getErrorAggregator()->hasToBeTerminated()) {
                    $this->getErrorAggregator()->addRowToSkip($rowNum);

                    continue;
                }

                $rowId = $row[static::ENTITY_ID_COLUMN];
                $rows[] = $rowId;
                $columnValues = [];

                foreach ($this->getAvailableColumns() as $columnKey) {
                    $columnValues[$columnKey] = $row[$columnKey];
                }

                $entityList[$rowId][] = $columnValues;
                $this->countItemsCreated += (int)!isset($row[static::ENTITY_ID_COLUMN]);
                $this->countItemsUpdated += (int)isset($row[static::ENTITY_ID_COLUMN]);
            }

            if (Import::BEHAVIOR_REPLACE === $behavior) {
                if ($rows && $this->deleteEntityFinish(array_unique($rows))) {
                    $this->saveEntityFinish($entityList);
                }
            } elseif (Import::BEHAVIOR_APPEND === $behavior) {
                $this->saveEntityFinish($entityList);
            }
        }
    }

    protected function getRating($rating)
    {
        $ratingCollection = $this->_ratingFactory->create()->getResourceCollection();
        if (!$this->ratings[$rating]) {
            $this->ratings[$rating] = $ratingCollection->addFieldToFilter('rating_code', $rating)->getFirstItem();
        }
        return $this->ratings[$rating];
    }

    /**
     * Save entities
     *
     * @param array $entityData
     *
     * @return bool
     */
    private function saveEntityFinish(array $entityData): bool
    {
        if ($entityData) {
            foreach ($entityData as $entityRows) {
                foreach ($entityRows as $row) {
                    $review = $this->_reviewFactory->create();
                    $storeId = $this->_storeManager->getDefaultStoreView()->getStoreId();
                    if ($row['customer_id'] == 0)
                        $row['customer_id'] = null;
                    $review->setEntityId(
                        $review->getEntityIdByCode(\Magento\Review\Model\Review::ENTITY_PRODUCT_CODE)
                    )->setEntityPkValue(
                        $row['product_id']
                    )->setCustomerId(
                        $row['customer_id']
                    )->setNickname(
                        $row['nick_name']
                    )->setTitle(
                        $row['title']
                    )->setDetail(
                        $row['detail']
                    )->setStatusId(
                        $row['status']
                    )->setStoreId(
                        $storeId
                    )->setStores(
                        [$storeId]
                    )->save();

                    $rating = $this->_ratingFactory->create();

                    $rating->setRatingId(self::RATING_ID)
                        ->setReviewId($review->getId())
                        ->setCustomerId($row['customer_id'])
                        ->addOptionVote($row['rating'], $row['product_id']);


                    $review->aggregate();
                }
            }
            return true;
        }

        return false;
    }

    /**
     * Delete entities
     *
     * @param array $entityIds
     *
     * @return bool
     */
    private
    function deleteEntityFinish(array $entityIds): bool
    {
        if ($entityIds) {
            try {
                $collections = $this->_reviewCollectionFactory->create();
                foreach ($collections as $collection) {
                    if (in_array($collection->getId(), $entityIds)) {
                        $collection->delete();
                    }
                }

                return true;
            } catch (Exception $e) {
                return false;
            }
        }

        return false;
    }

    /**
     * Get available columns
     *
     * @return array
     */
    private
    function getAvailableColumns(): array
    {
        return $this->validColumnNames;
    }


}

?>