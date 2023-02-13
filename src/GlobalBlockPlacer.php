<?php

namespace MediaWiki\Extension\GlobalUserBlocking;

use ChangeTags;
use ManualLogEntry;
use MediaWiki\Block\BlockPermissionChecker;
use MediaWiki\Block\BlockPermissionCheckerFactory;
use MediaWiki\Block\BlockUser;
use MediaWiki\Block\BlockUtils;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\HookContainer\HookRunner;
use MediaWiki\MainConfigNames;
use MediaWiki\Permissions\Authority;
use MediaWiki\User\UserEditTracker;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use Message;
use Psr\Log\LoggerInterface;
use RevisionDeleteUser;
use Status;
use Title;

/**
 * Handles the backend logic of blocking users
 *
 * @since 1.36
 */
class GlobalBlockPlacer {
    /**
     * @var UserIdentity|string|null
     *
     * Target of the block
     *
     * This is null in case BlockUtils::parseBlockTarget failed to parse the target.
     * Such case is detected in placeBlockUnsafe, by calling validateTarget from SpecialBlock.
     */
    private $target;

    /**
     * @var int
     *
     * One of AbstractBlock::TYPE_* constants
     *
     * This will be -1 if BlockUtils::parseBlockTarget failed to parse the target.
     */
    private $targetType;

    /** @var Authority Performer of the block */
    private $performer;

    /** @var ServiceOptions */
    private $options;

    /** @var BlockPermissionChecker */
    private $blockPermissionChecker;

    /** @var BlockUtils */
    private $blockUtils;

    /** @var HookRunner */
    private $hookRunner;

    /** @var GlobalBlockStore */
    private $blockStore;

    /** @var UserFactory */
    private $userFactory;

    /** @var UserEditTracker */
    private $userEditTracker;

    /** @var LoggerInterface */
    private $logger;

    /**
     * @internal For use by UserBlockCommandFactory
     */
    public const CONSTRUCTOR_OPTIONS = [
        MainConfigNames::HideUserContribLimit,
        MainConfigNames::BlockAllowsUTEdit,
    ];

    /**
     * @var string
     *
     * Expiry of the to-be-placed block exactly as it was passed to the constructor.
     */
    private $rawExpiry;

    /**
     * @var string|bool
     *
     * Parsed expiry. This may be false in case of an error in parsing.
     */
    private $expiryTime;

    /** @var string */
    private $reason;

    /** @var bool */
    private $isCreateAccountBlocked = false;

    /**
     * @var bool|null
     *
     * This may be null when an invalid option was passed to the constructor.
     * Such a case is caught in placeBlockUnsafe.
     */
    private $isUserTalkEditBlocked = null;

    /** @var bool */
    private $isEmailBlocked = false;

    /** @var bool */
    private $isHardBlock = true;

    /** @var bool */
    private $isHideUser = false;

    /** @var string[] */
    private $tags = [];

    /** @var int|null */
    private $logDeletionFlags;

    /**
     * @param ServiceOptions $options
     * @param GlobalBlockStore $blockStore
     * @param BlockPermissionCheckerFactory $blockPermissionCheckerFactory
     * @param BlockUtils $blockUtils
     * @param HookContainer $hookContainer
     * @param UserFactory $userFactory
     * @param UserEditTracker $userEditTracker
     * @param LoggerInterface $logger
     * @param string|UserIdentity $target Target of the block
     * @param Authority $performer Performer of the block
     * @param string $expiry Expiry of the block (timestamp or 'infinity')
     * @param string $reason Reason of the block
     * @param bool[] $blockOptions
     *    Valid options:
     *    - isCreateAccountBlocked      : Are account creations prevented?
     *    - isEmailBlocked              : Is emailing other users prevented?
     *    - isHardBlock                 : Are registered users prevented from editing?
     *    - isUserTalkEditBlocked       : Is editing blocked user's own talkpage allowed?
     *    - isHideUser                  : Should blocked user's name be hidden (needs hideuser)?
     * @param string[] $tags Tags that should be assigned to the log entry
     */
    public function __construct(
        ServiceOptions $options,
        GlobalBlockStore $blockStore,
        BlockPermissionCheckerFactory $blockPermissionCheckerFactory,
        BlockUtils $blockUtils,
        HookContainer $hookContainer,
        UserFactory $userFactory,
        UserEditTracker $userEditTracker,
        LoggerInterface $logger,
        $target,
        Authority $performer,
        string $expiry,
        string $reason,
        array $blockOptions,
        array $tags
    ) {
        // Process dependencies
        $options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
        $this->options = $options;
        $this->blockStore = $blockStore;
        $this->blockPermissionChecker = $blockPermissionCheckerFactory->newBlockPermissionChecker( $target, $performer );
        $this->blockUtils = $blockUtils;
        $this->hookRunner = new HookRunner( $hookContainer );
        $this->userFactory = $userFactory;
        $this->userEditTracker = $userEditTracker;
        $this->logger = $logger;

        // Process block target
        list( $this->target, $rawTargetType ) = $this->blockUtils->parseBlockTarget( $target );
        if ( $rawTargetType !== null ) { // Guard against invalid targets
            $this->targetType = $rawTargetType;
        } else {
            $this->targetType = -1;
        }

        // Process other block parameters
        $this->performer = $performer;
        $this->rawExpiry = $expiry;
        $this->expiryTime = BlockUser::parseExpiryInput( $this->rawExpiry );
        $this->reason = $reason;
        $this->tags = $tags;

        // Process blockOptions
        foreach ( [
            'isCreateAccountBlocked',
            'isEmailBlocked',
            'isHardBlock'
        ] as $possibleBlockOption ) {
            if ( isset( $blockOptions[ $possibleBlockOption ] ) ) {
                $this->$possibleBlockOption = $blockOptions[ $possibleBlockOption ];
            }
        }

        // It is possible to block user talk edit. User talk edit is:
        // - always blocked if the config says so;
        // - otherwise blocked/unblocked if the option was passed in;
        // - otherwise defaults to not blocked.
        if ( !$this->options->get( MainConfigNames::BlockAllowsUTEdit ) ) {
            $this->isUserTalkEditBlocked = true;
        } else {
            $this->isUserTalkEditBlocked = $blockOptions['isUserTalkEditBlocked'] ?? false;
        }
    }

