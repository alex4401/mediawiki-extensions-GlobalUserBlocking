<?php
namespace MediaWiki\Extension\GlobalUserBlocking\Jobs;

use GenericParameterJob;
use Job;
use ManualLogEntry;
use MediaWiki\MediaWikiServices;
use Title;
use WikiMap;

class CentralGlobalBlockLoggingJob extends Job implements GenericParameterJob {
    public function __construct( array $params ) {
        parent::__construct( 'GubCentralLog', $params );
    }

    public static function log( array $params ): string {
        $centralIdLookup = MediaWikiServices::getInstance()->getCentralIdLookup();

        $params['params']['5::wiki'] = $params['wiki'] === WikiMap::getCurrentWikiId() ? 'local' : $params['wiki'];

        $logEntry = new ManualLogEntry( 'globalblock', $params['action'] );
        $logEntry->setTarget( Title::makeTitle( NS_USER, $params['target'] ) );
        $logEntry->setComment( $params['reason'] );
        $logEntry->setPerformer( $centralIdLookup->localUserFromCentralId( $params['performerId'] ) );
        $logEntry->setParameters( $params['params'] );
        $logEntry->setRelations( [ 'gub_id' => $params['blockId'] ] );
        $logEntry->addTags( $params['tags'] );
        if ( $params['deletionFlags'] !== null ) {
            $logEntry->setDeleted( $params['deletionFlags'] );
        }

        if ( array_key_exists( 'timestamp', $params ) ) {
            $logEntry->setTimestamp( $params['timestamp'] );
        }

        $logId = $logEntry->insert();
        $logEntry->publish( $logId );

        return $logEntry->getTimestamp();
    }

    public function run() {
        self::log( $this->params );
        return true;
    }
}
