<?php
/**
 * Base for the things the AI assistant can do.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Ai\Features;

use Estasoft\NewsDistribution\Ai\Availability;
use Estasoft\NewsDistribution\Ai\Client;
use Estasoft\NewsDistribution\Ai\Config;
use Estasoft\NewsDistribution\Ai\Log;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * A feature turns a request into suggestions and, when a person decides, applies one.
 *
 * To add one: create `src/Ai/Features/<Name>.php` extending this class. It is found by folder and reached as
 * `POST /ai/suggest/<name>` and `POST /ai/decision`. The rules every feature follows:
 *
 * - `suggest()` goes through `Client::ask()` and nothing else, so consent, the switches and the limits apply.
 * - It never changes an article. It writes a row in the AI log with the decision `pending` (or, for a question, the
 *   answer) and returns what it wrote.
 * - `decide()` is the only step that changes an article, and only after a person chose. It records who chose.
 * - Every suggestion went through the rule check before it was kept.
 */
abstract class AbstractFeature {

	protected Plugin $plugin;

	/**
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The REST name, a key of Config::FEATURE_TOGGLE, such as "fix".
	 *
	 * @return string
	 */
	abstract public function name(): string;

	/**
	 * The name in the AI log and the report filter, such as "Fix suggestions".
	 *
	 * @return string
	 */
	abstract public function label(): string;

	/**
	 * Whether the feature is about one article.
	 *
	 * @return bool
	 */
	public function needs_article(): bool {
		return true;
	}

	/**
	 * Whether the person may use the feature, for this article when it needs one.
	 *
	 * @param int $post_id The article, or 0.
	 * @return bool
	 */
	public function can( int $post_id ): bool {
		$access = $this->plugin->access();

		return $this->needs_article() ? $access->can( Access::AI, $post_id ) : $access->can( Access::MANAGE );
	}

	/**
	 * Asks for suggestions and writes the log row.
	 *
	 * @param int                  $post_id The article, or 0.
	 * @param array<string, mixed> $args    What the feature asks for.
	 * @return array<string, mixed>|\WP_Error
	 */
	abstract public function suggest( int $post_id, array $args );

	/**
	 * Applies a person's decision to a suggestion made earlier.
	 *
	 * @param array<string, mixed> $row     The AI log row.
	 * @param array<string, mixed> $args    The decision: `decision`, `index`, `text`, `target` and what the feature reads.
	 * @param int                  $user_id The person who decided.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function decide( array $row, array $args, int $user_id ) {
		unset( $row, $args, $user_id );

		return new \WP_Error( 'estasoft_nd_ai_no_decision', __( 'There is nothing to decide here.', 'estasoft-news-distribution' ), array( 'status' => 400 ) );
	}

	/**
	 * Sends one request through the client.
	 *
	 * @param array<string, mixed> $request See TextGenerator.
	 * @return array{text: string, provider: string, model: string, tokens: int, ms: int}|\WP_Error
	 */
	protected function ask( array $request ) {
		return $this->plugin->make( Client::class )->ask( $this->name(), $request );
	}

	/**
	 * The article, refused when it is a draft and the owner has not allowed drafts.
	 *
	 * Nothing is sent before this passes.
	 *
	 * @param int $post_id The article.
	 * @return Article|\WP_Error
	 */
	protected function sendable( int $post_id ) {
		$article = $this->plugin->reader()->find( $post_id );

		if ( null === $article ) {
			return new \WP_Error( 'estasoft_nd_ai_no_article', __( 'That is not an article News Distribution sends.', 'estasoft-news-distribution' ), array( 'status' => 404 ) );
		}

		if ( ! $article->is_public() && ! $this->plugin->make( Config::class )->drafts() ) {
			return new \WP_Error(
				'estasoft_nd_ai_draft_not_allowed',
				__( 'This article is not published. Turn on “Also drafts and scheduled posts” in Settings, AI assistant, to use the assistant on a draft.', 'estasoft-news-distribution' ),
				array( 'status' => 403 )
			);
		}

		return $article;
	}

	/**
	 * The opening of an article as plain text, for a request that needs a little of it.
	 *
	 * @param Article $article The article.
	 * @param int     $chars   The most characters.
	 * @return string
	 */
	protected function lead( Article $article, int $chars = 600 ): string {
		$text = wp_strip_all_tags( strip_shortcodes( $article->content() ) );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		return mb_strlen( $text ) > $chars ? rtrim( mb_substr( $text, 0, $chars ) ) . '…' : $text;
	}

	/**
	 * Reads JSON out of an answer, which may come in a code fence or with a sentence around it.
	 *
	 * @param string $text The answer.
	 * @return array<mixed>|null
	 */
	protected function json( string $text ): ?array {
		$text = trim( $text );
		$text = (string) preg_replace( '/^```[a-z]*\s*|\s*```$/i', '', $text );

		$decoded = json_decode( $text, true );

		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		$open  = (int) strcspn( $text, '[{' );
		$close = max( (int) strrpos( $text, ']' ), (int) strrpos( $text, '}' ) );

		if ( $open < strlen( $text ) && $close > $open ) {
			$decoded = json_decode( substr( $text, $open, $close - $open + 1 ), true );

			return is_array( $decoded ) ? $decoded : null;
		}

		return null;
	}

