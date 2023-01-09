<?php
namespace MediaWiki\Extension\GlobalUserBlocking;

use Config;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Block\CompositeBlock;
use MediaWiki\CommentFormatter\CommentFormatter;
use Message;
use SpecialPage;
use Title;

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

	/**
	 * @param PermissionManager $permissionManager
	 * @param Config $mainConfig
	 * @param CommentFormatter $commentFormatter
	 */
	public function __construct(
		PermissionManager $permissionManager,
		Config $mainConfig,
		CommentFormatter $commentFormatter
	) {
		$this->permissionManager = $permissionManager;
		$this->config = $mainConfig;
		$this->commentFormatter = $commentFormatter;
	}

    public function onGetUserBlock( $user, $ip, &$block ) {
        // Check if global blocks are enabled on this wiki, or maybe only managed
        if ( !$this->config->get( 'ApplyGlobalUserBlocks' ) ) {
            return true;
        }

        // Check if user is exempted locally from global blocks
		if ( $this->permissionManager->userHasAnyRight( $user, 'globaluserblockexempt' ) ) {
			return true;
		}

        // Retrieve the global block
        $globalBlock = GlobalUserBlock::get( $user, $ip );
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
		$linkRenderer = $specialPage->getLinkRenderer();
		$user = $specialPage->getUser();
        $target = $title->getText();

        if ( $this->permissionManager->userHasRight( $user, 'globaluserblock' ) ) {
            if ( GlobalUserBlock::getBlockId( $target ) === 0 ) {
                $tools['gub'] = $linkRenderer->makeKnownLink(
                    SpecialPage::getTitleFor( 'GlobalBlockUser', $target ),
                    $specialPage->msg( 'globaluserblocking-contribs-add' )->text()
                );
            } else {
                $tools['gub'] = $linkRenderer->makeKnownLink(
                    SpecialPage::getTitleFor( 'GlobalBlockUser', $target ),
                    $specialPage->msg( 'globaluserblocking-contribs-modify' )->text()
                );

                $tools['guub'] = $linkRenderer->makeKnownLink(
                    SpecialPage::getTitleFor( 'GlobalUnblockUser', $target ),
                    $specialPage->msg( 'globaluserblocking-contribs-remove' )->text()
                );
            }
        }
    }
}
