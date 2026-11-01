<?php
/**
 * Turns a permission name into a capability check.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

defined( 'ABSPATH' ) || exit;

/**
 * No screen and no route calls current_user_can() with a literal capability.
 * They all ask this class, so the rules in the spec (section 5) live in one place.
 */
final class Access {

	public const VIEW     = 'view';
	public const VIEW_OWN = 'view_own';
	public const MANAGE   = 'manage';
	public const ADMIN    = 'admin';
	public const OVERRIDE = 'override';
	public const AI       = 'ai';
	public const NETWORK  = 'network';

	private Settings $settings;

	/**
	 * Creates the access checker.
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Whether a person holds a permission.
	 *
	 * @param string   $permission One of the class constants.
	 * @param int|null $post_id    The article, for permissions that depend on one.
	 * @param int|null $user_id    The person. Defaults to the signed-in user.
	 * @return bool
	 */
	public function can( string $permission, ?int $post_id = null, ?int $user_id = null ): bool {
		$user_id = null === $user_id ? get_current_user_id() : $user_id;

		if ( $user_id < 1 ) {
			return false;
		}

		switch ( $permission ) {
			case self::VIEW:
				return user_can( $user_id, 'edit_others_posts' ) && $this->may_edit_post( $user_id, $post_id );

			case self::VIEW_OWN:
				return $this->can_view_article_check( $user_id, $post_id );

			case self::MANAGE:
				return user_can( $user_id, $this->manage_capability() ) && $this->may_edit_post( $user_id, $post_id );

			case self::ADMIN:
				return user_can( $user_id, 'manage_options' );

			case self::OVERRIDE:
				return user_can( $user_id, $this->override_capability() ) && $this->may_edit_post( $user_id, $post_id );

			case self::AI:
				if ( null === $post_id || ! user_can( $user_id, 'edit_post', $post_id ) ) {
					return false;
				}

				/**
				 * Whether the AI assistant may run for this article.
				 *
				 * The AI module answers true only when the site turned the assistant
				 * on, WordPress reports a provider ready and the network allows it.
				 *
				 * @param bool $available Default false.
				 * @param int  $post_id   Article ID.
				 * @param int  $user_id   Person ID.
				 */
				return (bool) apply_filters( 'estasoft_nd_internal_ai_available', false, $post_id, $user_id );

			case self::NETWORK:
				return is_multisite() && user_can( $user_id, 'manage_network_options' );
		}

		return false;
	}

	/**
	 * Whether a person may edit the article behind a permission check. The permissions are site-wide, but a
	 * custom post type can have its own capabilities, so a check that names an article must also pass
	 * WordPress's own `edit_post` for it. An article that does not exist passes here, so the route can answer 404.
	 *
	 * @param int      $user_id Person.
	 * @param int|null $post_id Article ID, or null for a site-wide check.
	 * @return bool
	 */
	private function may_edit_post( int $user_id, ?int $post_id ): bool {
		if ( null === $post_id || $post_id < 1 || ! get_post( $post_id ) instanceof \WP_Post ) {
			return true;
		}

		return user_can( $user_id, 'edit_post', $post_id );
	}

	/**
	 * Whether the person can edit an article, which is what the editor panel and the classic box need.
	 *
	 * @param int      $post_id Article ID.
	 * @param int|null $user_id Person, or the signed-in user.
	 * @return bool
	 */
	public function can_edit_article( int $post_id, ?int $user_id = null ): bool {
		$user_id = null === $user_id ? get_current_user_id() : $user_id;

		return $user_id > 0 && user_can( $user_id, 'edit_post', $post_id );
	}

	/**
	 * The lowest capability that opens the News Distribution menu.
	 *
	 * `edit_posts` when authors may open Article check, otherwise `edit_others_posts`.
	 * Each tab then checks its own permission.
	 *
	 * @return string
	 */
	public function menu_capability(): string {
		return 'authors_own' === $this->settings->get( 'access.article_check', 'editors' ) ? 'edit_posts' : 'edit_others_posts';
	}

