<?php
namespace MediaWiki\Extension\GlobalUserBlocking\SpecialPages;

use CentralIdLookup;
use CommentStore;
use Html;
use HTMLForm;
use MediaWiki\Block\BlockUtils;
use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\CommentFormatter\CommentFormatter;
use MediaWiki\Extension\GlobalUserBlocking\GlobalBlock;
use MediaWiki\Extension\GlobalUserBlocking\GlobalBlockStore;
use MediaWiki\Extension\GlobalUserBlocking\GlobalBlockUtils;
use MediaWiki\MediaWikiServices;
use SpecialPage;
use Wikimedia\IPUtils;
use Wikimedia\Rdbms\ILoadBalancer;

class SpecialGlobalBlockList extends SpecialPage {
    /** @var string|null */
    protected $target;

    /** @var array */
    protected $options;

    /** @var LinkBatchFactory */
    private $linkBatchFactory;

    /** @var ILoadBalancer */
    private $loadBalancer;

    /** @var CommentStore */
    private $commentStore;

    /** @var GlobalBlockUtils */
    private $blockUtils;

    public function __construct(
        LinkBatchFactory $linkBatchFactory,
        ILoadBalancer $loadBalancer,
        CommentStore $commentStore,
        GlobalBlockUtils $blockUtils
    ) {
        parent::__construct( 'GlobalBlockUserList' );

        $this->linkBatchFactory = $linkBatchFactory;
        $this->loadBalancer = $loadBalancer;
        $this->commentStore = $commentStore;
        $this->blockUtils = $blockUtils;
    }

    /**
     * @param string $par Parameters of the URL, probably the IP being actioned
     * @return void
     */
    public function execute( $par ) {
        $out = $this->getOutput();
        $this->setHeaders();
        $this->outputHeader( 'globaluserblocking-list-intro' );
        $out->addModuleStyles( [ 'mediawiki.special' ] );

        $out = $this->getOutput();
        $out->setPageTitle( $this->msg( 'globaluserblocking-list' ) );
        $out->setArticleRelated( false );

        // Set the pager up here to get the actual default Limit
        $pager = $this->getBlockListPager();

        # Just show the block list
        $fields = [
            'Target' => [
                'type' => 'user',
                'label-message' => 'ipaddressorusername',
                'tabindex' => '1',
                'size' => '45',
                'default' => $this->target,
            ],
            'Options' => [
                'type' => 'multiselect',
                'options-messages' => [
                    'blocklist-tempblocks' => 'tempblocks',
                    'blocklist-indefblocks' => 'indefblocks',
                    'blocklist-userblocks' => 'userblocks',
                    'blocklist-addressblocks' => 'addressblocks',
                    'blocklist-rangeblocks' => 'rangeblocks',
                ],
                'flatlist' => true,
            ],
        ];

        $fields['Limit'] = [
            'type' => 'limitselect',
            'label-message' => 'table_pager_limit_label',
            'options' => $pager->getLimitSelectList(),
            'name' => 'limit',
            'default' => $pager->getLimit(),
            'cssclass' => 'mw-field-limit mw-has-field-block-type',
        ];

        $form = HTMLForm::factory( 'ooui', $fields, $this->getContext() );
        $form
            ->setMethod( 'get' )
            ->setTitle( $this->getPageTitle() ) // Remove subpage
            ->setFormIdentifier( 'blocklist' )
            ->setWrapperLegendMsg( 'ipblocklist-legend' )
            ->setSubmitTextMsg( 'ipblocklist-submit' )
            ->prepareForm()
            ->displayForm( false );

        $this->showList( $pager );
    }


