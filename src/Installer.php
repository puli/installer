<?php

/*
 * This file is part of the puli/installer package.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Puli\Installer;

use Exception;
use Phar;
use PharException;
use UnexpectedValueException;

/**
 * Installs the puli.phar on the local system.
 *
 * Use it like this:
 *
 * ```
 * $ curl -sS https://puli.io/installer | php
 * ```
 *
 * This file was adapted from the installer file bundled with Composer. For the
 * original file, authors and copyright information see
 *
 * https://github.com/composer/getcomposer.org/blob/master/web/installer
 *
 * @author Nils Adermann <naderman@naderman.de>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Thomas Rudolph <me@holloway-web.de>
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class Installer
{
    /**
     * The help text of the installer.
     */
    const HELP_TEXT = <<<HELP
Puli Installer
--------------
Options
--help               this help
--check              for checking environment only
--force              forces the installation even if the system requirements are not satisfied
--ansi               force ANSI color output
--no-ansi            disable ANSI color output
--quiet              do not output unimportant messages
--install-dir="..."  set the target installation directory
--version="..."      install a specific version
--filename="..."     set the target filename (default: puli.phar)
--disable-tls        disable SSL/TLS security for file downloads
--cafile="..."       set the path to a Certificate Authority (CA) certificate file for SSL/TLS verification

