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

/**
 * Handles and stores errors.
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
final class ErrorHandler
{
    /**
     * @var string[]
     */
    private static $errors = array();

    /**
     * Registers the error handler.
     */
    public static function register()
    {
        self::$errors = array();

        set_error_handler(array(__CLASS__, 'handleError'));
    }

    /**
     * Unregisters the error handler.
     */
    public static function unregister()
    {
        restore_error_handler();
    }

    /**
     * Returns the caught errors.
     *
     * @return string[] The caught error messages.
     */
    public static function getErrors()
    {
        return self::$errors;
    }

    /**
     * Returns whether the handler caught any errors.
     *
     * @return bool Returns `true` if the handler caught errors and `false`
     *              otherwise.
     */
    public static function hasErrors()
    {
        return count(self::$errors) > 0;
    }

    /**
     * Handles an error.
     *
     * @param int    $code    The error code.
     * @param string $message The error message.
     */
    public static function handleError($code, $message)
    {
        self::$errors[] = preg_replace('{^copy\(.*?\): }', '', $message);
    }

    private function __construct()
    {
    }
}
