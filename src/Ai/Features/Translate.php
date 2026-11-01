<?php
/**
 * Translation drafts: a first version in another language, for a person who reads it to review.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Ai\Features;

use Estasoft\NewsDistribution\Ai\Config;
use Estasoft\NewsDistribution\Ai\Log;
use Estasoft\NewsDistribution\Ai\Marker;
use Estasoft\NewsDistribution\Ai\Reviewers;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Language\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * `POST /ai/suggest/translate` with `post`, `language` and `keep` (names and terms to leave as written). One request
 * sends the title and the body of the article, with its headings, captions and the alt text of its images. It does
 * not send the author, and the byline is added back after the AI has finished.
 *
 * The draft is checked before anyone sees it. These checks fail the draft, and nothing is saved:
 *
 * - the text is in the script of the language (Latin for Kurdish (Kurmanji), Arabic for Arabic, and so on);
 * - every name or term the person listed, that the source has, is in the draft;
 * - every figure written in digits is the same in the draft, and none was added;
 * - the HTML tags and block comments are the same, and so are the addresses of links and images.
 *
 * This one only warns: the draft is much shorter or longer than the source.
 *
 * What the checks cannot do is judge whether the translation is right. Only a person who reads the language can.
 * So the draft is held in a transient until a person chooses to save it, and a saved draft carries
 * `_estasoft_nd_ai_review`: it cannot be published until a signed-off reviewer for the language approves it, or two
 * approvers do when the network asks for that. Kurdish (Kurmanji) drafts are made only after a signed-off reviewer exists.
 */
final class Translate extends AbstractFeature {

	public const REVIEW = '_estasoft_nd_ai_review';

	/**
	 * Most words in one request. A longer article is translated by a person, or in parts.
	 */
	public const MAX_WORDS = 2500;

	/**
	 * Kept terms may not be longer than this.
	 */
	public const TERM_MAX = 80;

	public function name(): string {
		return 'translate';
	}

	public function label(): string {
		return __( 'Translation drafts', 'estasoft-news-distribution' );
	}

	/**
	 * What publishing a draft into the language needs, in words for the review card.
	 *
	 * @param string $language Language code.
	 * @return array{ok: bool, text: string}
	 */
	public function requirement_text( string $language ): array {
		$reviewers = $this->plugin->make( Reviewers::class );
		$need      = $reviewers->requirement( $language );
		$name      = Catalog::name( $language );

		if ( 'reviewer' === $need['mode'] ) {
			return array(
				'ok'   => true,
				'text' => sprintf(
					/* translators: %s: a language name such as Arabic. */
					__( 'A signed-off reviewer for %s approves it before it can be published.', 'estasoft-news-distribution' ),
					$name
				),
			);
		}

		if ( 'two_approvers' === $need['mode'] ) {
			return array(
				'ok'   => true,
				'text' => sprintf(
					/* translators: %s: a language name such as Arabic. */
					__( 'No reviewer is signed off for %s, so two approvers must approve it before it can be published.', 'estasoft-news-distribution' ),
					$name
				),
			);
		}

		return array(
			'ok'   => false,
			'text' => sprintf(
				/* translators: %s: a language name such as Arabic. */
				__( 'No reviewer is signed off for %s yet, so the draft cannot be published. Sign one off in Settings, AI assistant.', 'estasoft-news-distribution' ),
				$name
			),
		);
	}

	/**
	 * Whether a draft into a language may be made now.
	 *
	 * @param string $language Language code.
	 * @return true|\WP_Error
	 */
	public function may_draft( string $language ) {
		$reviewers = $this->plugin->make( Reviewers::class );

		if ( ! $reviewers->accepts( $language ) ) {
			return new \WP_Error( 'estasoft_nd_ai_language', __( 'That language is not set up on this site. Set it up in your translation plugin first.', 'estasoft-news-distribution' ), array( 'status' => 400 ) );
		}

		if ( 'ku' === Catalog::primary( $language ) && array() === $reviewers->signed_off( $language ) ) {
			return new \WP_Error(
				'estasoft_nd_ai_no_reviewer',
				__( 'Kurdish (Kurmanji) drafts are weaker, so they are made only after a signed-off reviewer exists. Sign one off in Settings, AI assistant, then make the draft.', 'estasoft-news-distribution' ),
				array( 'status' => 409 )
			);
		}

		return true;
	}

