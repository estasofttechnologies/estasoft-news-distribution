<?php
/**
 * Republished content: a copy of another source's article.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Checks;

use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Channels\Group;
use Estasoft\NewsDistribution\Content\Republished;
use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Codes: `republished.address_missing`, `republished.no_source` and
 * `republished.duplicate_risk`. Nothing here reads a platform's rule: the duplicate
 * warning says what the plugin will still send, and says a platform "may" treat the
 * copy as a duplicate, because no platform's duplicate handling is confirmed.
 *
 * Only articles that are republished content are looked at. No network.
 */
final class RepublishedCheck extends AbstractCheck {

	/**
	 * @return string
	 */
	public function id(): string {
		return 'republished';
	}

	/**
	 * @return int
	 */
	public function order(): int {
		return 90;
	}

	/**
	 * @param Article $article The article.
	 * @return array<int, array{code: string, severity: string, message: string, channels: string[], fix: string}>
	 */
	public function run( Article $article ): array {
		$content = $this->plugin->make( Republished::class );
		$policy  = $content->policy( $article->id() );

		if ( null === $policy ) {
			return array();
		}

		$findings = array();

		if ( $policy['degraded'] ) {
			$findings[] = $this->finding(
				'republished.address_missing',
				$this->default_severity( 'republished.address_missing', Status::WARN ),
				__( 'The address of the original is missing. “Point to the source” needs it, so this article is kept out of search instead until you add it.', 'estasoft-news-distribution' ),
				array(),
				''
			);
		}

		if ( '' === trim( $policy['name'] ) ) {
			$findings[] = $this->finding(
				'republished.no_source',
				$this->default_severity( 'republished.no_source', Status::INFO ),
				__( 'No source is named, so no line saying where the article came from is added at its end.', 'estasoft-news-distribution' ),
				array(),
				''
			);
		}

		$carriers = $this->carriers( $article, $policy, $content );

		if ( array() !== $carriers ) {
			$names = array();

			foreach ( $carriers as $channel ) {
				$names[] = $channel->label();
			}

			$findings[] = $this->finding(
				'republished.duplicate_risk',
				$this->default_severity( 'republished.duplicate_risk', Status::WARN ),
				sprintf(
					/* translators: %s: names of channels, such as "Microsoft Start, SmartNews". */
					__( 'This copy of another source’s article will still go to %s. A platform may treat it as a duplicate of the original.', 'estasoft-news-distribution' ),
					implode( ', ', $names )
				),
				array_map(
					static function ( ChannelInterface $channel ): string {
						return $channel->id();
					},
					$carriers
				),
				''
			);
		}

		return $findings;
	}

	/**
	 * The channels that are on and would still carry the copy, where a duplicate could matter.
	 *
	 * Partner feeds and outlets count whatever the handling is. The sitemaps count only when the
	 * copy keeps its own canonical link, because the other handlings already tell search what it is.
	 *
	 * @param Article              $article The article.
	 * @param array<string, mixed> $policy  The article's policy.
	 * @param Republished          $content The service.
	 * @return ChannelInterface[]
	 */
	private function carriers( Article $article, array $policy, Republished $content ): array {
		$found = array();

		foreach ( $this->plugin->registry()->enabled() as $channel ) {
			if ( ! $channel->accepts_language( $article->language() ) || '' !== $content->skip_reason( $policy, $channel ) ) {
				continue;
			}

			$partner = Group::PARTNER === $channel->group() || 'outlets' === $channel->id();
			$sitemap = Group::SITEMAP === $channel->group() && ChannelInterface::MODE_PULLED === $channel->mode() && Republished::OWN === $policy['handling'];

			if ( $partner || $sitemap ) {
				$found[] = $channel;
			}
		}

		return $found;
	}
}
