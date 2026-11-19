<?php

namespace Psecio\Iniscan;

/**
 * Redacts sensitive values (passwords, tokens, connection strings, ...)
 * 	so audit reports can be shared without exposing secrets
 */
class Redactor
{
	/**
	 * Replacement text used for any sensitive value
	 */
	const MASK = '********';

	/**
	 * Key segments that mark an INI setting as sensitive
	 * @var array
	 */
	private static $sensitiveSegments = array(
		'pass', 'passwd', 'password', 'passphrase', 'pwd', 'pw',
		'secret', 'token', 'apikey', 'dsn', 'credential', 'credentials'
	);

	/**
	 * Compound key fragments (separators removed) that mark a setting as sensitive
	 * @var array
	 */
	private static $sensitiveCompounds = array(
		'apikey', 'privatekey', 'secretkey', 'accesskey', 'clientsecret',
		'authtoken', 'accesstoken', 'refreshtoken', 'connectionstring'
	);

	/**
	 * Check if an INI key usually holds a secret
	 *
	 * @param string $key INI setting key
	 * @return boolean Sensitive/not sensitive
	 */
	public static function isSensitiveKey($key)
	{
		$key = strtolower((string)$key);
		if ($key === '') {
			return false;
		}
		foreach (preg_split('/[._\-]+/', $key) as $segment) {
			if (in_array($segment, self::$sensitiveSegments, true)) {
				return true;
			}
		}
		$flat = str_replace(array('.', '_', '-'), '', $key);
		foreach (self::$sensitiveCompounds as $compound) {
			if (strpos($flat, $compound) !== false) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check if a value itself looks like a secret (connection string
	 * 	with credentials or an embedded "password = ..." style pair)
	 *
	 * @param mixed $value Value to check
	 * @return boolean Sensitive/not sensitive
	 */
	public static function isSensitiveValue($value)
	{
		if (!is_string($value) || $value === '') {
			return false;
		}
		// connection string with credentials, ex. "tcp://user:password@host:11211"
		if (preg_match('#^[a-z][a-z0-9+.\-]*://[^/@\s]*:[^/@\s]+@#i', $value) === 1) {
			return true;
		}
		// embedded "password = ..." style pairs, ex. "host=db;Password=secret"
		if (preg_match('/(?:pass(?:word|wd|phrase)?|pwd|secret|token|api[_-]?key)\s*[:=]\s*\S+/i', $value) === 1) {
			return true;
		}
		return false;
	}

	/**
	 * Redact a single value if the key or the value looks sensitive,
	 * 	non-sensitive values are returned untouched
	 *
	 * @param string $key INI setting key the value belongs to
	 * @param mixed $value Value to redact
	 * @return mixed Either the mask or the original value
	 */
	public static function redact($key, $value)
	{
		if (!is_string($value) || $value === '') {
			return $value;
		}
		if (self::isSensitiveKey($key) || self::isSensitiveValue($value)) {
			return self::MASK;
		}
		return $value;
	}

	/**
	 * Redact connection strings with embedded credentials found
	 * 	anywhere in a free text (ex. rule descriptions)
	 *
	 * @param string $text Text to redact
	 * @return string Text with sensitive parts masked
	 */
	public static function redactString($text)
	{
		if (!is_string($text) || $text === '') {
			return $text;
		}
		return preg_replace(
			'#[a-z][a-z0-9+.\-]*://[^/@\s]*:[^/@\s]+@[^/\s]*#i',
			self::MASK,
			$text
		);
	}
}
