<?php
namespace MediaWiki\Extension\GlobalUserBlocking;

use Config;
use LogicException;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Block\CompositeBlock;
use MediaWiki\CommentFormatter\CommentFormatter;
use MediaWiki\User\UserFactory;
use Message;
use SpecialPage;
use Title;
use Wikimedia\IPUtils;

class HookHandler implements
    \MediaWiki\Block\Hook\GetUserBlockHook,
    \MediaWiki\Hook\ContributionsToolLinksHook {

    public static function onLoadExtensionSchemaUpdates( $updater ) {
        $base = __DIR__ . '/..';
        $type = $updater->getDB()->getType();

        $updater->addExtensionTable(
            'global_user_blocks',
            "$base/sql/$type/tables-generated-global_user_blocks.sql"
        );

        return true;
    }

    /** @var PermissionManager */
    private $permissionManager;

    /** @var Config */
    private $config;

    /** @var CommentFormatter */
    private $commentFormatter;

    /** @var GlobalBlockStore */
    private $blockStore;

    private UserFactory $userFactory;

    /**
     * @param PermissionManager $permissionManager
     * @param Config $mainConfig
     * @param CommentFormatter $commentFormatter
     */
    public function __construct(
        PermissionManager $permissionManager,
        Config $mainConfig,
        CommentFormatter $commentFormatter,
        GlobalBlockStore $blockStore,
        UserFactory $userFactory
    ) {
        $this->permissionManager = $permissionManager;
        $this->config = $mainConfig;
        $this->commentFormatter = $commentFormatter;
        $this->blockStore = $blockStore;
        $this->userFactory = $userFactory;
    }

    public function onGetUserBlock( $user, $ip, &$block ) {
        // Check if global blocks are enabled on this wiki, or maybe only managed
        if ( !$this->config->get( 'ApplyGlobalBlocks' ) ) {
            return true;
        }

        // Check if user is exempted locally from global blocks
        if ( $this->permissionManager->userHasAnyRight( $user, 'globalblockexempt' ) ) {
            return true;
        }

        // Retrieve the global block
        $globalBlock = $this->blockStore->loadFromTarget( $user, $ip, true );
        if ( !$globalBlock ) {
            return true;
        }

        // Just return the block if local wiki has none
        if ( !$block ) {
            $block = $globalBlock;
            return true;
        }

        // User (or IP) is blocked both globally and locally, return a composite
        $allBlocks = $block instanceof CompositeBlock ? $block->getOriginalBlocks() : [ $block ];
        $allBlocks[] = $globalBlock;
        $block = new CompositeBlock( [
            'address' => $ip,
            'reason' => new Message( 'blockedtext-composite-reason' ),
            'originalBlocks' => $allBlocks,
        ] );

        return true;
    }

    public function onContributionsToolLinks( $id, Title $title, array &$tools, SpecialPage $specialPage ) {
        $user = $specialPage->getUser();
        $linkRenderer = $specialPage->getLinkRenderer();
        $target = $title->getText();

        if ( $this->permissionManager->userHasRight( $user, 'globalblock' ) ) {
            $targetUser = $id === 0 ? $this->userFactory->newAnonymous( $target ) : $this->userFactory->newFromId( $id );

            if ( $this->blockStore->loadFromTarget( $targetUser, $target, false ) === null ) {
                $tools['globalblock'] = $linkRenderer->makeKnownLink(
                    SpecialPage::getTitleFor( 'GlobalBlock', $target ),
                    $specialPage->msg( 'globaluserblocking-contribs-block' )->text()
                );
            } else {
                $tools['globalblock'] = $linkRenderer->makeKnownLink(
                    SpecialPage::getTitleFor( 'GlobalBlock', $target ),
                    $specialPage->msg( 'globaluserblocking-contribs-modify' )->text()
                );
                $tools['globalunblock'] = $linkRenderer->makeKnownLink(
                    SpecialPage::getTitleFor( 'RemoveGlobalBlock', $target ),
                    $specialPage->msg( 'globaluserblocking-contribs-remove' )->text()
                );
            }
        }
    }

    /**
     * So users can just type in a username for target and it'll work
     * @param array &$types
     * @return bool
     */
    public function onGetLogTypesOnUser( &$types ) {
        $types[] = 'globalblock';

        return true;
    }
}
