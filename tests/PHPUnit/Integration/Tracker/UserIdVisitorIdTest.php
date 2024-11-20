<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace PHPUnit\Integration\Tracker;

use Piwik\Common;
use Piwik\Db;
use Piwik\Plugins\Goals\API as GoalsAPI;
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

    public function setUp(): void
    {
        parent::setUp();

        Fixture::createWebsite('2012-01-01 00:00:00');
    }

    private function trackPageview(\MatomoTracker $tracker, $url, $userId = null)
    {
        if (null !== $userId) {
            $tracker->setUserId($userId);
        }
        return $tracker->doTrackPageView($url);
    }

    private function trackAction(\MatomoTracker $tracker, $action, $userId = null)
    {
        if (null !== $userId) {
            $tracker->setUserId($userId);
        }
        return $tracker->doTrackAction($action, 'link');
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

        $response = $this->trackPageview($tracker,'page-1');
        Fixture::checkResponse($response);
        $this->assertVisitCount(1);
        $this->assertActionCount(1);
        $this->assertVisitorIdsCount(1);

        $response = $this->trackPageview($tracker,'page-2');
        Fixture::checkResponse($response);
        $this->assertVisitCount(1);
        $this->assertActionCount(2);
        $this->assertVisitorIdsCount(1);

        $response = $this->trackAction($tracker, 'action-1');
        Fixture::checkResponse($response);
        $this->assertVisitCount(1);
        $this->assertActionCount(3);
        $this->assertVisitorIdsCount(1);

        $tracker->setForceNewVisit();
        $response = $this->trackPageview($tracker, 'page-3');
        Fixture::checkResponse($response);
        $this->assertVisitCount(2);
        $this->assertActionCount(4);
        $this->assertVisitorIdsCount(1);

        $response = $this->trackAction($tracker, 'action-2');
        Fixture::checkResponse($response);
        $this->assertVisitCount(2);
        $this->assertActionCount(5);
        $this->assertVisitorIdsCount(1);
    }

    // user logs in during a visit, custom User ID is only provided for the log in action
    // user id replaces visitor id and there is still only one distinct value, but is different
    public function testUserLogsInDuringVisit()
    {
        $tracker = $this->getTracker();

        $response = $this->trackPageview($tracker,'page-1');
        Fixture::checkResponse($response);
        $this->assertVisitCount(1);
        $this->assertActionCount(1);
        $this->assertVisitorIdsCount(1);

        $response = $this->trackPageview($tracker,'page-2');
        Fixture::checkResponse($response);
        $this->assertVisitCount(1);
        $this->assertActionCount(2);
        $this->assertVisitorIdsCount(1);

        $response = $this->trackAction($tracker, 'action-1');
        Fixture::checkResponse($response);
        $this->assertVisitCount(1);
        $this->assertActionCount(3);
        $this->assertVisitorIdsCount(1);

        $visitorId1 = $this->getVisitProperty('idvisitor', 1);

        // track second action with user id
        $response = $this->trackAction($tracker, 'log-in', 'user 1');
        Fixture::checkResponse($response);
        $this->assertVisitCount(1);
        $this->assertActionCount(4);
        $this->assertVisitorIdsCount(1);

        // expect changed visitor id
        $visitorId2 = $this->getVisitProperty('idvisitor', 1);
        $this->assertNotEquals($visitorId1, $visitorId2);

        $response = $this->trackAction($tracker, 'action-2');
        Fixture::checkResponse($response);
        $this->assertVisitCount(1);
        $this->assertActionCount(5);
        $this->assertVisitorIdsCount(1);

        $response = $this->trackPageview($tracker, 'page-3');
        Fixture::checkResponse($response);
        $this->assertVisitCount(1);
        $this->assertActionCount(6);
        $this->assertVisitorIdsCount(1);
    }

    // user logs in during a visit, custom User ID is only provided for the log in action
    // user id replaces visitor id and there is still only one distinct value, but is different
    public function testUserLogsInAndOutDuringVisit()
    {
        $tracker = $this->getTracker();

        $response = $this->trackPageview($tracker,'page-1');
        Fixture::checkResponse($response);
        $this->assertVisitCount(1);
        $this->assertActionCount(1);
        $this->assertVisitorIdsCount(1);

        $response = $this->trackPageview($tracker,'page-2');
        Fixture::checkResponse($response);
        $this->assertVisitCount(1);
        $this->assertActionCount(2);
        $this->assertVisitorIdsCount(1);

        $response = $this->trackAction($tracker, 'action-1');
        Fixture::checkResponse($response);
        $this->assertVisitCount(1);
        $this->assertActionCount(3);
        $this->assertVisitorIdsCount(1);

        $visitorId1 = $this->getVisitProperty('idvisitor', 1);

        // track second action with user id
        $response = $this->trackAction($tracker, 'log-in', 'user 1');
        Fixture::checkResponse($response);
        $this->assertVisitCount(1);
        $this->assertActionCount(4);
        $this->assertVisitorIdsCount(1);

        // expect changed visitor id
        $visitorId2 = $this->getVisitProperty('idvisitor', 1);
        $this->assertNotEquals($visitorId1, $visitorId2);

        $response = $this->trackAction($tracker, 'action-2');
        Fixture::checkResponse($response);
        $this->assertVisitCount(1);
        $this->assertActionCount(5);
        $this->assertVisitorIdsCount(1);

        $response = $this->trackPageview($tracker, 'page-3');
        Fixture::checkResponse($response);
        $this->assertVisitCount(1);
        $this->assertActionCount(6);
        $this->assertVisitorIdsCount(1);

        // log out and de-set user id
        $response = $this->trackAction($tracker, 'log-out', false);
        Fixture::checkResponse($response);
        $this->assertVisitCount(1);
        $this->assertActionCount(7);
        $this->assertVisitorIdsCount(1);

        // expect original visitor id after logging out
        $visitorId3 = $this->getVisitProperty('idvisitor', 1);
        $this->assertEquals($visitorId1, $visitorId3);
        $this->assertNotEquals($visitorId2, $visitorId3);

        $response = $this->trackAction($tracker, 'action-3');
        Fixture::checkResponse($response);
        $this->assertVisitCount(1);
        $this->assertActionCount(8);
        $this->assertVisitorIdsCount(1);
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
