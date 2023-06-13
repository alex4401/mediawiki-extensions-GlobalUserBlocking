<?php
namespace MediaWiki\Extension\GlobalUserBlocking;

use Job;
use MediaWiki\Extension\GlobalUserBlocking\Jobs\CentralGlobalBlockLoggingJob;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserIdentity;
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

    public static function logReplicated( array $params ): void {
        $centralIdLookup = MediaWikiServices::getInstance()->getCentralIdLookup();

        $jobParams = $params;
        if ( !array_key_exists( 'params', $jobParams ) ) {
            $jobParams['params'] = [];
        }
        if ( $jobParams['performer'] instanceof UserIdentity ) {
            $jobParams['performerId'] = $centralIdLookup->centralIdFromLocalUser( $jobParams['performer'] );
        }
        $jobParams['wiki'] = WikiMap::getCurrentWikiId();

        // Log locally
        $jobParams['timestamp'] = CentralGlobalBlockLoggingJob::log( $jobParams );

        if ( self::getCentralWiki() !== $jobParams['wiki'] ) {
            // Log on the central wiki via job queue
            $jobGroup = MediaWikiServices::getInstance()->getJobQueueGroupFactory()->makeJobQueueGroup( self::getCentralWiki() );
            $jobGroup->push( Job::factory( 'GubCentralLog', $jobParams ) );
        }
    }
}
