<?php
/**
 * Lightweight test runner backed by tools/phpunit-shim.php.
 *
 * Discovers every Test.php file under tests/, runs setUp, the test
 * methods and tearDown including the expectedException annotation, and
 * reports a PHPUnit style summary. Exits non-zero on any failure.
 */

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/phpunit-shim.php';

// Reflection setAccessible became a no-op (and emits a deprecation) on
// modern PHP; silence the noise while keeping the runner version agnostic.
error_reporting(error_reporting() & ~E_DEPRECATED);

$root = __DIR__.'/../tests';
$testFiles = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

$total = 0;
$passed = 0;
$failures = array();

foreach ($testFiles as $file) {
	if ($file->getExtension() !== 'php' || substr($file->getFilename(), -8) !== 'Test.php') {
		continue;
	}

	require_once $file->getPathname();
	$declared = get_declared_classes();
	$testClass = end($declared);

	$reflection = new ReflectionClass($testClass);
	if ($reflection->isAbstract()) {
		continue;
	}

	$instance = $reflection->newInstanceWithoutConstructor();

	foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
		if (strpos($method->getName(), 'test') !== 0) {
			continue;
		}

		$docComment = $method->getDocComment();
		$expected = null;
		if ($docComment && preg_match('/@expectedException\s+([\\\\\w]+)/', $docComment, $matches)) {
			$expected = ltrim($matches[1], '\\');
		}

		$provider = null;
		if ($docComment && preg_match('/@dataProvider\s+(\w+)/', $docComment, $matches)) {
			$provider = $matches[1];
		}

		$datasets = array(array());
		if ($provider !== null) {
			$datasets = $reflection->getMethod($provider)->invoke($instance);
		}

		foreach ($datasets as $datasetIndex => $arguments) {
			$total++;
			$label = $testClass.'::'.$method->getName()
				.($provider !== null ? ' #'.($datasetIndex + 1) : '');

			try {
				$setUp = $reflection->getMethod('setUp');
				$setUp->setAccessible(true);
				$setUp->invoke($instance);

				try {
					$method->setAccessible(true);
					$method->invokeArgs($instance, $arguments);
					if ($expected !== null) {
						throw new PHPUnit_Framework_AssertionFailedError(
							'Expected exception '.$expected.' was not thrown'
						);
					}
				} catch (\Throwable $exception) {
					if ($expected === null) {
						throw $exception;
					}
					$actual = ltrim(get_class($exception), '\\');
					if ($actual !== $expected && !is_subclass_of($exception, $expected)) {
						throw new PHPUnit_Framework_AssertionFailedError(
							'Expected exception '.$expected.' but got '.$actual.': '.$exception->getMessage()
						);
					}
				}

				$tearDown = $reflection->getMethod('tearDown');
				$tearDown->setAccessible(true);
				$tearDown->invoke($instance);
				$passed++;
				echo '.';
			} catch (\Throwable $exception) {
				$failures[] = $label.' -> '.$exception->getMessage();
				echo 'F';
			}
		}
	}
}

echo "\n\n";
foreach ($failures as $failure) {
	echo "FAILURE: ".$failure."\n";
}

echo "\nTests: ".$total.', Passed: '.$passed.', Failed: '.count($failures)."\n";
exit(count($failures) > 0 ? 1 : 0);
