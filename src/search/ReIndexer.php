<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\search;

use craft\console\Controller;
use craft\db\Query;
use craft\db\Table;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Console;
use craft\queue\Queue;
use yii\base\InvalidArgumentException;
use Craft;

/**
 * Reindexer class
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.2
 */
class ReIndexer
{
    // Properties
    // =========================================================================

    /**
     * @var
     */
    protected $elements;

    /**
     * @var Queue $queue
     */
    protected $queue;

    /**
     * @var Controller $consoleController
     */
    protected $consoleController;

    /**
     * @var int $elementCount
     */
    protected $elementCount;

    /**
     * @var int $completed
     */
    protected $completed;

    /**
     * @var
     */
    protected $typeSiteMapping;

    // Public Methods
    // =========================================================================

    /**
     * SearchQuery constructor.
     *
     * @param array $elements
     * @param Queue|null $queue
     * @param Controller|null $consoleController
     */
    public function __construct(array $elements, Queue $queue = null, Controller $consoleController = null)
    {
        $this->elements = $elements;
        $this->queue = $queue;
        $this->consoleController = $consoleController;
    }

    /**
     * Self invoker factory
     *
     * @param array $elements
     * @param Queue|null $queue
     * @param Controller|null $consoleController
     * @return bool
     * @throws \Throwable
     */
    public static function reIndexAllElements(array $elements, Queue $queue = null, Controller $consoleController = null)
    {
        return (new self($elements, $queue, $consoleController))->run();
    }

    /**
     * @return bool
     * @throws \Throwable
     */
    public function run()
    {
        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            $this->printMessage('Dropping search index');

            Craft::$app->getDb()->createCommand()
                ->truncateTable(Table::SEARCHINDEX)
                ->execute();

            $elementTypes = (new Query())
                ->select(['type'])
                ->from([Table::ELEMENTS])
                ->where([
                    'dateDeleted' => null,
                ])
                ->orderBy(['type' => SORT_ASC, 'id' => SORT_ASC])
                ->distinct(true)
                ->all();


            foreach ($elementTypes as $elementTypeData) {
                $type = $elementTypeData['type'];
                $this->printMessage("Reindexing elements belonging to $elementTypeData");

                $siteIds = $this->getTypeSiteIds($type);

                foreach ($siteIds as $siteId) {
                    $this->printMessage('Re indexing on site: ' . $siteId . '');

                    /* @var ElementQueryInterface $query */
                    $query = $type::find()
                        ->siteId($siteId)
                        ->anyStatus()
                        ->trashed(false);

                    foreach ($query->all() as $element) {
                        if (!\Craft::$app->getSearch()->indexElement($element, $siteId)) {
                            throw new InvalidArgumentException('Unable to index element');
                        }
                    }

                }
            }

            $transaction->commit();
        } catch (\Throwable $exception) {
            $transaction->rollBack();
            $this->printError($exception);

            throw $exception;
        }

        return true;
    }

    /**
     * @param string $message
     */
    public function printMessage(string $message)
    {
        if ($this->consoleController) {
            $this->consoleController->stdout($message);
        }
    }

    /**
     * @param string $type
     * @return string
     * @throws \craft\errors\SiteNotFoundException
     */
    public function getElementSiteIds(string $type) : string
    {
        if (isset($this->typeSiteMapping[$type])) {
            return $this->typeSiteMapping[$type];
        }

        if ($type::isLocalizable()) {
            $siteIds = Craft::$app->getSites()->getAllSiteIds();
        } else {
            $siteIds = [Craft::$app->getSites()->getPrimarySite()->id];
        }

        $this->typeSiteMapping[$type] = $siteIds;

        return $this->typeSiteMapping[$type];
    }

    /**
     * @param $done
     * @param $total
     */
    public function updateProgress($done, $total)
    {
        if ($this->consoleController) {
            Console::updateProgress($done, $total);
        }

        if ($this->queue) {
            $this->queue->setProgress($done, $total);
        }
    }
}
