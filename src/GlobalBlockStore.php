<?php
namespace MediaWiki\Extension\GlobalUserBlocking;

use AutoCommitUpdate;
use CentralIdLookup;
use DeferredUpdates;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MWException;
use Psr\Log\LoggerInterface;
use ReadOnlyMode;
use WikiMap;
use Wikimedia\IPUtils;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\ILoadBalancer;

class GlobalBlockStore {

    /** @var ServiceOptions */
    private $options;

    /**
     * @internal For use by ServiceWiring
     */
    public const CONSTRUCTOR_OPTIONS = [
        MainConfigNames::PutIPinRC,
        MainConfigNames::BlockDisablesLogin,
        MainConfigNames::UpdateRowsPerQuery,
    ];

    /** @var LoggerInterface */
    private $logger;

    /** @var ILoadBalancer */
    private $loadBalancer;

    /** @var ReadOnlyMode */
    private $readOnlyMode;

    /** @var UserFactory */
    private $userFactory;

    /**
     * @param ServiceOptions $options
     * @param LoggerInterface $logger
     * @param ILoadBalancer $loadBalancer
     * @param ReadOnlyMode $readOnlyMode
     * @param UserFactory $userFactory
     */
    public function __construct(
        ServiceOptions $options,
        LoggerInterface $logger,
        ILoadBalancer $loadBalancer,
        ReadOnlyMode $readOnlyMode,
        UserFactory $userFactory
    ) {
        $options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );

