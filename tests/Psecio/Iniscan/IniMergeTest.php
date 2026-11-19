<?php

namespace Psecio\Iniscan;

use Psecio\Iniscan\Exceptions\IniConflictException;
use Psecio\Iniscan\Exceptions\IniParseException;

class IniMergeTest extends \PHPUnit_Framework_TestCase
{
	private $fixturePath;

	public function setUp()
	{
		$this->fixturePath = __DIR__.'/Fixtures/ini-merge';
	}

	private function fixture($relativePath)
	{
		return $this->fixturePath.'/'.$relativePath;
	}

	/**
	 * A single legacy INI file must produce the same flat map and a single
	 * recorded source, keeping the pre-merge behaviour compatible.
	 */
	public function testLegacySingleFileStaysCompatible()
	{
		$merge = new IniMerge(array('allow_url_fopen'));
		$config = $merge->addSource($this->fixture('legacy-single/php.ini'));

		$this->assertSame('0', $config['allow_url_fopen']);
		$this->assertSame('128M', $config['memory_limit']);
		$this->assertCount(1, $merge->getSources());
		$this->assertEmpty($merge->getDuplicates());
		$this->assertEmpty($merge->getConflicts());

		$winning = $merge->getWinningEntry('memory_limit');
		$this->assertSame('128M', $winning['effectiveValue']);
		$this->assertTrue($winning['winning']);
	}

	/**
	 * Multiple sources merge in order, benign identical re-declarations are
	 * tracked as duplicates but not as conflicts, and non-security overrides
	 * follow the historical later-value-wins rule.
	 */
	public function testMultiSourceMergeIsExplainable()
	{
		$merge = new IniMerge(array('allow_url_fopen', 'session.use_cookies'));
		$merge->addSource($this->fixture('multi-source/php.ini'));
		$merge->addSource($this->fixture('multi-source/scan.d/10-base.ini'));
		$merge->addSource($this->fixture('multi-source/scan.d/20-extras.ini'));

		$config = $merge->getConfig();
		$this->assertSame('0', $config['allow_url_fopen']);
		$this->assertSame('second-override', $config['custom_setting']);
		$this->assertSame('0', $config['expose_php']);

		$this->assertCount(3, $merge->getSources());
		$this->assertContains('allow_url_fopen', $merge->getDuplicates());
		$this->assertContains('custom_setting', $merge->getDuplicates());
		$this->assertNotContains('allow_url_fopen', $merge->getConflicts());
		$this->assertContains('custom_setting', $merge->getConflicts());

		$history = $merge->getProvenance('custom_setting');
		$this->assertCount(3, $history);
		$this->assertSame('base', $history[0]['value']);
		$this->assertSame('first-override', $history[1]['value']);
		$this->assertSame('second-override', $history[2]['value']);
		$this->assertSame(0, $history[0]['sourceOrder']);
		$this->assertSame(1, $history[1]['sourceOrder']);
		$this->assertSame(2, $history[2]['sourceOrder']);
		$this->assertFalse($history[0]['winning']);
		$this->assertTrue($history[2]['winning']);
		$this->assertSame('second-override', $history[2]['effectiveValue']);

		$winning = $merge->getWinningEntry('allow_url_fopen');
		$this->assertSame('0', $winning['value']);
		$this->assertStringEndsWith('10-base.ini', $winning['source']);
		$this->assertSame(4, $winning['line']);
	}

	/**
	 * A dangerous key overridden by a different later value fails closed.
	 *
	 * @expectedException \Psecio\Iniscan\Exceptions\IniConflictException
	 */
	public function testDangerousOverrideFailsClosed()
	{
		$merge = new IniMerge(array('allow_url_fopen'));
		$merge->addSource($this->fixture('dangerous/php.ini'));
		$merge->addSource($this->fixture('dangerous/scan.d/99-attack.ini'));
	}

