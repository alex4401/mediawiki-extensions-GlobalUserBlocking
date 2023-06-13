<?php
namespace MediaWiki\Extension\GlobalUserBlocking\SpecialPages;

use CentralIdLookup;
use CommentStore;
use Html;
use IContextSource;
use IndexPager;
use Linker;
use MediaWiki\Block\BlockActionInfo;
use MediaWiki\Block\BlockRestrictionStore;
use MediaWiki\Block\BlockUtils;
use MediaWiki\Block\Restriction\ActionRestriction;
use MediaWiki\Block\Restriction\NamespaceRestriction;
use MediaWiki\Block\Restriction\PageRestriction;
use MediaWiki\Block\Restriction\Restriction;
use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\CommentFormatter\RowCommentFormatter;
use MediaWiki\Extension\GlobalUserBlocking\GlobalBlockUtils;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\MainConfigNames;
use MediaWiki\SpecialPage\SpecialPageFactory;
use MediaWiki\User\UserIdentity;
use MWTimestamp;
use phpDocumentor\Reflection\DocBlock\Tags\Link;
use stdClass;
use TablePager;
use User;
use WikiMap;
use Wikimedia\IPUtils;
use Wikimedia\Rdbms\ILoadBalancer;
use Wikimedia\Rdbms\IResultWrapper;

class GlobalBlockListPager extends TablePager {

    protected $conds;

    /** @var GlobalBlockUtils */
    private $blockUtils;

    /** @var LinkBatchFactory */
    private $linkBatchFactory;

    /** @var SpecialPageFactory */
    private $specialPageFactory;

    /** @var CentralIdLookup */
    private $centralIdLookup;

    /**
     * @param IContextSource $context
     * @param BlockActionInfo $blockActionInfo
     * @param BlockRestrictionStore $blockRestrictionStore
     * @param GlobalBlockUtils $blockUtils
     * @param LinkBatchFactory $linkBatchFactory
     * @param LinkRenderer $linkRenderer
     * @param ILoadBalancer $loadBalancer
     * @param SpecialPageFactory $specialPageFactory
     * @param CentralIdLookup $centralIdLookup
     * @param array $conds
     */
    public function __construct(
        IContextSource $context,
        GlobalBlockUtils $blockUtils,
        LinkBatchFactory $linkBatchFactory,
        LinkRenderer $linkRenderer,
        ILoadBalancer $loadBalancer,
        SpecialPageFactory $specialPageFactory,
        CentralIdLookup $centralIdLookup,
        $conds
    ) {
        $this->mDb = $loadBalancer->getConnection( ILoadBalancer::DB_REPLICA );
        parent::__construct( $context, $linkRenderer );
        $this->blockUtils = $blockUtils;
        $this->linkBatchFactory = $linkBatchFactory;
        $this->specialPageFactory = $specialPageFactory;
        $this->centralIdLookup = $centralIdLookup;
        $this->conds = $conds;
        $this->mDefaultDirection = IndexPager::DIR_DESCENDING;
    }

    protected function getFieldNames() {
        static $headers = null;

        if ( $headers === null ) {
            $headers = [
                'gub_timestamp' => 'blocklist-timestamp',
                'gub_target_address' => 'blocklist-target',
                'gub_expiry' => 'blocklist-expiry',
                'gub_performer_central_id' => 'blocklist-by',
                'gub_wiki_id' => 'globaluserblocking-blocklist-origin',
                'gub_params' => 'blocklist-params',
                'gub_reason' => 'blocklist-reason',
            ];
            foreach ( $headers as $key => $val ) {
                $headers[$key] = $this->msg( $val )->text();
            }
        }

        return $headers;
    }

