/*
 * The News Distribution box in the classic editor: Check now shows "Checking..." while the page posts.
 *
 * The box works without this script. Check now is a plain form post; this only tells the person it is
 * working, and keeps a second press from posting twice. The button is switched back on if the page is
 * not left (the browser asked about unsaved changes and the person stayed) or is shown again from the
 * back button.
 */
( function () {
	'use strict';

	var form = document.getElementById( 'estasoft-nd-classic-check' );

	if ( ! form ) {
		return;
	}

	var buttons = Array.prototype.slice.call( document.querySelectorAll( 'button[form="estasoft-nd-classic-check"]' ) );
	var status = document.querySelector( '.estasoft-nd-cb [data-status]' );
	var timer = 0;

	function settle() {
		window.clearTimeout( timer );

		buttons.forEach( function ( button ) {
			if ( button.hasAttribute( 'data-was' ) ) {
				button.textContent = button.getAttribute( 'data-was' );
				button.removeAttribute( 'data-was' );
			}

			button.disabled = false;
			button.removeAttribute( 'aria-disabled' );
		} );

		if ( status && status.hasAttribute( 'data-busy' ) ) {
			status.removeAttribute( 'data-busy' );
			status.textContent = '';
		}
	}

	form.addEventListener( 'submit', function () {
		buttons.forEach( function ( button ) {
			var busy = button.getAttribute( 'data-checking' ) || '';

			button.setAttribute( 'data-was', button.textContent );
			button.setAttribute( 'aria-disabled', 'true' );

			if ( busy ) {
				button.textContent = busy;
			}

			// Disabled after the submit has started, so the form still posts.
			window.setTimeout( function () {
				button.disabled = true;
			}, 0 );
		} );

		if ( status ) {
			status.setAttribute( 'data-busy', '1' );
			status.textContent = buttons.length ? ( buttons[ 0 ].getAttribute( 'data-checking' ) || '' ) : '';
		}

		// The browser may ask about unsaved changes. If the person stays, the button must work again.
		timer = window.setTimeout( settle, 10000 );
	} );

	window.addEventListener( 'pageshow', function ( event ) {
		if ( event.persisted ) {
			settle();
		}
	} );
}() );
