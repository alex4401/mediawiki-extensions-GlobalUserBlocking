<?php
namespace MediaWiki\Extension\GlobalUserBlocking;

use MediaWiki\Block\AbstractBlock;
use MediaWiki\User\UserIdentity;
use User;

class GlobalUserBlock extends AbstractBlock {
	/** @var UserIdentity|null */
	private $blocker;

    public static function get( User $user, ?string $ip ): ?GlobalUserBlock {
        // TODO: stub
        return null;
    }

    public static function getBlockId( string $target ): int {
        // TODO: stub
        return 0;
    }

	/**
	 * @inheritDoc
	 */
	public function getBy( $wikiId = self::LOCAL ): int {
		$this->assertWiki( $wikiId );
		return ( $this->blocker ) ? $this->blocker->getId( $wikiId ) : 0;
	}

	/**
	 * @inheritDoc
	 */
	public function getByName(): string {
		return ( $this->blocker ) ? $this->blocker->getName() : '';
	}

	/**
	 * @inheritDoc
	 */
	public function getBlocker(): ?UserIdentity {
		return $this->blocker;
	}

	/**
	 * @inheritDoc
	 */
	public function getIdentifier( $wikiId = self::LOCAL ) {
		return $this->getId( $wikiId );
	}

	/** @inheritDoc */
	public function getId( $wikiId = self::LOCAL ): ?int {
		return $this->id;
	}
}