    /**
     * @param string $name
     * @param string|null $value
     * @return string
     * @suppress PhanTypeArraySuspicious
     */
    public function formatValue( $name, $value ) {
        static $msg = null;
        if ( $msg === null ) {
            $keys = [
                'anononlyblock',
                'createaccountblock',
                'noautoblockblock',
                'emailblock',
                'blocklist-nousertalk',
                'unblocklink',
                'change-blocklink',
                'blocklist-editing',
                'blocklist-editing-sitewide',
            ];

            foreach ( $keys as $key ) {
                $msg[$key] = $this->msg( $key )->text();
            }
        }
        '@phan-var string[] $msg';

        /** @var stdClass $row */
        $row = $this->mCurrentRow;

        $language = $this->getLanguage();

        $formatted = '';

        $linkRenderer = $this->getLinkRenderer();

        switch ( $name ) {
            case 'gub_timestamp':
                $formatted = htmlspecialchars( $language->userTimeAndDate( $value, $this->getUser() ) );
                break;
            
            case 'gub_wiki_id':
                $formatted = WikiMap::getWikiName( $value );
                break;

            case 'gub_target_address':
                $target = null;

                if ( (int)$row->gub_target_central_id !== 0 ) {
                    $target = $this->centralIdLookup->localUserFromCentralId( (int)$row->gub_target_central_id,
                        CentralIdLookup::AUDIENCE_PUBLIC );
                } else {
                    list( $target, ) = $this->blockUtils->parseBlockTarget( $row->gub_target_address );
                }

                if ( is_string( $target ) ) {
                    if ( IPUtils::isValidRange( $target ) ) {
                        $target = User::newFromName( $target, false );
                    } else {
                        $formatted = $target;
                    }
                }

                if ( $target instanceof UserIdentity ) {
                    $formatted = Linker::userLink( $target->getId(), $target->getName() );
                    $formatted .= Linker::userToolLinks(
                        $target->getId(),
                        $target->getName(),
                        false,
                        Linker::TOOL_LINKS_NOBLOCK
                    );
                }
                break;

            case 'gub_expiry':
                $formatted = htmlspecialchars( $language->formatExpiry(
                    $value,
                    /* User preference timezone */true,
                    'infinity',
                    $this->getUser()
                ) );
                if ( $this->getAuthority()->isAllowed( 'block' ) ) {
                    $targetName = $row->gub_target_address;
                    if ( (int)$row->gub_target_central_id !== 0 ) {
                        $targetName = $this->centralIdLookup->nameFromCentralId( (int)$row->gub_target_central_id,
                            CentralIdLookup::AUDIENCE_PUBLIC );
                    }

                    $links = [];
                    $links[] = $linkRenderer->makeKnownLink(
                        $this->specialPageFactory->getTitleForAlias( 'GlobalUnblock/' . $targetName ),
                        $msg['unblocklink']
                    );
                    $links[] = $linkRenderer->makeKnownLink(
                        $this->specialPageFactory->getTitleForAlias( 'GlobalBlock/' . $targetName ),
                        $msg['change-blocklink']
                    );
                    $formatted .= ' ' . Html::rawElement(
                        'span',
                        [ 'class' => 'mw-blocklist-actions' ],
                        $this->msg( 'parentheses' )->rawParams(
                            $language->pipeList( $links ) )->escaped()
                    );
                }
                if ( $value !== 'infinity' ) {
                    $timestamp = new MWTimestamp( $value );
                    $formatted .= '<br />' . $this->msg(
                        'ipb-blocklist-duration-left',
                        $language->formatDuration(
                            (int)$timestamp->getTimestamp( TS_UNIX ) - MWTimestamp::time(),
                            // reasonable output
                            [
                                'minutes',
                                'hours',
                                'days',
                                'years',
                            ]
                        )
                    )->escaped();
                }
                break;

            case 'gub_performer_central_id':
                $userName = $this->centralIdLookup->nameFromCentralId( (int)$value );
                $formatted = Linker::userLink( (int)$value, $userName );
                $formatted .= Linker::userToolLinks( (int)$value, $userName );
                break;

            case 'gub_reason':
                $formatted = htmlspecialchars( $row->gub_reason );
                break;

            case 'gub_params':
                $properties = [];

                if ( $row->gub_anon_only ) {
                    $properties[] = htmlspecialchars( $msg['anononlyblock'] );
                }
                if ( $row->gub_create_account ) {
                    $properties[] = htmlspecialchars( $msg['createaccountblock'] );
                }

                if ( $row->gub_block_email ) {
                    $properties[] = htmlspecialchars( $msg['emailblock'] );
                }

                if ( !$row->gub_allow_usertalk ) {
                    $properties[] = htmlspecialchars( $msg['blocklist-nousertalk'] );
                }

                $formatted = Html::rawElement(
                    'ul',
                    [],
                    implode( '', array_map( static function ( $prop ) {
                        return Html::rawElement(
                            'li',
                            [],
                            $prop
                        );
                    }, $properties ) )
                );
                break;

            default:
                $formatted = "Unable to format $name";
                break;
        }

        return $formatted;
    }

    public function getQueryInfo() {
        $info = [
            'tables' => [ 'global_user_blocks' ],
            'fields' => [
                'gub_id',
                'gub_target_address',
                'gub_target_central_id',
                'gub_performer_central_id',
                'gub_timestamp',
                'gub_wiki_id',
                'gub_reason',
                'gub_anon_only',
                'gub_create_account',
                'gub_expiry',
                'gub_range_start',
                'gub_range_end',
                'gub_block_email',
                'gub_allow_usertalk'
            ],
            'conds' => $this->conds
        ];

        # Filter out any expired blocks
        $db = $this->getDatabase();
        $info['conds'][] = 'gub_expiry > ' . $db->addQuotes( $db->timestamp() );

        # Is the user allowed to see hidden blocks?
        if ( !$this->getAuthority()->isAllowed( 'hideuser' ) ) {
            $info['conds']['gub_deleted'] = 0;
        }

        return $info;
    }

    protected function getTableClass() {
        return parent::getTableClass() . ' mw-blocklist';
    }

    public function getIndexField() {
        return [ [ 'gub_timestamp', 'gub_id' ] ];
    }

    public function getDefaultSort() {
        return '';
    }

    protected function isFieldSortable( $name ) {
        return false;
    }

    /**
     * Do a LinkBatch query to minimise database load when generating all these links
     * @param IResultWrapper $result
     */
    public function preprocessResults( $result ) {
        // Do a link batch query
        $lb = $this->linkBatchFactory->newLinkBatch();
        $lb->setCaller( __METHOD__ );

        foreach ( $result as $row ) {
            if ( $row->gub_target_central_id !== null ) {
                $targetName = $this->centralIdLookup->nameFromCentralId( $row->gub_target_central_id,
                    CentralIdLookup::AUDIENCE_PUBLIC );
            } else {
                $targetName = $row->gub_target_address;
            }

            $lb->add( NS_USER, $targetName );
            $lb->add( NS_USER_TALK, $targetName );

            $performerName = $this->centralIdLookup->nameFromCentralId( $row->gub_performer_central_id,
                CentralIdLookup::AUDIENCE_PUBLIC );
            $lb->add( NS_USER, $performerName );
            $lb->add( NS_USER_TALK, $performerName );
        }

        $lb->execute();
    }

}
