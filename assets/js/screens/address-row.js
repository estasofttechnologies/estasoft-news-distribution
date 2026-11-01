/*
 * Copy buttons for addresses and values (Admin\Ui\AddressRow).
 *
 * The address is plain text beside the button, so a person can select it
 * without this script. The script only makes the button copy it.
 */
( function () {
	'use strict';

	var root = document.getElementById( 'estasoft-nd-app' );

	if ( ! root ) {
		return;
	}

	var live = document.createElement( 'span' );
	live.className = 'estasoft-nd-sr';
	live.setAttribute( 'role', 'status' );
	root.appendChild( live );

	function copy( value ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( value );
		}

		return new Promise( function ( resolve, reject ) {
			var area = document.createElement( 'textarea' );
			area.value = value;
			area.setAttribute( 'readonly', '' );
			area.className = 'estasoft-nd-sr';
			document.body.appendChild( area );
			area.select();

			try {
				document.execCommand( 'copy' ) ? resolve() : reject( new Error( 'copy' ) );
			} catch ( error ) {
				reject( error );
			} finally {
				document.body.removeChild( area );
			}
		} );
	}

	function flash( button ) {
		var label = button.lastChild;
		var original = label ? label.textContent : '';
		var done = button.getAttribute( 'data-copied-label' ) || '';

		if ( label && done ) {
			label.textContent = done;
		}

		live.textContent = done;

		window.setTimeout( function () {
			if ( label ) {
				label.textContent = original;
			}
			live.textContent = '';
		}, 2000 );
	}

	root.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '[data-copy]' ) : null;

		if ( ! button ) {
			return;
		}

		copy( button.getAttribute( 'data-copy' ) || '' ).then( function () {
			flash( button );
		}, function () {
			// The address stays selectable, so nothing else to do.
		} );
	} );
}() );