    /**
     * @unstable This method might be removed without prior notice (see T271101)
     * @param int $flags One of LogPage::* constants
     */
    public function setLogDeletionFlags( int $flags ): void {
        $this->logDeletionFlags = $flags;
    }

    /**
     * Configure DatabaseBlock according to class properties
     *
     * @param GlobalBlock|null $sourceBlock Copy any options from this block,
     *                                        null to construct a new one.
     *
     * @return GlobalBlock
     */
    private function configureBlock( $sourceBlock = null ): GlobalBlock {
        if ( $sourceBlock === null ) {
            $block = new GlobalBlock();
        } else {
            $block = clone $sourceBlock;
        }

        $block->setTarget( $this->target );
        $block->setBlocker( $this->performer->getUser() );
        $block->setReason( $this->reason );
        $block->setExpiry( $this->expiryTime );
        $block->isCreateAccountBlocked( $this->isCreateAccountBlocked );
        $block->isEmailBlocked( $this->isEmailBlocked );
        $block->isHardblock( $this->isHardBlock );
        $block->isUsertalkEditAllowed( !$this->isUserTalkEditBlocked );
        $block->setHideName( $this->isHideUser );
        return $block;
    }

    /**
     * Places a block with checking permissions
     *
     * @param bool $reblock Should this reblock?
     *
     * @return Status If the block is successful, the value of the returned
     * Status is an instance of a newly placed block.
     */
    public function placeBlock( bool $reblock = false ): Status {
        $priorBlock = $this->blockStore->loadFromTarget( $this->target, null, true );
        $priorHideUser = $priorBlock instanceof GlobalBlock && $priorBlock->getHideName();
        if ( $this->blockPermissionChecker->checkBasePermissions( $this->isHideUser || $priorHideUser ) !== true ) {
            return Status::newFatal( $priorHideUser ? 'cant-see-hidden-user' : 'badaccess-group0' );
        }

        $blockCheckResult = $this->blockPermissionChecker->checkBlockPermissions();
        if ( $blockCheckResult !== true ) {
            return Status::newFatal( $blockCheckResult );
        }

        if ( $this->isEmailBlocked && !$this->blockPermissionChecker->checkEmailPermissions() ) {
            $this->isEmailBlocked = false;
        }

        if ( $this->tags !== [] ) {
            $status = ChangeTags::canAddTagsAccompanyingChange( $this->tags, $this->performer );
            if ( !$status->isOK() ) {
                return $status;
            }
        }

        return $this->placeBlockUnsafe( $reblock );
    }

    /**
     * Places a block without any sort of permissions checks.
     *
     * @param bool $reblock Should this reblock?
     *
     * @return Status If the block is successful, the value of the returned
     * Status is an instance of a newly placed block.
     */
    public function placeBlockUnsafe( bool $reblock = false ): Status {
        $status = $this->blockUtils->validateTarget( $this->target );

        if ( !$status->isOK() ) {
            return $status;
        }

        if ( $this->isUserTalkEditBlocked === null ) {
            return Status::newFatal( 'ipb-prevent-user-talk-edit' );
        }

        if (
            // There should be some expiry
            strlen( $this->rawExpiry ) === 0 ||
            // can't be a larger string as 50 (it should be a time format in any way)
            strlen( $this->rawExpiry ) > 50 ||
            // the time can't be parsed
            !$this->expiryTime
        ) {
            return Status::newFatal( 'ipb_expiry_invalid' );
        }

        if ( $this->expiryTime < wfTimestampNow() ) {
            return Status::newFatal( 'ipb_expiry_old' );
        }

        if ( $this->isHideUser ) {
            if ( !wfIsInfinity( $this->rawExpiry ) ) {
                return Status::newFatal( 'ipb_expiry_temp' );
            }

            $hideUserContribLimit = $this->options->get( MainConfigNames::HideUserContribLimit );
            if (
                $hideUserContribLimit !== false &&
                $this->userEditTracker->getUserEditCount( $this->target ) > $hideUserContribLimit
            ) {
                return Status::newFatal( 'ipb_hide_invalid', Message::numParam( $hideUserContribLimit ) );
            }
        }

        return $this->placeBlockInternal( $reblock );
    }

