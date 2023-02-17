<?php
namespace MediaWiki\Extension\GlobalUserBlocking\SpecialPages;

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
use Wikimedia\IPUtils;
use Wikimedia\Rdbms\ILoadBalancer;
use Wikimedia\Rdbms\IResultWrapper;

class GlobalBlockListPager extends TablePager {

    protected $conds;

    /**
     * Array of restrictions.
     *
     * @var Restriction[]
     */
    protected $restrictions = [];

    /** @var BlockActionInfo */
    private $blockActionInfo;

    /** @var BlockRestrictionStore */
    private $blockRestrictionStore;

    /** @var GlobalBlockUtils */
    private $blockUtils;

    /** @var CommentStore */
    private $commentStore;

    /** @var LinkBatchFactory */
    private $linkBatchFactory;

    /** @var RowCommentFormatter */
    private $rowCommentFormatter;

    /** @var SpecialPageFactory */
    private $specialPageFactory;

    /** @var string[] */
    private $formattedComments = [];

    /**
     * @param IContextSource $context
     * @param BlockActionInfo $blockActionInfo
     * @param BlockRestrictionStore $blockRestrictionStore
     * @param GlobalBlockUtils $blockUtils
     * @param CommentStore $commentStore
     * @param LinkBatchFactory $linkBatchFactory
     * @param LinkRenderer $linkRenderer
     * @param ILoadBalancer $loadBalancer
     * @param RowCommentFormatter $rowCommentFormatter
     * @param SpecialPageFactory $specialPageFactory
     * @param array $conds
     */
    public function __construct(
        IContextSource $context,
        BlockActionInfo $blockActionInfo,
        BlockRestrictionStore $blockRestrictionStore,
        GlobalBlockUtils $blockUtils,
        CommentStore $commentStore,
        LinkBatchFactory $linkBatchFactory,
        LinkRenderer $linkRenderer,
        ILoadBalancer $loadBalancer,
        RowCommentFormatter $rowCommentFormatter,
        SpecialPageFactory $specialPageFactory,
        $conds
    ) {
        $this->mDb = $loadBalancer->getConnection( ILoadBalancer::DB_REPLICA );
        parent::__construct( $context, $linkRenderer );
        $this->blockActionInfo = $blockActionInfo;
        $this->blockRestrictionStore = $blockRestrictionStore;
        $this->blockUtils = $blockUtils;
        $this->commentStore = $commentStore;
        $this->linkBatchFactory = $linkBatchFactory;
        $this->rowCommentFormatter = $rowCommentFormatter;
        $this->specialPageFactory = $specialPageFactory;
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

            case 'ipb_target':
                if ( $row->ipb_auto ) {
                    $formatted = $this->msg( 'autoblockid', $row->ipb_id )->parse();
                } else {
                    list( $target, ) = $this->blockUtils->parseBlockTarget( $row->ipb_address );

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
                    $links = [];
                    if ( $row->ipb_auto ) {
                        $links[] = $linkRenderer->makeKnownLink(
                            $this->specialPageFactory->getTitleForAlias( 'Unblock' ),
                            $msg['unblocklink'],
                            [],
                            [ 'wpTarget' => "#{$row->ipb_id}" ]
                        );
                    } else {
                        $links[] = $linkRenderer->makeKnownLink(
                            $this->specialPageFactory->getTitleForAlias( 'Unblock/' . $row->ipb_address ),
                            $msg['unblocklink']
                        );
                        $links[] = $linkRenderer->makeKnownLink(
                            $this->specialPageFactory->getTitleForAlias( 'Block/' . $row->ipb_address ),
                            $msg['change-blocklink']
                        );
                    }
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

            case 'ipb_by':
                $formatted = Linker::userLink( (int)$value, $row->ipb_by_text );
                $formatted .= Linker::userToolLinks( (int)$value, $row->ipb_by_text );
                break;

            case 'gub_reason':
                $formatted = $this->formattedComments[$this->getResultOffset()];
                break;

            case 'gub_params':
                $properties = [];

                if ( $row->ipb_anon_only ) {
                    $properties[] = htmlspecialchars( $msg['anononlyblock'] );
                }
                if ( $row->ipb_create_account ) {
                    $properties[] = htmlspecialchars( $msg['createaccountblock'] );
                }

                if ( $row->ipb_block_email ) {
                    $properties[] = htmlspecialchars( $msg['emailblock'] );
                }

                if ( !$row->ipb_allow_usertalk ) {
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
                'gub_address',
                'gub_user',
                'ipb_by' => 'ipblocks_by_actor.actor_user',
                'ipb_by_text' => 'ipblocks_by_actor.actor_name',
                'gub_timestamp',
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
            $lb->add( NS_USER, $row->ipb_address );
            $lb->add( NS_USER_TALK, $row->ipb_address );

            if ( $row->ipb_by ?? null ) {
                $lb->add( NS_USER, $row->ipb_by_text );
                $lb->add( NS_USER_TALK, $row->ipb_by_text );
            }
        }

        $lb->execute();

        // Format comments
        // The keys of formattedComments will be the corresponding offset into $result
        $this->formattedComments = $this->rowCommentFormatter->formatRows( $result, 'ipb_reason' );
    }

}
