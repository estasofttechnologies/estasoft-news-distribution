<?php
/**
 * Who may approve an AI translation, per language.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Ai;

use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * A language needs a signed-off reviewer before an AI draft into it can be published. The owner
 * signs a person off for a language in Settings, AI assistant, Who can approve a translation. The
 * reviewer must still be an editor. When no one is signed off for a language, a network can ask for
 * two approvers instead (`Policy::second_approver()`); without that, the draft cannot be published.
 */
final class Reviewers {

	private Plugin $plugin;

	/**
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The languages an AI draft can go into: the site's languages except its default one.
	 *
	 * @return string[]
	 */
	public function languages(): array {
		$resolver = $this->plugin->languages();
		$default  = (string) $resolver->default_language();
		$found    = array();

		foreach ( $resolver->languages() as $code ) {
			if ( (string) $code !== $default ) {
				$found[] = (string) $code;
			}
		}

		/**
		 * Changes the languages an AI draft can go into (internal; tests use it).
		 *
		 * @param string[] $found Language codes.
		 */
		$filtered = apply_filters( 'estasoft_nd_internal_ai_languages', $found );

		return is_array( $filtered ) ? array_values( array_map( 'strval', $filtered ) ) : $found;
	}

	/**
	 * Whether a language can have drafts into it at all: only languages the site writes in.
	 *
	 * @param string $language Language code.
	 * @return bool
	 */
	public function accepts( string $language ): bool {
		return in_array( $language, $this->languages(), true );
	}

	/**
	 * People who may be signed off: everyone who can edit other people's posts.
	 *
	 * @return array<int, string> User id => display name.
	 */
	public function candidates(): array {
		$users = get_users(
			array(
				'capability' => 'edit_others_posts',
				'number'     => 100,
				'orderby'    => 'display_name',
				'fields'     => array( 'ID', 'display_name' ),
			)
		);
		$out   = array();

		foreach ( $users as $user ) {
			$out[ (int) $user->ID ] = (string) $user->display_name;
		}

		return $out;
	}

	/**
	 * The people signed off for a language who still exist and can still edit.
	 *
	 * @param string $language Language code.
	 * @return array<int, string> User id => display name.
	 */
	public function signed_off( string $language ): array {
		$ids = $this->plugin->make( Config::class )->reviewers()[ $language ] ?? array();
		$out = array();

		foreach ( $ids as $id ) {
			$user = get_userdata( (int) $id );

			if ( $user instanceof \WP_User && user_can( $user, 'edit_others_posts' ) ) {
				$out[ (int) $id ] = (string) $user->display_name;
			}
		}

		return $out;
	}

	/**
	 * Whether a person is signed off for a language.
	 *
	 * @param string $language Language code.
	 * @param int    $user_id  Person.
	 * @return bool
	 */
	public function is_signed_off( string $language, int $user_id ): bool {
		return isset( $this->signed_off( $language )[ $user_id ] );
	}

	/**
	 * What publishing a draft into a language needs.
	 *
	 * @param string $language Language code.
	 * @return array{ok: bool, approvals: int, mode: string}
	 */
	public function requirement( string $language ): array {
		if ( array() !== $this->signed_off( $language ) ) {
			return array(
				'ok'        => true,
				'approvals' => 1,
				'mode'      => 'reviewer',
			);
		}

		if ( $this->plugin->make( Policy::class )->second_approver() ) {
			return array(
				'ok'        => true,
				'approvals' => 2,
				'mode'      => 'two_approvers',
			);
		}

		return array(
			'ok'        => false,
			'approvals' => 0,
			'mode'      => 'none',
		);
	}

	/**
	 * Whether a person may approve a draft now, given who has approved already.
	 *
	 * @param string $language    Language code.
	 * @param int    $user_id     The person.
	 * @param int[]  $approved_by People who approved already.
	 * @return true|\WP_Error
	 */
	public function can_approve( string $language, int $user_id, array $approved_by ) {
		$need = $this->requirement( $language );

		if ( ! $need['ok'] ) {
			return new \WP_Error(
				'estasoft_nd_ai_no_reviewer',
				__( 'This language has no signed-off reviewer yet, so the draft cannot be published. Sign one off in Settings, AI assistant.', 'estasoft-news-distribution' ),
				array( 'status' => 409 )
			);
		}

		if ( in_array( $user_id, array_map( 'intval', $approved_by ), true ) ) {
			return new \WP_Error( 'estasoft_nd_ai_already_approved', __( 'You have already approved this draft.', 'estasoft-news-distribution' ), array( 'status' => 409 ) );
		}

		if ( 'reviewer' === $need['mode'] && ! $this->is_signed_off( $language, $user_id ) ) {
			return new \WP_Error(
				'estasoft_nd_ai_not_reviewer',
				__( 'Only a signed-off reviewer for this language can approve the draft.', 'estasoft-news-distribution' ),
				array( 'status' => 403 )
			);
		}

		if ( 'two_approvers' === $need['mode'] ) {
			$admins = 'admins' === $this->plugin->make( Policy::class )->second_approver_role();

			if ( ! user_can( $user_id, $admins ? 'manage_options' : 'edit_others_posts' ) ) {
				return new \WP_Error(
					'estasoft_nd_ai_not_reviewer',
					$admins ? __( 'Only an administrator can approve the draft.', 'estasoft-news-distribution' ) : __( 'Only an editor can approve the draft.', 'estasoft-news-distribution' ),
					array( 'status' => 403 )
				);
			}
		}

		return true;
	}
}
