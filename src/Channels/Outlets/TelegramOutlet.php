<?php
/**
 * Telegram, through the Bot API.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\Outlets;

use Estasoft\NewsDistribution\Channels\ValidationResult;
use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Event;

defined( 'ABSPATH' ) || exit;

/**
 * Posts a link to a Telegram channel when an article goes live: the headline,
 * a short summary and the address, with Telegram's own link preview. One bot
 * token serves every language; each language names its own channel.
 *
 * The bot is only a sender. It cannot see whether it is an administrator of a
 * channel, whether anyone read a post, or edit or delete a post once it is sent.
 */
final class TelegramOutlet extends AbstractOutlet {

	/**
	 * The start of every Bot API address. The bot token follows it.
	 */
	public const API = 'https://api.telegram.org/bot';

	public function id(): string {
		return 'telegram';
	}

	public function label(): string {
		return __( 'Telegram', 'estasoft-news-distribution' );
	}

	/**
	 * Settings: the switch, the token, one channel per language and what a post holds.
	 *
	 * @param string[] $languages Language codes the site publishes in.
	 * @return array<string, array<string, mixed>>
	 */
	public function fields( array $languages ): array {
		$fields = array(
			'telegram_on'    => array(
				'type'    => 'bool',
				'label'   => __( 'Post to Telegram', 'estasoft-news-distribution' ),
				'default' => false,
			),
			'telegram_token' => array(
				'type'  => 'secret',
				'label' => __( 'Token', 'estasoft-news-distribution' ),
				'max'   => 200,
			),
		);

		foreach ( $languages as $language ) {
			$fields[ 'telegram_chat_' . self::suffix( (string) $language ) ] = array(
				'type'    => 'string',
				'label'   => sprintf(
					/* translators: %s: a language name such as English. */
					__( '%s channel', 'estasoft-news-distribution' ),
					$this->language_name( (string) $language )
				),
				'default' => '',
				'max'     => 64,
			);
		}

		$fields['telegram_headline'] = array(
			'type'    => 'bool',
			'label'   => __( 'The headline', 'estasoft-news-distribution' ),
			'default' => true,
		);
		$fields['telegram_summary']  = array(
			'type'    => 'bool',
			'label'   => __( 'A short summary', 'estasoft-news-distribution' ),
			'default' => true,
		);
		$fields['telegram_preview']  = array(
			'type'    => 'bool',
			'label'   => __( 'A link preview', 'estasoft-news-distribution' ),
			'default' => true,
		);

		return $fields;
	}

