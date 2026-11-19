<?php
/**
 * Standalone runner for PathSecurityTest.
 *
 * The project pins PHPUnit 4.2, which cannot execute on PHP 8 (the each()
 * function was removed). This script provides the minimal assertion surface
 * the test relies on so the fixed-directory fixture can be verified on any
 * PHP version without changing the project's pinned test framework.
 *
 * Usage: php tests/path_security_run.php
 */

require __DIR__.'/../vendor/autoload.php';

class PHPUnit_Framework_TestCase_Shim
{
    private $failures = array();

    public function assertSame($expected, $actual, $message = '')
    {
        if ($expected !== $actual) {
            $this->fail($message.' expected: '.var_export($expected, true)
                .', got: '.var_export($actual, true));
        }
    }

    public function assertNull($actual, $message = '')
    {
        if ($actual !== null) {
            $this->fail($message.' expected null, got: '.var_export($actual, true));
        }
    }

    public function assertTrue($actual, $message = '')
    {
        if ($actual !== true) {
            $this->fail($message);
        }
    }

    public function assertFalse($actual, $message = '')
    {
        if ($actual !== false) {
            $this->fail($message);
        }
    }

    public function assertInstanceOf($expected, $actual, $message = '')
    {
        if (!($actual instanceof $expected)) {
            $this->fail($message.' expected instance of '.$expected
                .', got: '.(is_object($actual) ? get_class($actual) : gettype($actual)));
        }
    }

    public function fail($message)
    {
        throw new RuntimeException($message);
    }
}

class_alias(
    'PHPUnit_Framework_TestCase_Shim',
    'PHPUnit_Framework_TestCase'
);

require __DIR__.'/Psecio/Iniscan/PathSecurityTest.php';

$test = new \Psecio\Iniscan\PathSecurityTest();
$methods = array();
$reflection = new ReflectionClass($test);
foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
    if (strpos($method->getName(), 'test') === 0) {
        $methods[] = $method->getName();
    }
}

$passed = 0;
$failed = 0;
foreach ($methods as $method) {
    try {
        $test->setUp();
        $test->$method();
        $test->tearDown();
        echo "[enter/skip/reject evidence] PASS  ".$method."\n";
        $passed++;
    } catch (Exception $exception) {
        $test->tearDown();
        echo "[enter/skip/reject evidence] FAIL  ".$method.": "
            .$exception->getMessage()."\n";
        $failed++;
    }
}

echo "\n".$passed.' passed, '.$failed.' failed'.( $failed > 0 ? "\n" : ' — all evidence verified'."\n");
exit($failed > 0 ? 1 : 0);
