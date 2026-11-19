<?php

namespace Psecio\Iniscan\Exceptions;

/**
 * Thrown when an INI source cannot be read or parsed. A broken INI source
 * must never be ignored, otherwise the audit could evaluate an incomplete
 * configuration.
 */
class IniParseException extends \RuntimeException
{
}
