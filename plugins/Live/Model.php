<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Live;

use Exception;
use Piwik\API\Request;
use Piwik\Common;
use Piwik\Config;
use Piwik\Container\StaticContainer;
use Piwik\Date;
use Piwik\Db;
use Piwik\DbHelper;
use Piwik\Period;
use Piwik\Period\Range;
use Piwik\Piwik;
use Piwik\Plugin\SettingsProvider;
use Piwik\Plugins\Live\Exception\MaxExecutionTimeExceededException;
use Piwik\Segment;
use Piwik\Site;
use Piwik\Updater\Migration\Db as DbMigration;

class Model
{
    /**
     * @internal for tests only
     */
    public $queryAndWhereSleepTestsOnly = false;

    /**
     * @param $idSite
     * @param $period
     * @param $date
     * @param $segment
     * @param $limit
     * @param $visitorId
     * @param $minTimestamp
     * @param $filterSortOrder
     * @param $checkforMoreEntries
     * @return array
     * @throws Exception
     */
    public function queryLogVisits($idSite, $period, $date, $segment, $offset, $limit, $visitorId, $minTimestamp, $filterSortOrder, $checkforMoreEntries = false)
    {
        // to check for more entries increase the limit by one, but cut off the last entry before returning the result
        if ((int)$limit > -1 && $checkforMoreEntries) {
            $limit++;
        }

        // If no other filter, only look at the last 24 hours of stats
        if (
            empty($visitorId)
            && empty($limit)
            && empty($offset)
            && empty($period)
            && empty($date)
        ) {
            $period = 'day';
            $date = 'yesterdaySameTime';
        }

        [$dateStart, $dateEnd] = $this->getStartAndEndDate($idSite, $period, $date);

        $queries = $this->splitDatesIntoMultipleQueries($dateStart, $dateEnd, $limit, $offset, $filterSortOrder);

        $foundVisits = array();

        foreach ($queries as $queryRange) {
            $updatedLimit = $limit;
            if (!empty($limit) && (int)$limit > -1) {
                $updatedLimit = $limit - count($foundVisits);
            }

            $updatedOffset = $offset;
            if (!empty($offset) && !empty($foundVisits)) {
                $updatedOffset = 0; // we've already skipped enough rows
            }

            $settings = new SettingsProvider(\Piwik\Plugin\Manager::getInstance());

            /*
             * for each site, determine if visitor logs or visitor profiles have
             * been disabled.
             */
            $siteIdsWithVisitorLogsDisabled = [];
            $siteIdsWithVisitorLogsEnabled = [];
            if (!is_array($idSite)) {
                $idSite = [intval($idSite)];
            }
            foreach ($idSite as $id) {
                $measurableSettings = $settings->getAllMeasurableSettings($id, null);
                $isVisitorLogDisabled = $measurableSettings["Live"]->getSetting('disable_visitor_log')->getValue();
                $isVisitorProfileDisabled = $measurableSettings["Live"]->getSetting('disable_visitor_profile')->getValue();

                if ($isVisitorLogDisabled || $isVisitorProfileDisabled) {
                    $siteIdsWithVisitorLogsDisabled[] = $id;
                } else {
                    $siteIdsWithVisitorLogsEnabled[] = $id;
                }
            }

            $visitsDisabled = [];
            $visitsEnabled = [];

            /*
             * for both sites with visitor logs/profiles enabled and disabled, retrieve 
             * the relevant data from the database.
             *
             * sites with visitor logs/profiles disabled only return a subset of
             * the columns that are returned for sites with logs enabled, in 
             * order to remove any PII. 
             *
             * sites with logs disabled have a more limited list of available
             * segments, and so are able to fail when that segment is retrieved.
             * In that scenario the data is considered not retrievable and so 
             * the exception is caught silently.
             */
            if (count($siteIdsWithVisitorLogsDisabled) > 0) {
                try {
                    [$sql, $bind] = $this->makeLogVisitsQueryStringNoVisitorLog($siteIdsWithVisitorLogsDisabled, $queryRange[0], $queryRange[1], $segment, $updatedOffset, $updatedLimit, $visitorId, $minTimestamp, $filterSortOrder);
                    $visitsDisabled = $this->executeLogVisitsQuery($sql, $bind, $segment, $dateStart, $dateEnd, $minTimestamp, $limit);
                } catch (Exception $e) {
                    // do nothing for now, in the future add some sort of message
                }
            }
            if (count($siteIdsWithVisitorLogsEnabled) > 0) {
                [$sql, $bind] = $this->makeLogVisitsQueryString($siteIdsWithVisitorLogsEnabled, $queryRange[0], $queryRange[1], $segment, $updatedOffset, $updatedLimit, $visitorId, $minTimestamp, $filterSortOrder);
                $visitsEnabled = $this->executeLogVisitsQuery($sql, $bind, $segment, $dateStart, $dateEnd, $minTimestamp, $limit);
            }

            /*
             * the data from both sites with visitor logs disabled and enabled 
             * is merged into a single visits[] array. 
             *
             * The data is sorted via the visit_last_action_time column.
             * if either the sites with logs disabled or enabled returns no 
             * data, then no sorting needs to occur.
             */
            $visits = [];
            if (count($visitsEnabled) > 0 && count($visitsDisabled) > 0) {
                // both types of visits are present

                $iDisabled = 0;
                $iEnabled = 0;

                while ($iDisabled < count($visitsDisabled) && $iEnabled < count($visitsEnabled)) {
                    if (strtotime($visitsDisabled[$iDisabled]["visit_last_action_time"]) >= strtotime($visitsEnabled[$iEnabled]["visit_last_action_time"])) {
                        $visits[] = $visitsDisabled[$iDisabled];
                        $iDisabled++;
                    } else {
                        $visits[] = $visitsEnabled[$iEnabled];
                        $iEnabled++;
                    }
                }

                if ($iDisabled < count($visitsDisabled)) {
                    $visits += array_slice($visitsDisabled, $iDisabled);
                }

                if ($iEnabled < count($visitsEnabled)) {
                    $visits += array_slice($visitsEnabled, $iEnabled);
                }
            } else {
                if (count($visitsDisabled) > 0) {
                    $visits += $visitsDisabled;
                }
                if (count($visitsEnabled) > 0) {
                    $visits += $visitsEnabled;
                }
            }

            if (!empty($offset) && empty($visits)) {
                // find out if there are any matches
                $updatedOffset = 0;
                [$sql, $bind] = $this->makeLogVisitsQueryString($idSite, $queryRange[0], $queryRange[1], $segment, $updatedOffset, $updatedLimit, $visitorId, $minTimestamp, $filterSortOrder);

                $visits = $this->executeLogVisitsQuery($sql, $bind, $segment, $dateStart, $dateEnd, $minTimestamp, $limit);
                if (!empty($visits)) {
                    // found out the number of visits that we skipped in this query
                    $offset = $offset - count($visits);
                }
                continue;
            }

            if (!empty($visits)) {
                $foundVisits = array_merge($foundVisits, $visits);
            }

            if ($limit > 0 && count($foundVisits) >= $limit) {
                if (count($foundVisits) > $limit) {
                    $foundVisits = array_slice($foundVisits, 0, $limit);
                }
                break;
            }
        }

        if ($checkforMoreEntries) {
            if (count($foundVisits) == $limit) {
                array_pop($foundVisits);
                return [$foundVisits, true];
            }

            return [$foundVisits, false];
        }

        return $foundVisits;
    }