	public function suggest( int $post_id, array $args ) {
		$language = (string) ( $args['language'] ?? '' );
		$article  = $this->sendable( $post_id );

		if ( is_wp_error( $article ) ) {
			return $article;
		}

		if ( $language === $article->language() ) {
			return new \WP_Error( 'estasoft_nd_ai_same_language', __( 'The article is already in that language.', 'estasoft-news-distribution' ), array( 'status' => 400 ) );
		}

		$may = $this->may_draft( $language );

		if ( is_wp_error( $may ) ) {
			return $may;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'estasoft_nd_ai_no_article', __( 'That is not an article News Distribution sends.', 'estasoft-news-distribution' ), array( 'status' => 404 ) );
		}

		$title = trim( wp_strip_all_tags( (string) $post->post_title ) );
		$body  = (string) $post->post_content;
		$words = $this->words( $title . ' ' . $body );

		if ( $words > self::MAX_WORDS ) {
			return new \WP_Error(
				'estasoft_nd_ai_too_long',
				sprintf(
					/* translators: %s: the most words, such as 2,500. */
					__( 'The article is longer than %s words, which is more than one request can translate well. Translate it in parts, or have a person translate it.', 'estasoft-news-distribution' ),
					number_format_i18n( self::MAX_WORDS )
				),
				array( 'status' => 422 )
			);
		}

		$keep   = $this->terms( isset( $args['keep'] ) && is_array( $args['keep'] ) ? $args['keep'] : array() );
		$answer = $this->ask(
			array(
				'system'     => $this->system( $language, $keep ),
				'prompt'     => "===TITLE===\n" . $title . "\n===BODY===\n" . $body,
				'max_tokens' => (int) min( 8000, max( 800, $words * 4 ) ),
			)
		);

		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		$parts = $this->parts( $answer['text'] );

		if ( null === $parts ) {
			$log = $this->record( $post_id, $answer, $this->data( $language, $words, $keep, array(), false, 'unreadable' ), Log::NONE );

			return new \WP_Error(
				'estasoft_nd_ai_bad_answer',
				__( 'The answer could not be read as a title and a body, so nothing was saved. Make the draft again.', 'estasoft-news-distribution' ),
				array(
					'status' => 502,
					'log'    => $log,
				)
			);
		}

		$checks = $this->checks( $title, $body, $parts['title'], $parts['body'], $language, $keep );
		$pass   = true;

		foreach ( $checks as $check ) {
			$pass = $pass && 'fail' !== $check['level'];
		}

		$log = $this->record(
			$post_id,
			$answer,
			$this->data( $language, $words, $keep, $checks, $pass, '' ),
			$pass ? Log::PENDING : Log::NONE
		);

		if ( ! $pass ) {
			return new \WP_Error(
				'estasoft_nd_ai_rule_check',
				__( 'The draft did not pass the checks, so it was not saved. Make the draft again.', 'estasoft-news-distribution' ),
				array(
					'status' => 422,
					'checks' => $checks,
					'log'    => $log,
				)
			);
		}

		set_transient(
			$this->key( $log ),
			array(
				'title'    => $parts['title'],
				'content'  => $parts['body'],
				'language' => $language,
				'source'   => $post_id,
				'user'     => get_current_user_id(),
				'checks'   => $checks,
			),
			DAY_IN_SECONDS
		);

		$requirement = $this->requirement_text( $language );

