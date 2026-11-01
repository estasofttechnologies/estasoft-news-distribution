<?php
/**
 * Image helper: three checks on the featured image of one article, each with a suggestion a person can accept.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\CheckTabs;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Check\Times;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\EmptyState;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Ai\Availability;
use Estasoft\NewsDistribution\Ai\Config;
use Estasoft\NewsDistribution\Ai\Features\Image;
use Estasoft\NewsDistribution\Ai\Log;
use Estasoft\NewsDistribution\Ai\View\Parts;
use Estasoft\NewsDistribution\Ai\View\Runner;
use Estasoft\NewsDistribution\Content\ImageCredits;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Operations\Flash;
use Estasoft\NewsDistribution\Core\Operations\Links;

defined( 'ABSPATH' ) || exit;

/**
 * `admin.php?page=estasoft-nd-check&post=<id>&tab=image-helper`. It exists only while the assistant is ready and
 * "Image helper" or "Image captions" is on. With "Image helper" on, one request sends the featured image, and nothing
 * else, to the provider and brings back three things: what kind of picture it is, a focal point and alt text. Each is a
 * card with its own buttons.
 *
 * - The focal point and the alt text change only when a person presses Use (or Edit first, for the alt text).
 * - What a change replaced is kept, and Undo puts it back while no newer change has replaced it.
 * - The sentence about the kind of picture is written by News Distribution from a kind and a percentage.
 *
 * With "Image captions" on, a fourth card suggests a caption from the article's words. The AI does not see the photo.
 * A caption the photo already has is replaced only when the person ticks the box that says so, and the credit is never
 * changed.
 *
 * The buttons are forms, so the page works without a script; `Ai\View\Runner` handles what they post.
 */
final class ImageHelperTab extends AbstractCheckTab {

	public function slug(): string {
		return 'image-helper';
	}

	public function title(): string {
		return __( 'Image helper', 'estasoft-news-distribution' );
	}

	public function description(): string {
		return __( 'Checks and a caption for the featured image, each with a suggestion you can accept.', 'estasoft-news-distribution' );
	}

	public function order(): int {
		return 84;
	}

	public function permission(): string {
		return Access::AI;
	}

	public function available( int $post_id ): bool {
		$config = $this->plugin->make( Config::class );

		return $this->plugin->make( Availability::class )->usable() && ( $config->feature_on( 'image' ) || $config->feature_on( 'caption' ) );
	}

	public function handle( int $post_id ): void {
		if ( $post_id < 1 || ! Runner::posted( $this->slug() ) ) {
			return;
		}

		$this->plugin->access()->require_or_die( Access::AI, $post_id );
		$this->plugin->make( Runner::class )->handle( $this->slug(), $this->url( $post_id ) );
	}

	public function enqueue(): void {
		Assets::screen_style( 'ai' );
		Assets::screen_script( 'ai' );
	}

