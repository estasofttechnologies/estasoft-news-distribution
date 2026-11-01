<?php
/**
 * Posts an alert to a Microsoft Teams webhook.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Chat;

defined( 'ABSPATH' ) || exit;

/**
 * The body is the message with one Adaptive Card attachment, the shape Teams documents for a webhook made
 * in the Workflows app:
 *
 *     { "type": "message", "attachments": [ { "contentType": "application/vnd.microsoft.card.adaptive",
 *       "contentUrl": null, "content": { "$schema": "http://adaptivecards.io/schemas/adaptive-card.json",
 *       "type": "AdaptiveCard", "version": "1.2", "body": [ TextBlock, TextBlock ], "actions": [ Action.OpenUrl ] } } ] }
 *
 * UNVERIFIED: this shape is written from what the Teams documentation is known to describe. No request was
 * made to Teams while this was written, and the card version, the `actions` block and whether a particular
 * workflow accepts them were not checked. A workflow can also accept a message and fail afterwards, which
 * this plugin cannot see; the test message is how a person finds out.
 */
final class TeamsSender extends AbstractChatSender {

	public function service(): string {
		return 'teams';
	}

	public function label(): string {
		return 'Microsoft Teams';
	}

	/**
	 * The JSON body.
	 *
	 * @param ChatMessage $message The message.
	 * @return array<string, mixed>
	 */
	public function payload( ChatMessage $message ): array {
		$body = array(
			array(
				'type'   => 'TextBlock',
				'text'   => $message->title(),
				'weight' => 'Bolder',
				'size'   => 'Medium',
				'wrap'   => true,
			),
			array(
				'type' => 'TextBlock',
				'text' => $message->text(),
				'wrap' => true,
			),
		);

		$card = array(
			'$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
			'type'    => 'AdaptiveCard',
			'version' => '1.2',
			'body'    => $body,
		);

		if ( $message->has_link() ) {
			$card['actions'] = array(
				array(
					'type'  => 'Action.OpenUrl',
					'title' => $message->link_label(),
					'url'   => $message->link_url(),
				),
			);
		}

		return array(
			'type'        => 'message',
			'attachments' => array(
				array(
					'contentType' => 'application/vnd.microsoft.card.adaptive',
					'contentUrl'  => null,
					'content'     => $card,
				),
			),
		);
	}
}