    /**
     * Places a block without any sort of permission or double checking, hooks can still
     * abort the block through, as well as already existing block.
     *
     * @param bool $reblock Should this reblock?
     *
     * @return Status
     */
    private function placeBlockInternal( bool $reblock = true ): Status {
        $block = $this->configureBlock();

        $denyReason = [ 'hookaborted' ];
        $legacyUser = $this->userFactory->newFromAuthority( $this->performer );
        if ( !$this->hookRunner->onBlockIp( $block, $legacyUser, $denyReason ) ) {
            $status = Status::newGood();
            foreach ( $denyReason as $key ) {
                $status->fatal( $key );
            }
            return $status;
        }

        // Is there a conflicting block?
        // xxx: there is an identical call at the beginning of ::placeBlock
        $priorBlock = $this->blockStore->loadFromTarget( $this->target, null, true );

        $isReblock = false;
        if ( $priorBlock !== null ) {
            // Reblock only if the caller wants so
            if ( !$reblock ) {
                return Status::newFatal( 'ipb_already_blocked', $block->getTargetName() );
            }

            if ( $block->equals( $priorBlock ) ) {
                // Block settings are equal => user is already blocked
                return Status::newFatal( 'ipb_already_blocked', $block->getTargetName() );
            }

            $currentBlock = $this->configureBlock( $priorBlock );
            $this->blockStore->updateBlock( $currentBlock );
            $isReblock = true;
            $block = $currentBlock;
        } else {
            // Try to insert block.
            $insertStatus = $this->blockStore->insertBlock( $block );
            if ( !$insertStatus ) {
                $this->logger->warning( 'Block could not be inserted. No existing block was found.' );
                return Status::newFatal( 'ipb-block-not-found', $block->getTargetName() );
            }
        }

        // Set *_deleted fields if requested
        if ( $this->isHideUser ) {
            // This should only be the case of $this->target is a user, so we can safely call ->getId()
            RevisionDeleteUser::suppressUserName( $this->target->getName(), $this->target->getId() );
        }

        $this->hookRunner->onBlockIpComplete( $block, $legacyUser, $priorBlock );

        // GlobalBlock constructor sanitizes certain block options on insert
        $this->isEmailBlocked = $block->isEmailBlocked();

        $this->log( $block, $isReblock );

        return Status::newGood( $block );
    }

    /**
     * Prepare $logParams
     *
     * Helper method for $this->log()
     *
     * @return array
     */
    private function constructLogParams(): array {
        $logExpiry = wfIsInfinity( $this->rawExpiry ) ? 'infinity' : $this->rawExpiry;
        $logParams = [
            '5::duration' => $logExpiry,
            '6::flags' => $this->blockLogFlags()
        ];
        return $logParams;
    }

    /**
     * Log the block to Special:Log
     *
     * @param GlobalBlock $block
     * @param bool $isReblock
     */
    private function log( GlobalBlock $block, bool $isReblock ) {
        $logType = 'globalblock';
        $logAction = $isReblock ? 'reblock' : 'block';

        $logEntry = new ManualLogEntry( $logType, $logAction );
        $logEntry->setTarget( Title::makeTitle( NS_USER, $this->target ) );
        $logEntry->setComment( $this->reason );
        $logEntry->setPerformer( $this->performer->getUser() );
        $logEntry->setParameters( $this->constructLogParams() );
        $logEntry->setRelations( [ 'gub_id' => $block->getId() ] );
        $logEntry->addTags( $this->tags );
        if ( $this->logDeletionFlags !== null ) {
            $logEntry->setDeleted( $this->logDeletionFlags );
        }
        $logId = $logEntry->insert();
        $logEntry->publish( $logId );
    }

    /**
     * Return a comma-delimited list of flags to be passed to the log
     * reader for this block, to provide more information in the logs.
     *
     * @return string
     */
    private function blockLogFlags(): string {
        $flags = [];

        if ( $this->targetType != GlobalBlock::TYPE_USER && !$this->isHardBlock ) {
            // For grepping: message block-log-flags-anononly
            $flags[] = 'anononly';
        }

        if ( $this->isCreateAccountBlocked ) {
            // For grepping: message block-log-flags-nocreate
            $flags[] = 'nocreate';
        }

        if ( $this->isEmailBlocked ) {
            // For grepping: message block-log-flags-noemail
            $flags[] = 'noemail';
        }

        if ( $this->options->get( MainConfigNames::BlockAllowsUTEdit ) && $this->isUserTalkEditBlocked ) {
            // For grepping: message block-log-flags-nousertalk
            $flags[] = 'nousertalk';
        }

        if ( $this->isHideUser ) {
            // For grepping: message block-log-flags-hiddenname
            $flags[] = 'hiddenname';
        }

        return implode( ',', $flags );
    }
}
