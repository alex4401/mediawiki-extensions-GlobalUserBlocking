<?php
namespace MediaWiki\Extension\GlobalUserBlocking;

use CentralIdLookup;
use MediaWiki\Block\AbstractBlock;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserIdentity;
use MWException;
use stdClass;
use Wikimedia\IPUtils;
use Wikimedia\Rdbms\Database;

class GlobalBlock extends AbstractBlock {
    /** @var UserIdentity|null */
    private ?UserIdentity $blocker;

    /** @var int */
    private $mId;

    /**
     * Create a new block with specified parameters on a user, IP or IP range.
     *
     * @param array $options Parameters of the block, with supported options:
     *  - target: (int|UserIdentity) Target central user ID, user identity object, IP address or IP range
     *  - by: (int|UserIdentity) Performer's central user ID or user identity object
     *  - wiki: (string|false) The wiki the block has been issued in, self::LOCAL for the local wiki
     *  - reason: (string) Reason for the block
     *  - timestamp: (string) The time at which the block comes into effect
     *  - hideName: (bool) Hide the target user name
     *  - anonOnly: (bool) Whether an IP block should not affect logged-in users
     */
    public function __construct( array $options = [] ) {
        parent::__construct( $options );

        $defaults = [
            'target'          => null,
            'by'              => null,
            'wiki'            => self::LOCAL,
            'reason'          => '',
            'timestamp'       => '',
            'expiry'          => '',
            'hideName'        => false,
            'anonOnly'        => false,
            'createAccount'   => false,
            'blockEmail'      => false,
            'allowUsertalk'   => false,
        ];
        $options += $defaults;

        $this->wikiId = $options['wiki'];
        $this->setTarget( $options['target'] );
        $this->setBlocker( $options['by'] );
        $this->setReason( $options['reason'] );
        $this->setTimestamp( wfTimestamp( TS_MW, $options['timestamp'] ) );
        $this->setExpiry( wfTimestamp( TS_MW, $options['expiry'] ) );
        $this->setHideName( (bool)$options['hideName'] );
        $this->isHardblock( !$options['anonOnly'] );
        $this->isCreateAccountBlocked( (bool)$options['createAccount'] );
        $this->isEmailBlocked( (bool)$options['blockEmail'] );
        $this->isUsertalkEditAllowed( (bool)$options['allowUsertalk'] );
    }

    /**
     * Given a database row from the global_user_blocks table, initialise member variables
     * @param stdClass $row
     * @param Database $db
     * @return GlobalBlock
     */
    public static function newFromRow( $row, $db ) {
        $block = new GlobalBlock( [
            'target'          => (int)$row->gub_target_central_id || $row->gub_target_address,
            'by'              => (int)$row->gub_performer_central_id,
            'wiki'            => $row->gub_wiki_id,
            'reason'          => $row->gub_reason,
            'timestamp'       => $row->gub_timestamp,
            'expiry'          => $db->decodeExpiry( $row->gub_expiry ),
            'hideName'        => (bool)$row->gub_deleted,
            'anonOnly'        => (bool)$row->gub_anon_only,
            'createAccount'   => (bool)$row->gub_create_account,
            'blockEmail'      => (bool)$row->gub_block_email,
            'allowUsertalk'   => (bool)$row->gub_allow_usertalk,
        ] );
        $block->mId = (int)$row->gub_id;
        return $block;
    }

    /**
     * Return the tables and fields to be selected to create a new block object.
     *
     * @return array[] With three keys:
     *   - tables: (string[]) to include in the `$table` to `IDatabase->select()` or `SelectQueryBuilder::tables`
     *   - fields: (string[]) to include in the `$vars` to `IDatabase->select()` or `SelectQueryBuilder::fields`
     * @phan-return array{tables:string[],fields:string[]}
     */
    public static function getQueryInfo() {
        return [
            'tables' => [
                'global_user_blocks'
            ],
            'fields' => [
                'gub_id',
                'gub_target_address',
                'gub_target_central_id',
                'gub_performer_central_id',
                'gub_wiki_id',
                'gub_reason',
                'gub_timestamp',
                'gub_anon_only',
                'gub_create_account',
                'gub_expiry',
                'gub_range_start',
                'gub_range_end',
                'gub_deleted',
                'gub_block_email',
                'gub_allow_usertalk'
            ]
        ];
    }

    /**
     * @inheritDoc
     */
    public function getIdentifier( $wikiId = self::LOCAL ) {
        return $this->getId( $wikiId );
    }

    /** @inheritDoc */
    public function getId( $wikiId = self::LOCAL ): ?int {
        return $this->mId;
    }

