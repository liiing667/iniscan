<?php

namespace Psecio\Iniscan;

use Psecio\Iniscan\Exceptions\IniConflictException;
use Psecio\Iniscan\Exceptions\IniParseException;

/**
 * Merges several INI sources in a deterministic, explainable way.
 *
 * Every key keeps the full chain of sources that declared it, the order in
 * which they were applied and which value finally won. Conflicting
 * re-declarations of security relevant keys fail closed by throwing an
 * IniConflictException instead of silently weakening the audited
 * configuration.
 */
class IniMerge
{
	/**
	 * Effective flat key/value map (same shape parse_ini_file returned
	 * historically, so existing rule code keeps working unchanged).
	 * @var array
	 */
	private $config = array();

	/**
	 * Ordered list of the source files that were merged.
	 * @var array
	 */
	private $sources = array();

	/**
	 * Provenance chain per key: a list of entries describing every
	 * declaration in application order.
	 * @var array
	 */
	private $provenance = array();

	/**
	 * Keys declared by more than one source (or more than once in a file).
	 * @var array
	 */
	private $duplicates = array();

	/**
	 * Duplicate keys whose declarations do not carry the same value.
	 * @var array
	 */
	private $conflicts = array();

	/**
	 * Lookup of security relevant keys (lower cased) whose overrides must
	 * fail closed.
	 * @var array
	 */
	private $dangerousKeys = array();

	/**
	 * Monotonic application order counter.
	 * @var integer
	 */
	private $order = 0;

	/**
	 * @param array $dangerousKeys Keys that must not be silently overridden
	 */
	public function __construct(array $dangerousKeys = array())
	{
		foreach ($dangerousKeys as $key) {
			$this->dangerousKeys[strtolower($key)] = true;
		}
	}

	/**
	 * Parse and merge one INI source after the previously added sources.
	 *
	 * @param string $path Path to the INI file
	 * @throws IniParseException When the file cannot be read or parsed
	 * @throws IniConflictException When a dangerous key is overridden
	 * @return array The effective configuration after merging this source
	 */
	public function addSource($path)
	{
		$realPath = realpath($path);
		if ($realPath === false || !is_file($realPath)) {
			throw new IniParseException('INI source "'.$path.'" could not be found');
		}

		// The same file must never be applied twice in one merge chain.
		if (in_array($realPath, $this->sources, true)) {
			return $this->config;
		}

		$ini = @parse_ini_file($realPath);
		if ($ini === false) {
			throw new IniParseException('INI source "'.$realPath.'" could not be parsed');
		}

		$sourceIndex = count($this->sources);
		$this->sources[] = $realPath;

		$occurrences = $this->parseOccurrences($realPath);

		// Record every declaration (including repeated ones inside the file)
		// so the provenance chain stays complete.
		foreach ($occurrences as $occurrence) {
			if (!empty($occurrence['array'])) {
				continue;
			}
			$this->record($realPath, $sourceIndex, $occurrence['key'], $occurrence['value'], $occurrence['line']);
		}

		// Apply the file's own effective values (PHP "last wins" semantics
		// inside a single file) on top of the previously merged sources.
		foreach ($ini as $key => $value) {
			$this->config[$key] = $value;
			if (is_array($value)) {
				$this->recordArrayDeclaration($realPath, $sourceIndex, $key, $value, $occurrences);
			}
		}

		$this->refreshWinningEntries();

		return $this->config;
	}

