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
     * @var array
     */
    private $headers = array(
        "Connection: close\r\n",
        "User-Agent: Puli Installer\r\n",
    );

    /**
     * Creates the HTTP client.
     */
    public function __construct()
    {
        if (extension_loaded('zlib')) {
            $this->headers[] = "Accept-Encoding: gzip\r\n";
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
        humbug_set_headers($this->headers);
        $result = humbug_get_contents($url);

        if ($result && extension_loaded('zlib')) {
            $decode = false;

            foreach (humbug_get_headers() as $header) {
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
}