		return array_merge(
			array(
				'log'         => $log,
				'feature'     => $this->name(),
				'language'    => $language,
				'source'      => array(
					'id'       => $post_id,
					'title'    => $title,
					'language' => $article->language(),
					'words'    => $words,
				),
				'draft'       => array(
					'title'   => $parts['title'],
					'content' => $parts['body'],
					'words'   => $this->words( $parts['title'] . ' ' . $parts['body'] ),
				),
				'checks'      => $checks,
				'requirement' => $requirement,
				'message'     => '',
			),
			$this->meta( $answer )
		);
	}

	/**
	 * The draft made a moment ago, which waits for a person to save it.
	 *
	 * @param int $log_id The AI log row.
	 * @return array<string, mixed>|null
	 */
	public function waiting( int $log_id ): ?array {
		$draft = get_transient( $this->key( $log_id ) );

		return is_array( $draft ) ? $draft : null;
	}

	/**
	 * Saved drafts that are not approved yet, newest first.
	 *
	 * @param int $limit How many at most.
	 * @return array<int, array{log: int, post: int, language: string, approvals: int, needs: int, at: int, reviewer: int}>
	 */
	public function unreviewed( int $limit = 20 ): array {
		$found = $this->plugin->make( Log::class )->query(
			array(
				'feature'  => $this->name(),
				'decision' => Log::REVIEW,
				'limit'    => max( 1, min( 200, $limit ) ),
			)
		);
		$out   = array();

		foreach ( $found['items'] as $row ) {
			$post   = (int) ( $row['data']['draft_post'] ?? 0 );
			$review = $post > 0 && null !== get_post( $post ) ? $this->review( $post ) : null;

			if ( null === $review || 'approved' === ( $review['state'] ?? '' ) ) {
				continue;
			}

			$out[] = array(
				'log'       => (int) $row['id'],
				'post'      => $post,
				'language'  => (string) $review['language'],
				'approvals' => count( (array) ( $review['approvals'] ?? array() ) ),
				'needs'     => max( 1, (int) ( $review['needs'] ?? 1 ) ),
				'at'        => (int) ( $review['at'] ?? 0 ),
				'reviewer'  => (int) ( $review['reviewer'] ?? 0 ),
			);
		}

		return $out;
	}

	/**
	 * What a saved draft records about its review.
	 *
	 * @param int $post_id The draft.
	 * @return array<string, mixed>|null Null when the article is not an AI draft.
	 */
	public function review( int $post_id ): ?array {
		$review = get_post_meta( $post_id, self::REVIEW, true );

		return is_array( $review ) && ! empty( $review['language'] ) ? $review : null;
	}

	public function decide( array $row, array $args, int $user_id ) {
		$decision = sanitize_key( (string) ( $args['decision'] ?? '' ) );
		$log      = $this->plugin->make( Log::class );

		if ( 'approve' === $decision ) {
			return $this->approve( $row, $user_id );
		}

		$decided = $this->already_decided( $row );

		if ( null !== $decided ) {
			return $decided;
		}

		if ( 'dismiss' === $decision ) {
			delete_transient( $this->key( (int) $row['id'] ) );
			$log->decide( (int) $row['id'], Log::DISMISSED, $user_id );

			return array(
				'log'      => (int) $row['id'],
				'decision' => Log::DISMISSED,
				'applied'  => '',
				'message'  => __( 'The draft was thrown away. Nothing was saved.', 'estasoft-news-distribution' ),
			);
		}

		if ( 'save_draft' !== $decision && 'send_review' !== $decision ) {
			return new \WP_Error( 'estasoft_nd_ai_bad_decision', __( 'Choose Send for review or Save as a draft only.', 'estasoft-news-distribution' ), array( 'status' => 400 ) );
		}

		$draft = $this->waiting( (int) $row['id'] );

		if ( null === $draft ) {
			return new \WP_Error( 'estasoft_nd_ai_draft_gone', __( 'The draft is no longer kept. Make the draft again.', 'estasoft-news-distribution' ), array( 'status' => 410 ) );
		}

		$language    = (string) $draft['language'];
		$responsible = (int) ( $args['responsible'] ?? $user_id );
		$reviewer    = (int) ( $args['reviewer'] ?? 0 );
		$reviewers   = $this->plugin->make( Reviewers::class );

		if ( ! user_can( $responsible, 'edit_others_posts' ) ) {
			return new \WP_Error( 'estasoft_nd_ai_bad_responsible', __( 'Choose an editor to hold editorial responsibility.', 'estasoft-news-distribution' ), array( 'status' => 400 ) );
		}

		if ( 'send_review' === $decision && $reviewer > 0 && ! $reviewers->is_signed_off( $language, $reviewer ) && 'reviewer' === $reviewers->requirement( $language )['mode'] ) {
			return new \WP_Error( 'estasoft_nd_ai_not_reviewer', __( 'Choose a reviewer who is signed off for this language.', 'estasoft-news-distribution' ), array( 'status' => 400 ) );
		}

		$source = get_post( (int) $draft['source'] );

		if ( ! $source instanceof \WP_Post ) {
			return new \WP_Error( 'estasoft_nd_ai_no_article', __( 'The original article is gone, so the draft was not saved.', 'estasoft-news-distribution' ), array( 'status' => 404 ) );
		}

		$new = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => $source->post_type,
					'post_status'  => 'draft',
					'post_title'   => (string) $draft['title'],
					'post_content' => (string) $draft['content'],
					'post_author'  => (int) $source->post_author,
				)
			),
			true
		);

		if ( is_wp_error( $new ) || (int) $new < 1 ) {
			return new \WP_Error( 'estasoft_nd_ai_not_saved', __( 'WordPress could not save the draft, so nothing was saved. Make the draft again.', 'estasoft-news-distribution' ), array( 'status' => 500 ) );
		}

		$new     = (int) $new;
		$thumb   = (int) get_post_thumbnail_id( $source );
		$linked  = $this->link( $new, (int) $source->ID, $language );
		$request = $reviewers->requirement( $language );

		if ( $thumb > 0 ) {
			set_post_thumbnail( $new, $thumb );
		}

		update_post_meta(
			$new,
			self::REVIEW,
			array(
				'language'    => $language,
				'source'      => (int) $source->ID,
				'log'         => (int) $row['id'],
				'state'       => 'pending',
				'approvals'   => array(),
				'needs'       => (int) $request['approvals'],
				'reviewer'    => 'send_review' === $decision ? $reviewer : 0,
				'responsible' => $responsible,
				'made_by'     => $user_id,
				'at'          => Clock::now(),
				'checks'      => $draft['checks'],
				'linked'      => $linked,
			)
		);
		$this->plugin->make( Marker::class )->mark( $new, (int) $row['id'], $this->name(), $user_id );
		delete_transient( $this->key( (int) $row['id'] ) );

		$log->decide(
			(int) $row['id'],
			Log::REVIEW,
			$user_id,
			array(
				'draft_post'  => $new,
				'responsible' => $responsible,
				'reviewer'    => 'send_review' === $decision ? $reviewer : 0,
			)
		);

		if ( 'send_review' === $decision && $reviewer > 0 ) {
			$this->tell( $reviewer, $new, $language );
		}

		/**
		 * Runs after an AI translation draft was saved (internal), so a translation plugin can link it.
		 *
		 * @param int    $draft    The draft.
		 * @param int    $source   The original article.
		 * @param string $language Language code.
		 */
		do_action( 'estasoft_nd_internal_ai_translation_created', $new, (int) $source->ID, $language );

		return array(
			'log'      => (int) $row['id'],
			'decision' => Log::REVIEW,
			'applied'  => 'draft',
			'post'     => $new,
			'edit_url' => (string) get_edit_post_link( $new, 'raw' ),
			'linked'   => $linked,
			'message'  => 'send_review' === $decision
				? __( 'The draft is saved and sent for review. It cannot be published until it is approved.', 'estasoft-news-distribution' )
				: __( 'The draft is saved. It cannot be published until it is approved.', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * Whether a save may publish an AI draft. Hooked to `wp_insert_post_data`: the status goes back to draft
	 * when the text has not been approved as it stands now.
	 *
	 * @param array<string, mixed> $data    The data about to be saved.
	 * @param array<string, mixed> $postarr The arguments given.
	 * @return array<string, mixed>
	 */
	public function guard( $data, $postarr ) {
		if ( ! is_array( $data ) || ! is_array( $postarr ) ) {
			return $data;
		}

		$post_id = (int) ( $postarr['ID'] ?? 0 );
		$status  = (string) ( $data['post_status'] ?? '' );

		if ( $post_id < 1 || ! in_array( $status, array( 'publish', 'future', 'private' ), true ) ) {
			return $data;
		}

		$review = $this->review( $post_id );

		if ( null === $review ) {
			return $data;
		}

		$hash = md5( wp_unslash( (string) ( $data['post_title'] ?? '' ) ) . "\n" . wp_unslash( (string) ( $data['post_content'] ?? '' ) ) );

		if ( 'approved' === ( $review['state'] ?? '' ) && ( $review['hash'] ?? '' ) === $hash ) {
			return $data;
		}

		// The text changed after it was approved, so the approval no longer covers it.
		if ( 'approved' === ( $review['state'] ?? '' ) ) {
			$review['state']     = 'pending';
			$review['approvals'] = array();
			unset( $review['hash'] );
			update_post_meta( $post_id, self::REVIEW, $review );
		}

		$data['post_status'] = 'draft';

		set_transient( 'estasoft_nd_ai_blocked_' . get_current_user_id(), $post_id, 120 );

		return $data;
	}

	/**
	 * Records one person's approval of a saved draft.
	 *
	 * @param array<string, mixed> $row     The AI log row.
	 * @param int                  $user_id The person approving.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function approve( array $row, int $user_id ) {
		$post_id = (int) ( $row['data']['draft_post'] ?? 0 );
		$review  = $post_id > 0 ? $this->review( $post_id ) : null;

		if ( null === $review ) {
			return new \WP_Error( 'estasoft_nd_ai_no_draft', __( 'There is no saved draft to approve.', 'estasoft-news-distribution' ), array( 'status' => 404 ) );
		}

		if ( 'approved' === $review['state'] ) {
			return new \WP_Error( 'estasoft_nd_ai_decided', __( 'This draft is approved already.', 'estasoft-news-distribution' ), array( 'status' => 409 ) );
		}

		if ( ! user_can( $user_id, 'edit_post', $post_id ) ) {
			return new \WP_Error( 'estasoft_nd_ai_forbidden', __( 'You cannot edit this draft, so you cannot approve it.', 'estasoft-news-distribution' ), array( 'status' => 403 ) );
		}

		$language  = (string) $review['language'];
		$approvals = array_map( 'intval', (array) $review['approvals'] );
		$allowed   = $this->plugin->make( Reviewers::class )->can_approve( $language, $user_id, $approvals );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'estasoft_nd_ai_no_draft', __( 'There is no saved draft to approve.', 'estasoft-news-distribution' ), array( 'status' => 404 ) );
		}

		$approvals[] = $user_id;
		$needs       = max( 1, (int) $review['needs'] );
		$done        = count( $approvals ) >= $needs;

		$review['approvals'] = $approvals;

		if ( $done ) {
			$review['state'] = 'approved';
			$review['hash']  = md5( (string) $post->post_title . "\n" . (string) $post->post_content );
			$review['at_ok'] = Clock::now();
		}

		update_post_meta( $post_id, self::REVIEW, $review );

		$log = $this->plugin->make( Log::class );

		if ( $done ) {
			$log->decide( (int) $row['id'], Log::APPROVED, $user_id, array( 'approved_by' => $approvals ) );
		} else {
			$log->annotate( (int) $row['id'], array( 'approved_by' => $approvals ) );
		}

		return array(
			'log'       => (int) $row['id'],
			'decision'  => $done ? Log::APPROVED : Log::REVIEW,
			'applied'   => 'approval',
			'post'      => $post_id,
			'approvals' => count( $approvals ),
			'needs'     => $needs,
			'message'   => $done ? __( 'Approved. The draft can now be published.', 'estasoft-news-distribution' ) : __( 'Your approval is recorded. A second approver is needed before the draft can be published.', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * Links the draft to the original in the translation plugin, when the plugin has a function for it.
	 *
	 * Written from each plugin's documented functions; it has not been run against either plugin here.
	 *
	 * @param int    $draft    The draft.
	 * @param int    $source   The original.
	 * @param string $language The draft's language.
	 * @return bool Whether a translation plugin took the link.
	 */
	private function link( int $draft, int $source, string $language ): bool {
		$resolver = $this->plugin->languages();

		if ( 'polylang' === $resolver->name() && function_exists( 'pll_set_post_language' ) && function_exists( 'pll_save_post_translations' ) && function_exists( 'pll_get_post_translations' ) ) {
			pll_set_post_language( $draft, $language );

			$group = (array) pll_get_post_translations( $source );
			$group[ $resolver->language_of_post( $source ) ] = $source;
			$group[ $language ]                              = $draft;

			pll_save_post_translations( $group );

			return true;
		}

		if ( 'wpml' === $resolver->name() ) {
			$type = 'post_' . (string) get_post_type( $source );
			$trid = apply_filters( 'wpml_element_trid', null, $source, $type ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.

			if ( ! empty( $trid ) ) {
				do_action(
					'wpml_set_element_language_details', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own action.
					array(
						'element_id'           => $draft,
						'element_type'         => $type,
						'trid'                 => $trid,
						'language_code'        => $language,
						'source_language_code' => $resolver->language_of_post( $source ),
					)
				);

				return true;
			}
		}

		return false;
	}

	/**
	 * Tells a reviewer by email that a draft waits. The only message this feature sends, and it goes to a person on this site.
	 *
	 * @param int    $reviewer The reviewer.
	 * @param int    $draft    The draft.
	 * @param string $language Language code.
	 * @return void
	 */
	private function tell( int $reviewer, int $draft, string $language ): void {
		$user = get_userdata( $reviewer );

		if ( ! $user instanceof \WP_User || '' === $user->user_email ) {
			return;
		}

		wp_mail(
			$user->user_email,
			sprintf(
				/* translators: %s: a language name such as Arabic. */
				__( 'A %s translation draft is waiting for your review', 'estasoft-news-distribution' ),
				Catalog::name( $language )
			),
			sprintf(
				/* translators: %s: the address of the draft. */
				__( "An AI translation draft is waiting for your review. Read it in full, then approve it if it is right.\n\n%s", 'estasoft-news-distribution' ),
				(string) get_edit_post_link( $draft, 'raw' )
			)
		);
	}

	/**
	 * What the provider is told it is.
	 *
	 * @param string   $language Language code.
	 * @param string[] $keep     Names and terms to keep.
	 * @return string
	 */
	private function system( string $language, array $keep ): string {
		$name = 'ku' === Catalog::primary( $language ) ? 'Kurdish (Kurmanji) written in Latin script' : Catalog::name( $language );
		$text = 'You are a careful news translator. Translate the article into ' . $name . '. '
			. 'The article is given after the markers ===TITLE=== and ===BODY===. The body is HTML with WordPress block comments. '
			. 'Keep every HTML tag, every HTML comment and every attribute exactly as in the source. Translate only the text between tags and the values of alt attributes. Never change an address. '
			. 'Do not add, remove or reorder anything, and do not add notes. Keep every number, date and unit the same in meaning. ';

		if ( array() !== $keep ) {
			$text .= 'Keep these names and terms exactly as written, untranslated: ' . implode( '; ', $keep ) . '. ';
		}

		return $text . 'Reply with the marker ===TITLE=== and the translated title on the next line, then the marker ===BODY=== and the translated body, and nothing else.';
	}

	/**
	 * Splits the answer at the markers.
	 *
	 * @param string $text The answer.
	 * @return array{title: string, body: string}|null
	 */
	private function parts( string $text ): ?array {
		if ( 1 !== preg_match( '/===TITLE===\s*(.*?)\s*===BODY===\s*(.*)$/su', $text, $found ) ) {
			return null;
		}

		$title = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $found[1] ) ) );
		// The answer is text from a model that has read the article, so it is cleaned like the body of a post by an author
		// (no script, no event attributes, no unsafe address) before anything is checked or saved.
		$body = trim( wp_kses_post( trim( (string) $found[2] ) ) );

		return '' === $title || '' === $body ? null : array(
			'title' => $title,
			'body'  => $body,
		);
	}

	/**
	 * The checks on a draft.
	 *
	 * @param string   $source_title The original title.
	 * @param string   $source_body  The original body.
	 * @param string   $title        The draft title.
	 * @param string   $body         The draft body.
	 * @param string   $language     Language code.
	 * @param string[] $keep         Names and terms to keep.
	 * @return array<int, array{level: string, ok: bool, label: string, text: string}> `level` is `pass`, `warn` or `fail`.
	 */
	private function checks( string $source_title, string $source_body, string $title, string $body, string $language, array $keep ): array {
		$source_text = $source_title . ' ' . wp_strip_all_tags( $source_body );
		$draft_text  = $title . ' ' . wp_strip_all_tags( $body );
		$lines       = array();
		$add         = static function ( string $level, string $text ) use ( &$lines ): void {
			$lines[] = array(
				'level' => $level,
				'ok'    => 'fail' !== $level && 'warn' !== $level,
				'label' => '',
				'text'  => $text,
			);
		};

		$script = $this->script( $language );

		if ( null !== $script ) {
			$share = $this->share( str_ireplace( $keep, '', $draft_text ), $script['pattern'] );

			if ( $share >= $script['min'] ) {
				$add( 'pass', sprintf( /* translators: %s: a script such as Arabic or Latin. */ __( 'The text is in %s script', 'estasoft-news-distribution' ), $script['name'] ) );
			} else {
				$add( 'fail', sprintf( /* translators: %s: a script such as Arabic or Latin. */ __( 'The text is not in %s script', 'estasoft-news-distribution' ), $script['name'] ) );
			}
		}

		$present = array();
		$missing = array();

		foreach ( $keep as $term ) {
			if ( false === mb_stripos( $source_text . ' ' . $source_body, $term ) ) {
				continue;
			}

			if ( false !== mb_stripos( $draft_text . ' ' . $body, $term ) ) {
				$present[] = $term;
			} else {
				$missing[] = $term;
			}
		}

		if ( array() !== $missing ) {
			$add(
				'fail',
				sprintf(
					/* translators: %s: names and terms. */
					__( 'These are missing from the draft: %s', 'estasoft-news-distribution' ),
					implode( ', ', $missing )
				)
			);
		} elseif ( array() !== $present ) {
			$add(
				'pass',
				sprintf(
					/* translators: %s: names and terms. */
					_n( 'The kept item is present: %s', 'Both kept items are present: %s', count( $present ), 'estasoft-news-distribution' ),
					implode( ', ', $present )
				)
			);
		}

		$wanted = Grounding::numbers( $source_text );
		$found  = Grounding::numbers( $draft_text );
		$off    = array_merge( array_diff( $wanted, $found ), array_diff( $found, $wanted ) );

		if ( array() === $off ) {
			$add(
				'pass',
				array() === $wanted
					? __( 'There are no figures written in digits to compare', 'estasoft-news-distribution' )
					: sprintf(
						/* translators: %s: figures such as 26, 214. */
						__( 'Figures written in digits match: %s. Numbers written in words and month names are not checked', 'estasoft-news-distribution' ),
						implode( ', ', $wanted )
					)
			);
		} else {
			$add(
				'fail',
				sprintf(
					/* translators: %s: figures such as 26, 214. */
					__( 'These figures are in one text and not the other: %s', 'estasoft-news-distribution' ),
					implode( ', ', array_values( array_unique( $off ) ) )
				)
			);
		}

		// The draft was cleaned the way a post by an author is, so the original is read the same way.
		$source_clean = wp_kses_post( $source_body );

		if ( $this->structure( $source_clean ) === $this->structure( $body ) && $this->addresses( $source_clean ) === $this->addresses( $body ) ) {
			$add( 'pass', __( 'The page structure, links and images are the same', 'estasoft-news-distribution' ) );
		} else {
			$add( 'fail', __( 'The draft changed the page structure, a link or an image', 'estasoft-news-distribution' ) );
		}

		$was = $this->words( $source_text );
		$now = $this->words( $draft_text );

		if ( $was > 0 ) {
			$change = (int) round( ( $now - $was ) / $was * 100 );

			if ( $change < -5 ) {
				$add(
					'warn',
					sprintf(
						/* translators: %d: a percentage. */
						__( 'The draft is %d%% shorter than the source. A person should check nothing is missing', 'estasoft-news-distribution' ),
						abs( $change )
					)
				);
			} elseif ( $change > 30 ) {
				$add(
					'warn',
					sprintf(
						/* translators: %d: a percentage. */
						__( 'The draft is %d%% longer than the source. A person should check nothing was added', 'estasoft-news-distribution' ),
						$change
					)
				);
			}
		}

		return $lines;
	}

	/**
	 * The expected script of a language, or null when it is not checked.
	 *
	 * @param string $language Language code.
	 * @return array{name: string, pattern: string, min: float}|null
	 */
	private function script( string $language ): ?array {
		switch ( Catalog::primary( $language ) ) {
			case 'ar':
				return array(
					'name'    => __( 'Arabic', 'estasoft-news-distribution' ),
					'pattern' => '/\p{Arabic}/u',
					'min'     => 0.6,
				);
			case 'he':
				return array(
					'name'    => __( 'Hebrew', 'estasoft-news-distribution' ),
					'pattern' => '/\p{Hebrew}/u',
					'min'     => 0.6,
				);
			case 'ku':
			case 'es':
			case 'fr':
			case 'nl':
			case 'en':
				return array(
					'name'    => __( 'Latin', 'estasoft-news-distribution' ),
					'pattern' => '/\p{Latin}/u',
					'min'     => 0.9,
				);
		}

		return null;
	}

	/**
	 * The share of letters that match a script.
	 *
	 * @param string $text    Text.
	 * @param string $pattern A pattern for one letter of the script.
	 * @return float 0 to 1; 1 when there are no letters.
	 */
	private function share( string $text, string $pattern ): float {
		$letters = (int) preg_match_all( '/\p{L}/u', $text );

		if ( 0 === $letters ) {
			return 1.0;
		}

		return (int) preg_match_all( $pattern, $text ) / $letters;
	}

	/**
	 * The tags and comments of a body, in order, without attributes.
	 *
	 * @param string $html The body.
	 * @return string[]
	 */
	private function structure( string $html ): array {
		preg_match_all( '/<!--.*?-->|<\/?([a-zA-Z][a-zA-Z0-9-]*)\b[^>]*>/su', $html, $found, PREG_SET_ORDER );

		$out = array();

		foreach ( $found as $item ) {
			if ( 0 === strpos( $item[0], '<!--' ) ) {
				$out[] = trim( (string) preg_replace( '/\s+/', ' ', $item[0] ) );
			} else {
				$out[] = ( 0 === strpos( $item[0], '</' ) ? '/' : '' ) . strtolower( $item[1] );
			}
		}

		return $out;
	}

	/**
	 * The addresses of links and images in a body, sorted.
	 *
	 * @param string $html The body.
	 * @return string[]
	 */
	private function addresses( string $html ): array {
		preg_match_all( '/\b(?:href|src)\s*=\s*"([^"]*)"/i', $html, $found );

		$out = array_map( 'html_entity_decode', $found[1] );

		sort( $out );

		return $out;
	}

	/**
	 * Words in a text.
	 *
	 * @param string $text Text, with or without tags.
	 * @return int
	 */
	private function words( string $text ): int {
		return (int) preg_match_all( '/[\p{L}\p{N}]+/u', wp_strip_all_tags( $text ) );
	}

	/**
	 * Cleans the names and terms the person listed.
	 *
	 * @param array<mixed> $terms Raw terms.
	 * @return string[]
	 */
	private function terms( array $terms ): array {
		$out = array();

		foreach ( $terms as $term ) {
			$term = is_scalar( $term ) ? trim( (string) preg_replace( '/\s+/u', ' ', sanitize_text_field( (string) $term ) ) ) : '';

			if ( '' !== $term && mb_strlen( $term ) <= self::TERM_MAX && ! in_array( $term, $out, true ) ) {
				$out[] = $term;
			}
		}

		return array_slice( $out, 0, 20 );
	}

	/**
	 * The names and terms kept by default: the publication name, and the author only when the owner allows author names.
	 *
	 * @param Article $article The article.
	 * @return string[]
	 */
	public function default_terms( Article $article ): array {
		$terms = array_filter( array( $this->plugin->settings()->publication_name( $article->language() ) ) );

		if ( $this->plugin->make( Config::class )->authors() && '' !== $article->author_name() ) {
			$terms[] = $article->author_name();
		}

		return $this->terms( $terms );
	}

	/**
	 * The log data of a draft: what was asked and how it went, not the article.
	 *
	 * @param string                                                                  $language Language code.
	 * @param int                                                                     $words    Words sent.
	 * @param string[]                                                                $keep     Kept terms.
	 * @param array<int, array{level: string, ok: bool, label: string, text: string}> $checks   The checks.
	 * @param bool                                                                    $pass     Whether the draft passed.
	 * @param string                                                                  $problem  A reason the answer was refused.
	 * @return array<string, mixed>
	 */
	private function data( string $language, int $words, array $keep, array $checks, bool $pass, string $problem ): array {
		return array(
			'finding'  => 'translate',
			'language' => $language,
			'words'    => $words,
			'keep'     => $keep,
			'checks'   => $checks,
			'pass'     => $pass,
			'problem'  => $problem,
		);
	}

	/**
	 * Transient key of a waiting draft.
	 *
	 * @param int $log_id The AI log row.
	 * @return string
	 */
	private function key( int $log_id ): string {
		return 'estasoft_nd_ai_draft_' . $log_id;
	}
}