	/**
	 * Record one aggregate provenance entry per source that declares an
	 * array key. Each element line is kept as the first declaration line.
	 *
	 * @param string $source Source file path
	 * @param integer $sourceIndex Position of the source in merge order
	 * @param string $key Array INI key
	 * @param array $value Effective array value
	 * @param array $occurrences Occurrences parsed from this source
	 */
	private function recordArrayDeclaration($source, $sourceIndex, $key, array $value, array $occurrences)
	{
		$firstLine = null;
		foreach ($occurrences as $occurrence) {
			if ($occurrence['key'] === $key && !empty($occurrence['array'])) {
				$firstLine = $occurrence['line'];
				break;
			}
		}

		if (isset($this->provenance[$key])) {
			$this->duplicates[$key] = true;
			$previous = end($this->provenance[$key]);
			if (!$this->sameValue($previous['value'], $value)) {
				$this->conflicts[$key] = true;
				if ($this->isDangerous($key)) {
					throw new IniConflictException(
						'Refusing to silently override security key "'.$key.'": '
						.json_encode($previous['value']).' from '.$this->describeEntry($previous)
						.' is replaced by '.json_encode($value).' from '.$source
						.($firstLine !== null ? ' on line '.$firstLine : '')
					);
				}
			}
		} else {
			$this->provenance[$key] = array();
		}

		$this->provenance[$key][] = array(
			'key' => $key,
			'value' => $value,
			'rawValue' => implode(',', $value),
			'source' => $source,
			'line' => $firstLine,
			'order' => $this->order++,
			'sourceOrder' => $sourceIndex,
		);
	}

	/**
	 * Record a single declaration in the provenance chain.
	 *
	 * @param string $source Source file path
	 * @param integer $sourceIndex Position of the source in merge order
	 * @param string $key INI key
	 * @param string $value Raw value as declared in the file
	 * @param integer $line Line number inside the source file
	 * @throws IniConflictException When a dangerous key gets a new value
	 */
	private function record($source, $sourceIndex, $key, $value, $line)
	{
		$normalized = $this->normalizeValue($key, $value);
		$entry = array(
			'key' => $key,
			'value' => $normalized,
			'rawValue' => $value,
			'source' => $source,
			'line' => $line,
			'order' => $this->order++,
			'sourceOrder' => $sourceIndex,
		);

		if (isset($this->provenance[$key])) {
			$this->duplicates[$key] = true;
			$previous = end($this->provenance[$key]);
			if (!$this->sameValue($previous['value'], $normalized)) {
				$this->conflicts[$key] = true;
				if ($this->isDangerous($key)) {
					throw new IniConflictException(
						'Refusing to silently override security key "'.$key.'": "'
						.$previous['value'].'" from '.$this->describeEntry($previous)
						.' is replaced by "'.$normalized.'" from '
						.$source.' on line '.$line
					);
				}
			}
		} else {
			$this->provenance[$key] = array();
		}

		$this->provenance[$key][] = $entry;
	}

	/**
	 * Mark the last declaration of every key as the winning one and attach
	 * the effective value from the merged configuration.
	 */
	private function refreshWinningEntries()
	{
		foreach ($this->provenance as $key => $entries) {
			$lastIndex = count($entries) - 1;
			foreach ($entries as $index => $entry) {
				$this->provenance[$key][$index]['winning'] = ($index === $lastIndex);
				$this->provenance[$key][$index]['effectiveValue'] = isset($this->config[$key])
					? $this->config[$key] : null;
			}
		}
	}

	/**
	 * Walk an INI file line by line and collect every key declaration in
	 * order, including repeated keys that parse_ini_file collapses.
	 *
	 * @param string $path INI file path
	 * @return array List of array('key', 'value', 'line') occurrences
	 */
	private function parseOccurrences($path)
	{
		$occurrences = array();
		$lines = file($path, FILE_IGNORE_NEW_LINES);
		if ($lines === false) {
			return $occurrences;
		}

		foreach ($lines as $index => $line) {
			$lineNumber = $index + 1;
			$trimmed = trim($line);
			if ($trimmed === '' || $trimmed[0] === ';' || $trimmed[0] === '#' || $trimmed[0] === '[') {
				continue;
			}

			$equals = strpos($line, '=');
			if ($equals === false) {
				continue;
			}

			$key = trim(substr($line, 0, $equals));
			$value = $this->stripInlineComment(trim(substr($line, $equals + 1)));

			// Quoted keys are valid INI syntax.
			if (strlen($key) > 1 && ($key[0] === '"' || $key[0] === "'") && substr($key, -1) === $key[0]) {
				$key = substr($key, 1, -1);
			}

			// Array syntax (key[]) collapses onto the base key name.
			$isArray = false;
			if (substr($key, -2) === '[]') {
				$key = substr($key, 0, -2);
				$isArray = true;
			}

			if ($key === '') {
				continue;
			}

			$occurrences[] = array(
				'key' => $key,
				'value' => $value,
				'line' => $lineNumber,
				'array' => $isArray,
			);
		}

		return $occurrences;
	}

