<?php

namespace Psecio\Iniscan\Exceptions;

/**
 * Thrown when a configuration path resolves outside of the allowed root
 * directory, for example through a symbolic link or a ".." traversal.
 */
class PathOutsideRootException extends \InvalidArgumentException
{
}