    /**
     * Set the block ID
     *
     * @internal Only for use in GlobalBlockStorage
     * @param int $blockId
     */
    public function setId( $blockId ) {
        $this->mId = (int)$blockId;
    }

    /**
     * @return int|null
     */
    public function getTargetUserCentralId(): ?int {
        if ( $this->getTargetUserIdentity() ) {
            return MediaWikiServices::getInstance()->getCentralIdLookup()
                ->centralIdFromLocalUser( $this->getTargetUserIdentity(), CentralIdLookup::AUDIENCE_RAW );
        }
        return null;
    }

    /**
     * Get the IP address at the start of the range in Hex form
     * @throws MWException
     * @return string IP in Hex form
     */
    public function getRangeStart() {
        switch ( $this->type ) {
            case self::TYPE_USER:
                return '';
            case self::TYPE_IP:
                return IPUtils::toHex( $this->target );
            case self::TYPE_RANGE:
                list( $start, /*...*/ ) = IPUtils::parseRange( $this->target );
                return $start;
            default:
                throw new MWException( 'Block with invalid type' );
        }
    }

    /**
     * Get the IP address at the end of the range in Hex form
     * @throws MWException
     * @return string IP in Hex form
     */
    public function getRangeEnd() {
        switch ( $this->type ) {
            case self::TYPE_USER:
                return '';
            case self::TYPE_IP:
                return IPUtils::toHex( $this->target );
            case self::TYPE_RANGE:
                list( /*...*/, $end ) = IPUtils::parseRange( $this->target );
                return $end;
            default:
                throw new MWException( 'Block with invalid type' );
        }
    }

    /**
     * Set the target for this block, and update $this->type accordingly
     * @param string|UserIdentity|null $target
     */
    public function setTarget( $target ) {
        // Small optimization to make this code testable, this is what would happen anyway
        if ( $target === '' ) {
            $this->target = null;
            $this->type = null;
        } else {
            list( $parsedTarget, $this->type ) = MediaWikiServices::getInstance()
                ->getBlockUtils()
                ->parseBlockTarget( $target );
            $this->target = $parsedTarget;
        }
    }

    /**
     * @inheritDoc
     */
    public function getBy( $wikiId = self::LOCAL ): int {
        return ( $this->blocker ) ? $this->blocker->getId( $wikiId ) : 0;
    }

    /**
     * @inheritDoc
     */
    public function getByName(): string {
        return ( $this->blocker ) ? $this->blocker->getName() : '';
    }

    /**
     * @inheritDoc
     */
    public function getBlocker(): ?UserIdentity {
        return $this->blocker;
    }

    /**
     * @param int|UserIdentity $identity
     * @return void
     */
    public function setBlocker( $identity ) {
        if ( is_int( $identity ) ) {
            $identity = MediaWikiServices::getInstance()->getCentralIdLookup()->localUserFromCentralId( $identity );
        }

        $this->blocker = $identity;
    }

    /**
     * @return int
     */
    public function getBlockerCentralId(): int {
        if ( !$this->blocker ) {
            throw new \RuntimeException( __METHOD__ . ': this block does not have a blocker' );
        }
        return MediaWikiServices::getInstance()->getCentralIdLookup()
            ->centralIdFromLocalUser( $this->blocker, CentralIdLookup::AUDIENCE_RAW );
    }

    /** @inheritDoc */
    public function isSitewide( $x = null ): bool {
        return true;
    }

    /**
     * Has the block expired?
     * @return bool
     */
    public function isExpired() {
        $timestamp = wfTimestampNow();
        wfDebug( __METHOD__ . " checking current " . $timestamp . " vs $this->mExpiry" );

        return $this->getExpiry() && $timestamp > $this->getExpiry();
    }

    /**
     * Check if two blocks are effectively equal. Doesn't check irrelevant things like the blocking user or the block timestamp,
     * only things which affect the blocked user.
     *
     * @param GlobalBlock $block
     * @return bool
     */
    public function equals( GlobalBlock $block ) {
        return (
            (string)$this->target == (string)$block->target
            && $this->type == $block->type
            && $this->isHardblock() == $block->isHardblock()
            && $this->isCreateAccountBlocked() == $block->isCreateAccountBlocked()
            && $this->getExpiry() == $block->getExpiry()
            && $this->getHideName() == $block->getHideName()
            && $this->isEmailBlocked() == $block->isEmailBlocked()
            && $this->isUsertalkEditAllowed() == $block->isUsertalkEditAllowed()
            && $this->getReasonComment()->text == $block->getReasonComment()->text
        );
    }
}