HELP;

    /**
     * The api url to determine the available versions of puli.
     */
    const VERSION_API_URL = '%s://puli.io/download/versions.json';

    /**
     * The phar download url.
     */
    const PHAR_DOWNLOAD_URL = '%s://puli.io/download/%s/puli.phar';

    /**
     * @var string
     */
    private $stability;

    /**
     * @var bool
     */
    private $check;

    /**
     * @var bool
     */
    private $help;

    /**
     * @var bool
     */
    private $force;

    /**
     * @var bool
     */
    private $quiet;

    /**
     * @var bool
     */
    private $disableTls;

    /**
     * @var string
     */
    private $installDir;

    /**
     * @var string
     */
    private $version;

    /**
     * @var string
     */
    private $filename;

    /**
     * @var string
     */
    private $cafile;

    /**
     * Runs the installer.
     *
     * @param array $argv The console arguments.
     *
     * @return int The return status.
     */
    public function run(array $argv)
    {
        $this->parseOptions($argv);

        if ($this->help) {
            echo self::HELP_TEXT;

            return 0;
        }

        $ok = $this->validateSystem() && $this->validateOptions();

        if ($this->disableTls) {
            $this->info(
                'You have instructed the Installer not to enforce SSL/TLS '.
                'security on remote HTTPS requests.'.PHP_EOL.
                'This will leave all downloads during installation vulnerable '.
                'to Man-In-The-Middle (MITM) attacks.'
            );
        }

        if ($this->check) {
            return $ok ? 0 : 1;
        }

        if ($ok || $this->force) {
            return $this->installPuli();
        }

        return 1;
    }

    /**
     * Installs puli to the current working directory.
     *
     * @return int The return status.
     *
     * @throws Exception
     */
    private function installPuli()
    {
        $installDir = is_dir($this->installDir) ? realpath($this->installDir) : getcwd();
        $installPath = str_replace('\\', '/', $installDir).'/'.$this->filename;

        if (is_readable($installPath)) {
            @unlink($installPath);
        }

        $httpClient = new HttpClient($this->disableTls, $this->cafile);

        $versionUrl = sprintf(
            static::VERSION_API_URL,
            $this->disableTls ? 'http' : 'https'
        );

        for ($retries = 3; $retries > 0; --$retries) {
            $versions = json_decode($httpClient->download($versionUrl), true);

            if (null === $versions) {
                continue;
            }

            break;
        }

        if (0 === $retries) {
            $this->error('The download failed repeatedly, aborting.');

            return 1;
        }

        usort($versions, 'version_compare');

        if (!empty($this->version)) {
            if (!$this->validateVersion($this->version, $versions)) {
                $this->error(sprintf(
                    'Could not found version: %s, Aborting.',
                    $this->version
                ));

                return 1;
            }
        } elseif ('stable' === $this->stability) {
            $this->version = $this->getLatestStableVersion($versions);
            if (null === $this->version) {
                $this->error('Could not found any stable version, Aborting.');

                return 1;
            }
        } else {
            $this->version = $this->getLatestVersion($versions);
        }

        $url = sprintf(
            static::PHAR_DOWNLOAD_URL,
            $this->disableTls ? 'http' : 'https',
            $this->version
        );

        for ($retries = 3; $retries > 0; --$retries) {
            if (!$this->quiet) {
                $this->info('Downloading...');
            }

            if (!$this->downloadFile($httpClient, $url, $installPath)) {
                continue;
            }

            try {
                $this->validatePhar($installPath);
            } catch (Exception $e) {
                unlink($installPath);

                if (!$e instanceof UnexpectedValueException && !$e instanceof PharException) {
                    throw $e;
                }

                if ($retries > 0) {
                    if (!$this->quiet) {
                        $this->error('The download is corrupt, retrying...');
                    }
                } else {
                    $this->error(sprintf(
                        'The download is corrupt (%s), aborting.',
                        $e->getMessage()
                    ));

                    return 1;
                }
            }

            break;
        }

        if (0 === $retries) {
            $this->error('The download failed repeatedly, aborting.');

            return 1;
        }

        chmod($installPath, 0755);

        if (!$this->quiet) {
            $this->success(PHP_EOL.'Puli successfully installed to: '.$installPath);
            $this->info('Use it: php '.$installPath);
        }

        return 0;
    }

    /**
     * Validates if the passed version is valid.
     *
     * @param string $version The passed version.
     * @param array $versions Available versions.
     *
     * @return bool Whether the version is valid.
     */
    private function validateVersion($version, array $versions)
    {
        return in_array($version, $versions);
    }

    /**
     * Returns the last stable version.
     *
     * @param array $versions The available versions.
     *
     * @return string|null The latest stable version, null if there is no stable version.
     */
    private function getLatestStableVersion(array $versions)
    {
        foreach (array_reverse($versions) as $version) {
            if (1 === preg_match('/^\d+\.\d+\.\d+$/', $version)) {
                return $version;
            }
        }

        return null;
    }

    /**
     * Returns the latest version.
     *
     * @param array $versions The available versions.
     *
     * @return string The latest version.
     */
    private function getLatestVersion(array $versions)
    {
        return end($versions);
    }

    /**
     * Downloads a URL to a path.
     *
     * @param HttpClient $httpClient The client to use.
     * @param string     $url        The URL to download.
     * @param string     $targetPath The path to download the URL to.
     *
     * @return bool Whether the download completed successfully.
     */
    private function downloadFile(HttpClient $httpClient, $url, $targetPath)
    {
        ErrorHandler::register();

        $fh = fopen($targetPath, 'w');

        if (!$fh) {
            $this->error(sprintf(
                'Could not create file %s: %s',
                $targetPath,
                implode(PHP_EOL, ErrorHandler::getErrors())
            ));
        }

        if (!fwrite($fh, $httpClient->download($url))) {
            $this->error(sprintf(
                'Download failed: %s',
                implode(PHP_EOL, ErrorHandler::getErrors())
            ));
        }

        fclose($fh);

        ErrorHandler::unregister();

        return !ErrorHandler::hasErrors();
    }

    /**
     * Validates that the given path is a valid PHAR file.
     *
     * @param string $path A path to a PHAR file.
     *
     * @throws Exception If an error occurs.
     */
    private function validatePhar($path)
    {
        $tmpFile = $path;

        try {
            // create a temp file ending in .phar since the Phar class only accepts that
            if ('.phar' !== substr($path, -5)) {
                copy($path, $path.'.tmp.phar');
                $tmpFile = $path.'.tmp.phar';
            }

            if (!ini_get('phar.readonly')) {
                // test the phar validity
                $phar = new Phar($tmpFile);

                // free the variable to unlock the file
                unset($phar);
            }
        } catch (Exception $e) {
            // clean up temp file if needed
            if ($path !== $tmpFile) {
                unlink($tmpFile);
            }

            throw $e;
        }

        // clean up temp file if needed
        if ($path !== $tmpFile) {
            unlink($tmpFile);
        }
    }

    /**
     * Check the platform for possible issues on running Puli.
     *
     * @return bool Whether the platform requirements are satisfied.
     */
    private function validateSystem()
    {
        $errors = array();
        $warnings = array();

        $iniPath = php_ini_loaded_file();
        $displayIniMessage = false;

        if ($iniPath) {
            $iniMessage = PHP_EOL.PHP_EOL.'The php.ini used by your command-line PHP is: '.$iniPath;
        } else {
            $iniMessage = PHP_EOL.PHP_EOL.'A php.ini file does not exist. You will have to create one.';
        }

        $iniMessage .= PHP_EOL.'If you can not modify the ini file, you can also run `php -d option=value` to modify ini values on the fly. You can use -d multiple times.';

        if (ini_get('detect_unicode')) {
            $errors['unicode'] = 'On';
        }

        if (extension_loaded('suhosin')) {
            $suhosin = ini_get('suhosin.executor.include.whitelist');
            $suhosinBlacklist = ini_get('suhosin.executor.include.blacklist');
            if (false === stripos($suhosin,
                    'phar') && (!$suhosinBlacklist || false !== stripos($suhosinBlacklist,
                        'phar'))
            ) {
                $errors['suhosin'] = $suhosin;
            }
        }

        if (!function_exists('json_decode')) {
            $errors['json'] = true;
        }

        if (!extension_loaded('Phar')) {
            $errors['phar'] = true;
        }

        if (!ini_get('allow_url_fopen')) {
            $errors['allow_url_fopen'] = true;
        }

        if (extension_loaded('ionCube Loader') && ioncube_loader_iversion() < 40009) {
            $errors['ioncube'] = ioncube_loader_version();
        }

        if (version_compare(PHP_VERSION, '5.3.9', '<')) {
            $errors['php'] = PHP_VERSION;
        }

        if (!extension_loaded('openssl') && $this->disableTls) {
            $warnings['openssl'] = true;
        } elseif (!extension_loaded('openssl')) {
            $errors['openssl'] = true;
        }

        if (!defined('HHVM_VERSION') && !extension_loaded('apcu') && ini_get('apc.enable_cli')) {
            $warnings['apc_cli'] = true;
        }

        ob_start();
        phpinfo(INFO_GENERAL);
        $phpinfo = ob_get_clean();
        if (preg_match('{Configure Command(?: *</td><td class="v">| *=> *)(.*?)(?:</td>|$)}m',
            $phpinfo, $match)) {
            $configure = $match[1];

            if (false !== strpos($configure, '--enable-sigchild')) {
                $warnings['sigchild'] = true;
            }

            if (false !== strpos($configure, '--with-curlwrappers')) {
                $warnings['curlwrappers'] = true;
            }
        }

        if (!empty($errors)) {
            $this->error('Some settings on your machine make Puli unable to work properly.');
            $this->error('Make sure that you fix the issues listed below and run this script again:');

            foreach ($errors as $error => $current) {
                $text = '';
                switch ($error) {
                    case 'json':
                        $text = PHP_EOL.'The json extension is missing.'.PHP_EOL;
                        $text .= 'Install it or recompile php without --disable-json';
                        break;

                    case 'phar':
                        $text = PHP_EOL.'The phar extension is missing.'.PHP_EOL;
                        $text .= 'Install it or recompile php without --disable-phar';
                        break;

                    case 'unicode':
                        $text = PHP_EOL.'The detect_unicode setting must be disabled.'.PHP_EOL;
                        $text .= 'Add the following to the end of your `php.ini`:'.PHP_EOL;
                        $text .= '    detect_unicode = Off';
                        $displayIniMessage = true;
                        break;

                    case 'suhosin':
                        $text = PHP_EOL.'The suhosin.executor.include.whitelist setting is incorrect.'.PHP_EOL;
                        $text .= 'Add the following to the end of your `php.ini` or suhosin.ini (Example path [for Debian]: /etc/php5/cli/conf.d/suhosin.ini):'.PHP_EOL;
                        $text .= '    suhosin.executor.include.whitelist = phar '.$current;
                        $displayIniMessage = true;
                        break;

                    case 'php':
                        $text = PHP_EOL."Your PHP ({$current}) is too old, you must upgrade to PHP 5.3.9 or higher.";
                        break;

                    case 'allow_url_fopen':
                        $text = PHP_EOL.'The allow_url_fopen setting is incorrect.'.PHP_EOL;
                        $text .= 'Add the following to the end of your `php.ini`:'.PHP_EOL;
                        $text .= '    allow_url_fopen = On';
                        $displayIniMessage = true;
                        break;

                    case 'ioncube':
                        $text = PHP_EOL."Your ionCube Loader extension ($current) is incompatible with Phar files.".PHP_EOL;
                        $text .= 'Upgrade to ionCube 4.0.9 or higher or remove this line (path may be different) from your `php.ini` to disable it:'.PHP_EOL;
                        $text .= '    zend_extension = /usr/lib/php5/20090626+lfs/ioncube_loader_lin_5.3.so';
                        $displayIniMessage = true;
                        break;

                    case 'openssl':
                        $text = PHP_EOL.'The openssl extension is missing, which means that secure HTTPS transfers are impossible.'.PHP_EOL;
                        $text .= 'If possible you should enable it or recompile php with --with-openssl';
                        break;
                }

                if ($displayIniMessage) {
                    $text .= $iniMessage;
                }

                $this->info($text);
            }

            echo PHP_EOL;

            return false;
        }

        if (!empty($warnings)) {
            $this->error('Some settings on your machine may cause stability issues with Puli.');
            $this->error('If you encounter issues, try to change the following:');

            foreach ($warnings as $warning => $current) {
                $text = '';
                switch ($warning) {
                    case 'apc_cli':
                        $text = PHP_EOL.'The apc.enable_cli setting is incorrect.'.PHP_EOL;
                        $text .= 'Add the following to the end of your `php.ini`:'.PHP_EOL;
                        $text .= '    apc.enable_cli = Off';
                        $displayIniMessage = true;
                        break;

                    case 'sigchild':
                        $text = PHP_EOL.'PHP was compiled with --enable-sigchild which can cause issues on some platforms.'.PHP_EOL;
                        $text .= 'Recompile it without this flag if possible, see also:'.PHP_EOL;
                        $text .= '    https://bugs.php.net/bug.php?id=22999';
                        break;

                    case 'curlwrappers':
                        $text = PHP_EOL.'PHP was compiled with --with-curlwrappers which will cause issues with HTTP authentication and GitHub.'.PHP_EOL;
                        $text .= 'Recompile it without this flag if possible';
                        break;

                    case 'openssl':
                        $text = PHP_EOL.'The openssl extension is missing, which means that secure HTTPS transfers are impossible.'.PHP_EOL;
                        $text .= 'If possible you should enable it or recompile php with --with-openssl';
                        break;
                }
                if ($displayIniMessage) {
                    $text .= $iniMessage;
                }
                $this->info($text);
            }

            echo PHP_EOL;

            return true;
        }

        if (!$this->quiet) {
            $this->success('All settings correct for using Puli');
        }

        return true;
    }

    /**
     * Validate whether the passed command line options are correct.
     *
     * @return bool Returns `true` if the options are valid and `false` otherwise.
     */
    private function validateOptions()
    {
        $ok = true;

        if (false !== $this->installDir && !is_dir($this->installDir)) {
            $this->info(sprintf(
                'The defined install dir (%s) does not exist.',
                $this->installDir
            ));
            $ok = false;
        }

        if (false !== $this->version && 1 !== preg_match('/^\d+\.\d+\.\d+(\-(alpha|beta)\d+)*$/', $this->version)) {
            $this->info(sprintf(
                'The defined install version (%s) does not match release pattern.',
                $this->version
            ));
            $ok = false;
        }

        if (false !== $this->cafile && (!file_exists($this->cafile) || !is_readable($this->cafile))) {
            $this->info(sprintf(
                'The defined Certificate Authority (CA) cert file (%s) does '.
                'not exist or is not readable.',
                $this->cafile
            ));
            $ok = false;
        }

        return $ok;
    }

    /**
     * Parses the command line options.
     *
     * @param string[] $argv The command line options.
     */
    private function parseOptions(array $argv)
    {
        $this->check = in_array('--check', $argv);
        $this->help = in_array('--help', $argv);
        $this->force = in_array('--force', $argv);
        $this->quiet = in_array('--quiet', $argv);
        $this->disableTls = in_array('--disable-tls', $argv);
        $this->installDir = false;
        $this->version = false;
        $this->filename = 'puli.phar';
        $this->cafile = false;
        $this->stability = 'unstable';
        if (in_array('--stable', $argv)) {
            $this->stability = 'stable';
        }

        if (in_array('--unstable', $argv)){
            $this->stability = 'unstable';
        }

        // --no-ansi wins over --ansi
        if (in_array('--no-ansi', $argv)) {
            define('USE_ANSI', false);
        } elseif (in_array('--ansi', $argv)) {
            define('USE_ANSI', true);
        } else {
            // On Windows, default to no ANSI, except in ANSICON and ConEmu.
            // Everywhere else, default to ANSI if stdout is a terminal.
            define('USE_ANSI', ('\\' === DIRECTORY_SEPARATOR)
                ? (false !== getenv('ANSICON') || 'ON' === getenv('ConEmuANSI'))
                : (function_exists('posix_isatty') && posix_isatty(1)));
        }

        foreach ($argv as $key => $val) {
            if (0 === strpos($val, '--install-dir')) {
                if (13 === strlen($val) && isset($argv[$key + 1])) {
                    $this->installDir = trim($argv[$key + 1]);
                } else {
                    $this->installDir = trim(substr($val, 14));
                }
            }

            if (0 === strpos($val, '--version')) {
                if (9 === strlen($val) && isset($argv[$key + 1])) {
                    $this->version = trim($argv[$key + 1]);
                } else {
                    $this->version = trim(substr($val, 10));
                }
            }

            if (0 === strpos($val, '--filename')) {
                if (10 === strlen($val) && isset($argv[$key + 1])) {
                    $this->filename = trim($argv[$key + 1]);
                } else {
                    $this->filename = trim(substr($val, 11));
                }
            }

            if (0 === strpos($val, '--cafile')) {
                if (8 === strlen($val) && isset($argv[$key + 1])) {
                    $this->cafile = trim($argv[$key + 1]);
                } else {
                    $this->cafile = trim(substr($val, 9));
                }
            }
        }
    }

    /**
     * Prints output indicating a success.
     *
     * @param string $text The text to print.
     */
    private function success($text)
    {
        printf(USE_ANSI ? "\033[0;32m%s\033[0m" : '%s', $text.PHP_EOL);
    }

    /**
     * Prints output indicating an error.
     *
     * @param string $text The text to print.
     */
    private function error($text)
    {
        printf(USE_ANSI ? "\033[31;31m%s\033[0m" : '%s', $text.PHP_EOL);
    }

    /**
     * Prints output indicating some information.
     *
     * @param string $text The text to print.
     */
    private function info($text)
    {
        printf(USE_ANSI ? "\033[33;33m%s\033[0m" : '%s', $text.PHP_EOL);
    }
}