    /**
     * Return the most recent date time of any visit for the given idSite
     * If period / date are provided the method return the most recent date time within that period
     *
     * @param $idSite
     * @param $period
     * @param $date
     * @return string
     * @throws Exception
     */
    public function getMostRecentVisitsDateTime($idSite, $period = null, $date = null): string
    {
        $readerDb = Db::getReader();

        [$where, $bind] = $this->getIdSitesWhereClause($idSite, Common::prefixTable('log_visit'));

        [$dateStart, $dateEnd] = $this->getStartAndEndDate($idSite, $period, $date);

        if (!empty($dateStart)) {
            $where .= ' AND visit_last_action_time >= ?';
            $bind[] = $dateStart;
        }

        if (!empty($dateEnd)) {
            $where .= ' AND visit_last_action_time <= ?';
            $bind[] = $dateEnd;
        }

        $dateTime = $readerDb->fetchOne(sprintf(
            'SELECT visit_last_action_time from %s WHERE %s ORDER BY visit_last_action_time DESC LIMIT 1',
            Common::prefixTable('log_visit'),
            $where
        ), $bind);

        return $dateTime ?: '';
    }

    private function executeLogVisitsQuery($sql, $bind, $segment, $dateStart, $dateEnd, $minTimestamp, $limit)
    {
        $readerDb = Db::getReader();
        try {
            $visits = $readerDb->fetchAll($sql, $bind);
        } catch (Exception $e) {
            $this->handleMaxExecutionTimeError($readerDb, $e, $segment, $dateStart, $dateEnd, $minTimestamp, $limit, ['sql' => $sql, 'bind' => $bind,]);
            throw $e;
        }
        return $visits;
    }

