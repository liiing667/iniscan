<?php

namespace Psecio\Iniscan;

class ScanTest extends \PHPUnit_Framework_TestCase
{
    private $scan;

    public function setUp()
    {
        $this->scan = new Scan();
    }
    public function tearDown()
    {
        unset($this->scan);
    }

    /**
     * Test that an exception is thrown when a bad file path
     *     is given
     *
     * @expectedException \InvalidArgumentException
     */
    public function testBadPath()
    {
        $this->scan->setPath('foobar');
    }

    /**
     * Test the getter/setter for context data
     */
    public function testGetSetContext()
    {
        $context = array('foo' => 'test');
        $this->scan->setContext($context);

        $this->assertEquals(
            $context,
            $this->scan->getContext()
        );
    }

    /**
     * Test the getter/setter for threshold value
     */
    public function testGetSetThreshold()
    {
        $threshold = 1234;
        $this->scan->setThreshold($threshold);

        $this->assertEquals(
            $threshold,
            $this->scan->getThreshold()
        );
    }

    /**
     * Test the getter/setter for version information
     */
    public function testGetSetVersion()
    {
        $version = 1;
        $this->scan->setVersion($version);

        $this->assertEquals(
            $version,
            $this->scan->getVersion()
        );
    }

    /**
     * Test the adding of a "marked" key name
     */
    public function testAddMarkedKey()
    {
        $this->scan->markKey('test');
        $this->assertTrue(in_array(
            'test',
            $this->scan->getMarked()
        ));
    }

    /**
     * Test the getter/setter for the configuration data
     */
    public function testGetSetConfig()
    {
        $config = array('foo' => 'bar');
        $this->scan->setConfig($config);

        $this->assertEquals(
            $config,
            $this->scan->getConfig()
        );
    }

    /**
     * Test the getter/setter for the strict mode flag
     */
    public function testGetSetStrict()
    {
        $this->assertFalse($this->scan->isStrict());
        $this->scan->setStrict(true);
        $this->assertTrue($this->scan->isStrict());
    }

    /**
     * Test that, in strict mode, settings missing from the scanned file
     *     are reported as "unknown" while settings present in the file
     *     are still evaluated
     */
    public function testStrictExecuteMarksMissingKeysUnknown()
    {
        $scan = new Scan(__DIR__.'/../../fixtures/strict.ini', array(), null, null, true);
        $results = $scan->execute();

        $statuses = array();
        foreach ($results as $rule) {
            $statuses[$rule->getTestKey()] = $rule->getStatus();
        }

        // settings present in the fixture are evaluated normally
        $this->assertSame(true, $statuses['expose_php']);
        $this->assertSame(false, $statuses['display_errors']);

        // settings missing from the fixture are "unknown"
        $this->assertSame(Rule::STATUS_UNKNOWN, $statuses['log_errors']);
        $this->assertSame(Rule::STATUS_UNKNOWN, $statuses['session.use_cookies']);
    }

    /**
     * Test that strict mode results do not depend on the runtime
     *     configuration of the scanning process
     */
    public function testStrictExecuteIgnoresRuntimeValues()
    {
        $original = ini_get('log_errors');

        ini_set('log_errors', '1');
        $scan = new Scan(__DIR__.'/../../fixtures/strict.ini', array(), null, null, true);
        $statusesOn = array();
        foreach ($scan->execute() as $rule) {
            $statusesOn[$rule->getTestKey()] = $rule->getStatus();
        }

        ini_set('log_errors', '0');
        $scan = new Scan(__DIR__.'/../../fixtures/strict.ini', array(), null, null, true);
        $statusesOff = array();
        foreach ($scan->execute() as $rule) {
            $statusesOff[$rule->getTestKey()] = $rule->getStatus();
        }

        ini_set('log_errors', $original);

        $this->assertSame(Rule::STATUS_UNKNOWN, $statusesOn['log_errors']);
        $this->assertSame(Rule::STATUS_UNKNOWN, $statusesOff['log_errors']);
        $this->assertSame($statusesOn, $statusesOff);
    }

    /**
     * Test that the default (non-strict) scan still falls back to the
     *     runtime configuration for settings missing from the file
     */
    public function testNonStrictExecuteFallsBackToRuntime()
    {
        $scan = new Scan(__DIR__.'/../../fixtures/strict.ini');
        $statuses = array();
        foreach ($scan->execute() as $rule) {
            $statuses[$rule->getTestKey()] = $rule->getStatus();
        }

        // missing from the fixture, but still evaluated (via ini_get fallback)
        $this->assertArrayHasKey('log_errors', $statuses);
        $this->assertNotSame(Rule::STATUS_UNKNOWN, $statuses['log_errors']);
        $this->assertInternalType('bool', $statuses['log_errors']);
    }
}
