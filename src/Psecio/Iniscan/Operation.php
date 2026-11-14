<?php

namespace Psecio\Iniscan;

abstract class Operation
{
	/**
	 * Section of the ini file
	 * @var string
	 */
	public $section;

	/**
	 * Current Cast instance
	 * @var \Psecio\Iniscan\Cast
	 */
	private $cast;

	/**
	 * Strict "file only" mode flag. When enabled, missing settings
	 * are not pulled from the runtime configuration (ini_get)
	 * @var boolean
	 */
	private $strict = false;

	/**
	 * Init the Operation and set the section
	 *
	 * @param string $section Section name
	 */
	public function __construct($section)
	{
		$this->setSection($section);
		$this->setCast(new \Psecio\Iniscan\Cast());
	}

	/**
	 * Set the current Cast instance
	 *
	 * @param \Psecio\Iniscan\Cast $cast Cast object instance
	 * @return \Psecio\Iniscan\Operation instance
	 */
	public function setCast(\Psecio\Iniscan\Cast $cast)
	{
		$this->cast = $cast;
		return $this;
	}

	/**
	 * Get the current Cast instance
	 *
	 * @return \Psecio\Iniscan\Cast instance
	 */
	public function getCast()
	{
		return $this->cast;
	}

	/**
	 * Set the strict "file only" mode flag
	 *
	 * @param boolean $strict Enable/disable strict mode
	 * @return \Psecio\Iniscan\Operation instance
	 */
	public function setStrict($strict)
	{
		$this->strict = (bool)$strict;
		return $this;
	}

	/**
	 * Check if strict "file only" mode is enabled
	 *
	 * @return boolean Strict mode enabled/disabled
	 */
	public function isStrict()
	{
		return $this->strict;
	}

	/**
	 * Set the section name
	 *
	 * @param string $section
	 * @return \Psecio\Iniscan\Operation instance
	 */
	public function setSection($section)
	{
		$this->section = $section;
		return $this;
	}

	/**
	 * Get the current section name
	 *
	 * @param string $path INI "path" for settings
	 * @return string
	 */
	public function getSection($path)
	{
		$parts = explode('.', $path);
		return (count($parts) === 1)
			? 'PHP' : ucwords(strtolower($parts[0]));
	}

	/**
	 * Execute the operation
	 *
	 * @param string $key INI key name
	 * @param string $value Value to match
	 * @param array $ini Current INI settings
	 * @return boolean Pass/fail
	 */
	public abstract function execute($key, $value, $ini);

	/**
	 * Find the given value in the INI array
	 *   If not found, returns the currently set value (unless in strict mode)
	 *
	 * @param string $path "Path" to the value
	 * @param array $ini Current INI settings
	 * @return string|boolean Found INI value, false if not found in strict mode
	 */
	public function findValue($path, &$ini)
	{
		if (array_key_exists($path, $ini)) {
			return $ini[$path];
		}
		if ($this->isStrict() === true) {
			// strict mode: never fall back to the runtime configuration
			return false;
		}
		// not in the file, pull out the default
		$value = ini_get($path);
		$ini[$path] = $value;

		return $value;
	}
}