    /**
     * Setup a new BlockListPager instance.
     * @return GlobalBlockListPager
     */
    protected function getBlockListPager() {
        $conds = [];
        $db = $this->loadBalancer->getConnection( ILoadBalancer::DB_REPLICA );
        # Is the user allowed to see hidden blocks?
        if ( !$this->getAuthority()->isAllowed( 'hideuser' ) ) {
            $conds['gub_deleted'] = 0;
        }

        if ( $this->target !== '' ) {
            list( $target, $type ) = $this->blockUtils->parseBlockTarget( $this->target );

            switch ( $type ) {
                case GlobalBlock::TYPE_ID:
                    $conds['gub_id'] = $target;
                    break;

                case GlobalBlock::TYPE_IP:
                case GlobalBlock::TYPE_RANGE:
                    list( $start, $end ) = IPUtils::parseRange( $target );
                    $conds[] = $db->makeList(
                        [
                            'gub_target_address' => $target,
                            GlobalBlockStore::getRangeCond( $start, $end )
                        ],
                        LIST_OR
                    );
                    break;

                case GlobalBlock::TYPE_USER:
                    $conds['gub_target_central_id'] = $this->centralIdLookup->centralIdFromLocalUser( $target,
                        CentralIdLookup::AUDIENCE_RAW );
                    break;
            }
        }

        # Apply filters
        if ( in_array( 'userblocks', $this->options ) ) {
            $conds['ipb_user'] = 0;
        }
        if ( in_array( 'addressblocks', $this->options ) ) {
            $conds[] = "ipb_user != 0 OR ipb_range_end > ipb_range_start";
        }
        if ( in_array( 'rangeblocks', $this->options ) ) {
            $conds[] = "ipb_range_end = ipb_range_start";
        }

        $hideTemp = in_array( 'tempblocks', $this->options );
        $hideIndef = in_array( 'indefblocks', $this->options );
        if ( $hideTemp && $hideIndef ) {
            // If both types are hidden, ensure query doesn't produce any results
            $conds[] = '1=0';
        } elseif ( $hideTemp ) {
            $conds['gub_expiry'] = $db->getInfinity();
        } elseif ( $hideIndef ) {
            $conds[] = "gub_expiry != " . $db->addQuotes( $db->getInfinity() );
        }

        return new BlockListPager(
            $this->getContext(),
            $this->blockActionInfo,
            $this->blockRestrictionStore,
            $this->blockUtils,
            $this->commentStore,
            $this->linkBatchFactory,
            $this->getLinkRenderer(),
            $this->loadBalancer,
            $this->rowCommentFormatter,
            $this->getSpecialPageFactory(),
            $conds
        );
    }

    /**
     * Show the list of blocked accounts matching the actual filter.
     * @param BlockListPager $pager The BlockListPager instance for this page
     */
    protected function showList( GlobalBlockListPager $pager ) {
        $out = $this->getOutput();

        # Check for other blocks, i.e. global/tor blocks
        $otherBlockLink = [];
        $this->getHookRunner()->onOtherBlockLogLink( $otherBlockLink, $this->target );

        # Show additional header for the local block only when other blocks exists.
        # Not necessary in a standard installation without such extensions enabled
        if ( count( $otherBlockLink ) ) {
            $out->addHTML(
                Html::element( 'h2', [], $this->msg( 'ipblocklist-localblock' )->text() ) . "\n"
            );
        }

        if ( $pager->getNumRows() ) {
            $out->addParserOutputContent( $pager->getFullOutput() );
        } elseif ( $this->target ) {
            $out->addWikiMsg( 'ipblocklist-no-results' );
        } else {
            $out->addWikiMsg( 'ipblocklist-empty' );
        }

        if ( count( $otherBlockLink ) ) {
            $out->addHTML(
                Html::rawElement(
                    'h2',
                    [],
                    $this->msg( 'ipblocklist-otherblocks', count( $otherBlockLink ) )->parse()
                ) . "\n"
            );
            $list = '';
            foreach ( $otherBlockLink as $link ) {
                $list .= Html::rawElement( 'li', [], $link ) . "\n";
            }
            $out->addHTML( Html::rawElement(
                'ul',
                [ 'class' => 'mw-ipblocklist-otherblocks' ],
                $list
            ) . "\n" );
        }
    }

    protected function getGroupName() {
        return 'users';
    }
}
