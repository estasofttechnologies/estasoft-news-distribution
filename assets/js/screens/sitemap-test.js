/*
 * "Test sitemap": asks the plugin to build and check the sitemap for every
 * language (POST /channels/{id}/validate) and prints the answer in place. Without
 * this script the button is a link that does the same check on the server and
 * reloads the page.
 */
( function () {
	'use strict';

	var cfg = window.estasoftND || { rest: '', nonce: '' };
	var data = ( window.estasoftNDScreens || {} )[ 'sitemap-test' ] || {};
	var text = data.text || {};
	var button = document.querySelector( '[data-test-sitemap]' );
	var out = document.getElementById( 'estasoft-nd-test-result' );

	if ( ! button || ! out || ! window.fetch || ! cfg.rest ) {
		return;
	}

	function render( kind, lead, lines ) {
		out.textContent = '';
		out.hidden = false;
		out.className = 'estasoft-nd-message estasoft-nd-message--' + kind + ' estasoft-nd-test-result';

		var strong = document.createElement( 'strong' );
		strong.textContent = lead;
		out.appendChild( strong );

		if ( lines && lines.length ) {
			var list = document.createElement( 'ul' );

			lines.forEach( function ( line ) {
				var item = document.createElement( 'li' );
				item.textContent = line;
				list.appendChild( item );
			} );

			out.appendChild( list );
		}
	}

	button.addEventListener( 'click', function ( event ) {
		event.preventDefault();

		if ( button.getAttribute( 'aria-disabled' ) === 'true' ) {
			return;
		}

		button.setAttribute( 'aria-disabled', 'true' );
		render( 'info', text.running || '', [] );

		window.fetch( cfg.rest + button.getAttribute( 'data-endpoint' ), {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: '{}'
		} ).then( function ( response ) {
			return response.json().then( function ( body ) {
				return { ok: response.ok, body: body };
			} );
		} ).then( function ( result ) {
			if ( ! result.ok ) {
				render( 'danger', ( result.body && result.body.message ) || text.failed || '', [] );
				return;
			}

			var lines = [];

			( result.body.languages || [] ).forEach( function ( row ) {
				( row.problems || [] ).forEach( function ( problem ) {
					lines.push( String( row.language ).toUpperCase() + ': ' + problem );
				} );
			} );

			if ( lines.length ) {
				render( 'warning', text.problems || '', lines );
			} else {
				render( 'success', text.passed || '', [] );
			}
		} ).catch( function () {
			render( 'danger', text.offline || '', [] );
		} ).then( function () {
			button.removeAttribute( 'aria-disabled' );
		} );
	} );
}() );
