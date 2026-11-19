<?php

namespace Psecio\Iniscan;

use Psecio\Iniscan\Exceptions\IniConflictException;

/**
 * Scan variant that loads additional INI sources from a fixture directory
 * instead of asking the running PHP interpreter for its scanned files.
 */
class FixtureScan extends Scan
{
	private $scannedFiles = array();

	public function setScannedFiles(array $files)
	{
		$this->scannedFiles = $files;
	}

	protected function getScannedFiles()
	{
		return $this->scannedFiles;
	}
}

class ScanMergeTest extends \PHPUnit_Framework_TestCase
{
	private $fixturePath;

	public function setUp()
	{
		$this->fixturePath = __DIR__.'/Fixtures/ini-merge';
	}

	private function createScan($primaryIni, array $scannedFiles = array())
	{
		$scan = new FixtureScan($primaryIni);
		$scan->setScannedFiles($scannedFiles);
		return $scan;
	}

	/**
	 * End-to-end merge of the fixed multi-source fixture: the audit sees the
	 * real effective values plus an explainable source/order chain.
	 */
	public function testMultiSourceScanExposesExplainableState()
	{
		$scan = $this->createScan(
			$this->fixturePath.'/multi-source/php.ini',
			array(
				$this->fixturePath.'/multi-source/scan.d/10-base.ini',
				$this->fixturePath.'/multi-source/scan.d/20-extras.ini',
			)
		);

		$config = $scan->parseConfig();

		$this->assertSame('second-override', $config['custom_setting']);
		$this->assertSame('0', $config['allow_url_fopen']);
		$this->assertCount(3, $scan->getConfigSources());
		$this->assertContains('custom_setting', $scan->getDuplicateKeys());
		$this->assertContains('custom_setting', $scan->getConflictingKeys());

		$source = $scan->getKeySource('custom_setting');
		$this->assertStringEndsWith('20-extras.ini', $source['source']);
		$this->assertSame('second-override', $source['effectiveValue']);

		$history = $scan->getKeyProvenance('custom_setting');
		$this->assertCount(3, $history);
		$this->assertSame('base', $history[0]['value']);
		$this->assertSame('second-override', $history[2]['value']);
	}

	/**
	 * Dangerous overrides bubble up from the merge so the scan fails closed.
	 *
	 * @expectedException \Psecio\Iniscan\Exceptions\IniConflictException
	 */
	public function testScanFailsClosedOnDangerousOverride()
	{
		$scan = $this->createScan(
			$this->fixturePath.'/dangerous/php.ini',
			array($this->fixturePath.'/dangerous/scan.d/99-attack.ini')
		);
		$scan->parseConfig();
	}

	/**
	 * Rules.json keys are treated as dangerous without any extra wiring.
	 */
	public function testRuleKeysAreDangerousByDefault()
	{
		$scan = new Scan();
		$dangerous = $scan->getDangerousKeys();

		$this->assertContains('allow_url_fopen', $dangerous);
		$this->assertContains('session.use_cookies', $dangerous);
		$this->assertContains('open_basedir', $dangerous);
		$this->assertContains('disable_functions', $dangerous);
	}

	/**
	 * Legacy single-file scans keep returning the same flat config and have
	 * no duplicate or conflict state.
	 */
	public function testLegacySingleFileScanStaysCompatible()
	{
		$scan = $this->createScan($this->fixturePath.'/legacy-single/php.ini');
		$config = $scan->parseConfig();

		$this->assertSame('0', $config['allow_url_fopen']);
		$this->assertSame('128M', $config['memory_limit']);
		$this->assertCount(1, $scan->getConfigSources());
		$this->assertEmpty($scan->getDuplicateKeys());
		$this->assertEmpty($scan->getConflictingKeys());
		$this->assertNotEmpty($scan->getKeySource('allow_url_fopen'));
	}

	/**
	 * execute() still works against the merged configuration and returns
	 * evaluated rules.
	 */
	public function testExecuteRunsAgainstMergedConfig()
	{
		$scan = $this->createScan(
			$this->fixturePath.'/multi-source/php.ini',
			array(
				$this->fixturePath.'/multi-source/scan.d/10-base.ini',
				$this->fixturePath.'/multi-source/scan.d/20-extras.ini',
			)
		);

		$rules = $scan->execute();
		$this->assertNotEmpty($rules);
		foreach ($rules as $rule) {
			$this->assertInstanceOf('\\Psecio\\Iniscan\\Rule', $rule);
		}
	}
}
