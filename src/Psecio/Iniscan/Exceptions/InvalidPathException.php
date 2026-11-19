<?php

namespace Psecio\Iniscan\Exceptions;

/**
 * Thrown when a configuration path is malformed (for example contains
 * control characters) or cannot be resolved to a real, readable file.
 */
class InvalidPathException extends \InvalidArgumentException
{
}
