<?php
namespace MediaWiki\Extension\GlobalUserBlocking;

use CentralIdLookup;
use Config;
use Html;
use LogicException;
use MediaWiki\Block\Block;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Block\CompositeBlock;
use MediaWiki\CommentFormatter\CommentFormatter;
use MediaWiki\Extension\GlobalUserBlocking\SpecialPages\GlobalBlockListPager;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserFactory;
use Message;
use RequestContext;
use SpecialPage;
use Title;
use Wikimedia\IPUtils;

class HookHandler implements
    \MediaWiki\Block\Hook\GetUserBlockHook,
    \MediaWiki\Hook\ContributionsToolLinksHook,
    \MediaWiki\Hook\UserToolLinksEditHook,
    \MediaWiki\Hook\OtherBlockLogLinkHook,
    \MediaWiki\Hook\SpecialContributionsBeforeMainOutputHook
{

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

    /** @var GlobalBlockUtils */
    private $blockUtils;

    /** @var GlobalBlockStore */
    private $blockStore;

    private UserFactory $userFactory;

    private CentralIdLookup $centralIdLookup;

    /**
     * @param PermissionManager $permissionManager
     * @param Config $mainConfig
     * @param CommentFormatter $commentFormatter
     */
    public function __construct(
        PermissionManager $permissionManager,
        Config $mainConfig,
        GlobalBlockUtils $blockUtils,
        GlobalBlockStore $blockStore,
        UserFactory $userFactory,
        CentralIdLookup $centralIdLookup
    ) {
        $this->permissionManager = $permissionManager;
        $this->config = $mainConfig;
        $this->blockUtils = $blockUtils;
        $this->blockStore = $blockStore;
        $this->userFactory = $userFactory;
        $this->centralIdLookup = $centralIdLookup;
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

    public function onGetLogTypesOnUser( &$types ) {
        $types[] = 'globalblock';
        return true;
    }

    public function onUserToolLinksEdit( $userId, $userText, &$items ) {
        if ( RequestContext::getMain()->getAuthority()->isAllowed( 'globalblock' ) ) {
            // TODO: insert a global block link
        }
    }

    public function onContributionsToolLinks( $id, Title $title, array &$tools, SpecialPage $specialPage ) {
        $user = $specialPage->getUser();
        $linkRenderer = $specialPage->getLinkRenderer();
        $target = $title->getDBkey();

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

    public function onSpecialContributionsBeforeMainOutput( $userId, $user, $sp ) {
        $name = $user->getName();
        $centralId = 0;
        if ( !IPUtils::isIPAddress( $name ) ) {
            $centralId = $this->centralIdLookup->centralIdFromLocalUser( $user );
        }

        $block = $this->blockStore->loadFromTarget( $name, $centralId );

        if ( $block !== null ) {
            $conds = GlobalBlockStore::getRangeCond( $block->gb_address );
            $pager = new GlobalBlockListPager(
                $sp->getContext(),
                $this->blockUtils,
                MediaWikiServices::getInstance()->getLinkBatchFactory(),
                MediaWikiServices::getInstance()->getLinkRenderer(),
                MediaWikiServices::getInstance()->getDBLoadBalancer(),
                MediaWikiServices::getInstance()->getSpecialPageFactory(),
                $this->centralIdLookup,
                $conds,
                $sp->getLinkRenderer()
            );
            $body = $pager->formatRow( $block );

            $msg = $user->isAnon() ? 'globaluserblocking-contributions-notice-anon' : 'globaluserblocking-contributions-notice';
            $out = $sp->getOutput();
            $out->addHTML(
                Html::warningBox(
                    $sp->msg( $msg, $name )->parseAsBlock() .
                Html::rawElement( 'ul', [], $body ),
                    'mw-warning-with-logexcerpt'
                )
            );
        }

        return true;
    }

    public function onOtherBlockLogLink( &$msg, $ip ) {
        $centralId = 0;
        $msgKey = 'globaluserblocking-loglink-anon';
        if ( !IPUtils::isIPAddress( $ip ) ) {
            $centralId = $this->centralIdLookup->centralIdFromName( $ip, CentralIdLookup::AUDIENCE_RAW );
            $msgKey = 'globaluserblocking-loglink';
        }

        $block = $this->blockStore->loadFromTarget( $ip, $centralId );

        if ( !$block ) {
            return true;
        }

        $msg[] = Html::rawElement(
            'span',
            [ 'class' => 'mw-globalblock-loglink plainlinks' ],
            wfMessage( $msgKey, $ip )->parse()
        );
        return true;
    }
}
