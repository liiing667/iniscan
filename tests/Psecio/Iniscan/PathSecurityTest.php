<?php

namespace Psecio\Iniscan;

use Psecio\Iniscan\Exceptions\InvalidPathException;
use Psecio\Iniscan\Exceptions\PathOutsideRootException;

/**
 * Fixed-directory fixture proving that the path handling keeps the root,
 * the real path, the symlink target and the configuration identity in
 * sync, failing closed on anything that cannot be proven safe.
 */
class PathSecurityTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var string Fixed workspace root created per test run
     */
    private $root;

    /**
     * @var string File outside of the root that must never be read
     */
    private $outside;

    public function setUp()
    {
        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'iniscan-path-security';
        $this->removeDirectory($base);

        $this->root = $base.DIRECTORY_SEPARATOR.'workspace';
        $nested = $this->root.DIRECTORY_SEPARATOR.'conf'.DIRECTORY_SEPARATOR.'nested';
        mkdir($nested, 0700, true);

        $outsideDir = $base.DIRECTORY_SEPARATOR.'outside';
        mkdir($outsideDir, 0700, true);
        $this->outside = $outsideDir.DIRECTORY_SEPARATOR.'secret.ini';
        file_put_contents(
            $this->outside,
            "expose_php = \"outside-secret\"\n"
        );

        // 1. Legitimate configuration inside the root (relative path)
        file_put_contents(
            $this->root.DIRECTORY_SEPARATOR.'php.ini',
            "expose_php = \"inside-value\"\n"
        );

        // 2. Legitimate nested configuration reached through a relative path
        file_put_contents(
            $nested.DIRECTORY_SEPARATOR.'php.ini',
            "expose_php = \"nested-value\"\n"
        );

        // 3. Symlink whose target stays inside the root (allowed)
        symlink(
            $this->root.DIRECTORY_SEPARATOR.'php.ini',
            $this->root.DIRECTORY_SEPARATOR.'conf'.DIRECTORY_SEPARATOR.'inside-link.ini'
        );

        // 4. Symlink that escapes to a file outside the root (must skip/reject)
        symlink(
            $this->outside,
            $this->root.DIRECTORY_SEPARATOR.'conf'.DIRECTORY_SEPARATOR.'escape-link.ini'
        );

        // 4b. Symlinked directory that escapes the root (link on a parent path)
        symlink(
            dirname($this->outside),
            $this->root.DIRECTORY_SEPARATOR.'conf'.DIRECTORY_SEPARATOR.'escape-dir'
        );

        // 5. Dangling symlink (cannot be resolved, fail closed)
        symlink(
            $this->root.DIRECTORY_SEPARATOR.'conf'.DIRECTORY_SEPARATOR.'does-not-exist.ini',
            $this->root.DIRECTORY_SEPARATOR.'conf'.DIRECTORY_SEPARATOR.'dangling-link.ini'
        );
    }

    public function tearDown()
    {
        if ($this->root !== null) {
            $this->removeDirectory(dirname($this->root));
        }
    }

    /**
     * Evidence "enter": a relative configuration inside the root is resolved
     * to its real path and can still be scanned.
     */
    public function testRelativeConfigInsideRootEntersAndScans()
    {
        $scan = new Scan(null, array(), null, null, $this->root);
        $scan->setPath('php.ini');

        $this->assertSame(
            realpath($this->root.DIRECTORY_SEPARATOR.'php.ini'),
            $scan->getPath()
        );
        $this->assertSame(realpath($this->root), $scan->getRoot());

        $inspection = $scan->inspectPath('php.ini');
        $this->assertSame('enter', $inspection['status']);

        $config = $scan->parseConfig();
        $this->assertSame('inside-value', $config['expose_php']);
    }

    /**
     * Evidence "enter": nested relative paths and internal symlinks are
     * accepted while the stored identity is the resolved real path.
     */
    public function testNestedRelativePathAndInternalSymlinkEnter()
    {
        $scan = new Scan(null, array(), null, null, $this->root);

        $scan->setPath('conf'.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'php.ini');
        $this->assertSame(
            realpath($this->root.DIRECTORY_SEPARATOR.'conf'.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'php.ini'),
            $scan->getPath()
        );

        $scan->setPath('conf'.DIRECTORY_SEPARATOR.'inside-link.ini');
        $this->assertSame(
            realpath($this->root.DIRECTORY_SEPARATOR.'php.ini'),
            $scan->getPath()
        );

        $inspection = $scan->inspectPath('conf'.DIRECTORY_SEPARATOR.'inside-link.ini');
        $this->assertSame('enter', $inspection['status']);
    }

    /**
     * Evidence "skip": a symlink pointing outside the root is reported as a
     * skip by inspect() and fails closed when resolution is attempted.
     */
    public function testEscapingSymlinkIsSkippedAndRejected()
    {
        $guard = new PathGuard($this->root);
        $candidates = array(
            'conf'.DIRECTORY_SEPARATOR.'escape-link.ini',
            'conf'.DIRECTORY_SEPARATOR.'escape-dir'.DIRECTORY_SEPARATOR.basename($this->outside),
        );

        foreach ($candidates as $candidate) {
            $inspection = $guard->inspect($candidate);
            $this->assertSame('skip', $inspection['status']);
            $this->assertSame(realpath($this->outside), $inspection['realPath']);
            $this->assertSame(realpath($this->root), $inspection['rootPath']);

            $thrown = null;
            try {
                $guard->resolve($candidate);
            } catch (PathOutsideRootException $exception) {
                $thrown = $exception;
            }
            $this->assertInstanceOf(
                '\Psecio\Iniscan\Exceptions\PathOutsideRootException',
                $thrown
            );

            $scan = new Scan(null, array(), null, null, $this->root);
            $thrown = null;
            try {
                $scan->setPath($candidate);
            } catch (PathOutsideRootException $exception) {
                $thrown = $exception;
            }
            $this->assertInstanceOf(
                '\Psecio\Iniscan\Exceptions\PathOutsideRootException',
                $thrown
            );
            $this->assertNull($scan->getPath());
        }
    }

    /**
     * Evidence "reject": control characters in the path fail closed.
     */
    public function testControlCharacterPathIsRejected()
    {
        $guard = new PathGuard($this->root);
        $inspection = $guard->inspect("php.ini\x00.txt");
        $this->assertSame('reject', $inspection['status']);

        $scan = new Scan(null, array(), null, null, $this->root);
        $thrown = null;
        try {
            $scan->setPath("php.ini\n");
        } catch (InvalidPathException $exception) {
            $thrown = $exception;
        }
        $this->assertInstanceOf(
            '\Psecio\Iniscan\Exceptions\InvalidPathException',
            $thrown
        );
        $this->assertInstanceOf('\InvalidArgumentException', $thrown);
    }

    /**
     * Evidence "reject": traversal and absolute paths outside the root fail.
     */
    public function testTraversalAndAbsoluteOutsidePathsAreRejected()
    {
        $scan = new Scan(null, array(), null, null, $this->root);

        $thrown = $this->expectPathFailure(
            $scan,
            '..'.DIRECTORY_SEPARATOR.'outside'.DIRECTORY_SEPARATOR.'secret.ini'
        );
        $this->assertInstanceOf('\InvalidArgumentException', $thrown);

        $thrown = $this->expectPathFailure($scan, $this->outside);
        $this->assertInstanceOf(
            '\Psecio\Iniscan\Exceptions\InvalidPathException',
            $thrown
        );
    }

    /**
     * Evidence "reject": a dangling symlink and a missing file cannot be
     * resolved, so the scan fails closed instead of guessing.
     */
    public function testUnresolvablePathsAreRejected()
    {
        $scan = new Scan(null, array(), null, null, $this->root);

        $thrown = null;
        try {
            $scan->setPath('conf'.DIRECTORY_SEPARATOR.'dangling-link.ini');
        } catch (InvalidPathException $exception) {
            $thrown = $exception;
        }
        $this->assertInstanceOf(
            '\Psecio\Iniscan\Exceptions\InvalidPathException',
            $thrown
        );

        $thrown = null;
        try {
            $scan->setPath('missing.ini');
        } catch (InvalidPathException $exception) {
            $thrown = $exception;
        }
        $this->assertInstanceOf(
            '\Psecio\Iniscan\Exceptions\InvalidPathException',
            $thrown
        );
        $this->assertInstanceOf('\InvalidArgumentException', $thrown);
    }

    /**
     * Identity check: replacing the file with an escaping symlink between
     * setPath() and parseConfig() is detected and fails closed.
     */
    public function testSwappedIdentityIsRejectedAtParseTime()
    {
        $target = $this->root.DIRECTORY_SEPARATOR.'swappable.ini';
        file_put_contents($target, "allow_url_include = Off\n");

        $scan = new Scan(null, array(), null, null, $this->root);
        $scan->setPath('swappable.ini');
        $this->assertSame(realpath($target), $scan->getPath());

        unlink($target);
        symlink($this->outside, $target);

        $thrown = null;
        try {
            $scan->parseConfig();
        } catch (\InvalidArgumentException $exception) {
            $thrown = $exception;
        }
        $this->assertInstanceOf('\InvalidArgumentException', $thrown);
        $this->assertTrue(
            $thrown instanceof InvalidPathException
                || $thrown instanceof PathOutsideRootException
        );
    }

    private function expectPathFailure(Scan $scan, $path)
    {
        try {
            $scan->setPath($path);
        } catch (\InvalidArgumentException $exception) {
            return $exception;
        }
        $this->fail('Expected an InvalidArgumentException for path: '.$path);
    }

    private function removeDirectory($directory)
    {
        if (!is_dir($directory)) {
            return;
        }
        $entries = scandir($directory);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory.DIRECTORY_SEPARATOR.$entry;
            if (is_link($path) || is_file($path)) {
                unlink($path);
            } else {
                $this->removeDirectory($path);
            }
        }
        rmdir($directory);
    }
}
