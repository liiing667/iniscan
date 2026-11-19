<?php

namespace Psecio\Iniscan\Exceptions;

/**
 * Thrown when a security relevant INI key is silently overridden with a
 * different value while merging multiple INI sources.
 *
 * The scanner fails closed instead of reporting a potentially weakened
 * effective configuration.
 */
class IniConflictException extends \Exception
{
}
