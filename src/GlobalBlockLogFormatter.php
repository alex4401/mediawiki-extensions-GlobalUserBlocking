<?php
namespace MediaWiki\Extension\GlobalUserBlocking;

use ApiResult;
use BlockLogFormatter;
use Linker;
use LogFormatter;
use LogPage;
use Message;
use SpecialPage;
use User;

class GlobalBlockLogFormatter extends BlockLogFormatter {
    protected function getMessageParameters() {
        $params = LogFormatter::getMessageParameters();

        $title = $this->entry->getTarget();
        if ( substr( $title->getText(), 0, 1 ) === '#' ) {
            // autoblock - no user link possible
            $params[2] = $title->getText();
            $params[3] = ''; // no user name for gender use
        } else {
            // Create a user link for the blocked
            $username = $title->getText();
            // @todo Store the user identifier in the parameters
            // to make this faster for future log entries
            $targetUser = User::newFromName( $username, false );
            $params[2] = Message::rawParam( $this->makeUserLink( $targetUser, Linker::TOOL_LINKS_NOBLOCK ) );
            $params[3] = $username; // plain user name for gender use
        }

        $subtype = $this->entry->getSubtype();
        if ( $subtype === 'block' || $subtype === 'reblock' ) {
            if ( !isset( $params[5] ) ) {
                // Very old log entry without duration: means infinity
                $params[5] = 'infinity';
            }
            // Localize the duration, and add a tooltip
            // in English to help visitors from other wikis.
            // The lrm is needed to make sure that the number
            // is shown on the correct side of the tooltip text.
            // @phan-suppress-next-line SecurityCheck-DoubleEscaped
            $durationTooltip = '&lrm;' . htmlspecialchars( $params[4] );
            $blockExpiry = $this->context->getLanguage()->translateBlockExpiry(
                $params[5],
                $this->context->getUser(),
                (int)wfTimestamp( TS_UNIX, $this->entry->getTimestamp() )
            );
            if ( $this->plaintext ) {
                $params[5] = Message::rawParam( $blockExpiry );
            } else {
                $params[5] = Message::rawParam(
                    "<span class=\"blockExpiry\" title=\"$durationTooltip\">" .
                    // @phan-suppress-next-line SecurityCheck-DoubleEscaped language class does not escape
                    htmlspecialchars( $blockExpiry ) .
                    '</span>'
                );
            }
            $params[6] = isset( $params[6] ) ?
                self::formatBlockFlags( $params[6], $this->context->getLanguage() ) : '';
        }

        return $params;
    }

    public function getPreloadTitles() {
        $title = $this->entry->getTarget();
        $preload = [];
        // Preload user page for non-autoblocks
        if ( substr( $title->getText(), 0, 1 ) !== '#' && $title->canExist() ) {
            $preload[] = $title->getTalkPage();
        }
        return $preload;
    }

    public function getActionLinks() {
        $subtype = $this->entry->getSubtype();
        $linkRenderer = $this->getLinkRenderer();
        if ( $this->entry->isDeleted( LogPage::DELETED_ACTION ) // Action is hidden
            || !( $subtype === 'block' || $subtype === 'reblock' )
            || !$this->context->getAuthority()->isAllowed( 'globalblock' )
        ) {
            return '';
        }

        // Show unblock/change block link
        $title = $this->entry->getTarget();
        $links = [
            $linkRenderer->makeKnownLink(
                SpecialPage::getTitleFor( 'GlobalUnblock', $title->getDBkey() ),
                $this->msg( 'unblocklink' )->text()
            ),
            $linkRenderer->makeKnownLink(
                SpecialPage::getTitleFor( 'GlobalBlock', $title->getDBkey() ),
                $this->msg( 'change-blocklink' )->text()
            )
        ];

        return $this->msg( 'parentheses' )->rawParams( $this->context->getLanguage()->pipeList( $links ) )->escaped();
    }

    /**
     * @inheritDoc
     * @suppress PhanTypeInvalidDimOffset
     */
    public function formatParametersForApi() {
        $ret = parent::formatParametersForApi();
        if ( isset( $ret['flags'] ) ) {
            ApiResult::setIndexedTagName( $ret['flags'], 'f' );
        }
        return $ret;
    }

    protected function getMessageKey() {
        $subtype = $this->entry->getSubtype();
        $key = "globaluserblocking-logentry-$subtype";

        $params = LogFormatter::getMessageParameters();
        if ( $params[4] === 'local' ) {
            $key = "$key-local";
        }

        return $key;
    }
}
