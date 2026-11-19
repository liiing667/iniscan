<?php

namespace Psecio\Iniscan\Command\ScanCommand\Output;

use Symfony\Component\Console\Output\BufferedOutput;

class ConsoleMergeOutputTest extends \PHPUnit_Framework_TestCase
{
	private function renderConsole(array $options, array $results = array())
	{
		$buffered = new BufferedOutput();
		$console = new Console($buffered, $options);
		$console->render($results);
		return $buffered->fetch();
	}

	/**
	 * The report header shows the ordered source list, duplicate keys and
	 * tolerated overrides.
	 */
	public function testReportShowsSourceChainAndDuplicates()
	{
		$output = $this->renderConsole(array(
			'path' => '/etc/php.ini',
			'failOnly' => false,
			'deprecated' => array(),
			'verbose' => false,
			'output' => null,
			'sources' => array('/etc/php.ini', '/etc/conf.d/10-base.ini'),
			'duplicates' => array('allow_url_fopen', 'custom_setting'),
			'conflicts' => array('custom_setting'),
			'provenance' => array(),
		));

		$this->assertContains('Configuration sources (effective merge order)', $output);
		$this->assertContains('#0', $output);
		$this->assertContains('/etc/php.ini', $output);
		$this->assertContains('#1', $output);
		$this->assertContains('10-base.ini', $output);
		$this->assertContains('Duplicate keys declared across sources', $output);
		$this->assertContains('allow_url_fopen', $output);
		$this->assertContains('Tolerated overrides', $output);
		$this->assertContains('custom_setting', $output);
		$this->assertContains('Effective Source', $output);
	}

	/**
	 * Each rule row resolves to the winning source, and verbose mode prints
	 * the full provenance chain with the effective marker.
	 */
	public function testReportShowsWinningSourceAndProvenance()
	{
		$testDefinition = (object)array(
			'key' => 'allow_url_fopen',
			'operation' => 'equals',
			'value' => '0',
		);

		$rule = new \Psecio\Iniscan\Rule(
			(object)array(
				'name' => 'Remote resources',
				'description' => 'Do not allow remote resources',
				'level' => 'ERROR',
				'test' => $testDefinition,
			),
			'PHP'
		);
		$rule->setStatus(true);
		$rule->setValue('0');

		$provenance = array(
			'allow_url_fopen' => array(
				array(
					'key' => 'allow_url_fopen',
					'value' => '0',
					'source' => '/etc/php.ini',
					'line' => 10,
					'sourceOrder' => 0,
					'winning' => false,
				),
				array(
					'key' => 'allow_url_fopen',
					'value' => '0',
					'source' => '/etc/conf.d/10-base.ini',
					'line' => 4,
					'sourceOrder' => 1,
					'winning' => true,
				),
			),
		);

		$output = $this->renderConsole(
			array(
				'path' => '/etc/php.ini',
				'failOnly' => false,
				'deprecated' => array(),
				'verbose' => true,
				'output' => null,
				'sources' => array('/etc/php.ini', '/etc/conf.d/10-base.ini'),
				'duplicates' => array('allow_url_fopen'),
				'conflicts' => array(),
				'provenance' => $provenance,
			),
			array($rule)
		);

		$this->assertContains('10-base.ini:4', $output);
		$this->assertContains('provenance:', $output);
		$this->assertContains('#0 php.ini:10 = "0"', $output);
		$this->assertContains('#1 10-base.ini:4 = "0" [effective]', $output);
	}

	/**
	 * Keys without a declaration render as "default" instead of failing.
	 */
	public function testMissingProvenanceRendersAsDefault()
	{
		$output = $this->renderConsole(array(
			'path' => '/etc/php.ini',
			'failOnly' => false,
			'deprecated' => array(),
			'verbose' => false,
			'output' => null,
			'sources' => array('/etc/php.ini'),
			'duplicates' => array(),
			'conflicts' => array(),
			'provenance' => array(),
		));

		$this->assertContains('0 passing', $output);
		$this->assertContains('Configuration sources (effective merge order)', $output);
	}
}
