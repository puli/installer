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

use RuntimeException;

/**
 * Downloads files.
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
class HttpClient
{
    /**
     * The ciphers supported for TLS.
     */
    const CIPHERS = array(
        'ECDHE-RSA-AES128-GCM-SHA256',
        'ECDHE-ECDSA-AES128-GCM-SHA256',
        'ECDHE-RSA-AES256-GCM-SHA384',
        'ECDHE-ECDSA-AES256-GCM-SHA384',
        'DHE-RSA-AES128-GCM-SHA256',
        'DHE-DSS-AES128-GCM-SHA256',
        'kEDH+AESGCM',
        'ECDHE-RSA-AES128-SHA256',
        'ECDHE-ECDSA-AES128-SHA256',
        'ECDHE-RSA-AES128-SHA',
        'ECDHE-ECDSA-AES128-SHA',
        'ECDHE-RSA-AES256-SHA384',
        'ECDHE-ECDSA-AES256-SHA384',
        'ECDHE-RSA-AES256-SHA',
        'ECDHE-ECDSA-AES256-SHA',
        'DHE-RSA-AES128-SHA256',
        'DHE-RSA-AES128-SHA',
        'DHE-DSS-AES128-SHA256',
        'DHE-RSA-AES256-SHA256',
        'DHE-DSS-AES256-SHA',
        'DHE-RSA-AES256-SHA',
        'AES128-GCM-SHA256',
         'AES256-GCM-SHA384',
        'ECDHE-RSA-RC4-SHA',
        'ECDHE-ECDSA-RC4-SHA',
        'AES128',
        'AES256',
        'RC4-SHA',
        'HIGH',
        '!aNULL',
        '!eNULL',
        '!EXPORT',
        '!DES',
        '!3DES',
        '!MD5',
        '!PSK',
    );

    private $options = array('http' => array());

    /**
     * @var bool
     */
    private $tls = false;

    /**
     * @var string
     */
    private $cafile;

    /**
     * Returns the system's home directory.
     *
     * @return string The absolute path to the home directory.
     */
    private static function getHomeDirectory()
    {
        if ($home = getenv('PULI_HOME')) {
            return $home;
        }

        if (defined('PHP_WINDOWS_VERSION_MAJOR')) {
            if (!getenv('APPDATA')) {
                throw new RuntimeException('The APPDATA or PULI_HOME environment variable must be set for puli to install correctly');
            }

            return strtr(getenv('APPDATA'), '\\', '/').'/Puli';
        }

        if (!getenv('HOME')) {
            throw new RuntimeException('The HOME or PULI_HOME environment variable must be set for puli to install correctly');
        }

        return rtrim(getenv('HOME'), '/').'/.puli';
    }

    /**
     * Detects the CA file of the system.
     *
     * This method was adapted from Sslurp.
     * https://github.com/EvanDotPro/Sslurp.
     *
     * (c) Evan Coury <me@evancoury.com>
     *
     * For the full copyright and license information, please see below:
     *
     * Copyright (c) 2013, Evan Coury
     * All rights reserved.
     *
     * Redistribution and use in source and binary forms, with or without modification,
     * are permitted provided that the following conditions are met:
     *
     *     * Redistributions of source code must retain the above copyright notice,
     *       this list of conditions and the following disclaimer.
     *
     *     * Redistributions in binary form must reproduce the above copyright notice,
     *       this list of conditions and the following disclaimer in the documentation
     *       and/or other materials provided with the distribution.
     *
     * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
     * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
     * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
     * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR
     * ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
     * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
     * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON
     * ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
     * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
     * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
     */
    private static function detectSystemCaFile()
    {
        // If SSL_CERT_FILE env variable points to a valid certificate/bundle, use that.
        // This mimics how OpenSSL uses the SSL_CERT_FILE env variable.
        $envCertFile = getenv('SSL_CERT_FILE');

        if ($envCertFile && is_readable($envCertFile) && self::validateCaFile(file_get_contents($envCertFile))) {
            // Possibly throw exception instead of ignoring SSL_CERT_FILE if it's invalid?
            return $envCertFile;
        }

        $caBundlePaths = array(
            '/etc/pki/tls/certs/ca-bundle.crt', // Fedora, RHEL, CentOS (ca-certificates package)
            '/etc/ssl/certs/ca-certificates.crt', // Debian, Ubuntu, Gentoo, Arch Linux (ca-certificates package)
            '/etc/ssl/ca-bundle.pem', // SUSE, openSUSE (ca-certificates package)
            '/usr/local/share/certs/ca-root-nss.crt', // FreeBSD (ca_root_nss_package)
            '/usr/ssl/certs/ca-bundle.crt', // Cygwin
            '/opt/local/share/curl/curl-ca-bundle.crt', // OS X macports, curl-ca-bundle package
            '/usr/local/share/curl/curl-ca-bundle.crt', // Default cURL CA bunde path (without --with-ca-bundle option)
            '/usr/share/ssl/certs/ca-bundle.crt', // Really old RedHat?
            '/etc/ssl/cert.pem', // OpenBSD
        );

        $caBundle = ini_get('openssl.cafile');

        if ($caBundle && strlen($caBundle) > 0 && is_readable($caBundle) && self::validateCaFile(file_get_contents($caBundle))) {
            return $caBundle;
        }

        foreach ($caBundlePaths as $caBundle) {
            if (@is_readable($caBundle) && self::validateCaFile(file_get_contents($caBundle))) {
                return $caBundle;
            }
        }

        foreach ($caBundlePaths as $caBundle) {
            $caBundle = dirname($caBundle);

            if (is_dir($caBundle) && glob($caBundle.'/*')) {
                return $caBundle;
            }
        }

        return null;
    }

    /**
     * Verifies whether the given CA file contents are valid.
     *
     * @param string $contents The contents of the CA file.
     *
     * @return bool Returns `true` if the contents are valid and `false`
     *              otherwise.
     */
    private static function validateCaFile($contents)
    {
        // assume the CA is valid if php is vulnerable to
        // https://www.sektioneins.de/advisories/advisory-012013-php-openssl_x509_parse-memory-corruption-vulnerability.html
        if (
            PHP_VERSION_ID <= 50327
            || (PHP_VERSION_ID >= 50400 && PHP_VERSION_ID < 50422)
            || (PHP_VERSION_ID >= 50500 && PHP_VERSION_ID < 50506)
        ) {
            return !empty($contents);
        }

        return (bool) openssl_x509_parse($contents);
    }

    /**
     * Installs the default CA file at the given path.
     *
     * @param string $targetPath The path where to install the default CA file.
     */
    private static function installDefaultCaFile($targetPath)
    {
        ErrorHandler::register();

        $dir = dirname($targetPath);

        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $written = file_put_contents($targetPath, MozillaCaFile::getContent(), LOCK_EX);

        @chmod($targetPath, 0644);

        ErrorHandler::unregister();

        if (!$written) {
            throw new RuntimeException(sprintf(
                'Unable to write bundled cacert.pem to %s',
                $targetPath
            ));
        }
    }

    /**
     * Creates the HTTP client.
     *
     * @param bool   $disableTls Whether to disable TLS.
     * @param string $cafile     The path to the CA file that should be used.
     */
    public function __construct($disableTls = false, $cafile = null)
    {
        $this->tls = !$disableTls;
        $this->cafile = $cafile;

        if ($this->tls) {
            if (empty($this->cafile)) {
                $this->cafile = self::detectSystemCaFile();
            } elseif (!is_readable($this->cafile)) {
                throw new RuntimeException(sprintf(
                    'The configured cafile (%s) was not valid.',
                    $this->cafile
                ));
            } elseif (!self::validateCaFile(file_get_contents($this->cafile))) {
                throw new RuntimeException(sprintf(
                    'The configured cafile (%s) could not be read.',
                    $this->cafile
                ));
            }

            // No system CA file detected. Create one.
            if (empty($this->cafile)) {
                $this->cafile = self::getHomeDirectory().'/cacert.pem';

                self::installDefaultCaFile($this->cafile);
            }

            $this->options = array_replace_recursive(
                $this->options,
                $this->getTlsStreamContextDefaults()
            );
        }
    }

    /**
     * Downloads a file.
     *
     * @param string $url The URL of the file to download.
     *
     * @return string The downloaded file body.
     */
    public function download($url)
    {
        $context = $this->getStreamContext($url);
        $result = file_get_contents($url, null, $context);

        if ($result && extension_loaded('zlib')) {
            $decode = false;

            // $http_response_header is populated by file_get_contents()
            foreach ($http_response_header as $header) {
                if (preg_match('{^content-encoding: *gzip *$}i', $header)) {
                    $decode = true;
                    continue;
                } elseif (preg_match('{^HTTP/}i', $header)) {
                    $decode = false;
                }
            }

            if ($decode) {
                $result = version_compare(PHP_VERSION, '5.4.0', '>=')
                    ? zlib_decode($result)
                    // work around issue with gzuncompress & co that do not work with all gzip checksums
                    : file_get_contents('compress.zlib://data:application/octet-stream;base64,'.base64_encode($result));

                if (!$result) {
                    throw new RuntimeException('Failed to decode zlib stream');
                }
            }
        }

        return $result;
    }

    private function getStreamContext($url)
    {
        if ($this->tls) {
            $host = parse_url($url, PHP_URL_HOST);

            if (PHP_VERSION_ID < 50600) {
                $this->options['ssl']['CN_match'] = $host;
                $this->options['ssl']['SNI_server_name'] = $host;
            }
        }

        // Keeping the above mostly isolated from the code copied from Puli.
        return $this->getMergedStreamContext($url);
    }

    private function getTlsStreamContextDefaults()
    {
        // "CN_match" and "SNI_server_name" are only known once a URL is passed.
        // They will be set in the getOptionsForUrl() method which receives a URL.
        //
        // "cafile" or "capath" can be overridden by passing in those options to
        // constructor.
        $options = array(
            'ssl' => array(
                'ciphers' => implode(':', self::CIPHERS),
                'verify_peer' => true,
                'verify_depth' => 7,
                'SNI_enabled' => true,
            ),
        );

        if (is_dir($this->cafile)) {
            $options['ssl']['capath'] = $this->cafile;
        } else {
            $options['ssl']['cafile'] = $this->cafile;
        }

        // Disable TLS compression to prevent CRIME attacks where supported.
        if (version_compare(PHP_VERSION, '5.4.13') >= 0) {
            $options['ssl']['disable_compression'] = true;
        }

        return $options;
    }

    /**
     * Function copied from Composer\Util\StreamContextFactory::getContext.
     *
     * Any changes should be applied there as well, or backported here.
     *
     * @param string $url URL the context is to be used for
     *
     * @return resource Default context
     *
     * @throws RuntimeException if https proxy required and OpenSSL uninstalled
     */
    private function getMergedStreamContext($url)
    {
        $options = $this->options;

        // Handle system proxy
        if (!empty($_SERVER['HTTP_PROXY']) || !empty($_SERVER['http_proxy'])) {
            // Some systems seem to rely on a lowercased version instead...
            $proxy = parse_url(!empty($_SERVER['http_proxy']) ? $_SERVER['http_proxy'] : $_SERVER['HTTP_PROXY']);
        }

        if (!empty($proxy)) {
            $proxyURL = isset($proxy['scheme']) ? $proxy['scheme'].'://' : '';
            $proxyURL .= isset($proxy['host']) ? $proxy['host'] : '';

            if (isset($proxy['port'])) {
                $proxyURL .= ':'.$proxy['port'];
            } elseif ('http://' === substr($proxyURL, 0, 7)) {
                $proxyURL .= ':80';
            } elseif ('https://' === substr($proxyURL, 0, 8)) {
                $proxyURL .= ':443';
            }

            // http(s):// is not supported in proxy
            $proxyURL = str_replace(array('http://', 'https://'), array('tcp://', 'ssl://'), $proxyURL);

            if (0 === strpos($proxyURL, 'ssl:') && !extension_loaded('openssl')) {
                throw new RuntimeException('You must enable the openssl extension to use a proxy over https');
            }

            $options['http'] = array(
                'proxy' => $proxyURL,
            );

            // enabled request_fulluri unless it is explicitly disabled
            switch (parse_url($url, PHP_URL_SCHEME)) {
                case 'http': // default request_fulluri to true
                    $reqFullUriEnv = getenv('HTTP_PROXY_REQUEST_FULLURI');
                    if ($reqFullUriEnv === false || $reqFullUriEnv === '' || (strtolower($reqFullUriEnv) !== 'false' && (bool) $reqFullUriEnv)) {
                        $options['http']['request_fulluri'] = true;
                    }
                    break;
                case 'https': // default request_fulluri to true
                    $reqFullUriEnv = getenv('HTTPS_PROXY_REQUEST_FULLURI');
                    if ($reqFullUriEnv === false || $reqFullUriEnv === '' || (strtolower($reqFullUriEnv) !== 'false' && (bool) $reqFullUriEnv)) {
                        $options['http']['request_fulluri'] = true;
                    }
                    break;
            }

            if (isset($proxy['user'])) {
                $auth = urldecode($proxy['user']);
                if (isset($proxy['pass'])) {
                    $auth .= ':'.urldecode($proxy['pass']);
                }
                $auth = base64_encode($auth);

                $options['http']['header'] = "Proxy-Authorization: Basic {$auth}\r\n";
            }
        }

        if (isset($options['http']['header'])) {
            $options['http']['header'] .= "Connection: close\r\n";
        } else {
            $options['http']['header'] = "Connection: close\r\n";
        }
        if (extension_loaded('zlib')) {
            $options['http']['header'] .= "Accept-Encoding: gzip\r\n";
        }
        $options['http']['header'] .= "User-Agent: Puli Installer\r\n";
        $options['http']['protocol_version'] = 1.1;

        return stream_context_create($options);
    }
}
