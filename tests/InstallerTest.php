<?php

/*
 * This file is part of the puli/installer package.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Puli\Installer\Tests;

use PHPUnit_Framework_TestCase;
use Puli\Installer\Installer;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @author Bernhard Schussek <bschussek@gmail.com>
 *
 * @runTestsInSeparateProcesses
 */
class InstallerTest extends PHPUnit_Framework_TestCase
{
    private $workingDir;

    private $tempDir;

    private $homeDir;

    private $rootDir;

    protected function setUp()
    {
        $this->tempDir = TestUtil::makeTempDir('puli-installer', __CLASS__);
        $this->homeDir = $this->tempDir.'/home';
        $this->rootDir = $this->tempDir.'/root';
        $this->workingDir = getcwd();

        mkdir($this->homeDir);
        mkdir($this->rootDir);

        putenv('PULI_HOME='.$this->homeDir);

        chdir($this->rootDir);

        // Remove env variables, just to make sure...
        putenv('APPDATA=');
        putenv('HOME=');
    }

    protected function tearDown()
    {
        chdir($this->workingDir);

        $filesystem = new Filesystem();
        $filesystem->remove($this->tempDir);

        putenv('PULI_HOME=');
    }

    public function testInstallWithVersion()
    {
        $installer = new Installer();

        ob_start();

        $expected = <<<EOF
All settings correct for using Puli
Downloading available versions...
Downloading puli.phar at version 1.0.0-beta9...

Puli successfully installed to: {$this->rootDir}/puli.phar
Use it: php {$this->rootDir}/puli.phar

EOF;

        $status = $installer->run(array('--version', '1.0.0-beta9', '--no-ansi'));

        $this->assertSame(str_replace("\n", PHP_EOL, $expected), ob_get_clean());
        $this->assertFileExists($this->rootDir.'/puli.phar');
        $this->assertSame(0, $status);
    }

    public function testInstallWithoutVersion()
    {
        $installer = new Installer();

        ob_start();

        $expected = <<<EOF
All settings correct for using Puli
Downloading available versions...
Downloading puli.phar at version %s...

Puli successfully installed to: {$this->rootDir}/puli.phar
Use it: php {$this->rootDir}/puli.phar

EOF;

        $status = $installer->run(array('--no-ansi'));

        $this->assertStringMatchesFormat(str_replace("\n", PHP_EOL, $expected), ob_get_clean());
        $this->assertFileExists($this->rootDir.'/puli.phar');
        $this->assertSame(0, $status);
    }

    public function testInstallStableIsNotPossible()
    {
        $installer = new Installer();

        ob_start();

        $expected = <<<EOF
All settings correct for using Puli
Downloading available versions...
Fatal: Could not find a stable version.

EOF;

        $status = $installer->run(array('--stable'));

        $this->assertSame(str_replace("\n", PHP_EOL, $expected), ob_get_clean());
        $this->assertFileNotExists($this->rootDir.'/puli.phar');
        $this->assertSame(1, $status);
    }
}
