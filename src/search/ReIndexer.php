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

    // Public Methods
    // =========================================================================

    /**
     * SearchQuery constructor.
     *
     * @param Queue|null $queue
     * @param Controller|null $consoleController
     */
    public function __construct(Queue $queue = null, Controller $consoleController = null)
    {
        $this->queue = $queue;
        $this->consoleController = $consoleController;
    }

    /**
     * Self invoker factory
     *
     * @param Queue|null $queue
     * @param Controller|null $consoleController
     * @return bool
     * @throws \Throwable
     */
    public static function reIndexAllElements(Queue $queue = null, Controller $consoleController = null)
    {
        return (new self($queue, $consoleController))->run();
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
                ->distinct(true)
                ->all();


            foreach ($elementTypes as $elementTypeData) {
                $type = $elementTypeData['type'];
                $this->printMessage("Reindexing elements belonging to $type");

                $siteIds = $this->getElementSiteIds($type);

                foreach ($siteIds as $siteId) {
                    $this->printMessage("Re indexing on site: $siteId");

                    /* @var ElementQueryInterface $query */
                    $query = $type::find()
                        ->siteId($siteId)
                        ->anyStatus()
                        ->trashed(false);

                    foreach ($query->all() as $element) {
                        $name = $element::hasTitles() ? $element->title : $element->id;
                        $this->printMessage("Indexing element $name");

                        if (!\Craft::$app->getSearch()->indexElement($element, $siteId)) {
                            throw new InvalidArgumentException('Unable to index element');
                        }
                    }
                }

                $this->printMessage("Completed indexing elements of type: $type");
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
        if ($type::isLocalizable()) {
            $siteIds = Craft::$app->getSites()->getAllSiteIds();
        } else {
            $siteIds = [Craft::$app->getSites()->getPrimarySite()->id];
        }

        return $siteIds;
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
