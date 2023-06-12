<?php
namespace MediaWiki\Extension\GlobalUserBlocking\SpecialPages;

use CentralIdLookup;
use CommentStore;
use Html;
use HTMLForm;
use MediaWiki\Block\BlockUtils;
use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\CommentFormatter\CommentFormatter;
use MediaWiki\CommentFormatter\RowCommentFormatter;
use MediaWiki\Extension\GlobalUserBlocking\GlobalBlock;
use MediaWiki\Extension\GlobalUserBlocking\GlobalBlockStore;
use MediaWiki\Extension\GlobalUserBlocking\GlobalBlockUtils;
use MediaWiki\MediaWikiServices;
use RedirectSpecialPage;
use SpecialPage;
use WikiMap;
use Wikimedia\IPUtils;
use Wikimedia\Rdbms\ILoadBalancer;

abstract class CentralWikiRedirectSpecialPage extends SpecialPage {

    public function __construct() {
        parent::__construct( 'GlobalBlock', 'globalblock' );
    }

	/**
	 * @stable to override
	 * @param string|null $subpage
	 */
	public function execute( $subpage ) {
		$url = WikiMap::getForeignURL()
		$this->getOutput()->redirect( $url );
		$redirect = $this->getRedirect( $subpage );
		$query = $this->getRedirectQuery( $subpage );

		if ( $redirect instanceof Title ) {
			// Redirect to a page title with possible query parameters
			$url = $redirect->getFullUrlForRedirect( $query );
			$this->getOutput()->redirect( $url );
		} elseif ( $redirect === true ) {
			// Redirect to index.php with query parameters
			$url = wfAppendQuery( wfScript( 'index' ), $query );
			$this->getOutput()->redirect( $url );
		} else {
			$this->showNoRedirectPage();
		}
	}

	public function isListed() {
		return true;
	}
}
