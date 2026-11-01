/*
 * Outlets page: "Import to Medium" copies the article's address as the link opens Medium's import page.
 *
 * The link works without this script: it opens the import page, and the address
 * is on the story's own row to copy by hand. The script only saves the copying.
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
				if ( document.execCommand( 'copy' ) ) {
					resolve();
				} else {
					reject( new Error( 'copy' ) );
				}
			} catch ( error ) {
				reject( error );
			} finally {
				document.body.removeChild( area );
			}
		} );
	}

	root.addEventListener( 'click', function ( event ) {
		var link = event.target && event.target.closest ? event.target.closest( 'a[data-copy]' ) : null;

		if ( ! link ) {
			return;
		}

		var value = link.getAttribute( 'data-copy' );
		var done = link.getAttribute( 'data-copy-done' ) || '';

		if ( ! value ) {
			return;
		}

		copy( value ).then(
			function () {
				live.textContent = '';
				window.setTimeout( function () {
					live.textContent = done;
				}, 50 );
			},
			function () {
				live.textContent = '';
			}
		);
	} );
}() );