    /**
     * @param \Piwik\Tracker\Db|\Piwik\Db\AdapterInterface|\Piwik\Db $readerDb
     * @param Exception $e
     * @param $segment
     * @param $dateStart
     * @param $dateEnd
     * @param $minTimestamp
     * @param $limit
     * @param $parameters
     *
     * @throws MaxExecutionTimeExceededException
     */
    public static function handleMaxExecutionTimeError($readerDb, $e, $segment, $dateStart, $dateEnd, $minTimestamp, $limit, $parameters)
    {
        // we also need to check for the 'maximum statement execution time exceeded' text as the query might be
        // aborted at different stages and we can't really know all the possible codes at which it may be aborted etc
        $isMaxExecutionTimeError = $readerDb->isErrNo($e, DbMigration::ERROR_CODE_MAX_EXECUTION_TIME_EXCEEDED_QUERY_INTERRUPTED)
                                   || $readerDb->isErrNo($e, DbMigration::ERROR_CODE_MAX_EXECUTION_TIME_EXCEEDED_SORT_ABORTED)
                                   || $readerDb->isErrNo($e, DbMigration::ERROR_CODE_MAX_STATEMENT_TIME_EXCEEDED_QUERY_INTERRUPTED)
                                   || strpos($e->getMessage(), 'maximum statement execution time exceeded') !== false
                                   || strpos($e->getMessage(), 'max_statement_time exceeded') !== false;

        if (false === $isMaxExecutionTimeError) {
            return;
        }

        $message = '';

        if (self::isLookingAtMoreThanOneDay($dateStart, $dateEnd, $minTimestamp)) {
            $message .= ' ' . Piwik::translate('Live_QueryMaxExecutionTimeExceededReasonDateRange');
        }

        if (!empty($segment)) {
            $message .= ' ' . Piwik::translate('Live_QueryMaxExecutionTimeExceededReasonSegment');
        }

        $limitThatCannotBeSelectedInUiButOnlyApi = 550;
        if ($limit > $limitThatCannotBeSelectedInUiButOnlyApi) {
            $message .= ' ' . Piwik::translate('Live_QueryMaxExecutionTimeExceededLimit');
        }

        if (empty($message)) {
            $message .= ' ' . Piwik::translate('Live_QueryMaxExecutionTimeExceededReasonUnknown');
        }

        $message = Piwik::translate('Live_QueryMaxExecutionTimeExceeded') . ' ' . $message;

        $params = array_merge($parameters, [
            'segment' => $segment, 'limit' => $limit
        ]);

        /**
         * @ignore
         * @internal
         */
        Piwik::postEvent('Live.queryMaxExecutionTimeExceeded', array($params));
        throw new MaxExecutionTimeExceededException($message);
    }

    /**
     * @param Date|null $dateStart
     * @param Date|null $dateEnd
     * @param int|null $minTimestamp
     * @return bool
     * @throws Exception
     */
    public static function isLookingAtMoreThanOneDay($dateStart, $dateEnd, $minTimestamp)
    {
        if (!$dateStart) {
            if (!$minTimestamp) {
                return true;
            } else {
                $dateStart = Date::factory($minTimestamp);
            }
        }

        if (!$dateEnd) {
            $dateEnd = Date::now();
        }

        if ($dateEnd->subHour(36)->isEarlier($dateStart)) {
            return false;
        }

        return true;
    }