	/**
	 * Remove an inline comment while respecting quoted values.
	 *
	 * @param string $value Raw value portion of an INI line
	 * @return string Value without the inline comment
	 */
	private function stripInlineComment($value)
	{
		if ($value === '') {
			return $value;
		}
		if ($value[0] === '"' || $value[0] === "'") {
			$quote = $value[0];
			$closing = strpos($value, $quote, 1);
			if ($closing !== false) {
				return substr($value, 0, $closing + 1);
			}
		}

		$comment = strpos($value, ';');
		if ($comment !== false) {
			$value = trim(substr($value, 0, $comment));
		}
		return $value;
	}

	/**
	 * Normalize a raw value with the same semantics parse_ini_file uses so
	 * comparisons never disagree with the effective configuration.
	 *
	 * @param string $key INI key
	 * @param string $raw Raw declared value
	 * @return string Normalized value
	 */
	private function normalizeValue($key, $raw)
	{
		$parsed = @parse_ini_string($key.'='.$raw);
		if ($parsed !== false && array_key_exists($key, $parsed)) {
			return $parsed[$key];
		}
		return trim($raw, " \t\"'");
	}

	/**
	 * Loose value comparison covering scalar and array declarations.
	 *
	 * @param mixed $left First value
	 * @param mixed $right Second value
	 * @return boolean Values are equivalent
	 */
	private function sameValue($left, $right)
	{
		if (is_array($left) || is_array($right)) {
			return $left === $right;
		}
		return (string)$left === (string)$right;
	}

	/**
	 * Whether the key belongs to the security relevant key set.
	 *
	 * @param string $key INI key
	 * @return boolean
	 */
	private function isDangerous($key)
	{
		return isset($this->dangerousKeys[strtolower($key)]);
	}

	/**
	 * Human readable location of a provenance entry.
	 *
	 * @param array $entry Provenance entry
	 * @return string
	 */
	private function describeEntry(array $entry)
	{
		return $entry['source'].' on line '.$entry['line'];
	}

	/**
	 * Effective flat key/value map.
	 *
	 * @return array
	 */
	public function getConfig()
	{
		return $this->config;
	}

	/**
	 * Ordered list of merged source files.
	 *
	 * @return array
	 */
	public function getSources()
	{
		return $this->sources;
	}

	/**
	 * Full provenance chain, optionally limited to one key.
	 *
	 * @param string $key INI key [optional]
	 * @return array
	 */
	public function getProvenance($key = null)
	{
		if ($key !== null) {
			return isset($this->provenance[$key]) ? $this->provenance[$key] : array();
		}
		return $this->provenance;
	}

	/**
	 * The winning provenance entry for a key, if any.
	 *
	 * @param string $key INI key
	 * @return array|null
	 */
	public function getWinningEntry($key)
	{
		if (!isset($this->provenance[$key])) {
			return null;
		}
		$winning = null;
		foreach ($this->provenance[$key] as $entry) {
			if (!empty($entry['winning']) || $winning === null) {
				$winning = $entry;
			}
		}
		return $winning;
	}

	/**
	 * Keys declared more than once.
	 *
	 * @return array
	 */
	public function getDuplicates()
	{
		return array_keys($this->duplicates);
	}

	/**
	 * Duplicate keys whose redeclarations carried different values.
	 *
	 * @return array
	 */
	public function getConflicts()
	{
		return array_keys($this->conflicts);
	}

	/**
	 * Whether at least one non failing conflict was tolerated.
	 *
	 * @return boolean
	 */
	public function hasConflicts()
	{
		return !empty($this->conflicts);
	}
}
