<?php
namespace MediaWiki\Extension\GlobalUserBlocking;

use Title;
use SpecialPage;
use MediaWiki\Permissions\PermissionManager;
use Config;
use MediaWiki\CommentFormatter\CommentFormatter;

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

    }

    public function onContributionsToolLinks( $id, Title $title, array &$tools, SpecialPage $specialPage ) {

    }
}