    public function splitDatesIntoMultipleQueries($dateStart, $dateEnd, $limit, $offset, $filterSortOrder)
    {
        $virtualDateEnd = $dateEnd;
        if (empty($dateEnd)) {
            $virtualDateEnd = Date::now()->addDay(1); // matomo always adds one day for some reason
        }

        $virtualDateStart = $dateStart;
        if (empty($virtualDateStart)) {
            $virtualDateStart = Date::factory(Date::FIRST_WEBSITE_TIMESTAMP);
        }

        $queries = [];
        $hasStartEndDateMoreThanOneDayInBetween = $virtualDateStart && $virtualDateStart->addDay(1)->isEarlier($virtualDateEnd);
        if (
            $limit
            && $hasStartEndDateMoreThanOneDayInBetween
        ) {
            if (strtolower($filterSortOrder) !== 'asc') {
                $virtualDateEnd = $virtualDateEnd->subDay(1);
                $queries[]      = [$virtualDateEnd, $dateEnd]; // need to use ",endDate" in case endDate is not set

                if ($virtualDateStart->addDay(7)->isEarlier($virtualDateEnd)) {
                    $queries[]      = [$virtualDateEnd->subDay(7), $virtualDateEnd->subSeconds(1)];
                    $virtualDateEnd = $virtualDateEnd->subDay(7);
                }

                if (!$offset) {
                    // only when no offset
                    // we would in worst case - if not enough visits are found to bypass the offset - execute below queries too often.
                    // like we would need to execute each of the queries twice just to find out if there are some visits that
                    // need to be skipped...

                    if ($virtualDateStart->addDay(30)->isEarlier($virtualDateEnd)) {
                        $queries[]      = [$virtualDateEnd->subDay(30), $virtualDateEnd->subSeconds(1)];
                        $virtualDateEnd = $virtualDateEnd->subDay(30);
                    }
                    if ($virtualDateStart->addPeriod(1, 'year')->isEarlier($virtualDateEnd)) {
                        $queries[]      = [$virtualDateEnd->subYear(1), $virtualDateEnd->subSeconds(1)];
                        $virtualDateEnd = $virtualDateEnd->subYear(1);
                    }
                }

                if ($virtualDateStart->isEarlier($virtualDateEnd)) {
                    // need to use ",endDate" in case startDate is not set in which case we do not want to have any limit
                    $queries[] = [$dateStart, $virtualDateEnd->subSeconds(1)];
                }
            } else {
                $queries[]      = [$virtualDateStart, $virtualDateStart->addDay(1)->subSeconds(1)];
                $virtualDateStart = $virtualDateStart->addDay(1);

                if ($virtualDateStart->addDay(7)->isEarlier($virtualDateEnd)) {
                    $queries[]      = [$virtualDateStart, $virtualDateStart->addDay(7)->subSeconds(1)];
                    $virtualDateStart = $virtualDateStart->addDay(7);
                }

                if (!$offset) {
                    // only when no offset
                    // we would in worst case - if not enough visits are found to bypass the offset - execute below queries too often.
                    // like we would need to execute each of the queries twice just to find out if there are some visits that
                    // need to be skipped...

                    if ($virtualDateStart->addDay(30)->isEarlier($virtualDateEnd)) {
                        $queries[]      = [$virtualDateStart, $virtualDateStart->addDay(30)->subSeconds(1)];
                        $virtualDateStart = $virtualDateStart->addDay(30);
                    }
                    if ($virtualDateStart->addPeriod(1, 'year')->isEarlier($virtualDateEnd)) {
                        $queries[]      = [$virtualDateStart, $virtualDateStart->addPeriod(1, 'year')->subSeconds(1)];
                        $virtualDateStart = $virtualDateStart->addPeriod(1, 'year');
                    }
                }

                if ($virtualDateStart->isEarlier($virtualDateEnd)) {
                    // need to use ",endDate" in case startDate is not set in which case we do not want to have any limit
                    $queries[] = [$virtualDateStart, $dateEnd];
                }
            }
        } else {
            $queries[] = array($dateStart, $dateEnd);
        }
        return $queries;
    }

    /**
     * @param $idSite
     * @param $lastMinutes
     * @param $segment
     * @return int
     * @throws Exception
     */
    public function getNumActions($idSite, $lastMinutes, $segment)
    {
        return $this->getLastMinutesCounterForQuery(
            $idSite,
            $lastMinutes,
            $segment,
            'COUNT(*)',
            'log_link_visit_action',
            'log_link_visit_action.server_time >= ?'
        );
    }

