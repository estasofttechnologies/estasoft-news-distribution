<?php
/**
 * Image helper: is the featured image a photo, where is its focal point, and what is its alt text.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Ai\Features;

use Estasoft\NewsDistribution\Ai\Client;
use Estasoft\NewsDistribution\Ai\Config;
use Estasoft\NewsDistribution\Ai\Log;
use Estasoft\NewsDistribution\Ai\Marker;
use Estasoft\NewsDistribution\Content\ImageCredits;
use Estasoft\NewsDistribution\Content\ImagePresets;
use Estasoft\NewsDistribution\Core\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * One request, once, for the featured image of an article: the image goes to the provider with a question and
 * nothing else. The title, the text and the author are not sent. The answer is read as three separate parts, and each
 * part is its own decision:
 *
 * - `photo`: whether it looks like a photo or like a logo, a graphic or a screenshot, with how sure the AI is.
 *   Nothing is applied. A person can keep the image or choose another.
 * - `focal`: a point to keep in view when platforms crop. `use_focal` stores it on the photo (`ImagePresets`).
 * - `alt`: up to three alt texts, each checked with `RuleCheck::alt()`. `use_alt` or `edit_alt` writes the photo's alt text.
 *
 * Every change keeps the value it replaced on the photo (`_estasoft_nd_ai_undo`), so `undo_alt`, `undo_focal` and
 * `undo_caption` can put it back. Only an image that is already in the media library is sent, and a photo someone else
 * uploaded is changed only when the person may edit it.
 *
 * A caption is a second request, `POST /ai/suggest/image` with `kind` set to `caption`, and it has its own switch
 * (`caption`) and its own name in the AI log (`caption`). It sends text only and never the picture: the headline, about
 * 600 characters from the start of the article, the language, the photo's alt text and the rules. The photo's credit is
 * sent only when "Author names" is on, because a credit is usually a person's name; otherwise the AI is told not to
 * write one, and the rule check refuses a caption that has the credit in it. The decision `use_caption` (or
 * `edit_caption`) writes the attachment's caption (its `post_excerpt`). A caption that is already there is replaced
 * only when the person confirms (`confirm`), and the credit is never touched.
 */
final class Image extends AbstractFeature {

	/**
	 * Post meta on the photo: what an applied change replaced, by part.
	 */
	public const UNDO = '_estasoft_nd_ai_undo';

	/**
	 * Alt text options kept.
	 */
	public const ALT_SHOWN = 3;

	/**
	 * Largest file sent, in bytes.
	 */
	public const MAX_BYTES = 4000000;

	/**
	 * Captions asked for.
	 */
	public const CAPTION_ASKED = 4;

	/**
	 * Captions kept.
	 */
	public const CAPTION_SHOWN = 3;

	/**
	 * Kinds of picture the AI may answer with.
	 */
	private const KINDS = array( 'photo', 'logo', 'graphic', 'screenshot' );

	public function name(): string {
		return 'image';
	}

	public function label(): string {
		return __( 'Image helper', 'estasoft-news-distribution' );
	}

