<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\GlobalUserBlocking\GlobalBlockCommandFactory;
use MediaWiki\Extension\GlobalUserBlocking\GlobalBlockStore;
use MediaWiki\Extension\GlobalUserBlocking\GlobalBlockUtils;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

return [
    GlobalBlockUtils::SERVICE_NAME => static function (
        MediaWikiServices $services
    ): GlobalBlockUtils {
        return new GlobalBlockUtils(
            new ServiceOptions(
                GlobalBlockUtils::CONSTRUCTOR_OPTIONS,
                $services->getMainConfig()
            ),
            $services->getBlockUtils(),
            $services->getCentralIdLookup(),
            $services->getUserIdentityLookup(),
            $services->getUserNameUtils()
        );
    },
    GlobalBlockStore::SERVICE_NAME => static function (
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
    GlobalBlockCommandFactory::SERVICE_NAME => static function (
        MediaWikiServices $services
    ): GlobalBlockCommandFactory {
        return new GlobalBlockCommandFactory(
            new ServiceOptions(
                GlobalBlockCommandFactory::CONSTRUCTOR_OPTIONS,
                $services->getMainConfig()
            ),
            $services->getHookContainer(),
            $services->getBlockPermissionCheckerFactory(),
            $services->getService( GlobalBlockUtils::SERVICE_NAME ),
            $services->getService( GlobalBlockStore::SERVICE_NAME ),
            $services->getUserFactory(),
            $services->getUserEditTracker(),
            LoggerFactory::getInstance( 'GlobalBlockCommandFactory' )
        );
    },
];