    /**
     * @param $idSite
     * @param $lastMinutes
     * @param $segment
     * @return int
     * @throws Exception
     */
    public function getNumVisitsConverted($idSite, $lastMinutes, $segment)
    {
        return $this->getLastMinutesCounterForQuery(
            $idSite,
            $lastMinutes,
            $segment,
            'COUNT(*)',
            'log_conversion',
            'log_conversion.server_time >= ?'
        );
    }

    /**
     * @param $idSite
     * @param $lastMinutes
     * @param $segment
     * @return int
     * @throws Exception
     */
    public function getNumVisits($idSite, $lastMinutes, $segment)
    {
        return $this->getLastMinutesCounterForQuery(
            $idSite,
            $lastMinutes,
            $segment,
            'COUNT(log_visit.visit_last_action_time)',
            'log_visit',
            'log_visit.visit_last_action_time >= ?'
        );
    }

    /**
     * @param $idSite
     * @param $lastMinutes
     * @param $segment
     * @return int
     * @throws Exception
     */
    public function getNumVisitors($idSite, $lastMinutes, $segment)
    {
        return $this->getLastMinutesCounterForQuery(
            $idSite,
            $lastMinutes,
            $segment,
            'COUNT(DISTINCT log_visit.idvisitor)',
            'log_visit',
            'log_visit.visit_last_action_time >= ?'
        );
    }

    private function shouldQuerySleepInTests()
    {
        return $this->queryAndWhereSleepTestsOnly && defined('PIWIK_TEST_MODE') && PIWIK_TEST_MODE;
    }

    private function getLastMinutesCounterForQuery($idSite, $lastMinutes, $segment, $select, $from, $where)
    {
        $lastMinutes = (int)$lastMinutes;

        if (empty($lastMinutes)) {
            return 0;
        }

        [$whereIdSites, $idSites] = $this->getIdSitesWhereClause($idSite, $from);

        $now = null;
        try {
            $now = StaticContainer::get('Tests.now');
        } catch (\Exception $ex) {
            // ignore
        }
        $now = $now ?: time();

        $bind   = $idSites;
        $startDate = Date::factory($now - $lastMinutes * 60);
        $bind[] = $startDate->toString('Y-m-d H:i:s');

        $where = $whereIdSites . "AND " . $where;
        if ($this->shouldQuerySleepInTests()) {
            $where = ' SLEEP(1)';
        }

        $segment = new Segment($segment, $idSite, $startDate, $endDate = null);
        $query   = $segment->getSelectQuery($select, $from, $where, $bind);

        if ($this->shouldQuerySleepInTests()) {
            $query['bind'] = [];
        }

        $query['sql'] = trim($query['sql']);
        if (0 === stripos($query['sql'], 'SELECT')) {
            $query['sql'] = 'SELECT /* Live.getCounters */' . mb_substr($query['sql'], strlen('SELECT'));
        }

        $query['sql'] = DbHelper::addMaxExecutionTimeHintToQuery($query['sql'], $this->getLiveQueryMaxExecutionTime());

        $readerDb = Db::getReader();
        try {
            $numVisitors = $readerDb->fetchOne($query['sql'], $query['bind']);
        } catch (Exception $e) {
            $this->handleMaxExecutionTimeError($readerDb, $e, $segment->getOriginalString(), $startDate, Date::now(), null, 0, $query);
            throw $e;
        }

        return $numVisitors;
    }

    /**
     * @param $idSite
     * @param string $table
     * @return array
     */
    private function getIdSitesWhereClause($idSite, $table = 'log_visit')
    {
        if (is_array($idSite)) {
            $idSites = $idSite;
        } else {
            $idSites = array($idSite);
        }

        Piwik::postEvent('Live.API.getIdSitesString', array(&$idSites));

        $idSitesBind = Common::getSqlStringFieldsArray($idSites);
        $whereClause = $table . ".idsite in ($idSitesBind) ";
        return array($whereClause, $idSites);
    }


