<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\authentication;

use craft\elements\User;
use craft\web\Request;
use yii\base\InvalidArgumentException;

/**
 * Two factor interface
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.2
 */
abstract class TwoFactorManager
{
    // Properties
    // =========================================================================

    /**
     * @var User
     */
    public $user;

    // Public Methods
    // =========================================================================

    /**
     * TwoFactorManager constructor.
     *
     * @param User $user
     */
    public function __construct(User $user)
    {
        if (!\Craft::$app->getRequest() instanceof Request) {
            throw new InvalidArgumentException('Using 2 factor authentication on a Console Request is not allowed');
        }

        $this->user = $user;
    }

    /**
     * Used to initialize 2Fa on a user
     *
     * @return bool
     */
    abstract public function initialize() : bool;

    /**
     * Sends a code to the user that they can use to authenticate
     *
     * @return bool
     */
    abstract public function sendCode() : bool;

    /**
     * Authenticates a user for Two factor authentication
     *
     * @param string $code
     * @return bool
     */
    abstract public function authenticate(string $code) : bool;
}