        $this->options = $options;
        $this->logger = $logger;
        $this->loadBalancer = $loadBalancer;
        $this->readOnlyMode = $readOnlyMode;
        $this->userFactory = $userFactory;
    }

    /**
     * Load a block from the block id.
     *
     * @param int $id id to search for
     * @return GlobalBlock|null
     */
    public function loadFromID( $id ) {
        $dbr = $this->loadBalancer->getConnection( DB_REPLICA );
        $blockQuery = GlobalBlock::getQueryInfo();
        $res = $dbr->selectRow(
            $blockQuery['tables'],
            $blockQuery['fields'],
            [ 'gub_id' => $id ],
            __METHOD__,
            []
        );
        if ( $res ) {
            return GlobalBlock::newFromRow( $res, $dbr );
        } else {
            return null;
        }
    }

    /**
     * Load blocks from the database which target the specific target exactly, or which cover the
     * vague target.
     *
     * @param UserIdentity|string|null $specificTarget
     * @param int|null $specificType
     * @param bool $fromPrimary
     * @param UserIdentity|string|null $vagueTarget Also search for blocks affecting this target.
     *     Doesn't make any sense to use TYPE_ID here. Leave blank to skip IP lookups.
     * @throws MWException
     * @return GlobalBlock[] Any relevant blocks
     */
    protected function load( $specificTarget, $specificType, $fromPrimary, $vagueTarget = null ) {
        $db = $this->loadBalancer->getConnection( $fromPrimary ? DB_PRIMARY : DB_REPLICA );
        $centralIdLookup = MediaWikiServices::getInstance()->getCentralIdLookup();

        if ( $specificType !== null ) {
            if ( $specificType === GlobalBlock::TYPE_USER ) {
                $conds = [ 'gub_target_central_id' => [
                    $centralIdLookup->centralIdFromLocalUser( $specificTarget, CentralIdLookup::AUDIENCE_RAW )
                ] ];
            } else {
                $conds = [ 'gub_target_address' => [ (string)$specificTarget ] ];
            }
        } else {
            $conds = [
                'gub_target_address' => [],
                'gub_target_central_id' => []
            ];
        }

        # Be aware that the != '' check is explicit, since empty values will be
        # passed by some callers (T31116)
        if ( $vagueTarget != '' ) {
            list( $target, $type ) = MediaWikiServices::getInstance()->getService( GlobalBlockUtils::SERVICE_NAME )
                ->parseBlockTarget( $vagueTarget );
            switch ( $type ) {
                case GlobalBlock::TYPE_USER:
                    $conds['gub_target_central_id'][] =
                        $centralIdLookup->centralIdFromLocalUser( $specificTarget, CentralIdLookup::AUDIENCE_RAW );
                    $conds = $db->makeList( $conds, LIST_OR );
                    break;

                case GlobalBlock::TYPE_IP:
                    $conds['gub_target_address'][] = (string)$target;
                    $conds['gub_target_address'] = array_unique( $conds['gub_target_address'] );
                    $conds[] = self::getRangeCond( IPUtils::toHex( $target ) );
                    // @phan-suppress-next-line SecurityCheck-SQLInjection
                    $conds = $db->makeList( $conds, LIST_OR );
                    break;

                case GlobalBlock::TYPE_RANGE:
                    list( $start, $end ) = IPUtils::parseRange( $target );
                    $conds['gub_target_address'][] = (string)$target;
                    $conds[] = self::getRangeCond( $start, $end );
                    // @phan-suppress-next-line SecurityCheck-SQLInjection
                    $conds = $db->makeList( $conds, LIST_OR );
                    break;

                default:
                    throw new MWException( "Tried to load block with invalid type" );
            }
        }

        $blockQuery = GlobalBlock::getQueryInfo();
        // @phan-suppress-next-line SecurityCheck-SQLInjection
        $res = $db->select(
            $blockQuery['tables'],
            $blockQuery['fields'],
            $conds,
            __METHOD__,
            [],
            $blockQuery['joins']
        );

        $blocks = [];
        foreach ( $res as $row ) {
            $block = GlobalBlock::newFromRow( $row, $db );

            # Don't use expired blocks
            if ( $block->isExpired() ) {
                continue;
            }

            # Don't use anon only blocks on users
            if ( $specificType == GlobalBlock::TYPE_USER && !$block->isHardblock() ) {
                continue;
            }

            $blocks[] = $block;
        }

        return $blocks;
    }

    /**
     * Given a target and the target's type, get an existing block object if possible.
     * @param string|UserIdentity|int|null $specificTarget A block target, which may be one of several types:
     *     * A user to block, in which case $target will be a User
     *     * An IP to block, in which case $target will be a User generated by using User::newFromName( $ip, false ) to turn off
     *     name validation
     *     * An IP range, in which case $target will be a String "123.123.123.123/18" etc
     *     * The ID of an existing block, in the format "#12345" (since pure numbers are valid usernames
     *     Calling this with a user, IP address or range will only select a block where the targets match exactly (so looking for
     *     blocks on 1.2.3.4 will not select 1.2.0.0/16 or even 1.2.3.4/32)
     * @param string|UserIdentity|int|null $vagueTarget As above, but we will search for *any* block which affects that target
     *     (so for an IP address, get ranges containing that IP; and also get any relevant autoblocks). Leave empty or blank to
     *     skip IP-based lookups.
     * @param bool $fromPrimary Whether to use the DB_PRIMARY database
     * @return GlobalBlock|null (null if no relevant block could be found). The target and type of the returned block will refer
     *     to the actual block which was found, which might not be the same as the target you gave if you used $vagueTarget!
     */
    public function loadFromTarget( $specificTarget, $vagueTarget = null, $fromPrimary = false ) {
        $blocks = $this->loadListFromTarget( $specificTarget, $vagueTarget, $fromPrimary );
        return self::chooseMostSpecificBlock( $blocks );
    }

    /**
     * This is similar to GlobalBlock::loadFromTarget, but it returns all the relevant blocks.
     *
     * @since 1.34
     * @param string|UserIdentity|int|null $specificTarget
     * @param string|UserIdentity|int|null $vagueTarget
     * @param bool $fromPrimary
     * @return GlobalBlock[] Any relevant blocks
     */
    public function loadListFromTarget( $specificTarget, $vagueTarget = null, $fromPrimary = false ) {
        list( $target, $type ) = MediaWikiServices::getInstance()->getService( GlobalBlockUtils::SERVICE_NAME )
            ->parseBlockTarget( $specificTarget );
        if ( $type == GlobalBlock::TYPE_ID ) {
            $block = $this->loadFromID( $target );
            return $block ? [ $block ] : [];
        } elseif ( $target === null && $vagueTarget == '' ) {
            return [];
        } elseif ( in_array(
            $type,
            [ GlobalBlock::TYPE_USER, GlobalBlock::TYPE_IP, GlobalBlock::TYPE_RANGE, null ] )
        ) {
            return $this->load( $target, $type, $fromPrimary, $vagueTarget );
        }
        return [];
    }

    /**
     * Choose the most specific block from some combination of user, IP and IP range blocks. Decreasing order of specificity:
     * user > IP > narrower IP range > wider IP range. A range that encompasses one IP address is ranked equally to a single IP.
     *
     * @param GlobalBlock[] $blocks These should not include autoblocks or ID blocks
     * @return GlobalBlock|null The block with the most specific target
     */
    protected static function chooseMostSpecificBlock( array $blocks ) {
        if ( count( $blocks ) === 1 ) {
            return $blocks[0];
        }

        # This result could contain a block on the user, a block on the IP, and a russian-doll
        # set of rangeblocks.  We want to choose the most specific one, so keep a leader board.
        $bestBlock = null;

        # Lower will be better
        $bestBlockScore = 100;
        foreach ( $blocks as $block ) {
            if ( $block->getType() == GlobalBlock::TYPE_RANGE ) {
                # This is the number of bits that are allowed to vary in the block, give
                # or take some floating point errors
                $target = $block->getTargetName();
                $max = IPUtils::isIPv6( $target ) ? 128 : 32;
                list( $network, $bits ) = IPUtils::parseCIDR( $target );
                $size = $max - $bits;

                # Rank a range block covering a single IP equally with a single-IP block
                $score = GlobalBlock::TYPE_RANGE - 1 + ( $size / $max );
            } else {
                $score = $block->getType();
            }

            if ( $score < $bestBlockScore ) {
                $bestBlockScore = $score;
                $bestBlock = $block;
            }
        }

        return $bestBlock;
    }

    /**
     * Get the component of an IP address which is certain to be the same between an IP
     * address and a rangeblock containing that IP address.
     * @param string $hex Hexadecimal IP representation
     * @return string
     */
    protected static function getIpFragment( $hex ) {
        $blockCIDRLimit = MediaWikiServices::getInstance()->getMainConfig()->get( MainConfigNames::BlockCIDRLimit );
        if ( substr( $hex, 0, 3 ) == 'v6-' ) {
            return 'v6-' . substr( substr( $hex, 3 ), 0, (int)floor( $blockCIDRLimit['IPv6'] / 4 ) );
        } else {
            return substr( $hex, 0, (int)floor( $blockCIDRLimit['IPv4'] / 4 ) );
        }
    }

    /**
     * Get a set of SQL conditions which will select rangeblocks encompassing a given range
     * @param string $start Hexadecimal IP representation
     * @param string|null $end Hexadecimal IP representation, or null to use $start = $end
     * @return string
     */
    public static function getRangeCond( $start, $end = null ) {
        if ( $end === null ) {
            $end = $start;
        }

        $chunk = self::getIpFragment( $start );
        $dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
        $like = $dbr->buildLike( $chunk, $dbr->anyString() );

        # Fairly hard to make a malicious SQL statement out of hex characters,
        # but stranger things have happened...
        $safeStart = $dbr->addQuotes( $start );
        $safeEnd = $dbr->addQuotes( $end );

        return $dbr->makeList(
            [
                "gub_range_start $like",
                "gub_range_start <= $safeStart",
                "gub_range_end >= $safeEnd",
            ],
            LIST_AND
        );
    }

    /**
     * Delete expired blocks from the ipblocks table
     *
     * @internal only public for use in DatabaseBlock
     */
    public function purgeExpiredBlocks() {
        if ( $this->readOnlyMode->isReadOnly() ) {
            return;
        }

        $dbw = $this->loadBalancer->getConnection( DB_PRIMARY );
        $limit = $this->options->get( MainConfigNames::UpdateRowsPerQuery );

        DeferredUpdates::addUpdate( new AutoCommitUpdate(
            $dbw,
            __METHOD__,
            static function ( IDatabase $dbw, $fname ) use ( $limit ) {
                $ids = $dbw->selectFieldValues(
                    'global_user_blocks',
                    'gub_id',
                    [ 'gub_expiry < ' . $dbw->addQuotes( $dbw->timestamp() ) ],
                    $fname,
                    [ 'LIMIT' => $limit ]
                );
                if ( $ids ) {
                    $dbw->delete( 'global_user_blocks', [ 'gub_id' => $ids ], $fname );
                }
            }
        ) );
    }

    /**
     * Insert a block into the block table. Will fail if there is a conflicting block (same name and options) already in the
     * database.
     *
     * @param GlobalBlock $block
     * @return bool|array False on failure, assoc array on success: ('id' => block ID)
     * @throws MWException
     */
    public function insertBlock( GlobalBlock $block ) {
        $blocker = $block->getBlocker();
        if ( !$blocker || $blocker->getName() === '' ) {
            throw new MWException( 'Cannot insert a global block without a blocker set' );
        }

        $this->logger->debug( 'Inserting global block; timestamp ' . $block->getTimestamp() );

        $this->purgeExpiredBlocks();

        $dbw = $this->loadBalancer->getConnection( DB_PRIMARY );
        $row = $this->getArrayForBlock( $block, $dbw );

        $dbw->insert( 'global_user_blocks', $row, __METHOD__, [ 'IGNORE' ] );
        $affected = $dbw->affectedRows();

        if ( $affected ) {
            $block->setId( $dbw->insertId() );
        }

        // Don't collide with expired blocks.
        // Do this after trying to insert to avoid locking.
        if ( !$affected ) {
            // T96428: The ipb_address index uses a prefix on a field, so
            // use a standard SELECT + DELETE to avoid annoying gap locks.
            $ids = $dbw->selectFieldValues(
                'global_user_blocks',
                'gub_id',
                [
                    'gub_target_address' => $row['gub_target_address'],
                    'gub_target_central_id' => $row['gub_target_central_id'],
                    'gub_expiry < ' . $dbw->addQuotes( $dbw->timestamp() )
                ],
                __METHOD__
            );
            if ( $ids ) {
                $dbw->delete( 'global_user_blocks', [ 'gub_id' => $ids ], __METHOD__ );
                $dbw->insert( 'global_user_blocks', $row, __METHOD__, [ 'IGNORE' ] );
                $affected = $dbw->affectedRows();
                $block->setId( $dbw->insertId() );
            }
        }

        if ( $affected ) {
            if ( $this->options->get( MainConfigNames::BlockDisablesLogin ) ) {
                $targetUserIdentity = $block->getTargetUserIdentity();
                if ( $targetUserIdentity ) {
                    $targetUser = $this->userFactory->newFromUserIdentity( $targetUserIdentity );
                    // Change user login token to force them to be logged out.
                    $targetUser->setToken();
                    $targetUser->saveSettings();
                }
            }

            return [ 'id' => $block->getId() ];
        }

        return false;
    }

    /**
     * Update a block in the DB with new parameters.
     * The ID field needs to be loaded first.
     *
     * @param GlobalBlock $block
     * @return bool|array False on failure, array on success: ('id' => block ID, 'autoIds' => array of autoblock IDs)
     */
    public function updateBlock( GlobalBlock $block ) {
        $this->logger->debug( 'Updating block; timestamp ' . $block->getTimestamp() );

        $blockId = $block->getId();
        if ( !$blockId ) {
            throw new MWException( __METHOD__ . " requires that a block id be set\n" );
        }

        $dbw = $this->loadBalancer->getConnection( DB_PRIMARY );
        $row = $this->getArrayForBlock( $block, $dbw );
        $dbw->startAtomic( __METHOD__ );

        $result = $dbw->update(
            'global_user_blocks',
            $row,
            [ 'gub_id' => $blockId ],
            __METHOD__
        );

        $dbw->endAtomic( __METHOD__ );

        if ( $result ) {
            return [ 'id' => $blockId ];
        }

        return false;
    }

    /**
     * Delete a GlobalBlock from the database
     *
     * @param GlobalBlock $block
     * @return bool whether it was deleted
     * @throws MWException
     */
    public function deleteBlock( GlobalBlock $block ): bool {
        if ( $this->readOnlyMode->isReadOnly() ) {
            return false;
        }

        $blockId = $block->getId();
        if ( !$blockId ) {
            throw new MWException( __METHOD__ . " requires that a block id be set\n" );
        }

        $dbw = $this->loadBalancer->getConnection( DB_PRIMARY );

        $dbw->delete(
            'global_user_blocks',
            [ 'gub_id' => $blockId ],
            __METHOD__
        );

        return $dbw->affectedRows() > 0;
    }

    /**
     * Get an array suitable for passing to $dbw->insert() or $dbw->update()
     *
     * @param GlobalBlock $block
     * @param IDatabase $dbw
     * @return array
     */
    private function getArrayForBlock( GlobalBlock $block, IDatabase $dbw ): array {
        $expiry = $dbw->encodeExpiry( $block->getExpiry() );

        return [
            'gub_target_address'       => $block->getType() != GlobalBlock::TYPE_USER ? $block->getTargetName() : '',
            'gub_target_central_id'    => $block->getTargetUserCentralId() ?? 0,
            'gub_performer_central_id' => $block->getBlockerCentralId(),
            'gub_wiki_id'              => $block->getWikiId() || WikiMap::getCurrentWikiId(),
            'gub_reason'               => $block->getReasonComment()->text,
            'gub_timestamp'            => $dbw->timestamp( $block->getTimestamp() ),
            'gub_anon_only'            => !$block->isHardblock(),
            'gub_create_account'       => $block->isCreateAccountBlocked(),
            'gub_expiry'               => $expiry,
            'gub_range_start'          => $block->getRangeStart(),
            'gub_range_end'            => $block->getRangeEnd(),
            'gub_deleted'              => intval( $block->getHideName() ),
            'gub_block_email'          => $block->isEmailBlocked(),
            'gub_allow_usertalk'       => $block->isUsertalkEditAllowed()
        ];
    }
}
