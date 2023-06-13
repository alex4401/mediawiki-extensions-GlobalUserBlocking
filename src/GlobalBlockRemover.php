<?php
namespace MediaWiki\Extension\GlobalUserBlocking;

use ChangeTags;
use ManualLogEntry;
use MediaWiki\Block\BlockPermissionChecker;
use MediaWiki\Block\BlockPermissionCheckerFactory;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\HookContainer\HookRunner;
use MediaWiki\Permissions\Authority;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use RevisionDeleteUser;
use Status;
use TitleValue;

/**
 * Backend class for globally unblocking users
 */
class GlobalBlockRemover {
    /** @var BlockPermissionChecker */
    private $blockPermissionChecker;

    /** @var GlobalBlockStore */
    private $blockStore;

    /** @var GlobalBlockUtils */
    private $blockUtils;

    /** @var UserFactory */
    private $userFactory;

    /** @var HookRunner */
    private $hookRunner;

    /** @var UserIdentity|string */
    private $target;

    /** @var int */
    private $targetType;

    /** @var GlobalBlock|null */
    private $block;

    /** @var Authority */
    private $performer;

    /** @var string */
    private $reason;

    /** @var array */
    private $tags = [];

    /**
     * @param BlockPermissionCheckerFactory $blockPermissionCheckerFactory
     * @param GlobalBlockStore $blockStore
     * @param GlobalBlockUtils $blockUtils
     * @param UserFactory $userFactory
     * @param HookContainer $hookContainer
     * @param UserIdentity|string $target
     * @param Authority $performer
     * @param string $reason
     * @param string[] $tags
     */
    public function __construct(
        BlockPermissionCheckerFactory $blockPermissionCheckerFactory,
        GlobalBlockStore $blockStore,
        GlobalBlockUtils $blockUtils,
        UserFactory $userFactory,
        HookContainer $hookContainer,
        $target,
        Authority $performer,
        string $reason,
        array $tags = []
    ) {
        // Process dependencies
        $this->blockPermissionChecker = $blockPermissionCheckerFactory->newBlockPermissionChecker( $target, $performer );
        $this->blockStore = $blockStore;
        $this->blockUtils = $blockUtils;
        $this->userFactory = $userFactory;
        $this->hookRunner = new HookRunner( $hookContainer );

        // Process params
        list( $this->target, $this->targetType ) = $this->blockUtils->parseBlockTarget( $target );
        $this->block = $this->blockStore->loadFromTarget( $this->target );
        $this->performer = $performer;
        $this->reason = $reason;
        $this->tags = $tags;
    }

    /**
     * Unblock user
     *
     * @return Status
     */
    public function unblock(): Status {
        $status = Status::newGood();

        $basePermissionCheckResult = $this->blockPermissionChecker->checkBasePermissions(
            $this->block instanceof GlobalBlock && $this->block->getHideName()
        );
        if ( $basePermissionCheckResult !== true ) {
            return $status->fatal( $basePermissionCheckResult );
        }

        $blockPermissionCheckResult = $this->blockPermissionChecker->checkBlockPermissions();
        if ( $blockPermissionCheckResult !== true ) {
            return $status->fatal( $blockPermissionCheckResult );
        }

        if ( count( $this->tags ) !== 0 ) {
            $status->merge(
                ChangeTags::canAddTagsAccompanyingChange(
                    $this->tags,
                    $this->performer
                )
            );
        }

        if ( !$status->isOK() ) {
            return $status;
        }
        return $this->unblockUnsafe();
    }

    /**
     * Unblock user without any sort of permission checks
     *
     * @internal This is public to be called in a maintenance script.
     * @return Status
     */
    public function unblockUnsafe(): Status {
        $status = Status::newGood();

        if ( $this->block === null ) {
            return $status->fatal( 'ipb_cant_unblock', $this->target );
        }

        if (
            $this->block->getType() === GlobalBlock::TYPE_RANGE && $this->targetType === GlobalBlock::TYPE_IP
        ) {
            return $status->fatal( 'ipb_blocked_as_range', $this->target, $this->block->getTargetName() );
        }

        $denyReason = [ 'hookaborted' ];
        $legacyUser = $this->userFactory->newFromAuthority( $this->performer );
        if ( !$this->hookRunner->onUnblockUser( $this->block, $legacyUser, $denyReason ) ) {
            foreach ( $denyReason as $key ) {
                $status->fatal( $key );
            }
            return $status;
        }

        $deleteBlockStatus = $this->blockStore->deleteBlock( $this->block );
        if ( !$deleteBlockStatus ) {
            return $status->fatal( 'ipb_cant_unblock', $this->block->getTargetName() );
        }

        $this->hookRunner->onUnblockUserComplete( $this->block, $legacyUser );

        $this->log();

        $status->setResult( $status->isOK(), $this->block );
        return $status;
    }

    /**
     * Log the unblock to Special:Log/globalblock
     */
    private function log() {
        Utils::logReplicated( [
            'action' => 'unblock',
            'target' => $this->block->getTargetName(),
            'performer' => $this->performer->getUser(),
            'reason' => $this->reason,
            'blockId' => $this->block->getId(),
            'tags' => $this->tags
        ] );
    }
}
