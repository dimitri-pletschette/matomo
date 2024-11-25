<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace PHPUnit\Integration\Tracker;

use Piwik\Common;
use Piwik\Config;
use Piwik\Date;
use Piwik\Db;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

class UserIdVisitorIdTest extends IntegrationTestCase
{
    public const FIRST_VISIT_TIME = '2012-01-05 00:00:00';
    public const TEST_USER_AGENT = 'Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2228.0 Safari/537.36';
    public const TEST_BROWSER_LANGUAGE = 'en-gb';
    public const TEST_COUNTRY = 'nl';
    public const TEST_REGION = '06';
    public const CHANGED_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_9_3) AppleWebKit/537.75.14 (KHTML, like Gecko) Version/7.0.3 Safari/7046A194A';
    public const CHANGED_BROWSER_LANGUAGE = 'ja';
    public const CHANGED_COUNTRY = 'jp';
    public const CHANGED_REGION = '22';

    private $trackerEventTsIterator;

    public function setUp(): void
    {
        parent::setUp();

        Fixture::createWebsite('2012-01-01 00:00:00');

        $this->trackerEventTsIterator = Date::factory(self::FIRST_VISIT_TIME)->getTimestamp();
    }

    private function trackPageview(\MatomoTracker $tracker, $url)
    {
        $tracker->setForceVisitDateTime($this->trackerEventTsIterator++);
        $response = $tracker->doTrackPageView($url);
        Fixture::checkResponse($response);
    }

    private function trackAction(\MatomoTracker $tracker, $action)
    {
        $tracker->setForceVisitDateTime($this->trackerEventTsIterator++);
        $response = $tracker->doTrackAction($action, 'link');
        Fixture::checkResponse($response);
    }

    private function logInUser(\MatomoTracker $tracker)
    {
        $tracker->setUserId('user-1');
    }

    private function logOutUser(\MatomoTracker $tracker)
    {
        $tracker->setUserId(false);
    }

    private function getTracker()
    {
        $tracker = Fixture::getTracker(1, self::FIRST_VISIT_TIME, $defaultInit = true, $useLocalTracker = true);
        $tracker->setTokenAuth(Fixture::getTokenAuth());

        // properties that cannot be changed on next action
        $tracker->setUserAgent(self::TEST_USER_AGENT);
        $tracker->setBrowserLanguage(self::TEST_BROWSER_LANGUAGE);

        // properties that can be changed on next action
        $tracker->setCountry(self::TEST_COUNTRY);
        $tracker->setRegion(self::TEST_REGION);

        return $tracker;
    }

    private function getTrackerForAlternateDevice()
    {
        $tracker = $this->getTracker();

        $tracker->setUserAgent(self::CHANGED_USER_AGENT);
        $tracker->setBrowserLanguage(self::CHANGED_BROWSER_LANGUAGE);
        $tracker->setCountry(self::CHANGED_COUNTRY);
        $tracker->setRegion(self::CHANGED_REGION);

        return $tracker;
    }

    // user does not log in during a visit, all actions are assigned to a single visitor ID
    public function testUserDoesNotLogInDuringVisit()
    {
        $tracker = $this->getTracker();

        $this->trackPageview($tracker, 'page-1');
        $this->assertCounts(1, 1, 1);

        $this->trackPageview($tracker, 'page-2');
        $this->assertCounts(1, 2, 1);

        $this->trackAction($tracker, 'action-1');
        $this->assertCounts(1, 3, 1);

        $tracker->setForceNewVisit();
        $this->trackPageview($tracker, 'page-3');
        $this->assertCounts(2, 4, 1);

        $this->trackAction($tracker, 'action-2');
        $this->assertCounts(2, 5, 1);
    }

    // user logs in during a visit, custom User ID is only provided for the log in action
    // user id replaces visitor id and there is still only one distinct value, but is different
    public function testUserLogsInDuringVisit()
    {
        $tracker = $this->getTracker();

        $this->trackPageview($tracker, 'page-1');
        $this->assertCounts(1, 1, 1);

        $this->trackPageview($tracker, 'page-2');
        $this->assertCounts(1, 2, 1);

        $this->trackAction($tracker, 'action-1');
        $this->assertCounts(1, 3, 1);

        $visitorId1 = $this->getVisitProperty('idvisitor', 1);

        // track second action with user id
        $this->logInUser($tracker);
        $this->trackAction($tracker, 'log-in');
        $this->assertCounts(1, 4, 1);

        // expect changed visitor id
        $visitorId2 = $this->getVisitProperty('idvisitor', 1);
        $this->assertNotEquals($visitorId1, $visitorId2);

        $this->trackAction($tracker, 'action-2');
        $this->assertCounts(1, 5, 1);

        $this->trackPageview($tracker, 'page-3');
        $this->assertCounts(1, 6, 1);
    }

    public function testUserLogsInDuringVisitWithoutActions()
    {
        $tracker = $this->getTracker();

        $this->trackPageview($tracker, 'page-1');
        $this->assertCounts(1, 1, 1);

        $this->trackPageview($tracker, 'page-2');
        $this->assertCounts(1, 2, 1);

        $visitorId1 = $this->getVisitProperty('idvisitor', 1);

        // track second action with user id
        $this->logInUser($tracker);
        $this->trackPageview($tracker, 'page-3');
        $this->assertCounts(1, 3, 1);

        // expect changed visitor id
        $visitorId2 = $this->getVisitProperty('idvisitor', 1);
        $this->assertNotEquals($visitorId1, $visitorId2);

        $this->trackPageview($tracker, 'page-3');
        $this->assertCounts(1, 4, 1);
    }

    // user logs in during a visit, custom User ID is only provided for the log in action
    // user id replaces visitor id and there is still only one distinct value, but is different
    public function testUserLogsInAndOutDuringVisit()
    {
        $tracker = $this->getTracker();

        $this->trackPageview($tracker, 'page-1');
        $this->assertCounts(1, 1, 1);

        $this->trackPageview($tracker, 'page-2');
        $this->assertCounts(1, 2, 1);

        $this->trackAction($tracker, 'action-1');
        $this->assertCounts(1, 3, 1);

        $visitorId1 = $this->getVisitProperty('idvisitor', 1);

        // track second action with user id
        $this->logInUser($tracker);
        $this->trackAction($tracker, 'log-in');
        $this->assertCounts(1, 4, 1);

        // expect changed visitor id
        $visitorId2 = $this->getVisitProperty('idvisitor', 1);
        $this->assertNotEquals($visitorId1, $visitorId2);

        $this->trackAction($tracker, 'action-2');
        $this->assertCounts(1, 5, 1);

        $this->trackPageview($tracker, 'page-3');
        $this->assertCounts(1, 6, 1);

        // log out and de-set user id
        $this->logOutUser($tracker);
        $this->trackAction($tracker, 'log-out');
        $this->assertCounts(1, 7, 1);

        // expect original visitor id after logging out
        $visitorId3 = $this->getVisitProperty('idvisitor', 1);
        $this->assertEquals($visitorId1, $visitorId3);
        $this->assertNotEquals($visitorId2, $visitorId3);

        $this->trackAction($tracker, 'action-3');
        $this->assertCounts(1, 8, 1);
    }

    public function testUserLogsInAndOutDuringVisitWithoutActions()
    {
        $tracker = $this->getTracker();

        $this->trackPageview($tracker, 'page-1');
        $this->assertCounts(1, 1, 1);

        $this->trackPageview($tracker, 'page-2');
        $this->assertCounts(1, 2, 1);

        $visitorId1 = $this->getVisitProperty('idvisitor', 1);

        // track second action with user id
        $this->logInUser($tracker);
        $this->trackPageview($tracker, 'page-3');
        $this->assertCounts(1, 3, 1);

        // expect changed visitor id
        $visitorId2 = $this->getVisitProperty('idvisitor', 1);
        $this->assertNotEquals($visitorId1, $visitorId2);

        $this->trackPageview($tracker, 'page-4');
        $this->assertCounts(1, 4, 1);

        // log out and de-set user id
        $this->logOutUser($tracker);
        $this->trackPageview($tracker, 'page-5');
        $this->assertCounts(1, 5, 1);

        // expect original visitor id after logging out
        $visitorId3 = $this->getVisitProperty('idvisitor', 1);
        $this->assertEquals($visitorId1, $visitorId3);
        $this->assertNotEquals($visitorId2, $visitorId3);

        $this->trackPageview($tracker, 'page-6');
        $this->assertCounts(1, 6, 1);
    }

    public function testUserLoggedInOnMultipleDevices()
    {
        $trackerDevice1 = $this->getTracker();

        $this->trackPageview($trackerDevice1, 'page-1');
        $this->trackPageview($trackerDevice1, 'page-2');
        $this->trackAction($trackerDevice1, 'action-1');
        $this->logInUser($trackerDevice1);
        $this->trackAction($trackerDevice1, 'log-in');
        $this->assertCounts(1, 4, 1);

        $trackerDevice2 = $this->getTrackerForAlternateDevice();

        $this->trackPageview($trackerDevice2, 'page-3');
        $this->trackPageview($trackerDevice2, 'page-4');
        $this->trackAction($trackerDevice2, 'action-2');
        $this->logInUser($trackerDevice2);
        $this->trackAction($trackerDevice2, 'log-in');

        $this->assertCounts(2, 8, 2);
        $this->assertVisitorIdsCount(2); // TODO - discuss with product that new device forms a new visit

        // multiple devices, multiple config IDs
        $this->assertConfigIdsCount(2);
    }

    public function testUserLoggedInOnMultipleDevicesWithoutActions()
    {
        $trackerDevice1 = $this->getTracker();

        $this->trackPageview($trackerDevice1, 'page-1');
        $this->trackPageview($trackerDevice1, 'page-2');
        $this->logInUser($trackerDevice1);
        $this->trackPageview($trackerDevice1, 'page-3');
        $this->assertCounts(1, 3, 1);

        $trackerDevice2 = $this->getTrackerForAlternateDevice();

        $this->trackPageview($trackerDevice2, 'page-4');
        $this->trackPageview($trackerDevice2, 'page-5');
        $this->logInUser($trackerDevice2);
        $this->trackPageview($trackerDevice2, 'page-6');

        $this->assertCounts(2, 6, 2);
        $this->assertVisitorIdsCount(2); // TODO - discuss with product that new device forms a new visit

        // multiple devices, multiple config IDs
        $this->assertConfigIdsCount(2);
    }

    public function testUserLogsInAndOutMultipleTimes()
    {
        $tracker = $this->getTracker();

        $this->trackPageview($tracker, 'page-1');
        $this->trackPageview($tracker, 'page-2');
        $this->trackAction($tracker, 'action-1');
        $this->logInUser($tracker);
        $this->trackAction($tracker, 'log-in');
        $visitorId1 = $this->getVisitProperty('idvisitor', 1);

        $this->assertCounts(1, 4, 1);

        $this->trackAction($tracker, 'action-2');
        $this->trackPageview($tracker, 'page-3');

        $this->assertCounts(1, 6, 1);

        $this->logOutUser($tracker);
        $this->trackAction($tracker, 'log-out');
        $visitorId2 = $this->getVisitProperty('idvisitor', 1);

        $this->assertCounts(1, 7, 1, 1);

        // force new visit and log in
        $tracker = $this->getTracker();
        $tracker->setForceNewVisit();

        $this->trackAction($tracker, 'action-3');
        $this->trackPageview($tracker, 'page-4');
        $this->logInUser($tracker);
        $this->trackAction($tracker, 'log-in');
        $visitorId3 = $this->getVisitProperty('idvisitor', 2);

        $this->assertCounts(2, 10, 2);

        $this->trackAction($tracker, 'action-4');
        $this->trackPageview($tracker, 'page-5');

        $this->assertCounts(2, 12, 2);

        $this->logOutUser($tracker);
        $this->trackAction($tracker, 'log-out');
        $visitorId4 = $this->getVisitProperty('idvisitor', 2);
        $this->assertCounts(2, 13, 2);

        // TODO: check when there's no action after the log out action, the idvisitor is empty

        $this->assertEquals($visitorId1, $visitorId3);
        // since we forced a new visit, the visitor id after second log out is different
        $this->assertNotEquals($visitorId2, $visitorId4);

        $this->assertUserIdsCount(1);
    }

    public function testUserLogsInAndOutMultipleTimesWithoutActions()
    {
        $tracker = $this->getTracker();

        $this->trackPageview($tracker, 'page-1');
        $this->trackPageview($tracker, 'page-2');
        $this->logInUser($tracker);
        $this->trackPageview($tracker, 'page-3');
        $visitorId1 = $this->getVisitProperty('idvisitor', 1);

        $this->assertCounts(1, 3, 1);

        $this->trackPageview($tracker, 'page-4');

        $this->assertCounts(1, 4, 1);

        $this->logOutUser($tracker);
        $this->trackPageview($tracker, 'page-5');
        $visitorId2 = $this->getVisitProperty('idvisitor', 1);

        $this->assertCounts(1, 5, 1, 1);

        // force new visit and log in
        $tracker = $this->getTracker();
        $tracker->setForceNewVisit();

        $this->trackPageview($tracker, 'page-6');
        $this->logInUser($tracker);
        $this->trackPageview($tracker, 'page-7');
        $visitorId3 = $this->getVisitProperty('idvisitor', 2);

        $this->assertCounts(2, 7, 2);

        $this->trackPageview($tracker, 'page-5');
        $this->assertCounts(2, 8, 2);

        $this->logOutUser($tracker);
        $this->trackPageview($tracker, 'page-6');
        $visitorId4 = $this->getVisitProperty('idvisitor', 2);
        $this->assertCounts(2, 9, 2);

        $this->assertEquals($visitorId1, $visitorId3);
        // since we forced a new visit, the visitor id after second log out is different
        $this->assertNotEquals($visitorId2, $visitorId4);

        $this->assertUserIdsCount(1);
    }

    public function testNewVisitTriggeredByInactivity()
    {
        $tracker = $this->getTracker();

        $this->trackPageview($tracker, 'page-1');
        $this->trackPageview($tracker, 'page-2');
        $this->trackAction($tracker, 'action-1');
        $this->logInUser($tracker);
        $this->trackAction($tracker, 'log-in');
        $visitorId1 = $this->getVisitProperty('idvisitor', 1);

        // move time beyond default visit length
        $this->trackerEventTsIterator += Config::getInstance()->Tracker['visit_standard_length'] + 1;

        $this->trackPageview($tracker, 'page-3');
        $this->trackPageview($tracker, 'page-4');
        $this->trackAction($tracker, 'action-5');
        $visitorId2 = $this->getVisitProperty('idvisitor', 2);

        $this->assertCounts(2, 7, 1, 1, 1);
        $this->assertEquals($visitorId1, $visitorId2);

        // move time beyond default visit length
        $this->trackerEventTsIterator += Config::getInstance()->Tracker['visit_standard_length'] + 1;

        $this->trackPageview($tracker, 'page-5');

        $this->assertCounts(3, 8, 1, 1, 1);
    }

    public function testNewVisitTriggeredAtMidnight()
    {
        $tracker = $this->getTracker();

        $this->trackPageview($tracker, 'page-1');
        $this->trackAction($tracker, 'action-1');

        // move time by a day
        $this->trackerEventTsIterator += 24 * 60 * 60;

        $this->trackPageview($tracker, 'page-3');
        $this->trackAction($tracker, 'action-3');

        $this->assertCounts(2, 4, 1);
    }

    public function testNewVisitWhenCampaignChanges()
    {
        $tracker = $this->getTracker();

        $tracker->setUrl('http://www.example.com/?utm_campaign=first');
        $this->trackPageview($tracker, 'page-1');

        $tracker->setUrl('http://www.example.com/?utm_campaign=second');
        $this->trackPageview($tracker, 'page-2');

        $this->assertCounts(2, 2, 1);
    }

    private function assertCounts(int $visits, int $actions, int $visitorIds, int $userIds = null, int $configIds = null)
    {
        $this->assertVisitCount($visits);
        $this->assertActionCount($actions);
        $this->assertVisitorIdsCount($visitorIds);
        if (!is_null($userIds)) {
            $this->assertUserIdsCount($userIds);
        }
        if (!is_null($configIds)) {
            $this->assertConfigIdsCount($configIds);
        }
    }

    private function assertVisitCount($expected)
    {
        $visitCount = Db::fetchOne("SELECT COUNT(*) FROM " . Common::prefixTable('log_visit'));
        $this->assertEquals($expected, $visitCount);
    }

    private function assertActionCount($expected)
    {
        $visitCount = Db::fetchOne("SELECT COUNT(*) FROM " . Common::prefixTable('log_link_visit_action'));
        $this->assertEquals($expected, $visitCount);
    }

    private function assertVisitorIdsCount($expected)
    {
        $visitorIdsCount = Db::fetchOne("SELECT COUNT(DISTINCT idvisitor) FROM " . Common::prefixTable('log_visit'));
        $this->assertEquals($expected, $visitorIdsCount);
    }

    private function assertUserIdsCount($expected)
    {
        $visitorIdsCount = Db::fetchOne("SELECT COUNT(DISTINCT user_id) FROM " . Common::prefixTable('log_visit'));
        $this->assertEquals($expected, $visitorIdsCount);
    }

    private function assertConfigIdsCount($expected)
    {
        $visitorIdsCount = Db::fetchOne("SELECT COUNT(DISTINCT config_id) FROM " . Common::prefixTable('log_visit'));
        $this->assertEquals($expected, $visitorIdsCount);
    }

    private function getVisitProperty($columnName, $idVisit)
    {
        return Db::fetchOne("SELECT $columnName FROM " . Common::prefixTable('log_visit') . " WHERE idvisit = ?", array($idVisit));
    }

    protected static function configureFixture($fixture)
    {
        parent::configureFixture($fixture);
        $fixture->createSuperUser = true;
    }
}
