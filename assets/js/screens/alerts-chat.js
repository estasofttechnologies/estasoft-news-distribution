/*
 * Settings, Alerts: the two "Send test message" buttons (Slack and Microsoft Teams).
 *
 * Each button is a submit button of the settings form and works without this script: the page
 * posts, sends the test and comes back. The script only sends the test without leaving the page,
 * so what you typed and have not saved stays where it is. It posts the service's name and nothing
 * else, to a route that tests the address that is already saved.
 */
( function () {
	'use strict';

	var cfg = window.estasoftND || { rest: '', nonce: '' };
	var data = ( window.estasoftNDScreens || {} )[ 'alerts-chat' ] || {};
	var text = data.text || {};
	var root = document.getElementById( 'estasoft-nd-app' );

	if ( ! root || ! window.fetch || ! cfg.rest ) {
		return; // The buttons post the form the plain way.
	}

	function show( slot, html, message ) {
		if ( ! slot ) {
			return;
		}

		if ( html ) {
			slot.innerHTML = html;
		} else {
			slot.textContent = message || '';
		}
	}

	function run( button ) {
		var service = button.getAttribute( 'data-chat-test' );
		var slot = document.getElementById( 'estasoft-nd-' + service + '-result' );
		var label = button.textContent;

		button.disabled = true;
		button.setAttribute( 'aria-busy', 'true' );
		button.textContent = text.working || '…';

		window.fetch( cfg.rest + 'settings/alerts/test-chat', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce
			},
			body: JSON.stringify( { service: service } )
		} ).then( function ( response ) {
			return response.json().then( function ( body ) {
				return { ok: response.ok, body: body };
			} );
		} ).then( function ( result ) {
			if ( ! result.ok ) {
				show( slot, '', ( result.body && result.body.message ) || text.offline || '' );
				return;
			}

			show( slot, result.body.html, result.body.message );
		} ).catch( function () {
			show( slot, '', text.offline || '' );
		} ).then( function () {
			button.disabled = false;
			button.removeAttribute( 'aria-busy' );
			button.textContent = label;
		} );
	}

	root.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '[data-chat-test]' ) : null;

		if ( ! button ) {
			return;
		}

		event.preventDefault();

		if ( ! button.disabled ) {
			run( button );
		}
	} );
}() );