	public function render( int $post_id ): string {
		$feature = $this->plugin->make( Image::class );
		$photo   = $feature->attachment( $post_id );
		$flash   = Flash::take();

		if ( null === $photo ) {
			$edit = (string) get_edit_post_link( $post_id, 'raw' );

			return $flash . EmptyState::render(
				__( 'This article has no featured image in your media library', 'estasoft-news-distribution' ),
				__( 'Choose a featured image first. The helper checks that one, and nothing is sent until you ask.', 'estasoft-news-distribution' ),
				'' === $edit ? '' : Button::link( __( 'Edit post', 'estasoft-news-distribution' ), $edit, array( 'variant' => 'primary' ) )
			);
		}

		$config  = $this->plugin->make( Config::class );
		$checks  = $config->feature_on( 'image' );
		$caption = $config->feature_on( 'caption' );
		$row     = $checks ? $this->latest( $post_id ) : null;
		$main    = '';

		if ( $checks ) {
			$main .= null === $row ? $this->start( $post_id, $photo ) : $this->cards( $post_id, $photo, $row );
		}

		if ( $caption ) {
			$main .= $this->caption( $post_id, $photo );
		}

		return $flash
			. ( $checks ? $this->bar( $post_id, $photo, $row ) : '' )
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-ai-grid' ),
				Html::tag( 'div', array( 'class' => 'estasoft-nd-ai-grid__main' ), $main )
				. Html::tag( 'div', array( 'class' => 'estasoft-nd-ai-grid__side' ), $this->notes( $post_id, $checks, $caption ) )
			);
	}

	/**
	 * The newest request about this article's image, when it is recent.
	 *
	 * @param int $post_id The article.
	 * @return array<string, mixed>|null
	 */
	private function latest( int $post_id ): ?array {
		$row = $this->plugin->make( Log::class )->latest_for( 'image', $post_id );

		if ( null === $row || 'image' !== ( $row['data']['finding'] ?? '' ) || Clock::now() - (int) $row['created'] > 7 * DAY_IN_SECONDS ) {
			return null;
		}

		return $row;
	}

	/**
	 * Whether the article is a draft or scheduled and the owner allowed the assistant to read those.
	 *
	 * @param int $post_id The article.
	 * @return string `public`, `allowed` (not public, and allowed) or `refused` (not public, and not allowed).
	 */
	private function exposure( int $post_id ): string {
		$article = $this->plugin->reader()->find( $post_id );

		if ( null !== $article && $article->is_public() ) {
			return 'public';
		}

		return $this->plugin->make( Config::class )->drafts() ? 'allowed' : 'refused';
	}

	/**
	 * The line under the heading: the file, its size, when it was checked, and a warning for a draft.
	 *
	 * @param int                       $post_id The article.
	 * @param array<string, mixed>      $photo   What is known about the image.
	 * @param array<string, mixed>|null $row     The latest request.
	 * @return string Escaped HTML.
	 */
	private function bar( int $post_id, array $photo, ?array $row ): string {
		$text = Html::tag(
			'strong',
			array(),
			esc_html(
				sprintf(
					/* translators: 1: a file name, 2: width in pixels, 3: height in pixels. */
					__( '%1$s, %2$s × %3$s px.', 'estasoft-news-distribution' ),
					(string) $photo['file'],
					number_format_i18n( (int) $photo['width'] ),
					number_format_i18n( (int) $photo['height'] )
				)
			)
		);

		if ( null !== $row ) {
			$text .= ' ' . esc_html(
				sprintf(
					/* translators: %s: a time such as "14:31". */
					__( 'Checked %s.', 'estasoft-news-distribution' ),
					Times::at( (int) $row['created'] )
				)
			);
		}

		$exposure = $this->exposure( $post_id );

		if ( 'allowed' === $exposure ) {
			$text .= ' ' . esc_html__( 'This article is not published, and “Also drafts and scheduled posts” is on in Settings, AI, so its image could be sent.', 'estasoft-news-distribution' );
		} elseif ( 'refused' === $exposure ) {
			$text .= ' ' . esc_html__( 'This article is not published. Turn on “Also drafts and scheduled posts” in Settings, AI, if its image may be sent.', 'estasoft-news-distribution' );
		}

		return Html::tag( 'div', array( 'class' => 'estasoft-nd-ai-bar' ), Message::bar( 'info', $text ) );
	}

	/**
	 * The card before any request: the picture and the button.
	 *
	 * @param int                  $post_id The article.
	 * @param array<string, mixed> $photo   What is known about the image.
	 * @return string Escaped HTML.
	 */
	private function start( int $post_id, array $photo ): string {
		$button = 'refused' === $this->exposure( $post_id )
			? ''
			: Parts::form(
				$this->url( $post_id ),
				$this->slug(),
				'suggest',
				array(
					'post'                   => $post_id,
					'estasoft_nd_ai_feature' => 'image',
				),
				Button::submit( __( 'Check the image', 'estasoft-news-distribution' ), array( 'variant' => 'primary' ) )
			);

		return Card::note(
			'ai-start',
			__( 'Check the featured image', 'estasoft-news-distribution' ),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-ai-photo' ),
				$this->thumb( $photo )
				. Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ),
					Html::tag( 'p', array(), esc_html__( 'One request sends this image, and nothing else, to your AI provider. It brings back what kind of picture it is, a focal point and alt text. Nothing changes until you choose.', 'estasoft-news-distribution' ) )
					. $button
				)
			)
			. Parts::wait( __( 'Checking the image…', 'estasoft-news-distribution' ), true )
		);
	}

	/**
	 * The three cards of the latest request.
	 *
	 * @param int                  $post_id The article.
	 * @param array<string, mixed> $photo   What is known about the image.
	 * @param array<string, mixed> $row     The latest request.
	 * @return string Escaped HTML.
	 */
	private function cards( int $post_id, array $photo, array $row ): string {
		$data  = (array) $row['data'];
		$parts = isset( $data['parts'] ) && is_array( $data['parts'] ) ? $data['parts'] : array();
		$html  = $this->focal( $post_id, $photo, $row, $parts, $data )
			. $this->kind( $post_id, $photo, $row, $parts, $data )
			. $this->alt( $post_id, $photo, $row, $parts, $data );

		$again = Parts::form(
			$this->url( $post_id ),
			$this->slug(),
			'suggest',
			array(
				'post'                   => $post_id,
				'estasoft_nd_ai_feature' => 'image',
			),
			Button::submit( __( 'Check again', 'estasoft-news-distribution' ), array( 'size' => 'sm' ) )
		);

		return $html . Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-12' ),
			( 'refused' === $this->exposure( $post_id ) ? '' : $again ) . Html::tag(
				'span',
				array( 'class' => 'estasoft-nd-small estasoft-nd-muted' ),
				esc_html(
					Parts::made_by(
						array(
							'provider_name' => Parts::connection_name( $this->plugin, (string) $row['connection'] ),
							'requests'      => 1,
						)
					)
				)
			)
		);
	}

	/**
	 * The picture at a size the page can hold.
	 *
	 * @param array<string, mixed> $photo What is known about the image.
	 * @return string Escaped HTML.
	 */
	private function thumb( array $photo ): string {
		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-crop estasoft-nd-ai-thumb' ),
			Html::void(
				'img',
				array(
					'src'     => (string) $photo['url'],
					'alt'     => '',
					'loading' => 'lazy',
				)
			)
		);
	}

	/**
	 * A one-word state and, when something was applied, the button that puts it back.
	 *
	 * @param string               $state    The part's decision.
	 * @param string               $part     `focal`, `alt` or `caption`.
	 * @param int                  $post_id  The article.
	 * @param array<string, mixed> $photo    What is known about the image.
	 * @param array<string, mixed> $row      The request.
	 * @return string Escaped HTML.
	 */
	private function decided( string $state, string $part, int $post_id, array $photo, array $row ): string {
		$words = array(
			Log::USED      => __( 'Used', 'estasoft-news-distribution' ),
			Log::EDITED    => __( 'Used, after your edit', 'estasoft-news-distribution' ),
			Log::DISMISSED => __( 'Kept as it was', 'estasoft-news-distribution' ),
		);
		$html  = Html::tag( 'span', array( 'class' => 'estasoft-nd-ai-decided' ), Icon::svg( Log::DISMISSED === $state ? 'minus-circle' : 'check-circle' ) . esc_html( $words[ $state ] ?? $state ) );

		if ( Log::DISMISSED !== $state && $this->plugin->make( Image::class )->can_undo( (int) $photo['id'], $part, (int) $row['id'] ) ) {
			$html .= Parts::form(
				$this->url( $post_id ),
				$this->slug(),
				'decide',
				array( 'log' => (int) $row['id'] ),
				Parts::decide( __( 'Undo', 'estasoft-news-distribution' ), 'undo_' . $part )
			);
		}

		return Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-12' ), $html );
	}

	/**
	 * "Focal point": the picture with where the crops are centered today and where the AI suggests.
	 *
	 * @param int                  $post_id The article.
	 * @param array<string, mixed> $photo   What is known about the image.
	 * @param array<string, mixed> $row     The request.
	 * @param array<string, mixed> $parts   Part => decision.
	 * @param array<string, mixed> $data    The request's data.
	 * @return string Escaped HTML.
	 */
	private function focal( int $post_id, array $photo, array $row, array $parts, array $data ): string {
		$point = isset( $data['focal'] ) && is_array( $data['focal'] ) ? $data['focal'] : null;

		if ( null === $point || ! isset( $parts['focal'] ) ) {
			return Card::note( 'ai-focal', __( 'Focal point', 'estasoft-news-distribution' ), Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'No suggestion for this image. You can still set the point yourself under Image presets.', 'estasoft-news-distribution' ) ) );
		}

		$today = (array) $photo['focal'];
		$rings = Html::tag(
			'span',
			array(
				'class' => 'estasoft-nd-ai-ring estasoft-nd-ai-ring--today',
				'style' => sprintf( 'left:%s%%;top:%s%%', round( 100 * (float) ( $today['x'] ?? 0.5 ), 2 ), round( 100 * (float) ( $today['y'] ?? 0.5 ), 2 ) ),
			),
			Html::tag( 'span', array( 'class' => 'estasoft-nd-ai-ring__tag' ), esc_html__( 'Today', 'estasoft-news-distribution' ) )
		) . Html::tag(
			'span',
			array(
				'class' => 'estasoft-nd-ai-ring estasoft-nd-ai-ring--suggested',
				'style' => sprintf( 'left:%s%%;top:%s%%', round( 100 * (float) $point['x'], 2 ), round( 100 * (float) $point['y'], 2 ) ),
			),
			Html::tag( 'span', array( 'class' => 'estasoft-nd-ai-ring__tag' ), esc_html__( 'Suggested', 'estasoft-news-distribution' ) )
		);
		$note  = (string) ( $point['note'] ?? '' );
		$text  = '' === $note
			? __( 'Dashed ring: where your crops are centered today. Solid ring: where the AI suggests.', 'estasoft-news-distribution' )
			: sprintf(
				/* translators: %s: a few words from the AI saying what is at the point, such as "the stage by the water". */
				__( 'Dashed ring: where your crops are centered today. Solid ring: where the AI suggests, on %s.', 'estasoft-news-distribution' ),
				$note
			);
		$state = (string) $parts['focal'];
		$foot  = Log::PENDING === $state
			? Parts::form(
				$this->url( $post_id ),
				$this->slug(),
				'decide',
				array( 'log' => (int) $row['id'] ),
				Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
					Parts::decide( __( 'Use the suggested point', 'estasoft-news-distribution' ), 'use_focal', array( 'variant' => 'primary' ) )
					. Parts::decide( __( 'Keep mine', 'estasoft-news-distribution' ), 'keep_focal' )
				)
			)
			: $this->decided( $state, 'focal', $post_id, $photo, $row );

		return Card::note(
			'ai-focal',
			__( 'Focal point', 'estasoft-news-distribution' ),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-crop estasoft-nd-ai-focal' ),
				Html::void(
					'img',
					array(
						'src'     => (string) $photo['url'],
						'alt'     => '',
						'loading' => 'lazy',
					)
				) . $rings
			)
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( $text ) )
			. $foot
		);
	}

	/**
	 * "Is it a photo?": what kind of picture the AI sees, in words News Distribution wrote.
	 *
	 * @param int                  $post_id The article.
	 * @param array<string, mixed> $photo   What is known about the image.
	 * @param array<string, mixed> $row     The request.
	 * @param array<string, mixed> $parts   Part => decision.
	 * @param array<string, mixed> $data    The request's data.
	 * @return string Escaped HTML.
	 */
	private function kind( int $post_id, array $photo, array $row, array $parts, array $data ): string {
		$kind = isset( $data['photo'] ) && is_array( $data['photo'] ) ? $data['photo'] : null;

		if ( null === $kind || ! isset( $kind['kind'], $kind['confidence'] ) ) {
			return Card::note( 'ai-kind', __( 'Is it a photo?', 'estasoft-news-distribution' ), Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'No suggestion for this image. Look at it yourself.', 'estasoft-news-distribution' ) ) );
		}

		$kind  = array(
			'kind'       => (string) $kind['kind'],
			'confidence' => (int) $kind['confidence'],
		);
		$words = array(
			'photo'      => __( 'a photo', 'estasoft-news-distribution' ),
			'logo'       => __( 'a logo', 'estasoft-news-distribution' ),
			'graphic'    => __( 'a graphic', 'estasoft-news-distribution' ),
			'screenshot' => __( 'a screenshot', 'estasoft-news-distribution' ),
		);
		$sure  = $kind['confidence'] >= 60;
		$good  = $sure && 'photo' === $kind['kind'];
		$head  = $sure
			? sprintf(
				/* translators: 1: what it looks like, such as "a photo"; 2: how sure the AI is, as a percentage. */
				__( 'Looks like %1$s (%2$d%% sure)', 'estasoft-news-distribution' ),
				$words[ $kind['kind'] ] ?? $words['graphic'],
				$kind['confidence']
			)
			: __( 'Not sure what this is', 'estasoft-news-distribution' );
		$state = (string) ( $parts['photo'] ?? Log::DISMISSED );
		$edit  = (string) get_edit_post_link( $post_id, 'raw' );
		$foot  = Log::PENDING === $state
			? Parts::form(
				$this->url( $post_id ),
				$this->slug(),
				'decide',
				array( 'log' => (int) $row['id'] ),
				Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
					Parts::decide( __( 'Keep it', 'estasoft-news-distribution' ), 'keep_photo' )
					. ( '' === $edit ? '' : Html::tag(
						'a',
						array(
							'class' => 'estasoft-nd-btn estasoft-nd-btn--sm',
							'href'  => $edit,
						),
						esc_html__( 'Choose another image', 'estasoft-news-distribution' )
					) )
				)
			)
			: Html::tag( 'span', array( 'class' => 'estasoft-nd-ai-decided' ), Icon::svg( 'check-circle' ) . esc_html__( 'Kept', 'estasoft-news-distribution' ) );

		return Card::note(
			'ai-kind',
			__( 'Is it a photo?', 'estasoft-news-distribution' ),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-ai-photo' ),
				$this->thumb( $photo )
				. Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ),
					Html::tag( 'strong', array(), esc_html( (string) $photo['file'] ) )
					. Html::tag( 'span', array( 'class' => 'estasoft-nd-ai-decided' ), Icon::svg( $good ? 'check-circle' : 'warning' ) . esc_html( $head ) )
					. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( $this->plugin->make( Image::class )->photo_text( $kind ) ) )
					. $foot
				)
			)
		);
	}

	/**
	 * "Alt text": the suggestions, each with the checks it passed.
	 *
	 * @param int                  $post_id The article.
	 * @param array<string, mixed> $photo   What is known about the image.
	 * @param array<string, mixed> $row     The request.
	 * @param array<string, mixed> $parts   Part => decision.
	 * @param array<string, mixed> $data    The request's data.
	 * @return string Escaped HTML.
	 */
	private function alt( int $post_id, array $photo, array $row, array $parts, array $data ): string {
		$current = trim( (string) $photo['alt'] );
		$intro   = '' === $current
			? Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'The featured image of this article has no alt text yet.', 'estasoft-news-distribution' ) )
			: Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Alt text now:', 'estasoft-news-distribution' ) . ' ' . Html::tag( 'span', array( 'dir' => 'auto' ), esc_html( $current ) ) );
		$list    = (array) ( $data['suggestions'] ?? array() );

		if ( ! isset( $parts['alt'] ) || array() === $list ) {
			return Card::note( 'ai-alt', __( 'Alt text', 'estasoft-news-distribution' ), $intro . Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'No suggestion for this image. You can still write the alt text yourself on the photo’s own screen.', 'estasoft-news-distribution' ) ) );
		}

		$state = (string) $parts['alt'];
		$body  = $intro;

		if ( Log::PENDING !== $state ) {
			$chosen = (string) ( $data['chosen']['alt']['text'] ?? '' );
			$body  .= $this->decided( $state, 'alt', $post_id, $photo, $row )
				. ( '' === $chosen ? '' : Html::tag( 'p', array( 'dir' => 'auto' ), esc_html( $chosen ) ) );

			return Card::note( 'ai-alt', __( 'Alt text', 'estasoft-news-distribution' ), $body );
		}

		foreach ( $list as $index => $suggestion ) {
			if ( ! is_array( $suggestion ) ) {
				continue;
			}

			$body .= $this->alt_suggestion( $post_id, $row, (int) $index, $suggestion );
		}

		$body .= Parts::form(
			$this->url( $post_id ),
			$this->slug(),
			'decide',
			array(
				'log'      => (int) $row['id'],
				'decision' => 'dismiss_alt',
			),
			Button::submit( __( 'Dismiss all', 'estasoft-news-distribution' ), array( 'size' => 'sm' ) )
		) . Html::tag( 'p', array( 'class' => 'estasoft-nd-small estasoft-nd-muted' ), esc_html__( 'Check it describes what is really in the picture. The 125-character limit is News Distribution’s own style rule. Each change keeps the previous value, so you can undo it.', 'estasoft-news-distribution' ) );

		return Card::note( 'ai-alt', __( 'Alt text', 'estasoft-news-distribution' ), $body );
	}

	/**
	 * One alt text suggestion.
	 *
	 * @param int                  $post_id    The article.
	 * @param array<string, mixed> $row        The request.
	 * @param int                  $index      Its place in the stored list.
	 * @param array<string, mixed> $suggestion The suggestion.
	 * @return string Escaped HTML.
	 */
	private function alt_suggestion( int $post_id, array $row, int $index, array $suggestion ): string {
		$text  = (string) ( $suggestion['text'] ?? '' );
		$chars = (int) ( $suggestion['chars'] ?? mb_strlen( $text ) );
		$url   = $this->url( $post_id );
		$use   = Parts::form(
			$url,
			$this->slug(),
			'decide',
			array(
				'log'   => (int) $row['id'],
				'index' => $index,
			),
			Parts::decide( __( 'Use as alt text', 'estasoft-news-distribution' ), 'use_alt', array( 'variant' => 0 === $index ? 'primary' : '' ) )
		);
		$edit  = Html::tag(
			'details',
			array( 'class' => 'estasoft-nd-ai-edit' ),
			Html::tag( 'summary', array( 'class' => 'estasoft-nd-btn estasoft-nd-btn--sm' ), esc_html__( 'Edit first', 'estasoft-news-distribution' ) )
			. Parts::form(
				$url,
				$this->slug(),
				'decide',
				array(
					'log'      => (int) $row['id'],
					'decision' => 'edit_alt',
				),
				Html::tag(
					'label',
					array(
						'class' => 'screen-reader-text',
						'for'   => 'estasoft-nd-ai-alt-' . $index,
					),
					esc_html__( 'Your wording', 'estasoft-news-distribution' )
				)
				. Html::void(
					'input',
					array(
						'type'      => 'text',
						'id'        => 'estasoft-nd-ai-alt-' . $index,
						'name'      => 'text',
						'value'     => $text,
						'class'     => 'estasoft-nd-field estasoft-nd-w-480',
						'maxlength' => '200',
						'dir'       => 'auto',
					)
				)
				. Button::submit( __( 'Use edited text', 'estasoft-news-distribution' ), array( 'size' => 'sm' ) ),
				'estasoft-nd-ai-editform'
			)
		);

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-suggest' ),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
				Parts::badge()
				. Html::tag( 'strong', array( 'dir' => 'auto' ), esc_html( $text ) )
				. Html::tag(
					'span',
					array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
					esc_html(
						sprintf(
							/* translators: %d: a number of characters. */
							_n( '%d character', '%d characters', $chars, 'estasoft-news-distribution' ),
							$chars
						)
					)
				)
			)
			. Parts::ticks( (array) ( $suggestion['checks'] ?? array() ) )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ), $use . $edit )
		);
	}

	/**
	 * The newest request for a caption of this article, when it is recent.
	 *
	 * @param int $post_id The article.
	 * @return array<string, mixed>|null
	 */
	private function latest_caption( int $post_id ): ?array {
		$row = $this->plugin->make( Log::class )->latest_for( 'caption', $post_id );

		if ( null === $row || 'caption' !== ( $row['data']['finding'] ?? '' ) || Clock::now() - (int) $row['created'] > 7 * DAY_IN_SECONDS ) {
			return null;
		}

		return $row;
	}

	/**
	 * "Caption": what the photo has now, and up to three captions to choose from.
	 *
	 * @param int                  $post_id The article.
	 * @param array<string, mixed> $photo   What is known about the image.
	 * @return string Escaped HTML.
	 */
	private function caption( int $post_id, array $photo ): string {
		$current = trim( (string) get_post_field( 'post_excerpt', (int) $photo['id'] ) );
		$credit  = $this->plugin->make( ImageCredits::class )->credit_of( (int) $photo['id'] );
		$row     = $this->latest_caption( $post_id );
		$data    = null === $row ? array() : (array) $row['data'];
		$parts   = isset( $data['parts'] ) && is_array( $data['parts'] ) ? $data['parts'] : array();
		$state   = (string) ( $parts['caption'] ?? '' );
		$refused = 'refused' === $this->exposure( $post_id );

		$now = '' === $current
			? Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'This photo has no caption yet.', 'estasoft-news-distribution' ) )
			: Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Caption now:', 'estasoft-news-distribution' ) . ' ' . Html::tag( 'span', array( 'dir' => 'auto' ), esc_html( $current ) ) );

		if ( '' !== $credit ) {
			$now .= Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-small estasoft-nd-muted' ),
				esc_html(
					sprintf(
						/* translators: %s: the photo's credit, such as "Photo: A. Reporter". */
						__( 'Credit: %s. It is printed on its own and is never changed here.', 'estasoft-news-distribution' ),
						$credit
					)
				)
			);
		}

		$body = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-ai-photo' ),
			$this->thumb( $photo ) . Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $now )
		);

		if ( Log::PENDING === $state && array() !== (array) ( $data['suggestions'] ?? array() ) ) {
			foreach ( (array) $data['suggestions'] as $index => $suggestion ) {
				if ( is_array( $suggestion ) ) {
					$body .= $this->caption_suggestion( $post_id, (array) $row, (int) $index, $suggestion, '' !== $current );
				}
			}

			$body .= Parts::form(
				$this->url( $post_id ),
				$this->slug(),
				'decide',
				array(
					'log'      => (int) $row['id'],
					'decision' => 'dismiss_caption',
				),
				Button::submit( __( 'Dismiss all', 'estasoft-news-distribution' ), array( 'size' => 'sm' ) )
			);

			$dropped = (int) ( $data['caption']['dropped'] ?? 0 );

			if ( $dropped > 0 ) {
				$body .= Html::tag(
					'p',
					array( 'class' => 'estasoft-nd-small estasoft-nd-muted' ),
					esc_html(
						sprintf(
							/* translators: %d: a number of suggestions. */
							_n( '%d more suggestion was not shown because it broke a rule.', '%d more suggestions were not shown because they broke a rule.', $dropped, 'estasoft-news-distribution' ),
							$dropped
						)
					)
				);
			}

			$body .= Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-small estasoft-nd-muted' ),
				esc_html(
					Parts::made_by(
						array(
							'provider_name' => Parts::connection_name( $this->plugin, (string) $row['connection'] ),
							'requests'      => 1,
						)
					)
				) . ' ' . esc_html__( 'The AI did not see the photo, so check each caption against it before you use it. A caption the photo has now is replaced only if you tick the box.', 'estasoft-news-distribution' )
			);

			return Card::note( 'ai-caption', __( 'Caption', 'estasoft-news-distribution' ), $body );
		}

		if ( null !== $row && in_array( $state, array( Log::USED, Log::EDITED, Log::DISMISSED ), true ) ) {
			$body .= $this->decided( $state, 'caption', $post_id, $photo, $row );
			$wrote = (string) ( $data['chosen']['caption']['text'] ?? '' );

			if ( '' !== $wrote && Log::DISMISSED !== $state ) {
				$body .= Html::tag( 'p', array( 'dir' => 'auto' ), esc_html( $wrote ) );
			}
		} elseif ( null !== $row && Log::NONE === $row['decision'] ) {
			$body .= Message::bar( 'warning', Html::tag( 'strong', array(), esc_html__( 'No caption followed the rules, so none is shown.', 'estasoft-news-distribution' ) ) . ' ' . esc_html__( 'Nothing was changed. Write one yourself, or ask again.', 'estasoft-news-distribution' ) );
		}

		if ( $refused ) {
			$body .= Html::tag( 'p', array( 'class' => 'estasoft-nd-small estasoft-nd-muted' ), esc_html__( 'This article is not published. Turn on “Also drafts and scheduled posts” in Settings, AI, if its headline and opening may be sent.', 'estasoft-news-distribution' ) );
		} else {
			$body .= Parts::form(
				$this->url( $post_id ),
				$this->slug(),
				'suggest',
				array(
					'post'                   => $post_id,
					'estasoft_nd_ai_feature' => 'image',
					'kind'                   => 'caption',
				),
				Button::submit( null === $row ? __( 'Suggest a caption', 'estasoft-news-distribution' ) : __( 'Suggest another caption', 'estasoft-news-distribution' ), array( 'size' => 'sm' ) )
			) . Parts::wait( __( 'Writing captions…', 'estasoft-news-distribution' ), true );
		}

		return Card::note( 'ai-caption', __( 'Caption', 'estasoft-news-distribution' ), $body );
	}

	/**
	 * One caption suggestion.
	 *
	 * @param int                  $post_id    The article.
	 * @param array<string, mixed> $row        The request.
	 * @param int                  $index      Its place in the stored list.
	 * @param array<string, mixed> $suggestion The suggestion.
	 * @param bool                 $has        Whether the photo has a caption now.
	 * @return string Escaped HTML.
	 */
	private function caption_suggestion( int $post_id, array $row, int $index, array $suggestion, bool $has ): string {
		$text  = (string) ( $suggestion['text'] ?? '' );
		$chars = (int) ( $suggestion['chars'] ?? mb_strlen( $text ) );
		$url   = $this->url( $post_id );
		$use   = Parts::form(
			$url,
			$this->slug(),
			'decide',
			array(
				'log'   => (int) $row['id'],
				'index' => $index,
			),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
				Parts::decide( __( 'Use as caption', 'estasoft-news-distribution' ), 'use_caption', array( 'variant' => 0 === $index ? 'primary' : '' ) )
				. ( $has ? $this->replace_box( 'estasoft-nd-ai-replace-' . $index ) : '' )
			)
		);
		$edit  = Html::tag(
			'details',
			array( 'class' => 'estasoft-nd-ai-edit' ),
			Html::tag( 'summary', array( 'class' => 'estasoft-nd-btn estasoft-nd-btn--sm' ), esc_html__( 'Edit first', 'estasoft-news-distribution' ) )
			. Parts::form(
				$url,
				$this->slug(),
				'decide',
				array(
					'log'      => (int) $row['id'],
					'decision' => 'edit_caption',
				),
				Html::tag(
					'label',
					array(
						'class' => 'screen-reader-text',
						'for'   => 'estasoft-nd-ai-caption-' . $index,
					),
					esc_html__( 'Your wording', 'estasoft-news-distribution' )
				)
				. Html::void(
					'input',
					array(
						'type'      => 'text',
						'id'        => 'estasoft-nd-ai-caption-' . $index,
						'name'      => 'text',
						'value'     => $text,
						'class'     => 'estasoft-nd-field estasoft-nd-w-480',
						'maxlength' => '200',
						'dir'       => 'auto',
					)
				)
				. ( $has ? $this->replace_box( 'estasoft-nd-ai-replace-edit-' . $index ) : '' )
				. Button::submit( __( 'Use edited text', 'estasoft-news-distribution' ), array( 'size' => 'sm' ) ),
				'estasoft-nd-ai-editform'
			)
		);

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-suggest' ),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
				Parts::badge()
				. Html::tag( 'strong', array( 'dir' => 'auto' ), esc_html( $text ) )
				. Html::tag(
					'span',
					array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
					esc_html(
						sprintf(
							/* translators: %d: a number of characters. */
							_n( '%d character', '%d characters', $chars, 'estasoft-news-distribution' ),
							$chars
						)
					)
				)
			)
			. Parts::ticks( (array) ( $suggestion['checks'] ?? array() ) )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ), $use . $edit )
		);
	}

	/**
	 * The box that says a caption the photo has now may be replaced.
	 *
	 * @param string $id The field's id.
	 * @return string Escaped HTML.
	 */
	private function replace_box( string $id ): string {
		return Html::tag(
			'label',
			array(
				'class' => 'estasoft-nd-check',
				'for'   => $id,
			),
			Html::void(
				'input',
				array(
					'type'  => 'checkbox',
					'id'    => $id,
					'name'  => 'confirm',
					'value' => '1',
				)
			) . Html::tag( 'span', array(), esc_html__( 'Replace the caption this photo has now', 'estasoft-news-distribution' ) )
		);
	}

	/**
	 * The notes beside the cards.
	 *
	 * @param int  $post_id The article.
	 * @param bool $checks  Whether the image checks are on.
	 * @param bool $caption Whether captions are on.
	 * @return string Escaped HTML.
	 */
	private function notes( int $post_id, bool $checks, bool $caption ): string {
		$sent = '';

		if ( $checks ) {
			$sent .= Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted' ),
				esc_html__( 'For the image checks: the image, once, to your AI provider, so the provider must accept images. The title at the top of this page comes from your site and is not sent.', 'estasoft-news-distribution' )
			) . Parts::ticks(
				array(
					array(
						'ok'   => true,
						'text' => __( 'Only an image already in your media library', 'estasoft-news-distribution' ),
					),
					array(
						'ok'   => true,
						'text' => __( 'A draft’s image only because “Also drafts and scheduled posts” is on in Settings, AI', 'estasoft-news-distribution' ),
					),
					array(
						'level' => 'none',
						'text'  => __( 'Not the headline, the text or the author', 'estasoft-news-distribution' ),
					),
				)
			);
		}

		if ( $caption ) {
			$sent .= Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted' ),
				esc_html__( 'For the caption: words only. The language, the headline, the first 600 characters of the article and the photo’s alt text. The AI does not see the photo.', 'estasoft-news-distribution' )
			) . Parts::ticks(
				array(
					array(
						'level' => 'none',
						'text'  => __( 'Not the picture, the author or the rest of the text', 'estasoft-news-distribution' ),
					),
					array(
						'level' => 'none',
						'text'  => __( 'Not the photo’s credit, unless “Author names” is on in Settings, AI', 'estasoft-news-distribution' ),
					),
				)
			);
		}

		if ( $checks ) {
			$finish = Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ),
				Html::tag( 'p', array(), Html::tag( 'strong', array(), esc_html__( 'While it runs:', 'estasoft-news-distribution' ) ) . ' ' . esc_html__( 'each card shows gray placeholders and “Checking the image…”.', 'estasoft-news-distribution' ) )
				. Html::tag( 'p', array(), Html::tag( 'strong', array(), esc_html__( 'No suggestion:', 'estasoft-news-distribution' ) ) . ' ' . esc_html__( 'the card says “No suggestion for this image” and keeps your controls.', 'estasoft-news-distribution' ) )
				. Html::tag( 'p', array(), Html::tag( 'strong', array(), esc_html__( 'Provider did not answer:', 'estasoft-news-distribution' ) ) . ' ' . esc_html__( '“The AI provider did not answer. Nothing was changed. Try again.” You can still set the point and the alt text by hand.', 'estasoft-news-distribution' ) )
			);
		} else {
			$finish = Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ),
				Html::tag( 'p', array(), Html::tag( 'strong', array(), esc_html__( 'While it runs:', 'estasoft-news-distribution' ) ) . ' ' . esc_html__( 'the card shows gray placeholders and “Writing captions…”.', 'estasoft-news-distribution' ) )
				. Html::tag( 'p', array(), Html::tag( 'strong', array(), esc_html__( 'No suggestion:', 'estasoft-news-distribution' ) ) . ' ' . esc_html__( 'the card says no caption followed the rules and keeps your controls.', 'estasoft-news-distribution' ) )
				. Html::tag( 'p', array(), Html::tag( 'strong', array(), esc_html__( 'Provider did not answer:', 'estasoft-news-distribution' ) ) . ' ' . esc_html__( '“The AI provider did not answer. Nothing was changed. Try again.” You can still write the caption on the photo’s own screen.', 'estasoft-news-distribution' ) )
			);
		}

		$settings = Links::settings( 'ai' );

		$cannot = array(
			__( 'Be sure what is in the picture. The AI can be wrong, so you read every suggestion.', 'estasoft-news-distribution' ),
			__( 'Change the image file. A focal point only changes how it is cropped.', 'estasoft-news-distribution' ),
		);

		if ( $caption ) {
			$cannot[] = __( 'Know what the photo shows when it writes a caption. It reads words only, so check the caption against the picture.', 'estasoft-news-distribution' );
			$cannot[] = __( 'Change the photo’s credit. The credit is printed on its own.', 'estasoft-news-distribution' );
		}

		return Card::note( 'ai-sent', __( 'What is sent', 'estasoft-news-distribution' ), $sent )
			. Card::cannot( $cannot )
			. Card::note( 'ai-finish', __( 'If a check cannot finish', 'estasoft-news-distribution' ), $finish . ( '' === $settings || $post_id < 1 ? '' : Html::tag( 'p', array( 'class' => 'estasoft-nd-small' ), Html::tag( 'a', array( 'href' => $settings ), esc_html__( 'Settings, AI assistant', 'estasoft-news-distribution' ) ) ) ) );
	}
}
