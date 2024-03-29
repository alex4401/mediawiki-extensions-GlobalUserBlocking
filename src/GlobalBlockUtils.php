<?php

/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

namespace MediaWiki\Extension\GlobalUserBlocking;

use CentralIdLookup;
use MediaWiki\Block\BlockUtils;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\MainConfigNames;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityLookup;
use MediaWiki\User\UserIdentityValue;
use MediaWiki\User\UserNameUtils;
use Status;
use Wikimedia\IPUtils;

/**
 * Backend class for blocking utils
 *
 * This service should contain any methods that are useful
 * to more than one blocking-related class and doesn't fit any
 * other service.
 *
 * For now, this includes only
 * - block target parsing
 * - block target validation
 *
 * @since 1.36
 */
class GlobalBlockUtils {
    public const SERVICE_NAME = 'GlobalUserBlocking.GlobalBlockUtils';

    /** @var ServiceOptions */
    private $options;

    /** @var BlockUtils */
    private $blockUtils;

    /** @var CentralIdLookup */
    private $centralIdLookup;

    /** @var UserIdentityLookup */
    private $userIdentityLookup;

    /** @var UserNameUtils */
    private $userNameUtils;

    /**
     * @internal Only for use by ServiceWiring
     */
    public const CONSTRUCTOR_OPTIONS = [
        MainConfigNames::BlockCIDRLimit,
    ];

    /**
     * @param ServiceOptions $options
     * @param BlockUtils $blockUtils
     * @param CentralIdLookup $centralIdLookup
     * @param UserIdentityLookup $userIdentityLookup
     * @param UserNameUtils $userNameUtils
     */
    public function __construct(
        ServiceOptions $options,
        BlockUtils $blockUtils,
        CentralIdLookup $centralIdLookup,
        UserIdentityLookup $userIdentityLookup,
        UserNameUtils $userNameUtils
    ) {
        $options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
        $this->options = $options;
        $this->blockUtils = $blockUtils;
        $this->centralIdLookup = $centralIdLookup;
        $this->userIdentityLookup = $userIdentityLookup;
        $this->userNameUtils = $userNameUtils;
    }

    /**
     * From an existing block, get the target and the type of target.
     *
     * Note that, except for null, it is always safe to treat the target
     * as a string; for UserIdentityValue objects this will return
     * UserIdentityValue::__toString() which in turn gives
     * UserIdentityValue::getName().
     *
     * If the type is not null, it will be an AbstractBlock::TYPE_ constant.
     *
     * @param string|int|UserIdentity|null $target
     * @return array [ UserIdentity|String|null, int|null ]
     */
    public function parseBlockTarget( $target ): array {
        // We may have been through this before
        if ( $target instanceof UserIdentity ) {
            if ( IPUtils::isValid( $target->getName() ) ) {
                return [ $target, GlobalBlock::TYPE_IP ];
            } else {
                return [ $target, GlobalBlock::TYPE_USER ];
            }
        } elseif ( $target === null ) {
            return [ null, null ];
        }

        $trimmedTarget = trim( $target );

        if ( IPUtils::isValid( $trimmedTarget ) ) {
            return [
                UserIdentityValue::newAnonymous( IPUtils::sanitizeIP( $trimmedTarget ) ),
                GlobalBlock::TYPE_IP
            ];

        } elseif ( IPUtils::isValidRange( $trimmedTarget ) ) {
            // Can't create a UserIdentity from an IP range
            return [ IPUtils::sanitizeRange( $trimmedTarget ), GlobalBlock::TYPE_RANGE ];
        }

        if ( preg_match( '/^#\d+$/', $target ) ) {
            // ID reference
            return [ substr( $target, 1 ), GlobalBlock::TYPE_ID ];
        }

        $userFromDB = null;
        if ( is_int( $target ) ) {
            $userFromDB = $this->centralIdLookup->localUserFromCentralId( $target, CentralIdLookup::AUDIENCE_RAW );
        } else {
            $userFromDB = $this->userIdentityLookup->getUserIdentityByName( $trimmedTarget );
        }

        if ( $userFromDB instanceof UserIdentity ) {
            // Note that since numbers are valid usernames, a $target of "12345" will be
            // considered a UserIdentity. If you want to pass a block ID, prepend a hash "#12345",
            // since hash characters are not valid in usernames or titles generally.
            return [ $userFromDB, GlobalBlock::TYPE_USER ];
        }

        return [ null, null ];
    }

    /**
     * From an existing block, get the target and the type of target.
     *
     * Note that, except for null, it is always safe to treat the target
     * as a string; for UserIdentityValue objects this will return
     * UserIdentityValue::__toString() which in turn gives
     * UserIdentityValue::getName().
     *
     * If the type is not null, it will be an AbstractBlock::TYPE_ constant.
     *
     * @param string|UserIdentity|null $target
     * @return array [ UserIdentity|String|null, int|null ]
     */
    public function parseInternalBlockTarget( $target ): array {
        // We may have been through this before
        if ( $target instanceof UserIdentity ) {
            if ( IPUtils::isValid( $target->getName() ) ) {
                return [ $target, GlobalBlock::TYPE_IP ];
            } else {
                return [ $target, GlobalBlock::TYPE_USER ];
            }
        } elseif ( $target === null ) {
            return [ null, null ];
        }

        $target = trim( $target );

        if ( IPUtils::isValid( $target ) ) {
            return [
                UserIdentityValue::newAnonymous( IPUtils::sanitizeIP( $target ) ),
                GlobalBlock::TYPE_IP
            ];

        } elseif ( IPUtils::isValidRange( $target ) ) {
            // Can't create a UserIdentity from an IP range
            return [ IPUtils::sanitizeRange( $target ), GlobalBlock::TYPE_RANGE ];
        }

        // Consider the possibility that this is not a username at all
        // but actually an old subpage (T31797)
        if ( strpos( $target, '/' ) !== false ) {
            // An old subpage, drill down to the user behind it
            $target = explode( '/', $target )[0];
        }

        if ( preg_match( '/^#\d+$/', $target ) ) {
            // Autoblock reference in the form "#12345"
            return [ substr( $target, 1 ), GlobalBlock::TYPE_AUTO ];
        }

        $userFromDB = $this->centralIdLookup->localUserFromCentralId( $target, CentralIdLookup::AUDIENCE_RAW );
        if ( $userFromDB instanceof UserIdentity ) {
            // Note that since numbers are valid usernames, a $target of "12345" will be
            // considered a UserIdentity. If you want to pass a block ID, prepend a hash "#12345",
            // since hash characters are not valid in usernames or titles generally.
            return [ $userFromDB, GlobalBlock::TYPE_USER ];
        }

        return [ null, null ];
    }

    /**
     * Validate block target
     *
     * @param string|UserIdentity $value
     *
     * @return Status
     */
    public function validateTarget( $value ): Status {
        return $this->blockUtils->validateTarget( $value );
    }
}
