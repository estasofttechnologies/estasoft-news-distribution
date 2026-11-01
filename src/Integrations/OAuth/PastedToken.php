<?php
/**
 * A token the person pasted in, sent as it is.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Integrations\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Yandex Webmaster accepts an OAuth access token the person made in their own Yandex account. It
 * is used as pasted, with no request of its own.
 */
final class PastedToken implements CredentialSource {

	private string $token;

	/**
	 * Creates the source.
	 *
	 * @param string $token The token, as saved.
	 */
	public function __construct( string $token ) {
		$this->token = trim( $token );
	}

	public function kind(): string {
		return 'token';
	}

	public function account(): string {
		return '';
	}

	public function bearer( Transport $transport ): array {
		unset( $transport );

		return array(
			'token' => $this->token,
			'error' => '' === $this->token ? __( 'Paste the access credential first.', 'estasoft-news-distribution' ) : '',
		);
	}
}
