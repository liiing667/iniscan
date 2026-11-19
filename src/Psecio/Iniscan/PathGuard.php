<?php

namespace Psecio\Iniscan;

use Psecio\Iniscan\Exceptions\InvalidPathException;
use Psecio\Iniscan\Exceptions\PathOutsideRootException;

/**
 * Resolves configuration file paths against a fixed root directory.
 *
 * The guard makes the supplied path, the resolved real path, the symlink
 * target and the configuration identity agree with each other. Anything
 * that escapes the root, contains control characters or cannot be resolved
 * fails closed with an exception.
 */
class PathGuard
{
    /**
     * Real path of the directory the scanned configuration must stay inside.
     * @var string
     */
    private $rootPath;

    /**
     * @param string $root Root directory to confine configuration files to
     * @throws InvalidPathException When the root itself cannot be resolved
     */
    public function __construct($root)
    {
        if (!is_string($root) || $root === '') {
            throw new InvalidPathException('Root path must be a non-empty string');
        }
        if (self::containsControlCharacters($root)) {
            throw new InvalidPathException('Root path contains control characters');
        }

        $realRoot = realpath($root);
        if ($realRoot === false || !is_dir($realRoot)) {
            throw new InvalidPathException('Root path '.$root.' is not a readable directory');
        }
        $this->rootPath = $realRoot;
    }

    /**
     * Real path of the configured root directory.
     *
     * @return string
     */
    public function getRootPath()
    {
        return $this->rootPath;
    }

    /**
     * Classify a candidate path without throwing an exception.
     *
     * The returned status is one of:
     *  - "enter":  a real file inside the root that may be scanned
     *  - "skip":   a symlink whose target resolves outside the root
     *  - "reject": an unreadable, missing, control-character or traversing path
     *
     * @param string $path Candidate configuration path (relative or absolute)
     * @return array Hash with "status", "path", "realPath", "rootPath" and "reason"
     */
    public function inspect($path)
    {
        $result = array(
            'status' => 'reject',
            'path' => is_string($path) ? $path : '',
            'realPath' => null,
            'rootPath' => $this->rootPath,
            'reason' => ''
        );

        if (!is_string($path) || $path === '') {
            $result['reason'] = 'Path must be a non-empty string';
            return $result;
        }
        if (self::containsControlCharacters($path)) {
            $result['reason'] = 'Path contains control characters';
            return $result;
        }

        $candidate = $this->makeAbsolute($path);
        $usesLink = $this->pathContainsLink($candidate);

        $realPath = realpath($candidate);
        if ($realPath === false) {
            $result['reason'] = 'Path cannot be resolved to a real file';
            return $result;
        }
        if (!is_file($realPath)) {
            $result['reason'] = 'Resolved path is not a regular file';
            return $result;
        }

        if (!$this->isInsideRoot($realPath)) {
            $result['realPath'] = $realPath;
            if ($usesLink) {
                $result['status'] = 'skip';
                $result['reason'] = 'Symlink target resolves outside of the root directory';
            } else {
                $result['reason'] = 'Path resolves outside of the root directory';
            }
            return $result;
        }

        $result['status'] = 'enter';
        $result['realPath'] = $realPath;
        $result['reason'] = 'Path resolves to a file inside the root directory';
        return $result;
    }

    /**
     * Resolve a candidate path to a real, root-confined file.
     *
     * @param string $path Candidate configuration path (relative or absolute)
     * @throws InvalidPathException Malformed or unresolvable input (fail closed)
     * @throws PathOutsideRootException The path or its link target escapes the root
     * @return string Verified real path that may be read
     */
    public function resolve($path)
    {
        $inspection = $this->inspect($path);

        if ($inspection['status'] === 'enter') {
            return $inspection['realPath'];
        }
        if ($inspection['status'] === 'skip') {
            throw new PathOutsideRootException($inspection['reason'].': '.$path);
        }
        throw new InvalidPathException($inspection['reason'].': '.$path);
    }

    /**
     * Re-verify that a previously resolved path still has the same identity.
     *
     * This closes the time-of-check/time-of-use window between setPath() and
     * the actual parse_ini_file() read.
     *
     * @param string $realPath Path previously returned by resolve()
     * @throws InvalidPathException When the file has disappeared or changed type
     * @throws PathOutsideRootException When the path now escapes the root
     * @return string Same verified real path
     */
    public function verify($realPath)
    {
        if (!is_string($realPath) || $realPath === ''
            || self::containsControlCharacters($realPath)) {
            throw new InvalidPathException('Stored configuration path is invalid');
        }

        $current = realpath($realPath);
        if ($current === false || !is_file($current)) {
            throw new InvalidPathException(
                'Configuration path is no longer a readable file: '.$realPath
            );
        }
        if ($current !== $realPath) {
            throw new InvalidPathException(
                'Configuration identity changed: expected '.$realPath.', got '.$current
            );
        }
        if (!$this->isInsideRoot($current)) {
            throw new PathOutsideRootException(
                'Configuration path moved outside of the root directory: '.$current
            );
        }
        return $current;
    }

    /**
     * Turn a possibly relative candidate into an absolute path without
     * resolving symbolic links yet (realpath() does that afterwards).
     *
     * @param string $path
     * @return string
     */
    private function makeAbsolute($path)
    {
        if ($path[0] === DIRECTORY_SEPARATOR) {
            return $path;
        }
        return $this->rootPath.DIRECTORY_SEPARATOR.$path;
    }

    /**
     * Walk every component of the candidate path so a symbolic link on a
     * parent directory (not only the leaf element) is still recognised.
     *
     * @param string $absolutePath
     * @return boolean
     */
    private function pathContainsLink($absolutePath)
    {
        $components = explode(DIRECTORY_SEPARATOR, trim($absolutePath, DIRECTORY_SEPARATOR));
        $current = DIRECTORY_SEPARATOR;
        foreach ($components as $component) {
            if ($component === '') {
                continue;
            }
            $current .= $component;
            if (is_link($current)) {
                return true;
            }
            $current .= DIRECTORY_SEPARATOR;
        }
        return false;
    }

    /**
     * Boundary check that treats the root itself and everything below it as
     * inside, while preventing prefix-confusion ("/root-evil" vs "/root").
     *
     * @param string $realPath
     * @return boolean
     */
    private function isInsideRoot($realPath)
    {
        return $realPath === $this->rootPath
            || strpos($realPath, $this->rootPath.DIRECTORY_SEPARATOR) === 0;
    }

    /**
     * Detect ASCII control characters (0x00-0x1F and 0x7F), including NUL,
     * newline and tab, which must never appear in a trustworthy file path.
     *
     * @param string $path
     * @return boolean
     */
    private static function containsControlCharacters($path)
    {
        return (bool) preg_match('#[\x00-\x1f\x7f]#', $path);
    }
}