	/**
	 * The lowest WordPress capability that can hold a permission, for registering a menu item.
	 *
	 * This only decides who sees the menu entry. The screen still asks can() for
	 * the permission itself.
	 *
	 * @param string $permission One of the class constants.
	 * @return string
	 */
	public function capability_for( string $permission ): string {
		switch ( $permission ) {
			case self::VIEW:
				return 'edit_others_posts';
			case self::VIEW_OWN:
				return $this->menu_capability();
			case self::MANAGE:
				return $this->manage_capability();
			case self::OVERRIDE:
				return $this->override_capability();
			case self::AI:
				return 'edit_posts';
			case self::NETWORK:
				return 'manage_network_options';
			case self::ADMIN:
			default:
				return 'manage_options';
		}
	}

	/**
	 * The capability behind the "manage" permission.
	 *
	 * @return string
	 */
	public function manage_capability(): string {
		return 'editors' === $this->settings->get( 'access.manage', 'admins' ) ? 'edit_others_posts' : 'manage_options';
	}

	/**
	 * The capability behind "override" for the pre-publish gate.
	 *
	 * @return string
	 */
	public function override_capability(): string {
		return 'admins' === $this->settings->get( 'access.gate_override', 'editors' ) ? 'manage_options' : 'edit_others_posts';
	}

	/**
	 * Whether the person sees only their own articles in Article check.
	 *
	 * @param int|null $user_id Person, or the signed-in user.
	 * @return bool
	 */
	public function sees_only_own( ?int $user_id = null ): bool {
		$user_id = null === $user_id ? get_current_user_id() : $user_id;

		return $user_id > 0 && ! user_can( $user_id, 'edit_others_posts' );
	}

	/**
	 * People who can be handed a fix under the current "See Article check" choice.
	 *
	 * @return \WP_User[]
	 */
	public function assignable_users(): array {
		$choice = (string) $this->settings->get( 'access.article_check', 'editors' );

		if ( 'authors_own' === $choice ) {
			$capability = 'edit_posts';
		} elseif ( 'admins' === $choice ) {
			$capability = 'manage_options';
		} else {
			$capability = 'edit_others_posts';
		}

		$users = array();

		foreach ( get_users( array( 'fields' => 'all' ) ) as $user ) {
			if ( $user instanceof \WP_User && user_can( $user, $capability ) ) {
				$users[] = $user;
			}
		}

		return $users;
	}

	/**
	 * Stops the request with WordPress's own refusal when a permission is missing.
	 *
	 * The message is the same whether the screen exists or not, and nothing from
	 * the screen has been loaded by then.
	 *
	 * @param string   $permission Permission name.
	 * @param int|null $post_id    Article, when the permission depends on one.
	 * @return void
	 */
	public function require_or_die( string $permission, ?int $post_id = null ): void {
		if ( $this->can( $permission, $post_id ) ) {
			return;
		}

		$this->deny();
	}

	/**
	 * Ends the request with a 403 and a link back.
	 *
	 * @return void
	 */
	public function deny(): void {
		wp_die(
			esc_html__( 'Sorry, you are not allowed to access this page.', 'estasoft-news-distribution' ),
			'',
			array(
				'response'  => 403,
				'back_link' => true,
			)
		);
	}

	/**
	 * "See Article check" for one person and, optionally, one article.
	 *
	 * @param int      $user_id Person ID.
	 * @param int|null $post_id Article ID.
	 * @return bool
	 */
	private function can_view_article_check( int $user_id, ?int $post_id ): bool {
		$choice = (string) $this->settings->get( 'access.article_check', 'editors' );

		if ( 'admins' === $choice ) {
			return user_can( $user_id, 'manage_options' );
		}

		if ( user_can( $user_id, 'edit_others_posts' ) ) {
			return null === $post_id || user_can( $user_id, 'edit_post', $post_id );
		}

		if ( 'authors_own' !== $choice || ! user_can( $user_id, 'edit_posts' ) ) {
			return false;
		}

		if ( null === $post_id ) {
			return true;
		}

		$post = get_post( $post_id );

		return $post instanceof \WP_Post
			&& (int) $post->post_author === $user_id
			&& user_can( $user_id, 'edit_post', $post_id );
	}
}
