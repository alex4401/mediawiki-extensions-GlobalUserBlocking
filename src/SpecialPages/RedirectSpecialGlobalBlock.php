<?php
namespace MediaWiki\Extension\GlobalUserBlocking\SpecialPages;

class RedirectSpecialGlobalBlock extends CentralWikiRedirectSpecialPage {

    public function __construct() {
        parent::__construct( 'GlobalBlock', 'globalblock' );
    }
}
