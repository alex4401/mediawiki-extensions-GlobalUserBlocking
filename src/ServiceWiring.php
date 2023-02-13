<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\GlobalUserBlocking\GlobalBlockCommandFactory;
use MediaWiki\Extension\GlobalUserBlocking\GlobalBlockStore;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

return [
    'GlobalUserBlocking.GlobalBlockStore' => static function (
        MediaWikiServices $services
    ): GlobalBlockStore {
        return new GlobalBlockStore(
            new ServiceOptions(
                GlobalBlockStore::CONSTRUCTOR_OPTIONS,
                $services->getMainConfig()
            ),
            LoggerFactory::getInstance( 'GlobalBlockStore' ),
            $services->getDBLoadBalancer(),
            $services->getReadOnlyMode(),
            $services->getUserFactory()
        );
    },
    'GlobalUserBlocking.GlobalBlockCommandFactory' => static function (
        MediaWikiServices $services
    ): GlobalBlockCommandFactory {
        return new GlobalBlockCommandFactory(
            new ServiceOptions(
                GlobalBlockCommandFactory::CONSTRUCTOR_OPTIONS,
                $services->getMainConfig()
            ),
            $services->getHookContainer(),
            $services->getBlockPermissionCheckerFactory(),
            $services->getBlockUtils(),
            $services->getService( 'GlobalUserBlocking.GlobalBlockStore' ),
            $services->getUserFactory(),
            $services->getUserEditTracker(),
            LoggerFactory::getInstance( 'GlobalBlockCommandFactory' )
        );
    },
];