	/**
	 * What is known about the featured image without asking anyone.
	 *
	 * @param int $post_id The article.
	 * @return array{id: int, file: string, width: int, height: int, url: string, alt: string, focal: array{x: float, y: float, set: bool}}|null Null when the article has no featured image in the media library.
	 */
	public function attachment( int $post_id ): ?array {
		$id = (int) get_post_thumbnail_id( $post_id );

		if ( $id < 1 || ! wp_attachment_is_image( $id ) ) {
			return null;
		}

		$size = $this->plugin->make( ImagePresets::class )->original( $id );
		$url  = wp_get_attachment_image_url( $id, 'medium_large' );
		$file = get_attached_file( $id );

		return array(
			'id'     => $id,
			'file'   => is_string( $file ) ? wp_basename( $file ) : '',
			'width'  => null === $size ? 0 : $size['width'],
			'height' => null === $size ? 0 : $size['height'],
			'url'    => is_string( $url ) ? $url : '',
			'alt'    => trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ),
			'focal'  => $this->plugin->make( ImagePresets::class )->focal( $id ),
		);
	}

	/**
	 * The newest suggestions for an article that still wait for a decision. Captions are kept in the log under their own
	 * name, so `caption` looks there, and anything else looks at the image helper's rows.
	 *
	 * @param int    $post_id The article.
	 * @param string $finding `caption`, or empty for the image helper.
	 * @return array<string, mixed>|null
	 */
	public function pending_row( int $post_id, string $finding = '' ): ?array {
		if ( 'caption' !== $finding ) {
			return parent::pending_row( $post_id, 'image' );
		}

		$found = $this->plugin->make( Log::class )->query(
			array(
				'feature' => 'caption',
				'post_id' => $post_id,
				'limit'   => 5,
			)
		);

		foreach ( $found['items'] as $row ) {
			if ( Log::PENDING === $row['decision'] && Clock::now() - (int) $row['created'] <= DAY_IN_SECONDS ) {
				return $row;
			}
		}

		return null;
	}

	public function suggest( int $post_id, array $args ) {
		if ( 'caption' === sanitize_key( (string) ( $args['kind'] ?? '' ) ) ) {
			return $this->suggest_caption( $post_id );
		}

		$article = $this->sendable( $post_id );

		if ( is_wp_error( $article ) ) {
			return $article;
		}

		$photo = $this->attachment( $post_id );

		if ( null === $photo ) {
			return new \WP_Error( 'estasoft_nd_ai_no_image', __( 'This article has no featured image in your media library, so there is nothing to check.', 'estasoft-news-distribution' ), array( 'status' => 409 ) );
		}

		$file = $this->file_for( $photo['id'] );

		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$answer = $this->ask(
			array(
				'system'     => 'You look at one picture that belongs to a news article. Describe only what you can see. Never name a person unless text in the picture names them. Reply with a JSON object and nothing else.',
				'prompt'     => $this->prompt( $article->language() ),
				'max_tokens' => 500,
				'image'      => $file,
			)
		);

		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		$read    = $this->json( $answer['text'] );
		$read    = is_array( $read ) ? $read : array();
		$check   = $this->plugin->make( RuleCheck::class );
		$kind    = $this->kind( $read );
		$focal   = $this->focal( $read, $photo['focal'] );
		$alts    = array();
		$dropped = 0;

		$offered = $read['alt'] ?? array();

		foreach ( $this->clean_texts( is_array( $offered ) ? $offered : array( $offered ) ) as $text ) {
			$result = $check->alt( $text );

			if ( ! $result['pass'] ) {
				++$dropped;
				continue;
			}

			if ( count( $alts ) < self::ALT_SHOWN ) {
				$alts[] = array(
					'text'   => $text,
					'chars'  => mb_strlen( $text ),
					'checks' => $result['lines'],
					'pass'   => true,
				);
			}
		}

		$parts = array();

		foreach ( array(
			'photo' => null !== $kind,
			'focal' => null !== $focal,
			'alt'   => array() !== $alts,
		) as $part => $has ) {
			if ( $has ) {
				$parts[ $part ] = Log::PENDING;
			}
		}

		$data = array(
			'finding'     => 'image',
			'attachment'  => $photo['id'],
			'file'        => $photo['file'],
			'photo'       => $kind,
			'focal'       => $focal,
			'alt'         => array(
				'current' => $photo['alt'],
				'dropped' => $dropped,
			),
			'suggestions' => $alts,
			'parts'       => $parts,
		);

		$log = $this->record( $post_id, $answer, $data, array() === $parts ? Log::NONE : Log::PENDING );

		$data['photo']              = null === $kind ? null : array_merge( $kind, array( 'text' => $this->photo_text( $kind ) ) );
		$data['alt']['suggestions'] = $alts;

		return array_merge(
			array(
				'log'        => $log,
				'feature'    => $this->name(),
				'attachment' => $photo,
			),
			$data,
			$this->meta( $answer ),
			array( 'message' => array() === $parts ? __( 'No suggestion for this image.', 'estasoft-news-distribution' ) : '' )
		);
	}

	/**
	 * Suggests captions for the featured image. The picture is not sent: the AI gets words only.
	 *
	 * @param int $post_id The article.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function suggest_caption( int $post_id ) {
		$article = $this->sendable( $post_id );

		if ( is_wp_error( $article ) ) {
			return $article;
		}

		$photo = $this->attachment( $post_id );

		if ( null === $photo ) {
			return new \WP_Error( 'estasoft_nd_ai_no_image', __( 'This article has no featured image in your media library, so there is nothing to caption.', 'estasoft-news-distribution' ), array( 'status' => 409 ) );
		}

		$credits = $this->plugin->make( ImageCredits::class );
		$credit  = $credits->credit_of( $photo['id'] );
		$current = trim( (string) get_post_field( 'post_excerpt', $photo['id'] ) );
		$post    = get_post( $post_id );
		$title   = $post instanceof \WP_Post ? trim( wp_strip_all_tags( (string) $post->post_title ) ) : $article->title();

		$text  = "Language of the article: {$article->language()}\n";
		$text .= "Headline: {$title}\n";
		$text .= 'Start of the article: ' . $this->lead( $article, 600 ) . "\n";

		if ( '' !== $photo['alt'] ) {
			$text .= "Alt text of the photo: {$photo['alt']}\n";
		}

		// A credit is usually a person's name, so it goes to the AI only when the owner allows author names.
		if ( '' !== $credit && $this->plugin->make( Config::class )->authors() ) {
			$text .= "Credit of the photo (it is printed on its own, so do not repeat it): {$credit}\n";
		}

		$text .= "Rules for the caption:\n- One sentence of plain text, no markup, no web addresses.\n";
		$text .= sprintf( "- At most %d characters.\n", RuleCheck::CAPTION_MAX );
		$text .= "- Say what the photo shows, using only what the headline, the text and the alt text state. You cannot see the photo.\n";
		$text .= "- Do not name a person unless the text names them. Do not write a credit, a photographer or the word \"Photo:\".\n";
		$text .= sprintf( 'Write %1$d different captions. Reply with a JSON list of %1$d strings.', self::CAPTION_ASKED );

		$answer = $this->plugin->make( Client::class )->ask(
			'caption',
			array(
				'system'     => 'You write the caption that goes under one photo in a news article. You cannot see the photo. Follow the rules you are given exactly. Use only facts that the text you are given states, and never invent names, numbers, places or quotes. Write in the language of the article. Reply with a JSON list of strings and nothing else.',
				'prompt'     => $text,
				'max_tokens' => 400,
			)
		);

		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		$check      = $this->plugin->make( RuleCheck::class );
		$candidates = array();
		$seen       = array( mb_strtolower( $current ) => true );
		$dropped    = 0;
		$returned   = 0;

		foreach ( $this->texts( $answer['text'] ) as $line ) {
			$key = mb_strtolower( $line );

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			++$returned;

			if ( $returned > self::CAPTION_ASKED ) {
				break;
			}

			$result = $check->caption( $article, $line, $credit, $photo['alt'] );

			if ( ! $result['pass'] ) {
				++$dropped;
				continue;
			}

			if ( count( $candidates ) < self::CAPTION_SHOWN ) {
				$candidates[] = array(
					'text'   => $line,
					'chars'  => mb_strlen( $line ),
					'checks' => $result['lines'],
					'pass'   => true,
				);
			}
		}

		$parts = array() === $candidates ? array() : array( 'caption' => Log::PENDING );
		$data  = array(
			'finding'     => 'caption',
			'attachment'  => $photo['id'],
			'file'        => $photo['file'],
			'caption'     => array(
				'current' => $current,
				'dropped' => $dropped,
			),
			'suggestions' => $candidates,
			'parts'       => $parts,
		);

		$log = $this->plugin->make( Log::class )->add(
			array(
				'post_id'    => $post_id,
				'feature'    => 'caption',
				'connection' => $answer['provider'] . ':' . $answer['model'],
				'decision'   => array() === $parts ? Log::NONE : Log::PENDING,
				'data'       => array_merge(
					$data,
					array(
						'tokens'       => $answer['tokens'],
						'ms'           => $answer['ms'],
						'requested_by' => get_current_user_id(),
					)
				),
			)
		);

		return array_merge(
			array(
				'log'        => $log,
				'feature'    => 'caption',
				'attachment' => $photo,
			),
			$data,
			$this->meta( $answer ),
			array( 'message' => array() === $parts ? __( 'No caption followed the rules, so none is shown. Nothing was changed. Write one yourself, or ask again.', 'estasoft-news-distribution' ) : '' )
		);
	}

	public function decide( array $row, array $args, int $user_id ) {
		$decision = sanitize_key( (string) ( $args['decision'] ?? '' ) );
		$log      = $this->plugin->make( Log::class );
		$data     = (array) $row['data'];
		$parts    = isset( $data['parts'] ) && is_array( $data['parts'] ) ? $data['parts'] : array();
		$photo    = (int) ( $data['attachment'] ?? 0 );
		$map      = array(
			'use_focal'       => 'focal',
			'keep_focal'      => 'focal',
			'undo_focal'      => 'focal',
			'use_alt'         => 'alt',
			'edit_alt'        => 'alt',
			'dismiss_alt'     => 'alt',
			'undo_alt'        => 'alt',
			'keep_photo'      => 'photo',
			'use_caption'     => 'caption',
			'edit_caption'    => 'caption',
			'dismiss_caption' => 'caption',
			'undo_caption'    => 'caption',
		);

		if ( ! isset( $map[ $decision ] ) || $photo < 1 ) {
			return new \WP_Error( 'estasoft_nd_ai_bad_decision', __( 'Choose Use, Edit first, Keep or Dismiss.', 'estasoft-news-distribution' ), array( 'status' => 400 ) );
		}

		$part = $map[ $decision ];

		if ( 0 === strpos( $decision, 'undo_' ) ) {
			return $this->undo( $row, $part, $user_id );
		}

		if ( ! isset( $parts[ $part ] ) || Log::PENDING !== $parts[ $part ] ) {
			return new \WP_Error( 'estasoft_nd_ai_decided', __( 'This suggestion was decided already.', 'estasoft-news-distribution' ), array( 'status' => 409 ) );
		}

		$outcome = Log::DISMISSED;
		$applied = '';
		$text    = '';
		$message = __( 'Kept as it was. Nothing was changed.', 'estasoft-news-distribution' );

		if ( 'use_focal' === $decision ) {
			if ( ! user_can( $user_id, 'edit_post', $photo ) ) {
				return $this->cannot_edit();
			}

			$focal = $data['focal'] ?? null;

			if ( ! is_array( $focal ) || ! isset( $focal['x'], $focal['y'] ) ) {
				return new \WP_Error( 'estasoft_nd_ai_no_suggestion', __( 'That suggestion is not in the record. Ask again.', 'estasoft-news-distribution' ), array( 'status' => 404 ) );
			}

			$presets = $this->plugin->make( ImagePresets::class );
			$before  = $presets->focal( $photo );

			$this->remember( $photo, 'focal', $before, (int) $row['id'] );
			$presets->set_focal( $photo, (float) $focal['x'], (float) $focal['y'] );

			$outcome = Log::USED;
			$applied = 'focal';
			$message = __( 'The focal point is saved. Platform copies of the photo are made again with it.', 'estasoft-news-distribution' );
		} elseif ( 'use_alt' === $decision || 'edit_alt' === $decision ) {
			if ( ! user_can( $user_id, 'edit_post', $photo ) ) {
				return $this->cannot_edit();
			}

			if ( 'edit_alt' === $decision ) {
				$text   = sanitize_text_field( (string) ( $args['text'] ?? '' ) );
				$edited = true;
			} else {
				$stored = $this->stored_suggestion( $row, (int) ( $args['index'] ?? -1 ) );

				if ( null === $stored ) {
					return new \WP_Error( 'estasoft_nd_ai_no_suggestion', __( 'That suggestion is not in the record. Ask again.', 'estasoft-news-distribution' ), array( 'status' => 404 ) );
				}

				$text   = (string) $stored['text'];
				$edited = false;
			}

			// Text a person typed is held to the same rules as a suggestion.
			$result = $this->plugin->make( RuleCheck::class )->alt( $text );

			if ( ! $result['pass'] ) {
				return new \WP_Error(
					'estasoft_nd_ai_rule_check',
					__( 'That alt text breaks a rule, so it was not used. Change the wording and try again.', 'estasoft-news-distribution' ),
					array(
						'status' => 422,
						'checks' => $result['lines'],
					)
				);
			}

			$this->remember( $photo, 'alt', trim( (string) get_post_meta( $photo, '_wp_attachment_image_alt', true ) ), (int) $row['id'] );
			update_post_meta( $photo, '_wp_attachment_image_alt', wp_slash( $text ) );
			$this->plugin->make( Marker::class )->mark( (int) $row['post_id'], (int) $row['id'], $this->name(), $user_id );

			$outcome = $edited ? Log::EDITED : Log::USED;
			$applied = 'alt';
			$message = __( 'The alt text is saved on the photo. The article is marked AI-assisted.', 'estasoft-news-distribution' );
		} elseif ( 'use_caption' === $decision || 'edit_caption' === $decision ) {
			if ( ! user_can( $user_id, 'edit_post', $photo ) ) {
				return $this->cannot_edit();
			}

			if ( 'edit_caption' === $decision ) {
				$text   = sanitize_text_field( (string) ( $args['text'] ?? '' ) );
				$edited = true;
			} else {
				$stored = $this->stored_suggestion( $row, (int) ( $args['index'] ?? -1 ) );

				if ( null === $stored ) {
					return new \WP_Error( 'estasoft_nd_ai_no_suggestion', __( 'That suggestion is not in the record. Ask again.', 'estasoft-news-distribution' ), array( 'status' => 404 ) );
				}

				$text   = (string) $stored['text'];
				$edited = false;
			}

			$article = $this->plugin->reader()->find( (int) $row['post_id'] );

			if ( null === $article ) {
				return new \WP_Error( 'estasoft_nd_ai_no_article', __( 'That is not an article News Distribution sends.', 'estasoft-news-distribution' ), array( 'status' => 404 ) );
			}

			// Text a person typed is held to the same rules as a suggestion.
			$credits = $this->plugin->make( ImageCredits::class );
			$result  = $this->plugin->make( RuleCheck::class )->caption( $article, $text, $credits->credit_of( $photo ), trim( (string) get_post_meta( $photo, '_wp_attachment_image_alt', true ) ) );

			if ( ! $result['pass'] ) {
				return new \WP_Error(
					'estasoft_nd_ai_rule_check',
					__( 'That caption breaks a rule, so it was not used. Change the wording and try again.', 'estasoft-news-distribution' ),
					array(
						'status' => 422,
						'checks' => $result['lines'],
					)
				);
			}

			$replaced = trim( (string) get_post_field( 'post_excerpt', $photo ) );

			// A caption someone typed is replaced only when the person says so.
			if ( '' !== $replaced && ! rest_sanitize_boolean( $args['confirm'] ?? false ) ) {
				return new \WP_Error(
					'estasoft_nd_ai_caption_exists',
					__( 'This photo already has a caption. Tick “Replace the caption this photo has now” to use the new one. Nothing was changed.', 'estasoft-news-distribution' ),
					array(
						'status'   => 409,
						'existing' => $replaced,
					)
				);
			}

			$done = wp_update_post(
				array(
					'ID'           => $photo,
					'post_excerpt' => wp_slash( $text ),
				),
				true
			);

			if ( is_wp_error( $done ) ) {
				return new \WP_Error( 'estasoft_nd_ai_not_saved', __( 'WordPress could not save the caption. Nothing was changed.', 'estasoft-news-distribution' ), array( 'status' => 500 ) );
			}

			$this->remember( $photo, 'caption', $replaced, (int) $row['id'] );
			$this->plugin->make( Marker::class )->mark( (int) $row['post_id'], (int) $row['id'], (string) $row['feature'], $user_id );

			$outcome = $edited ? Log::EDITED : Log::USED;
			$applied = 'caption';
			$message = __( 'The caption is saved on the photo. The credit was not changed. The article is marked AI-assisted.', 'estasoft-news-distribution' );
		}

		$parts[ $part ] = $outcome;
		$remaining      = array_keys( array_filter( $parts, static fn( $state ) => Log::PENDING === $state ) );
		$merge          = array(
			'parts'  => $parts,
			'chosen' => array_merge(
				(array) ( $data['chosen'] ?? array() ),
				array(
					$part => array(
						'decision' => $outcome,
						'text'     => $text,
						'by'       => $user_id,
					),
				)
			),
		);

		if ( isset( $replaced ) ) {
			$merge['chosen']['caption']['previous'] = $replaced;
		}

		if ( array() === $remaining ) {
			$log->decide( (int) $row['id'], $this->summary( $parts ), $user_id, $merge );
		} else {
			$log->annotate( (int) $row['id'], $merge );
		}

		return array(
			'log'      => (int) $row['id'],
			'part'     => $part,
			'decision' => $outcome,
			'applied'  => $applied,
			'text'     => $text,
			'message'  => $message,
		);
	}

	/**
	 * Puts back what an applied change replaced.
	 *
	 * @param array<string, mixed> $row     The AI log row.
	 * @param string               $part    `alt`, `focal` or `caption`.
	 * @param int                  $user_id The person.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function undo( array $row, string $part, int $user_id ) {
		$photo = (int) ( $row['data']['attachment'] ?? 0 );
		$saved = get_post_meta( $photo, self::UNDO, true );
		$saved = is_array( $saved ) ? $saved : array();

		if ( ! user_can( $user_id, 'edit_post', $photo ) ) {
			return $this->cannot_edit();
		}

		if ( ! isset( $saved[ $part ] ) || (int) ( $saved[ $part ]['log'] ?? 0 ) !== (int) $row['id'] ) {
			return new \WP_Error( 'estasoft_nd_ai_nothing_to_undo', __( 'There is nothing to put back. A newer change replaced this one.', 'estasoft-news-distribution' ), array( 'status' => 409 ) );
		}

		if ( 'caption' === $part ) {
			$wrote = (string) ( $row['data']['chosen']['caption']['text'] ?? '' );

			// A caption someone typed since is not replaced by an undo.
			if ( trim( (string) get_post_field( 'post_excerpt', $photo ) ) !== trim( $wrote ) ) {
				return new \WP_Error( 'estasoft_nd_ai_nothing_to_undo', __( 'The caption was changed after this, so nothing was put back.', 'estasoft-news-distribution' ), array( 'status' => 409 ) );
			}

			wp_update_post(
				array(
					'ID'           => $photo,
					'post_excerpt' => wp_slash( (string) $saved['caption']['value'] ),
				)
			);
		} elseif ( 'alt' === $part ) {
			$value = (string) $saved['alt']['value'];

			if ( '' === $value ) {
				delete_post_meta( $photo, '_wp_attachment_image_alt' );
			} else {
				update_post_meta( $photo, '_wp_attachment_image_alt', wp_slash( $value ) );
			}
		} else {
			$value   = (array) $saved['focal']['value'];
			$presets = $this->plugin->make( ImagePresets::class );

			if ( ! empty( $value['set'] ) ) {
				$presets->set_focal( $photo, (float) $value['x'], (float) $value['y'] );
			} else {
				$presets->set_focal( $photo, null, null );
			}
		}

		unset( $saved[ $part ] );

		if ( array() === $saved ) {
			delete_post_meta( $photo, self::UNDO );
		} else {
			update_post_meta( $photo, self::UNDO, $saved );
		}

		$this->plugin->make( Log::class )->annotate(
			(int) $row['id'],
			array(
				'undone_' . $part => array(
					'by' => $user_id,
					'at' => time(),
				),
			)
		);

		return array(
			'log'      => (int) $row['id'],
			'part'     => $part,
			'decision' => 'undone',
			'applied'  => $part,
			'text'     => '',
			'message'  => __( 'Put back as it was before.', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * Whether a change can still be put back.
	 *
	 * @param int    $photo  The photo.
	 * @param string $part   `alt`, `focal` or `caption`.
	 * @param int    $log_id The AI log row.
	 * @return bool
	 */
	public function can_undo( int $photo, string $part, int $log_id ): bool {
		$saved = get_post_meta( $photo, self::UNDO, true );

		return is_array( $saved ) && isset( $saved[ $part ] ) && (int) ( $saved[ $part ]['log'] ?? 0 ) === $log_id;
	}

	/**
	 * Keeps what a change replaced.
	 *
	 * @param int    $photo  The photo.
	 * @param string $part   `alt`, `focal` or `caption`.
	 * @param mixed  $value  The old value.
	 * @param int    $log_id The AI log row.
	 * @return void
	 */
	private function remember( int $photo, string $part, $value, int $log_id ): void {
		$saved          = get_post_meta( $photo, self::UNDO, true );
		$saved          = is_array( $saved ) ? $saved : array();
		$saved[ $part ] = array(
			'value' => $value,
			'log'   => $log_id,
			'at'    => time(),
		);

		update_post_meta( $photo, self::UNDO, $saved );
	}

	/**
	 * The row's decision once every part has one: an edit counts over a use, and a use over a dismissal.
	 *
	 * @param array<string, string> $parts Part => decision.
	 * @return string
	 */
	private function summary( array $parts ): string {
		foreach ( array( Log::EDITED, Log::USED ) as $wanted ) {
			if ( in_array( $wanted, $parts, true ) ) {
				return $wanted;
			}
		}

		return Log::DISMISSED;
	}

	/**
	 * @return \WP_Error
	 */
	private function cannot_edit(): \WP_Error {
		return new \WP_Error( 'estasoft_nd_ai_cannot_edit_photo', __( 'You cannot change this photo. Ask the person who uploaded it, or an editor.', 'estasoft-news-distribution' ), array( 'status' => 403 ) );
	}

	/**
	 * The file to send: the original, or its large size when the original is too big.
	 *
	 * @param int $photo The photo.
	 * @return string|\WP_Error A path.
	 */
	private function file_for( int $photo ) {
		$path = get_attached_file( $photo );

		if ( ! is_string( $path ) || ! is_readable( $path ) ) {
			return new \WP_Error( 'estasoft_nd_ai_image_unreadable', __( 'The image file cannot be read on this server, so nothing was sent.', 'estasoft-news-distribution' ), array( 'status' => 409 ) );
		}

		if ( filesize( $path ) <= self::MAX_BYTES ) {
			return $this->typed( $path );
		}

		$info = image_get_intermediate_size( $photo, 'large' );
		$dir  = wp_get_upload_dir();

		if ( is_array( $info ) && ! empty( $info['path'] ) && ! empty( $dir['basedir'] ) ) {
			$smaller = path_join( (string) $dir['basedir'], (string) $info['path'] );

			if ( is_readable( $smaller ) && filesize( $smaller ) <= self::MAX_BYTES ) {
				return $this->typed( $smaller );
			}
		}

		return new \WP_Error( 'estasoft_nd_ai_image_too_big', __( 'The image file is too large to send. Choose a smaller image, or save a smaller version of it.', 'estasoft-news-distribution' ), array( 'status' => 422 ) );
	}

	/**
	 * Refuses a file type a provider does not take.
	 *
	 * @param string $path A path.
	 * @return string|\WP_Error The same path.
	 */
	private function typed( string $path ) {
		$type = wp_check_filetype( $path );

		if ( ! in_array( (string) $type['type'], array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ), true ) ) {
			return new \WP_Error( 'estasoft_nd_ai_image_type', __( 'Providers take JPEG, PNG, WebP and GIF images. This image is another type, so nothing was sent.', 'estasoft-news-distribution' ), array( 'status' => 422 ) );
		}

		return $path;
	}

	/**
	 * The question that goes with the image.
	 *
	 * @param string $language The article's language.
	 * @return string
	 */
	private function prompt( string $language ): string {
		return 'Answer with a JSON object with these keys. '
			. '"kind": one of "photo", "logo", "graphic" or "screenshot". '
			. '"confidence": a whole number from 0 to 100, how sure you are of the kind. '
			. '"focal": an object with "x" (a number from 0 to 1, from the left edge) and "y" (a number from 0 to 1, from the top edge) for the point of the picture that matters most, and "note", a few words saying what is at that point. '
			. '"alt": a list of three different alt texts for the picture, each under 125 characters, plain text, saying what is in the picture and not starting with "image of" or "picture of". '
			. sprintf( 'Write the "note" and the alt texts in the language with the code "%s".', $language );
	}

	/**
	 * The kind of picture and how sure the AI is.
	 *
	 * @param array<mixed> $read What the AI answered.
	 * @return array{kind: string, confidence: int}|null
	 */
	private function kind( array $read ): ?array {
		$kind = isset( $read['kind'] ) && is_string( $read['kind'] ) ? strtolower( trim( $read['kind'] ) ) : '';

		if ( ! in_array( $kind, self::KINDS, true ) || ! isset( $read['confidence'] ) || ! is_numeric( $read['confidence'] ) ) {
			return null;
		}

		$confidence = (float) $read['confidence'];

		if ( $confidence > 0 && $confidence <= 1 ) {
			$confidence *= 100;
		}

		return array(
			'kind'       => $kind,
			'confidence' => (int) max( 0, min( 100, round( $confidence ) ) ),
		);
	}

	/**
	 * The suggested focal point.
	 *
	 * @param array<mixed>                         $read    What the AI answered.
	 * @param array{x: float, y: float, set: bool} $current The point now.
	 * @return array{x: float, y: float, note: string, current: array{x: float, y: float, set: bool}}|null
	 */
	private function focal( array $read, array $current ): ?array {
		$point = isset( $read['focal'] ) && is_array( $read['focal'] ) ? $read['focal'] : array();

		if ( ! isset( $point['x'], $point['y'] ) || ! is_numeric( $point['x'] ) || ! is_numeric( $point['y'] ) ) {
			return null;
		}

		$x = (float) $point['x'];
		$y = (float) $point['y'];

		if ( $x < 0 || $x > 1 || $y < 0 || $y > 1 ) {
			return null;
		}

		$note = isset( $point['note'] ) && is_string( $point['note'] ) ? sanitize_text_field( $point['note'] ) : '';

		return array(
			'x'       => round( $x, 4 ),
			'y'       => round( $y, 4 ),
			'note'    => mb_substr( $note, 0, 80 ),
			'current' => $current,
		);
	}

	/**
	 * The sentence about what kind of picture it is, built here and not taken from the AI.
	 *
	 * @param array{kind: string, confidence: int} $kind The kind and confidence.
	 * @return string
	 */
	public function photo_text( array $kind ): string {
		if ( $kind['confidence'] < 60 ) {
			return __( 'The AI is not sure what kind of picture this is, so it makes no suggestion. Look at the image yourself.', 'estasoft-news-distribution' );
		}

		if ( 'photo' === $kind['kind'] ) {
			return sprintf(
				/* translators: %d: how sure the AI is, as a percentage. */
				__( 'The AI is %d%% sure this is a photo, not a logo or a graphic. Google advises against generic images, such as a site logo, for Discover.', 'estasoft-news-distribution' ),
				$kind['confidence']
			);
		}

		$words = array(
			'logo'       => __( 'a logo', 'estasoft-news-distribution' ),
			'graphic'    => __( 'a graphic', 'estasoft-news-distribution' ),
			'screenshot' => __( 'a screenshot', 'estasoft-news-distribution' ),
		);

		return sprintf(
			/* translators: 1: how sure the AI is, as a percentage; 2: what the picture looks like, such as "a logo". */
			__( 'The AI is %1$d%% sure this is %2$s, not a photo. Google advises against generic images, such as a site logo, for Discover, so choose a photo from the article if you have one.', 'estasoft-news-distribution' ),
			$kind['confidence'],
			$words[ $kind['kind'] ] ?? $words['graphic']
		);
	}
}
