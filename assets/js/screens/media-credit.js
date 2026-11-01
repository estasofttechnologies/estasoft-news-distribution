/*
 * Media library: the "Fill from the photo file" button in the credit and rights box.
 *
 * It calls POST /media/{id}/credit, which fills only the empty fields and never replaces what a person typed,
 * then shows the answer in the fields. Plain script, no build step. The box works without it.
 */
( function () {
	'use strict';

	var cfg = window.estasoftNDMediaCredit;

	if ( ! cfg ) {
		return;
	}

	var text = cfg.text || {};

	// The media modal draws its box after this script runs, so the buttons are shown when they appear.
	function reveal( scope ) {
		scope.querySelectorAll( '[data-credit-fill][hidden]' ).forEach( function ( button ) {
			button.hidden = false;
		} );
	}

	function setField( box, name, value ) {
		var field = box.querySelector( '[data-credit-field="' + name + '"]' );

		if ( ! field || field.value === value ) {
			return;
		}

		field.value = value;
		field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		field.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	function mark( box, name ) {
		var field = box.querySelector( '[data-credit-field="' + name + '"]' );
		var label = field ? field.parentNode.querySelector( 'label' ) : null;

		if ( ! label || label.parentNode.querySelector( '.estasoft-nd-media__from' ) ) {
			return;
		}

		var tag = document.createElement( 'span' );

		tag.className = 'estasoft-nd-media__from';
		tag.textContent = text.from;
		label.insertAdjacentElement( 'afterend', tag );
		label.insertAdjacentText( 'afterend', ' ' );
	}

	function fill( box, button ) {
		var result = box.querySelector( '[data-credit-result]' );
		var id = box.getAttribute( 'data-credit-box' );

		button.disabled = true;
		result.textContent = text.working;

		window.fetch( cfg.rest + 'media/' + encodeURIComponent( id ) + '/credit', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce
			}
		} ).then( function ( response ) {
			return response.json().then( function ( json ) {
				if ( ! response.ok ) {
					throw new Error( ( json && json.message ) || text.failed );
				}

				return json;
			} );
		} ).then( function ( json ) {
			// A field the file filled is shown; a field a person typed is not touched by this.
			json.filled.forEach( function ( name ) {
				setField( box, name, json.current[ name ] );
				mark( box, name );
			} );

			var warning = box.querySelector( '[data-credit-warning]' );

			if ( warning && json.current.credit ) {
				warning.hidden = true;
			}

			result.textContent = json.message;
		} ).catch( function ( error ) {
			result.textContent = ( error && error.message ) || text.failed;
		} ).then( function () {
			button.disabled = false;
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '[data-credit-fill]' ) : null;

		if ( ! button || ! button.closest( '[data-credit-box]' ) ) {
			return;
		}

		event.preventDefault();
		fill( button.closest( '[data-credit-box]' ), button );
	} );

	reveal( document );

	if ( window.MutationObserver ) {
		new window.MutationObserver( function () {
			reveal( document );
		} ).observe( document.body, { childList: true, subtree: true } );
	}
}() );
