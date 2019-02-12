<?php

namespace Entanet\Behat;

use PHPUnit\Framework\Assert;
use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\TableNode;
use Illuminate\Support\Facades\Log;
use Mockery;
use App;
use Superbalist\PubSub\Adapters\LocalPubSubAdapter;
use Exception;
use Superbalist\PubSub\PubSubAdapterInterface;
use ReflectionClass;

/**
 * Class KafkaContext
 * @package Entanet\Behat
 */
class KafkaContext implements Context
{
    /**
     * @var PubSubAdapterInterface
     */
    protected $adapter;

    /**
     * @var Keep hold of published events
     */
    public static $events;

    /**
     * @BeforeSuite
     */
    public static function prepare()
    {
        $mock = Mockery::mock(LocalPubSubAdapter::class)->makePartial();
        App::instance(PubSubAdapterInterface::class, $mock);
    }

    /**
     * @BeforeScenario
     */
    public function setUp()
    {
        $this->adapter = \Mockery::mock('PubSub');
        $this->setupFakeSubscribers();
    }

    /**
     * @When The following events are published to :topic
     */
    public function theFollowingEventsArePublishedTo($topic, TableNode $table)
    {
        foreach ($table as $row) {
            foreach ($row as $key => $value) {
                if (str_contains($key, '.')) {
                    $row = array_merge_recursive($row, $this->convertDotsToArray($key, $value));
                }
            }

            $this->adapter->publish($topic, $row);
        }
    }

    /**
     * @Then The following events should be published to :topic
     */
    public function theFollowingEventsShouldBePublishedTo($topic, TableNode $table)
    {
        // Get events that have been published
        $events = KafkaContext::$events;

        // Foreach expected published event
        foreach ($table as $row) {
            $found = false;

            $eventsPublished = $events[$topic];
            foreach ($eventsPublished as $key => $event) {
                if ($event == $row) {
                    $found = true;

                    // Count each row once
                    unset($events[$topic][$key]);
                }
            }

            // Event not found
            if ($found == false) {
                throw new Exception('Event not published - ' . $topic . ' : ' . json_encode($row));
            }
        }
    }

    /**
     * Helper for turning dots into array
     * @param $key
     * @param $value
     * @return array
     */
    private function convertDotsToArray($key, $value)
    {
        if (!str_contains($key, ".")) {
            return [
                $key => $value
            ];
        }

        $segments = explode(".", $key);
        $key = $segments[0];
        array_shift($segments);

        return [
            $key => $this->convertDotsToArray(implode(".", $segments), $value)
        ];
    }

    /**
     * Setup fake subscribers for events that come in
     * @throws \ReflectionException
     */
    private function setupFakeSubscribers()
    {
        // Reset events
        KafkaContext::$events = array();
        $mock = $this->adapter;

        // Make the subscribers property visible
        $reflect = new ReflectionClass($mock);
        $property = $reflect->getProperty('subscribers');
        $property->setAccessible(true);

        // Mock the get subscribers method to include a fake counter
        $mock->allows('getSubscribersForChannel')->andReturnUsing(
            function($topic) use ($property, $mock) {
                $subscribers = $property->getValue($mock);

                $existing = array();
                if (array_key_exists($topic, $subscribers)) {
                    $existing = $subscribers[$topic];
                }

                $fake = function($message) use ($topic) {
                    KafkaContext::$events[$topic][] = $message;
                };

                $existing[] = $fake;

                return $existing;
            }
        );
    }

    /**
     * @AfterScenario
     */
    public function tearDown()
    {

    }
}