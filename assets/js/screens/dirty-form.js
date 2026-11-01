/*
 * The save bar for a form that posts the plain way (Listings, Site verification).
 * It stays hidden until something changes, then offers Discard. The form itself
 * posts to admin-post.php, with or without this script.
 */
( function () {
	'use strict';

	var root = document.getElementById( 'estasoft-nd-app' );

	if ( ! root ) {
		return;
	}

	Array.prototype.slice.call( root.querySelectorAll( 'form[data-dirty-form]' ) ).forEach( function ( form ) {
		var bar = form.querySelector( '[data-savebar]' );
		var state = form.querySelector( '[data-savebar-state]' );
		var discard = form.querySelector( '[data-savebar-discard]' );
		var saving = false;

		function snapshot() {
			var parts = [];

			new FormData( form ).forEach( function ( value, key ) {
				if ( typeof value === 'string' && key !== '_wpnonce' ) {
					parts.push( key + '=' + value );
				}
			} );

			return parts.join( '&' );
		}

		var baseline = snapshot();

		function refresh() {
			var dirty = snapshot() !== baseline;

			if ( bar ) {
				bar.classList.toggle( 'is-dirty', dirty );
			}
			if ( state ) {
				state.hidden = ! dirty;
			}
			if ( discard ) {
				discard.hidden = ! dirty;
			}

			return dirty;
		}

		form.addEventListener( 'input', refresh );
		form.addEventListener( 'change', refresh );
		form.addEventListener( 'submit', function () {
			saving = true;
		} );

		if ( discard ) {
			discard.addEventListener( 'click', function () {
				form.reset();
				refresh();
			} );
		}

		window.addEventListener( 'beforeunload', function ( event ) {
			if ( ! saving && refresh() ) {
				event.preventDefault();
				event.returnValue = '';
			}
		} );

		refresh();
	} );
}() );