    /**
     * Returns the ID of a visitor that is adjacent to another visitor (by time of last action)
     * in the log_visit table.
     *
     * @param int $idSite The ID of the site whose visits should be looked at.
     * @param string $visitorId The ID of the visitor to get an adjacent visitor for.
     * @param string $visitLastActionTime The last action time of the latest visit for $visitorId.
     * @param string $segment
     * @param bool $getNext Whether to retrieve the next visitor or the previous visitor. The next
     *                      visitor will be the visitor that appears chronologically later in the
     *                      log_visit table. The previous visitor will be the visitor that appears
     *                      earlier.
     * @return string The hex visitor ID.
     * @throws Exception
     */
    public function queryAdjacentVisitorId($idSite, $visitorId, $visitLastActionTime, $segment, $getNext)
    {
        if ($getNext) {
            $visitLastActionTimeCondition = "sub.visit_last_action_time <= ?";
            $orderByDir = "DESC";
        } else {
            $visitLastActionTimeCondition = "sub.visit_last_action_time >= ?";
            $orderByDir = "ASC";
        }

        $visitLastActionDate = Date::factory($visitLastActionTime);
        $dateOneDayAgo = $visitLastActionDate->subDay(1);
        $dateOneDayInFuture = $visitLastActionDate->addDay(1);

        $select = "log_visit.idvisitor, MAX(log_visit.visit_last_action_time) as visit_last_action_time";
        $from = "log_visit";
        $where = "log_visit.idsite = ? AND log_visit.idvisitor <> ? AND visit_last_action_time >= ? and visit_last_action_time <= ?";
        $whereBind = array($idSite, @Common::hex2bin($visitorId), $dateOneDayAgo->toString('Y-m-d H:i:s'), $dateOneDayInFuture->toString('Y-m-d H:i:s'));
        $orderBy = "MAX(log_visit.visit_last_action_time) $orderByDir";
        $groupBy = "log_visit.idvisitor";

        if ($this->shouldQuerySleepInTests()) {
            $where = ' SLEEP(1)';
            $visitLastActionTimeCondition = 'SLEEP(1)';
        }

        $segment = new Segment($segment, $idSite, $dateOneDayAgo, $dateOneDayInFuture);
        $queryInfo = $segment->getSelectQuery($select, $from, $where, $whereBind, $orderBy, $groupBy);

        $sql = "SELECT /* Live.queryAdjacentVisitorId */ sub.idvisitor, sub.visit_last_action_time FROM ({$queryInfo['sql']}) as sub
                 WHERE $visitLastActionTimeCondition
                 LIMIT 1";
        $bind = array_merge($queryInfo['bind'], array($visitLastActionTime));

        if ($this->shouldQuerySleepInTests()) {
            $bind = [];
        }

        $sql = DbHelper::addMaxExecutionTimeHintToQuery($sql, $this->getLiveQueryMaxExecutionTime());

        $readerDb = Db::getReader();
        try {
            $visitorId = $readerDb->fetchOne($sql, $bind);
        } catch (Exception $e) {
            $this->handleMaxExecutionTimeError($readerDb, $e, $segment->getOriginalString(), Date::now(), Date::now(), null, 1, [
                'sql' => $sql, 'bind' => $bind
            ]);
            throw $e;
        }

        if (!empty($visitorId)) {
            $visitorId = bin2hex($visitorId);
        }
        return $visitorId;
    }

    /**
     * @param $idSite
     * @param Date $startDate
     * @param Date $endDate
     * @param $segment
     * @param int $offset
     * @param int $limit
     * @param $visitorId
     * @param $minTimestamp
     * @param $filterSortOrder
     * @return array
     * @throws Exception
     */
    public function makeLogVisitsQueryString($idSite, $startDate, $endDate, $segment, $offset, $limit, $visitorId, $minTimestamp, $filterSortOrder)
    {
        [$whereClause, $bindIdSites] = $this->getIdSitesWhereClause($idSite);

        [$whereBind, $where] = $this->getWhereClauseAndBind($whereClause, $bindIdSites, $startDate, $endDate, $visitorId, $minTimestamp);

        if (strtolower($filterSortOrder) !== 'asc') {
            $filterSortOrder = 'DESC';
        }

        $segment = new Segment($segment, $idSite, $startDate, $endDate);

        // Subquery to use the indexes for ORDER BY
        $select = "log_visit.*";
        $from = "log_visit";

        $limit = $limit >= 1 ? (int)$limit : 0;
        $offset = $offset >= 1 ? (int)$offset : 0;

        $orderBy = '';
        if (count($bindIdSites) <= 1) {
            $orderBy = 'log_visit.idsite ' . $filterSortOrder . ', ';
        }

        $orderBy .= "log_visit.visit_last_action_time " . $filterSortOrder;

        $orderBy .= ", log_visit.idvisit " . $filterSortOrder;

        if ($segment->isEmpty()) {
            $groupBy = false;
        } else {
            // see https://github.com/matomo-org/matomo/issues/13861
            $groupBy = 'log_visit.idvisit';
        }

        $innerLimit = $limit;

        $innerQuery = $segment->getSelectQuery($select, $from, $where, $whereBind, $orderBy, $groupBy, $innerLimit, $offset, $forceGroupBy = true);

        $bind = $innerQuery['bind'];

        if (!$visitorId) {
            // for now let's not apply when looking for a specific visitor
            $innerQuery['sql'] = DbHelper::addMaxExecutionTimeHintToQuery(
                $innerQuery['sql'],
                $this->getLiveQueryMaxExecutionTime()
            );
        }

        return array($innerQuery['sql'], $bind);
    }

