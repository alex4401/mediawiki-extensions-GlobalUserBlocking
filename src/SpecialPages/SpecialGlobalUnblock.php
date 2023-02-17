<?php
namespace MediaWiki\Extension\GlobalUserBlocking\SpecialPages;

use MediaWiki\Block\BlockActionInfo;
use MediaWiki\Block\BlockPermissionCheckerFactory;
use MediaWiki\Block\BlockUtils;
use MediaWiki\Block\DatabaseBlock;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserNamePrefixSearch;
use MediaWiki\User\UserNameUtils;
use FormSpecialPage;
use TitleFormatter;
use ErrorPageError;
use SpecialBlock;
use CommentStore;
use Html;
use HtmlArmor;
use HTMLForm;
use MediaWiki\Extension\GlobalUserBlocking\GlobalBlock;
use MediaWiki\Extension\GlobalUserBlocking\GlobalBlockCommandFactory;
use MediaWiki\Extension\GlobalUserBlocking\GlobalBlockStore;
use Status;
use SpecialPage;
use Title;
use TitleValue;
use User;
use WebRequest;

class SpecialGlobalUnblock extends SpecialPage {
    /** @var UserIdentity|string|null */
    protected $target;

    /** @var int|null GlobalBlock::TYPE_ constant */
    protected $type;

    protected $block;

    /** @var GlobalBlockCommandFactory */
    private $commandFactory;

    /** @var GlobalBlockStore */
    private $blockStore;

    /** @var BlockUtils */
    private $blockUtils;

    /** @var UserNameUtils */
    private $userNameUtils;

    /** @var UserNamePrefixSearch */
    private $userNamePrefixSearch;

    /**
     * @param GlobalBlockCommandFactory $commandFactory
     * @param GlobalBlockStore $blockStore
     * @param BlockUtils $blockUtils
     * @param UserNameUtils $userNameUtils
     * @param UserNamePrefixSearch $userNamePrefixSearch
     */
    public function __construct(
        GlobalBlockCommandFactory $commandFactory,
        GlobalBlockStore $blockStore,
        BlockUtils $blockUtils,
        UserNameUtils $userNameUtils,
        UserNamePrefixSearch $userNamePrefixSearch
    ) {
        parent::__construct( 'GlobalUnblock', 'globalblock' );
        $this->commandFactory = $commandFactory;
        $this->blockStore = $blockStore;
        $this->blockUtils = $blockUtils;
        $this->userNameUtils = $userNameUtils;
        $this->userNamePrefixSearch = $userNamePrefixSearch;
    }

    public function doesWrites() {
        return true;
    }

    public function execute( $par ) {
        $this->checkPermissions();
        $this->checkReadOnly();

        list( $this->target, $this->type ) = $this->getTargetAndType( $par, $this->getRequest() );
        $this->block = $this->blockStore->loadFromTarget( $this->target );
        if ( $this->target instanceof UserIdentity ) {
            # Set the 'relevant user' in the skin, so it displays links like Contributions,
            # User logs, UserRights, etc.
            $this->getSkin()->setRelevantUser( $this->target );
        }

        $this->setHeaders();
        $this->outputHeader();
        $this->addHelpLink( 'Help:Blocking users' );

        $out = $this->getOutput();
        $out->setPageTitle( $this->msg( 'unblockip' ) );
        $out->addModules( [ 'mediawiki.userSuggest' ] );

        $form = HTMLForm::factory( 'ooui', $this->getFields(), $this->getContext() )
            ->setWrapperLegendMsg( 'unblockip' )
            ->setSubmitCallback( function ( array $data, HTMLForm $form ) {
                return $this->commandFactory->newRemover(
                    $data['Target'],
                    $form->getContext()->getAuthority(),
                    $data['Reason'],
                    $data['Tags'] ?? []
                )->unblock();
            } )
            ->setSubmitTextMsg( 'ipusubmit' )
            ->addPreHtml( $this->msg( 'unblockiptext' )->parseAsBlock() );

        if ( $form->show() ) {
            switch ( $this->type ) {
                case DatabaseBlock::TYPE_IP:
                    // @phan-suppress-next-line PhanTypeMismatchArgumentNullable target is set when type is set
                    $out->addWikiMsg( 'unblocked-ip', wfEscapeWikiText( $this->target ) );
                    break;
                case DatabaseBlock::TYPE_USER:
                    // @phan-suppress-next-line PhanTypeMismatchArgumentNullable target is set when type is set
                    $out->addWikiMsg( 'unblocked', wfEscapeWikiText( $this->target ) );
                    break;
                case DatabaseBlock::TYPE_RANGE:
                    // @phan-suppress-next-line PhanTypeMismatchArgumentNullable target is set when type is set
                    $out->addWikiMsg( 'unblocked-range', wfEscapeWikiText( $this->target ) );
                    break;
                case DatabaseBlock::TYPE_ID:
                    // @phan-suppress-next-line PhanTypeMismatchArgumentNullable target is set when type is set
                    $out->addWikiMsg( 'unblocked-id', wfEscapeWikiText( $this->target ) );
                    break;
            }
        }
    }

