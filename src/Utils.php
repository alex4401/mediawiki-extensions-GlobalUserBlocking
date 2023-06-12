<?php
namespace MediaWiki\Extension\GlobalUserBlocking;

use WikiMap;

class Utils {
    /**
     * @return bool
     */
    public static function isCentralWiki() {
        global $wgGUBCentralWiki;
        return WikiMap::getCurrentWikiId() === $wgGUBCentralWiki;
    }

    /**
     * @return string|bool
     */
    public static function getCentralWiki() {
        global $wgGUBCentralWiki;
        return $wgGUBCentralWiki;
    }
}