    /**
     * @param $idSite
     * @param Date $startDate
     * @param Date $endDate
     * @param $segment
     * @param int $offset
     * @param int $limit
     * @param $visitorId
     * @param $minTimestamp
     * @param $filterSortOrder
     * @return array
     * @throws Exception
     */
    public function makeLogVisitsQueryStringNoVisitorLog($idSite, $startDate, $endDate, $segment, $offset, $limit, $visitorId, $minTimestamp, $filterSortOrder)
    {
        [$whereClause, $bindIdSites] = $this->getIdSitesWhereClause($idSite);

        [$whereBind, $where] = $this->getWhereClauseAndBind($whereClause, $bindIdSites, $startDate, $endDate, $visitorId, $minTimestamp);

        if (strtolower($filterSortOrder) !== 'asc') {
            $filterSortOrder = 'DESC';
        }

        $segment = new Segment($segment, $idSite, $startDate, $endDate);

        // Subquery to use the indexes for ORDER BY
        /*
         * In order to remove PII from being retrieved, specific columns are
         * not included in this query.
         */
        $select = "idvisit,
            idsite,
            visit_last_action_time,
            config_id,
            profilable,
            visit_first_action_time,
            visit_goal_buyer,
            visit_goal_converted,
            visitor_returning,
            visitor_seconds_since_first,
            visitor_seconds_since_order,
            visitor_count_visits,
            visit_entry_idaction_name,
            visit_entry_idaction_url,
            visit_exit_idaction_name,
            visit_exit_idaction_url,
            visit_total_actions,
            visit_total_interactions,
            visit_total_searches,
            referer_keyword,
            referer_name,
            referer_type,
            referer_url,
            location_browser_lang,
            config_browser_engine,
            config_browser_name,
            config_browser_version,
            config_client_type,
            config_device_brand,
            config_device_model,
            config_device_type,
            config_os,
            config_os_version,
            visit_total_events,
            visitor_localtime,
            visitor_seconds_since_last,
            config_resolution,
            config_cookie,
            config_flash,
            config_java,
            config_pdf,
            config_quicktime,
            config_realplayer,
            config_silverlight,
            config_windowsmedia,
            visit_total_time,
            location_city,
            location_country,
            location_latitude,
            location_longitude,
            location_region,
            last_idlink_va,
            custom_dimension_1,
            custom_dimension_2,
            custom_dimension_3,
            custom_dimension_4,
            custom_dimension_5";
        //$select = "log_visit.idvisit, log_visit.idsite, log_visit.visit_last_action_time, log_visit.visit_first_action_time";
        $from = "log_visit";

        $limit = $limit >= 1 ? (int)$limit : 0;
        $offset = $offset >= 1 ? (int)$offset : 0;

        $orderBy = '';
        if (count($bindIdSites) <= 1) {
            $orderBy = 'log_visit.idsite ' . $filterSortOrder . ', ';
        }

        $orderBy .= "log_visit.visit_last_action_time " . $filterSortOrder;

        $orderBy .= ", log_visit.idvisit " . $filterSortOrder;

        if ($segment->isEmpty()) {
            $groupBy = false;
        } else {
            // see https://github.com/matomo-org/matomo/issues/13861
            $groupBy = 'log_visit.idvisit';
        }

        $innerLimit = $limit;

        $innerQuery = $segment->getSelectQuery($select, $from, $where, $whereBind, $orderBy, $groupBy, $innerLimit, $offset, $forceGroupBy = true);

        $bind = $innerQuery['bind'];

        if (!$visitorId) {
            // for now let's not apply when looking for a specific visitor
            $innerQuery['sql'] = DbHelper::addMaxExecutionTimeHintToQuery(
                $innerQuery['sql'],
                $this->getLiveQueryMaxExecutionTime()
            );
        }

        return array($innerQuery['sql'], $bind);
    }