	/**
	 * The fail-closed exception explains both conflicting sources and values.
	 */
	public function testDangerousOverrideExceptionIsExplainable()
	{
		$merge = new IniMerge(array('allow_url_fopen'));
		$merge->addSource($this->fixture('dangerous/php.ini'));

		try {
			$merge->addSource($this->fixture('dangerous/scan.d/99-attack.ini'));
			$this->fail('Expected IniConflictException for the dangerous override');
		} catch (IniConflictException $exception) {
			$message = $exception->getMessage();
			$this->assertContains('allow_url_fopen', $message);
			$this->assertContains('99-attack.ini', $message);
			$this->assertContains('php.ini', $message);
			$this->assertContains('"0"', $message);
			$this->assertContains('"1"', $message);
		}
	}

	/**
	 * Repeating a dangerous key with the same value stays compatible and
	 * does not trip the fail-closed guard.
	 */
	public function testDangerousKeyWithIdenticalValueIsAllowed()
	{
		$merge = new IniMerge(array('allow_url_fopen'));
		$merge->addSource($this->fixture('multi-source/php.ini'));
		$merge->addSource($this->fixture('multi-source/scan.d/10-base.ini'));

		$this->assertContains('allow_url_fopen', $merge->getDuplicates());
		$this->assertSame('0', $merge->getConfig()['allow_url_fopen']);
	}

	/**
	 * Repeated keys inside one file keep PHP last-wins semantics while every
	 * declaration remains visible in provenance.
	 */
	public function testInFileDuplicatesAreRecorded()
	{
		$merge = new IniMerge(array());
		$merge->addSource($this->fixture('in-file-duplicate/php.ini'));

		$this->assertSame('256M', $merge->getConfig()['memory_limit']);
		$this->assertContains('memory_limit', $merge->getDuplicates());
		$history = $merge->getProvenance('memory_limit');
		$this->assertCount(2, $history);
		$this->assertSame(4, $history[0]['line']);
		$this->assertSame(5, $history[1]['line']);
		$this->assertTrue($history[1]['winning']);
	}

	/**
	 * An unparsable source fails with IniParseException rather than being
	 * silently skipped.
	 *
	 * @expectedException \Psecio\Iniscan\Exceptions\IniParseException
	 */
	public function testBrokenSourceFailsClosed()
	{
		$merge = new IniMerge();
		$merge->addSource($this->fixture('broken/php.ini'));
	}

	/**
	 * A missing source fails with IniParseException.
	 *
	 * @expectedException \Psecio\Iniscan\Exceptions\IniParseException
	 */
	public function testMissingSourceFailsClosed()
	{
		$merge = new IniMerge();
		$merge->addSource($this->fixture('does-not-exist.ini'));
	}

	/**
	 * Adding the exact same source twice must not double-apply it.
	 */
	public function testSameSourceIsOnlyAppliedOnce()
	{
		$merge = new IniMerge();
		$path = $this->fixture('legacy-single/php.ini');
		$merge->addSource($path);
		$merge->addSource($path);

		$this->assertCount(1, $merge->getSources());
		$this->assertEmpty($merge->getDuplicates());
	}

	/**
	 * Inline comments and quoted values are normalized like parse_ini_file.
	 */
	public function testQuotedAndCommentedValuesAreNormalized()
	{
		$merge = new IniMerge(array('display_errors'));
		$merge->addSource($this->fixture('legacy-single/php.ini'));

		$this->assertSame('0', $merge->getConfig()['display_errors']);
	}

	/**
	 * Array keys (key[]) keep the legacy array_merge semantics while still
	 * producing one provenance entry per declaring source.
	 */
	public function testArrayKeysAreMergedWithProvenance()
	{
		$first = tempnam(sys_get_temp_dir(), 'iniscan_a_').'.ini';
		$second = tempnam(sys_get_temp_dir(), 'iniscan_b_').'.ini';
		file_put_contents($first, "list[] = one\nlist[] = two\n");
		file_put_contents($second, "list[] = three\n");

		$merge = new IniMerge(array());
		$merge->addSource($first);
		$merge->addSource($second);

		$this->assertSame(array('three'), $merge->getConfig()['list']);
		$this->assertContains('list', $merge->getDuplicates());
		$this->assertContains('list', $merge->getConflicts());

		$history = $merge->getProvenance('list');
		$this->assertCount(2, $history);
		$this->assertSame(array('one', 'two'), $history[0]['value']);
		$this->assertSame(array('three'), $history[1]['value']);
		$this->assertTrue($history[1]['winning']);

		unlink($first);
		unlink($second);
	}
}