    /**
     * Get the target and type, given the request and the subpage parameter.
     * Several parameters are handled for backwards compatability. 'wpTarget' is
     * prioritized, since it matches the HTML form.
     *
     * @param string|null $par Subpage parameter
     * @param WebRequest $request
     * @return array [ UserIdentity|string|null, DatabaseBlock::TYPE_ constant|null ]
     * @phan-return array{0:UserIdentity|string|null,1:int|null}
     */
    private function getTargetAndType( ?string $par, WebRequest $request ) {
        $possibleTargets = [
            $request->getVal( 'wpTarget', null ),
            $par,
            $request->getVal( 'ip', null ),
            // B/C @since 1.18
            $request->getVal( 'wpBlockAddress', null ),
        ];
        foreach ( $possibleTargets as $possibleTarget ) {
            $targetAndType = $this->blockUtils->parseBlockTarget( $possibleTarget );
            // If type is not null then target is valid
            if ( $targetAndType[ 1 ] !== null ) {
                break;
            }
        }
        return $targetAndType;
    }

    protected function getFields() {
        $fields = [
            'Target' => [
                'type' => 'text',
                'label-message' => 'ipaddressorusername',
                'autofocus' => true,
                'size' => '45',
                'required' => true,
                'cssclass' => 'mw-autocomplete-user', // used by mediawiki.userSuggest
            ],
            'Name' => [
                'type' => 'info',
                'label-message' => 'ipaddressorusername',
            ],
            'Reason' => [
                'type' => 'text',
                'label-message' => 'ipbreason',
            ]
        ];

        if ( $this->block instanceof GlobalBlock ) {
            $type = $this->block->getType();
            $targetName = $this->block->getTargetName();

            $fields['Target']['default'] = $targetName;
            $fields['Target']['type'] = 'hidden';
            switch ( $type ) {
                case GlobalBlock::TYPE_IP:
                    $fields['Name']['default'] = $this->getLinkRenderer()->makeKnownLink(
                        $this->getSpecialPageFactory()->getTitleForAlias( 'Contributions/' . $targetName ),
                        $targetName
                    );
                    $fields['Name']['raw'] = true;
                    break;
                case GlobalBlock::TYPE_USER:
                    $fields['Name']['default'] = $this->getLinkRenderer()->makeLink(
                        new TitleValue( NS_USER, $targetName ),
                        $targetName
                    );
                    $fields['Name']['raw'] = true;
                    break;
                case GlobalBlock::TYPE_RANGE:
                    $fields['Name']['default'] = $targetName;
                    break;
            }
            // target is hidden, so the reason is the first element
            $fields['Target']['autofocus'] = false;
            $fields['Reason']['autofocus'] = true;
        } else {
            $fields['Target']['default'] = $this->target;
            unset( $fields['Name'] );
        }

        return $fields;
    }

    /**
     * Return an array of subpages beginning with $search that this special page will accept.
     *
     * @param string $search Prefix to search for
     * @param int $limit Maximum number of results to return (usually 10)
     * @param int $offset Number of results to skip (usually 0)
     * @return string[] Matching subpages
     */
    public function prefixSearchSubpages( $search, $limit, $offset ) {
        $search = $this->userNameUtils->getCanonical( $search );
        if ( !$search ) {
            // No prefix suggestion for invalid user
            return [];
        }
        // Autocomplete subpage as user list - public to allow caching
        return $this->userNamePrefixSearch->search( UserNamePrefixSearch::AUDIENCE_PUBLIC, $search, $limit, $offset );
    }

    protected function getGroupName() {
        return 'users';
    }
}