    /**
     * @param $idSite
     * @return Site
     */
    protected function makeSite($idSite)
    {
        return new Site($idSite);
    }

    /**
     * for tests only
     * @param $idSite
     * @param $period
     * @param $date
     * @return Date[]
     * @throws Exception
     * @internal
     */
    public function getStartAndEndDate($idSite, $period, $date)
    {
        $dateStart = null;
        $dateEnd = null;

        if (!empty($period) && !empty($date)) {
            if ($idSite === 'all' || is_array($idSite)) {
                $currentTimezone = Request::processRequest('SitesManager.getDefaultTimezone');
            } else {
                $currentSite = $this->makeSite($idSite);
                $currentTimezone = $currentSite->getTimezone();
            }

            $dateString = $date;
            if ($period == 'range' || Period::isMultiplePeriod($date, $period)) {
                $processedPeriod = new Range('range', $date);
                if ($parsedDate = Range::parseDateRange($date)) {
                    $dateString = $parsedDate[2];
                }
            } else {
                $processedDate = Date::factory($date);
                if (
                    $date == 'today'
                    || $date == 'now'
                    || $processedDate->toString() == Date::factory('now', $currentTimezone)->toString()
                ) {
                    $processedDate = $processedDate->subDay(1);
                }
                $processedPeriod = Period\Factory::build($period, $processedDate);
            }

            $dateStart = $processedPeriod->getDateStart()->setTimezone($currentTimezone);

            $now = Date::now();
            if ($dateStart->isLater($now)) {
                $dateStart = $now;
            }

            if (
                !in_array($date, array('now', 'today', 'yesterdaySameTime'))
                && strpos($date, 'last') === false
                && strpos($date, 'previous') === false
                && Date::factory($dateString)->toString('Y-m-d') != Date::factory('now', $currentTimezone)->toString()
            ) {
                $dateEnd = $processedPeriod->getDateEnd()->setTimezone($currentTimezone);
                $dateEnd = $dateEnd->addDay(1);

                if ($dateEnd->isLater(Date::now())) {
                    // making sure dateEnd is bit higher than start date plus in case a second past by now ...
                    $dateEnd = $now->addPeriod(1, 'second');
                }
            }
        }

        return [$dateStart, $dateEnd];
    }

    /**
     * @param string $whereClause
     * @param array $bindIdSites
     * @param Date $startDate
     * @param Date $endDate
     * @param $visitorId
     * @param $minTimestamp
     * @return array
     * @throws Exception
     */
    private function getWhereClauseAndBind($whereClause, $bindIdSites, $startDate, $endDate, $visitorId, $minTimestamp)
    {
        $where = array();
        if (!empty($whereClause)) {
            $where[] = $whereClause;
        }
        $whereBind = $bindIdSites;

        if (!empty($visitorId)) {
            $where[] = "log_visit.idvisitor = ? ";
            $whereBind[] = @Common::hex2bin($visitorId);
        }

        if (!empty($minTimestamp)) {
            $where[] = "log_visit.visit_last_action_time > ? ";
            $whereBind[] = date("Y-m-d H:i:s", $minTimestamp);
        }

        // SQL Filter with provided period
        if (!empty($startDate)) {
            $where[] = "log_visit.visit_last_action_time >= ?";
            $whereBind[] = $startDate->toString('Y-m-d H:i:s');
        }

        if (!empty($endDate)) {
            $where[] = " log_visit.visit_last_action_time <= ?";
            $whereBind[] = $endDate->toString('Y-m-d H:i:s');
        }

        if (count($where) > 0) {
            $where = join("
				AND ", $where);
        } else {
            $where = false;
        }
        return array($whereBind, $where);
    }

    private function getLiveQueryMaxExecutionTime()
    {
        return Config::getInstance()->General['live_query_max_execution_time'];
    }
}