	public function is_on( array $settings ): bool {
		if ( empty( $settings['telegram_on'] ) ) {
			return false;
		}

		foreach ( $this->targets( $settings, $this->languages() ) as $target ) {
			if ( $target->is_complete() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * One target per language that has a channel name.
	 *
	 * @param array<string, mixed> $settings  The channel's settings.
	 * @param string[]             $languages Language codes the site publishes in.
	 * @return Target[]
	 */
	public function targets( array $settings, array $languages ): array {
		$token   = $this->has_secret( 'telegram_token' );
		$targets = array();

		foreach ( $languages as $language ) {
			$language = (string) $language;
			$chat     = trim( (string) ( $settings[ 'telegram_chat_' . self::suffix( $language ) ] ?? '' ) );

			if ( '' === $chat ) {
				continue;
			}

			$targets[] = new Target( $this->id(), $language, $chat, $language, $token && self::is_valid_chat( $chat ) );
		}

		return $targets;
	}

	/**
	 * Cleans what a person typed as a channel: a link such as https://t.me/name becomes @name.
	 *
	 * @param string $text What was typed.
	 * @return string
	 */
	public static function normalise_chat( string $text ): string {
		$text = trim( $text );

		if ( 1 === preg_match( '#^(?:https?://)?(?:t\.me|telegram\.me)/([A-Za-z][A-Za-z0-9_]{3,31})/?$#i', $text, $found ) ) {
			return '@' . $found[1];
		}

		return $text;
	}

	/**
	 * Whether a channel name has a shape Telegram accepts: @name for a public channel, or the
	 * channel's number for a private one (it starts with -100).
	 *
	 * @param string $chat A normalised channel name.
	 * @return bool
	 */
	public static function is_valid_chat( string $chat ): bool {
		return 1 === preg_match( '/^@[A-Za-z][A-Za-z0-9_]{3,31}$/', $chat ) || 1 === preg_match( '/^-?\d{5,20}$/', $chat );
	}

	public function check( Article $article, array $settings ): ValidationResult {
		$result = parent::check( $article, $settings );

		if ( ! $this->has_target_for( $article->language(), $settings ) ) {
			$result->add(
				$this->context->rules->severity( OutletsChannel::ID, 'post.no_account', 'warn' ),
				'post.no_account',
				sprintf(
					/* translators: %s: a language name such as Kurmanji. */
					__( 'No Telegram channel is set for %s, so this article is not posted to Telegram.', 'estasoft-news-distribution' ),
					$this->language_name( $article->language() )
				)
			);
		}

		return $result;
	}

	public function send( Target $target, Event $event, ?Article $article, array $settings ): Outcome {
		if ( null === $article ) {
			return Outcome::skipped( $target, __( 'Telegram posts cannot be edited or removed after they are sent. Do that in Telegram.', 'estasoft-news-distribution' ) );
		}

		return $this->post(
			$target,
			array_merge(
				$this->message( $article, $settings ),
				array( 'chat_id' => $target->label() )
			)
		);
	}

	public function test( Target $target, array $settings, bool $send ): Outcome {
		if ( ! $this->has_secret( 'telegram_token' ) ) {
			return Outcome::failed( $target, __( 'Paste the bot token first.', 'estasoft-news-distribution' ), 0, false );
		}

		if ( ! $send ) {
			$reply = $this->http( 'GET', self::API . $this->secret( 'telegram_token' ) . '/getMe' );
			$json  = $reply->json();

			if ( $reply->is_success() && ! empty( $json['ok'] ) ) {
				$name = (string) ( $json['result']['username'] ?? '' );

				return Outcome::sent(
					$target,
					$reply->code(),
					'' === $name
						? __( 'Telegram accepted the bot token.', 'estasoft-news-distribution' )
						: sprintf(
							/* translators: %s: the bot's user name. */
							__( 'Telegram accepted the bot token for @%s. This does not show whether the bot can post in the channel.', 'estasoft-news-distribution' ),
							$this->clean( $name )
						)
				);
			}

			return $this->failure( $target, $reply );
		}

		return $this->post(
			$target,
			array(
				'chat_id'              => $target->label(),
				'text'                 => htmlspecialchars(
					sprintf(
						/* translators: %s: the publication's name. */
						__( 'Test message from %s. News Distribution is connected, and you can ignore this message.', 'estasoft-news-distribution' ),
						$this->context->settings->publication_name( $target->language() )
					),
					ENT_NOQUOTES,
					'UTF-8'
				),
				'parse_mode'           => 'HTML',
				'link_preview_options' => array( 'is_disabled' => true ),
			)
		);
	}

	/**
	 * The request body for an article: headline, summary and address, shortened to fit.
	 *
	 * The headline and the address are never cut. The summary takes what is left of Telegram's
	 * limit, counted in UTF-16 code units so the message is never over it.
	 *
	 * @param Article              $article  The article.
	 * @param array<string, mixed> $settings The channel's settings.
	 * @return array<string, mixed> The body without the chat.
	 */
	public function message( Article $article, array $settings ): array {
		$limit    = $this->limit( 'telegram_message_max', 4096 );
		$headline = ! empty( $settings['telegram_headline'] ) ? PostText::headline( $article ) : '';
		$url      = $article->permalink();
		$room     = $limit - PostText::utf16( $headline ) - PostText::utf16( $url ) - 8;
		$summary  = '';

		if ( ! empty( $settings['telegram_summary'] ) ) {
			$summary = PostText::shorten( PostText::summary( $article ), max( 0, $room ), array( PostText::class, 'utf16' ) );
		}

		$blocks = array();

		if ( '' !== $headline ) {
			$blocks[] = '<b>' . htmlspecialchars( $headline, ENT_NOQUOTES, 'UTF-8' ) . '</b>';
		}

		if ( '' !== $summary ) {
			$blocks[] = htmlspecialchars( $summary, ENT_NOQUOTES, 'UTF-8' );
		}

		$blocks[] = htmlspecialchars( $url, ENT_NOQUOTES, 'UTF-8' );

		$body = array(
			'text'       => implode( "\n\n", $blocks ),
			'parse_mode' => 'HTML',
		);

		$body['link_preview_options'] = ! empty( $settings['telegram_preview'] )
			? array(
				'is_disabled' => false,
				'url'         => $url,
			)
			: array( 'is_disabled' => true );

		return $body;
	}

	/**
	 * Calls sendMessage and turns the answer into an outcome.
	 *
	 * @param Target               $target  The target.
	 * @param array<string, mixed> $payload The sendMessage body.
	 * @return Outcome
	 */
	private function post( Target $target, array $payload ): Outcome {
		$token = $this->secret( 'telegram_token' );

		if ( '' === $token ) {
			return Outcome::failed( $target, __( 'The bot token is missing. Paste it again on the Telegram tab.', 'estasoft-news-distribution' ), 0, false );
		}

		$until = $this->state->paused_until( $this->id() );

		if ( $until > Clock::now() ) {
			return Outcome::failed(
				$target,
				sprintf(
					/* translators: %s: a time of day such as 14:38. */
					__( 'Telegram asked News Distribution to slow down, so it waits until %s.', 'estasoft-news-distribution' ),
					Clock::format( $until )
				),
				429,
				true
			);
		}

		$reply = $this->http(
			'POST',
			self::API . $token . '/sendMessage',
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
			)
		);
		$json  = $reply->json();

		if ( $reply->is_success() && ! empty( $json['ok'] ) ) {
			$id = (string) ( $json['result']['message_id'] ?? '' );

			return Outcome::sent(
				$target,
				$reply->code(),
				sprintf(
					/* translators: %s: a Telegram channel such as @example_times. */
					__( 'Posted to %s.', 'estasoft-news-distribution' ),
					$target->label()
				),
				$id
			);
		}

		return $this->failure( $target, $reply );
	}

	/**
	 * Turns a refusal into an outcome with a sentence a person can act on.
	 *
	 * @param Target $target The target.
	 * @param Reply  $reply  The answer.
	 * @return Outcome
	 */
	private function failure( Target $target, Reply $reply ): Outcome {
		$json   = $reply->json();
		$code   = (int) ( $json['error_code'] ?? $reply->code() );
		$detail = $this->clean( (string) ( $json['description'] ?? '' ) );

		if ( 429 === $code || 429 === $reply->code() ) {
			$wait = $reply->retry_after();

			if ( $wait > 0 ) {
				$this->state->pause( $this->id(), Clock::now() + $wait );
			}

			return Outcome::failed(
				$target,
				sprintf(
					/* translators: %d: seconds Telegram asked to wait. */
					_n( 'Telegram asked News Distribution to wait %d second.', 'Telegram asked News Distribution to wait %d seconds.', max( 1, $wait ), 'estasoft-news-distribution' ),
					max( 1, $wait )
				),
				429,
				true
			);
		}

		if ( 401 === $code ) {
			$message = __( 'Telegram did not accept the token. Paste the bot token again.', 'estasoft-news-distribution' );
		} elseif ( 403 === $code ) {
			$message = __( 'The bot may not post in this channel. Add it to the channel as an administrator with permission to post messages.', 'estasoft-news-distribution' );
		} elseif ( 400 === $code && false !== stripos( $detail, 'chat not found' ) ) {
			$message = __( 'Telegram cannot find this channel. Check the name, and add the bot to the channel.', 'estasoft-news-distribution' );
		} else {
			$message = OutletHttp::describe_failure( $reply, $this->label() );
		}

		if ( '' !== $detail && $reply->code() > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %s: the error Telegram gave, in its own words. */
				__( 'Telegram says: %s', 'estasoft-news-distribution' ),
				mb_substr( $detail, 0, 160 )
			);
		}

		return Outcome::failed( $target, $message, $reply->code() > 0 ? $reply->code() : $code, null );
	}

	/**
	 * Whether a language has a complete channel.
	 *
	 * @param string               $language Language code.
	 * @param array<string, mixed> $settings The channel's settings.
	 * @return bool
	 */
	private function has_target_for( string $language, array $settings ): bool {
		foreach ( $this->targets( $settings, $this->languages() ) as $target ) {
			if ( $target->is_complete() && $target->carries( $language ) ) {
				return true;
			}
		}

		return false;
	}
}
