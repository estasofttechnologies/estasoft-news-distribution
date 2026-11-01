<?php
/**
 * Shared behaviour of the outlets.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\Outlets;

use Estasoft\NewsDistribution\Channels\ChannelContext;
use Estasoft\NewsDistribution\Channels\ChannelSettings;
use Estasoft\NewsDistribution\Channels\ValidationResult;
use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Event;
use Estasoft\NewsDistribution\Core\Language\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Reads secrets, sends requests and cleans what comes back, the same way for
 * every outlet, so no outlet has to remember to.
 */
abstract class AbstractOutlet implements OutletInterface {

	protected ChannelContext $context;
	protected OutletsState $state;
	protected Marks $marks;

	/**
	 * The values of the channel's secrets, read once per request, for cleaning texts.
	 *
	 * @var string[]|null
	 */
	private ?array $known = null;

	/**
	 * Creates the outlet.
	 *
	 * @param ChannelContext $context Services the outlet may use.
	 * @param OutletsState   $state   What the outlets learned while they ran.
	 * @param Marks          $marks   Which targets already got a post.
	 */
	public function __construct( ChannelContext $context, OutletsState $state, Marks $marks ) {
		$this->context = $context;
		$this->state   = $state;
		$this->marks   = $marks;
	}

	/**
	 * By default an outlet posts new articles only. An outlet with a reason to hear more says so.
	 *
	 * @param Event $event The event.
	 * @return bool
	 */
	public function carries( Event $event ): bool {
		return Event::PUBLISH === $event->type();
	}

	/**
	 * Checks every outlet makes: the article needs a headline and an address.
	 *
	 * @param Article              $article  The article.
	 * @param array<string, mixed> $settings The channel's settings.
	 * @return ValidationResult
	 */
	public function check( Article $article, array $settings ): ValidationResult {
		$result = new ValidationResult();

		if ( '' === PostText::headline( $article ) ) {
			$result->add_error( 'outlets.title_missing', __( 'The article has no headline, so there is nothing to post.', 'estasoft-news-distribution' ) );
		}

		if ( '' === $article->permalink() ) {
			$result->add_error( 'outlets.permalink_missing', __( 'The article has no public address, so there is nothing to link to.', 'estasoft-news-distribution' ) );
		}

		return $result;
	}

	/**
	 * The part of a field name that stands for a language: "ku" for Kurmanji, "zh_hans" for "zh-hans".
	 *
	 * @param string $language Language code.
	 * @return string
	 */
	public static function suffix( string $language ): string {
		return trim( strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '_', $language ) ), '_' );
	}

	/**
	 * A secret this outlet stores. Never print it, log it or put it in a message.
	 *
	 * @param string $field The secret field's name.
	 * @return string
	 */
	protected function secret( string $field ): string {
		return $this->context->secrets->get( ChannelSettings::secret_key( OutletsChannel::ID, $field ) );
	}

	/**
	 * Whether a secret field has a value.
	 *
	 * @param string $field The secret field's name.
	 * @return bool
	 */
	protected function has_secret( string $field ): bool {
		return '' !== $this->secret( $field );
	}

	/**
	 * Sends a request. Anything read back is cleaned of every secret the channel holds.
	 *
	 * @param string               $method GET, POST or DELETE.
	 * @param string               $url    Address.
	 * @param array<string, mixed> $args   Request arguments.
	 * @return Reply
	 */
	protected function http( string $method, string $url, array $args = array() ): Reply {
		return OutletHttp::send( $method, $url, $args, $this->known_secrets() );
	}

	/**
	 * Removes every secret the channel holds from a text.
	 *
	 * @param string $text A text that will be shown or logged.
	 * @return string
	 */
	protected function clean( string $text ): string {
		return OutletHttp::scrub( $text, $this->known_secrets() );
	}

	/**
	 * A language name with the language code, for a label such as "English channel".
	 *
	 * @param string $language Language code.
	 * @return string
	 */
	protected function language_name( string $language ): string {
		return Catalog::name( $language );
	}

	/**
	 * A number from the rule pack.
	 *
	 * @param string $name     Limit name.
	 * @param int    $fallback Value when the pack has none.
	 * @return int
	 */
	protected function limit( string $name, int $fallback ): int {
		return (int) $this->context->rules->limit( OutletsChannel::ID, $name, $fallback );
	}

	/**
	 * The languages the site publishes in.
	 *
	 * @return string[]
	 */
	protected function languages(): array {
		return array_values( array_unique( array_map( 'strval', $this->context->languages->languages() ) ) );
	}

	/**
	 * The post's language, so one account gets only its own language.
	 *
	 * @param Event        $event   The event.
	 * @param Article|null $article The article.
	 * @return string
	 */
	protected function language_of( Event $event, ?Article $article ): string {
		return null === $article ? $event->language() : $article->language();
	}

	/**
	 * Every secret value the channel holds, for cleaning.
	 *
	 * @return string[]
	 */
	private function known_secrets(): array {
		if ( null === $this->known ) {
			$this->known = array();
			$prefix      = ChannelSettings::secret_key( OutletsChannel::ID, '' );

			foreach ( $this->context->secrets->keys() as $key ) {
				if ( 0 === strpos( (string) $key, $prefix ) ) {
					$value = $this->context->secrets->get( (string) $key );

					if ( '' !== $value ) {
						$this->known[] = $value;
					}
				}
			}
		}

		return $this->known;
	}

	/**
	 * Forgets the secrets read earlier in this request, after one changed.
	 *
	 * @return void
	 */
	public function forget_secrets(): void {
		$this->known = null;
	}
}