	/**
	 * Reads a list of short texts out of an answer: a JSON list of strings, or else one text on each line.
	 *
	 * @param string $text The answer.
	 * @return string[] Trimmed, without quotes or list marks, in the order given.
	 */
	protected function texts( string $text ): array {
		$decoded = $this->json( $text );
		$items   = array();

		if ( is_array( $decoded ) ) {
			// An object that holds the list under a name, such as {"headlines": [...]}.
			if ( array_keys( $decoded ) !== range( 0, count( $decoded ) - 1 ) ) {
				foreach ( $decoded as $value ) {
					if ( is_array( $value ) ) {
						$decoded = $value;
						break;
					}
				}
			}

			foreach ( $decoded as $item ) {
				if ( is_string( $item ) ) {
					$items[] = $item;
				} elseif ( is_array( $item ) && isset( $item['text'] ) && is_string( $item['text'] ) ) {
					$items[] = $item['text'];
				}
			}
		} else {
			$items = preg_split( '/\r\n|\r|\n/', $text );
			$items = is_array( $items ) ? $items : array();
		}

		return $this->clean_texts( $items );
	}

	/**
	 * Tidies a list of short texts: trims, drops list marks and quotes, and skips empty ones.
	 *
	 * @param array<mixed> $items What the AI gave; anything that is not text is skipped.
	 * @return string[]
	 */
	protected function clean_texts( array $items ): array {
		$out = array();

		foreach ( $items as $item ) {
			if ( ! is_string( $item ) ) {
				continue;
			}

			$item = trim( (string) preg_replace( '/^\s*(?:[-*•]|\d{1,2}[.)])\s+/u', '', $item ) );
			$item = (string) preg_replace( '/^[\s"\'“”‘’]+|[\s"\'“”‘’]+$/u', '', $item );
			$item = trim( (string) preg_replace( '/\s+/u', ' ', $item ) );

			if ( '' !== $item ) {
				$out[] = $item;
			}
		}

		return $out;
	}

	/**
	 * Writes the AI log row for an answer.
	 *
	 * @param int                                                          $post_id  The article, or 0.
	 * @param array{provider: string, model: string, tokens: int, ms: int} $answer   What the client returned.
	 * @param array<string, mixed>                                         $data     What the AI suggested and was asked.
	 * @param string                                                       $decision The decision so far.
	 * @param int                                                          $reviewer The person already known to be responsible, if any.
	 * @return int The log row id, or 0 when nothing was written.
	 */
	protected function record( int $post_id, array $answer, array $data, string $decision = Log::PENDING, int $reviewer = 0 ): int {
		return $this->plugin->make( Log::class )->add(
			array(
				'post_id'     => $post_id,
				'feature'     => $this->name(),
				'connection'  => $answer['provider'] . ':' . $answer['model'],
				'decision'    => $decision,
				'reviewer_id' => $reviewer,
				'data'        => array_merge(
					$data,
					array(
						'tokens'       => $answer['tokens'],
						'ms'           => $answer['ms'],
						'requested_by' => get_current_user_id(),
					)
				),
			)
		);
	}

	/**
	 * The name of a provider.
	 *
	 * @param string $provider_id Provider id.
	 * @return string
	 */
	protected function provider_name( string $provider_id ): string {
		foreach ( $this->plugin->make( Availability::class )->providers() as $provider ) {
			if ( $provider['id'] === $provider_id ) {
				return $provider['name'];
			}
		}

		return $provider_id;
	}

	/**
	 * What every suggestion answer carries about the request.
	 *
	 * @param array{provider: string, model: string, tokens: int, ms: int} $answer What the client returned.
	 * @return array<string, mixed>
	 */
	protected function meta( array $answer ): array {
		return array(
			'provider'      => $answer['provider'],
			'provider_name' => $this->provider_name( $answer['provider'] ),
			'model'         => $answer['model'],
			'tokens'        => $answer['tokens'],
			'requests'      => 1,
		);
	}

	/**
	 * Finds the row's stored suggestion by position.
	 *
	 * @param array<string, mixed> $row   The AI log row.
	 * @param int                  $index Position in the stored list.
	 * @return array<string, mixed>|null
	 */
	protected function stored_suggestion( array $row, int $index ): ?array {
		$list = $row['data']['suggestions'] ?? array();

		return is_array( $list ) && isset( $list[ $index ] ) && is_array( $list[ $index ] ) ? $list[ $index ] : null;
	}

	/**
	 * The newest row of this feature for an article, when it still waits for a decision.
	 *
	 * What a page shows after a form post without the script: the suggestions made a moment ago.
	 *
	 * @param int    $post_id The article.
	 * @param string $finding Which kind of suggestion, when the feature makes more than one; empty for any.
	 * @return array<string, mixed>|null
	 */
	public function pending_row( int $post_id, string $finding = '' ): ?array {
		$found = $this->plugin->make( Log::class )->query(
			array(
				'feature' => $this->name(),
				'post_id' => $post_id,
				'limit'   => 5,
			)
		);

		foreach ( $found['items'] as $row ) {
			if ( Log::PENDING !== $row['decision'] || Clock::now() - (int) $row['created'] > DAY_IN_SECONDS ) {
				continue;
			}

			$was = $row['data']['finding'] ?? '';

			if ( '' === $finding || $finding === $was ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Refuses a decision on a row that was decided before.
	 *
	 * @param array<string, mixed> $row The AI log row.
	 * @return \WP_Error|null
	 */
	protected function already_decided( array $row ): ?\WP_Error {
		if ( Log::PENDING !== $row['decision'] ) {
			return new \WP_Error( 'estasoft_nd_ai_decided', __( 'This suggestion was decided already.', 'estasoft-news-distribution' ), array( 'status' => 409 ) );
		}

		return null;
	}
}
