<?php
/**
 * Minimal PHPUnit 4.x compatibility shim.
 *
 * The pinned phpunit/phpunit 4.2 cannot boot on PHP 8 (removed each(),
 * $GLOBALS reference restrictions, ...). This shim implements just the
 * small PHPUnit_Framework_TestCase surface used by this repository's test
 * suite so the same test files keep running on modern PHP runtimes.
 *
 * It is a development helper only; production code does not depend on it.
 */

class PHPUnit_Framework_AssertionFailedError extends Exception
{
}

class PHPUnit_Framework_TestCase
{
	protected $backupGlobals = false;

	protected function setUp()
	{
	}

	protected function tearDown()
	{
	}

	public function assertTrue($condition, $message = '')
	{
		if ($condition !== true) {
			throw new PHPUnit_Framework_AssertionFailedError($message ?: 'Failed asserting that value is true.');
		}
	}

	public function assertFalse($condition, $message = '')
	{
		if ($condition !== false) {
			throw new PHPUnit_Framework_AssertionFailedError($message ?: 'Failed asserting that value is false.');
		}
	}

	public function assertNull($condition, $message = '')
	{
		if ($condition !== null) {
			throw new PHPUnit_Framework_AssertionFailedError($message ?: 'Failed asserting that value is null.');
		}
	}

	public function assertEmpty($condition, $message = '')
	{
		if (!empty($condition)) {
			throw new PHPUnit_Framework_AssertionFailedError($message ?: 'Failed asserting that value is empty.');
		}
}

	public function assertNotEmpty($condition, $message = '')
	{
		if (empty($condition)) {
			throw new PHPUnit_Framework_AssertionFailedError($message ?: 'Failed asserting that value is not empty.');
		}
	}

	public function assertEquals($expected, $actual, $message = '')
	{
		if ($expected != $actual) {
			throw new PHPUnit_Framework_AssertionFailedError(
				$message ?: 'Failed asserting equality. Expected: '.$this->export($expected).', got: '.$this->export($actual)
			);
		}
	}

	public function assertNotEquals($expected, $actual, $message = '')
	{
		if ($expected == $actual) {
			throw new PHPUnit_Framework_AssertionFailedError($message ?: 'Failed asserting inequality.');
		}
	}

	public function assertSame($expected, $actual, $message = '')
	{
		if ($expected !== $actual) {
			throw new PHPUnit_Framework_AssertionFailedError(
				$message ?: 'Failed asserting identity. Expected: '.$this->export($expected).', got: '.$this->export($actual)
			);
		}
	}

	public function assertNotSame($expected, $actual, $message = '')
	{
		if ($expected === $actual) {
			throw new PHPUnit_Framework_AssertionFailedError($message ?: 'Failed asserting non-identity.');
		}
	}

	public function assertNotTrue($condition, $message = '')
	{
		if ($condition === true) {
			throw new PHPUnit_Framework_AssertionFailedError($message ?: 'Failed asserting that value is not true.');
		}
	}

	public function assertContains($needle, $haystack, $message = '')
	{
		$found = is_array($haystack) ? in_array($needle, $haystack, false)
			: (is_string($haystack) ? strpos($haystack, $needle) !== false : false);
		if (!$found) {
			throw new PHPUnit_Framework_AssertionFailedError(
				$message ?: 'Failed asserting that '.$this->export($needle).' is contained.'
			);
		}
	}

	public function assertNotContains($needle, $haystack, $message = '')
	{
		$found = is_array($haystack) ? in_array($needle, $haystack, false)
			: (is_string($haystack) ? strpos($haystack, $needle) !== false : false);
		if ($found) {
			throw new PHPUnit_Framework_AssertionFailedError(
				$message ?: 'Failed asserting that '.$this->export($needle).' is not contained.'
			);
		}
	}

	public function assertCount($expected, $haystack, $message = '')
	{
		if (count($haystack) !== $expected) {
			throw new PHPUnit_Framework_AssertionFailedError(
				$message ?: 'Failed asserting count '.$expected.', got '.count($haystack)
			);
		}
	}

	public function assertInstanceOf($expected, $actual, $message = '')
	{
		if (!($actual instanceof $expected)) {
			throw new PHPUnit_Framework_AssertionFailedError(
				$message ?: 'Failed asserting instance of '.$expected
			);
		}
	}

	public function assertStringEndsWith($suffix, $string, $message = '')
	{
		if (substr($string, -strlen($suffix)) !== $suffix) {
			throw new PHPUnit_Framework_AssertionFailedError(
				$message ?: 'Failed asserting that string ends with '.$this->export($suffix)
			);
		}
	}

	public function fail($message = '')
	{
		throw new PHPUnit_Framework_AssertionFailedError($message);
	}

	public function getMockBuilder($className)
	{
		return new ShimMockBuilder($className);
	}

	protected function expectedException()
	{
		return $this->expectedException;
	}

	public $expectedException;

	private function export($value)
	{
		if (is_string($value)) {
			return "'".$value."'";
		}
		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}
		if (is_null($value)) {
			return 'null';
		}
		if (is_array($value)) {
			return json_encode($value);
		}
		return (string)$value;
	}
}

class ShimMockBuilder
{
	private $className;

	public function __construct($className)
	{
		$this->className = $className;
	}

	public function disableOriginalConstructor()
	{
		return $this;
	}

	public function getMock()
	{
		// Prefer a real framework implementation so type hints keep working.
		if (interface_exists($this->className)) {
			$preferred = array(
				'Symfony\\Component\\Console\\Output\\OutputInterface'
					=> 'Symfony\\Component\\Console\\Output\\NullOutput',
			);
			if (isset($preferred[$this->className]) && class_exists($preferred[$this->className])) {
				return new $preferred[$this->className]();
			}
		}
		return new ShimMock($this->className);
	}
}

class ShimMock
{
	private $className;

	public function __construct($className)
	{
		$this->className = $className;
	}

	public function __call($method, $arguments)
	{
		return null;
	}
}
