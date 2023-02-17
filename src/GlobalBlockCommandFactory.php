<?php

namespace MediaWiki\Extension\GlobalUserBlocking;

use MediaWiki\Block\BlockPermissionCheckerFactory;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\Permissions\Authority;
use MediaWiki\User\UserEditTracker;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use Psr\Log\LoggerInterface;

class GlobalBlockCommandFactory {
    /**
     * @var BlockPermissionCheckerFactory
     */
    private $blockPermissionCheckerFactory;

    /** @var GlobalBlockUtils */
    private $blockUtils;

    /** @var HookContainer */
    private $hookContainer;

    /** @var ServiceOptions */
    private $options;

    /** @var GlobalBlockStore */
    private $blockStore;

    /** @var UserFactory */
    private $userFactory;

    /** @var UserEditTracker */
    private $userEditTracker;

    /** @var LoggerInterface */
    private $logger;

    /**
     * @internal Use only in ServiceWiring
     */
    public const CONSTRUCTOR_OPTIONS = GlobalBlockPlacer::CONSTRUCTOR_OPTIONS;

    /**
     * @param ServiceOptions $options
     * @param HookContainer $hookContainer
     * @param BlockPermissionCheckerFactory $blockPermissionCheckerFactory
     * @param GlobalBlockUtils $blockUtils
     * @param GlobalBlockStore $blockStore
     * @param UserFactory $userFactory
     * @param UserEditTracker $userEditTracker
     * @param LoggerInterface $logger
     */
    public function __construct(
        ServiceOptions $options,
        HookContainer $hookContainer,
        BlockPermissionCheckerFactory $blockPermissionCheckerFactory,
        GlobalBlockUtils $blockUtils,
        GlobalBlockStore $blockStore,
        UserFactory $userFactory,
        UserEditTracker $userEditTracker,
        LoggerInterface $logger
    ) {
        $options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );

        $this->options = $options;
        $this->hookContainer = $hookContainer;
        $this->blockPermissionCheckerFactory = $blockPermissionCheckerFactory;
        $this->blockUtils = $blockUtils;
        $this->blockStore = $blockStore;
        $this->userFactory = $userFactory;
        $this->userEditTracker = $userEditTracker;
        $this->logger = $logger;
    }

    /**
     * Create BlockUser
     *
     * @param string|UserIdentity $target Target of the block
     * @param Authority $performer Performer of the block
     * @param string $expiry Expiry of the block (timestamp or 'infinity')
     * @param string $reason Reason of the block
     * @param array $blockOptions
     * @param array|null $tags Tags that should be assigned to the log entry
     *
     * @return GlobalBlockPlacer
     */
    public function newPlacer(
        $target,
        Authority $performer,
        string $expiry,
        string $reason = '',
        array $blockOptions = [],
        $tags = []
    ): GlobalBlockPlacer {
        if ( $tags === null ) {
            $tags = [];
        }

        return new GlobalBlockPlacer(
            $this->options,
            $this->blockStore,
            $this->blockPermissionCheckerFactory,
            $this->blockUtils,
            $this->hookContainer,
            $this->userFactory,
            $this->userEditTracker,
            $this->logger,
            $target,
            $performer,
            $expiry,
            $reason,
            $blockOptions,
            $tags
        );
    }

    /**
     * @param UserIdentity|string $target
     * @param Authority $performer
     * @param string $reason
     * @param string[] $tags
     *
     * @return UnblockUser
     */
    public function newRemover(
        $target,
        Authority $performer,
        string $reason,
        array $tags = []
    ): GlobalBlockRemover {
        return new GlobalBlockRemover(
            $this->blockPermissionCheckerFactory,
            $this->blockStore,
            $this->blockUtils,
            $this->userFactory,
            $this->hookContainer,
            $target,
            $performer,
            $reason,
            $tags
        );
    }
}
