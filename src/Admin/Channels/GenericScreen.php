<?php
/**
 * The page of a channel that has no screen of its own.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Channels;

use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\Html;

defined( 'ABSPATH' ) || exit;

/**
 * Built from the channel's `fields()`: the on/off switch and every field in
 * order. A channel that needs more writes its own `<Name>Screen`.
 */
final class GenericScreen extends AbstractChannelScreen {

	/**
	 * Never matches a channel; this screen is chosen by the page, not by id.
	 *
	 * @return string
	 */
	public static function channel_id(): string {
		return '';
	}

	/**
	 * @param string $tab The tab slug.
	 * @return string
	 */
	public function render( string $tab ): string {
		unset( $tab );

		$rows = array( 'enabled' );

		foreach ( array_keys( $this->channel->fields() ) as $name ) {
			$rows[] = (string) $name;
		}

		$cards = Card::flush(
			'settings',
			__( 'Settings', 'estasoft-news-distribution' ),
			'',
			$this->rows( $rows )
		);

		$note = $this->channel->gate_note();

		if ( '' !== $note ) {
			$cards .= Html::tag( 'div', array( 'class' => 'estasoft-nd-notes' ), Card::cannot( array( $note ) ) );
		}

		return $this->form( $cards );
	}
}
