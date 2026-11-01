/*
 * Settings, Image credits: look through the library, fill photos from their files, and undo.
 * Plain script, no build step. Nothing here runs until a button is pressed; the settings above save as usual.
 */
( function () {
	'use strict';

	var root = document.querySelector( '[data-credit-bulk]' );
	var cfg = window.estasoftND;
	var screen = ( window.estasoftNDScreens || {} )[ 'image-credits' ] || {};
	var text = screen.text || {};

	if ( ! root || ! cfg ) {
		return;
	}

	var list = root.querySelector( '[data-credit-list]' );
	var intro = root.querySelector( '[data-credit-intro]' );
	var status = root.querySelector( '[data-credit-status]' );
	var note = root.querySelector( '[data-credit-note]' );
	var scanButton = root.querySelector( '[data-credit-scan]' );
	var fillButton = root.querySelector( '[data-credit-fill]' );
	var allButton = root.querySelector( '[data-credit-all]' );
	var total = parseInt( root.getAttribute( 'data-total' ) || '0', 10 );
	var scanned = parseInt( root.getAttribute( 'data-scanned' ) || '0', 10 );
	var busy = false;
	var batches = [];

	function fmt( template, values ) {
		var index = 0;

		return String( template ).replace( /%(\d\$)?s/g, function () {
			var value = values[ index ];

			index += 1;

			return String( value );
		} );
	}

	function call( method, path, body ) {
		return window.fetch( cfg.rest + path, {
			method: method,
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce
			},
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( response ) {
			return response.json().then( function ( json ) {
				if ( ! response.ok ) {
					throw new Error( ( json && json.message ) || text.failed );
				}

				return json;
			} );
		} );
	}

	function say( message, undo ) {
		status.hidden = false;
		status.textContent = message;

		if ( undo ) {
			var button = document.createElement( 'button' );

			button.type = 'button';
			button.className = 'estasoft-nd-link-button';
			button.setAttribute( 'data-credit-undo', '' );
			button.textContent = text.undo;
			status.appendChild( document.createTextNode( ' ' ) );
			status.appendChild( button );
		}
	}

	function checkedRows() {
		return Array.prototype.slice.call( root.querySelectorAll( '[data-credit-row]:checked' ) ).filter( function ( box ) {
			return box.value !== '';
		} );
	}

	function updateButtons() {
		var count = checkedRows().length;

		scanButton.hidden = false;
		fillButton.hidden = total < 1;
		allButton.hidden = total < 1;
		fillButton.disabled = count < 1;
		fillButton.textContent = fmt( text.fill_n, [ count ] );
		allButton.textContent = fmt( text.all_n, [ total ] );
	}

	function row( item ) {
		var blank = list.querySelector( '[data-credit-blank]' );
		var template = blank ? blank.closest( '[role="row"]' ) : null;

		if ( ! template ) {
			return null;
		}

		var copy = template.cloneNode( true );
		var box = copy.querySelector( 'input' );
		var label = copy.querySelector( 'label' );
		var cells = copy.querySelectorAll( '[role="cell"]' );

		label.removeAttribute( 'data-credit-blank' );
		label.setAttribute( 'for', 'estasoft-nd-photo-' + item.id );
		box.id = 'estasoft-nd-photo-' + item.id;
		box.value = String( item.id );
		box.checked = true;
		copy.querySelector( 'code' ).textContent = item.file;
		cells[ 2 ].firstChild.textContent = item.in_file;

		return copy;
	}

	function render( result ) {
		var template = list.querySelector( '[data-credit-blank]' );
		var holder = template ? template.closest( '[role="row"]' ) : null;

		total = result.total;
		scanned = result.at;

		if ( holder ) {
			Array.prototype.slice.call( holder.parentNode.querySelectorAll( '[role="row"]' ) ).forEach( function ( existing ) {
				if ( existing !== holder && ! existing.classList.contains( 'estasoft-nd-grid-row--head' ) ) {
					existing.parentNode.removeChild( existing );
				}
			} );

			result.items.forEach( function ( item ) {
				var copy = row( item );

				if ( copy ) {
					holder.parentNode.insertBefore( copy, holder );
				}
			} );
		}

		list.hidden = total < 1;

		if ( scanned === 0 ) {
			// Never looked through: the server's own sentence stays.
			return updateButtons();
		}

		intro.textContent = total === 0 ? text.none : ( total === 1 ? text.intro_one : fmt( text.intro, [ total ] ) );
		note.textContent = total === 0 ? '' : fmt( text.showing, [ result.items.length, total ] );
		updateButtons();
	}

	function refresh() {
		return call( 'GET', 'media/credit/candidates?limit=10' ).then( render );
	}

	function guard( work ) {
		if ( busy ) {
			return;
		}

		busy = true;
		scanButton.disabled = fillButton.disabled = allButton.disabled = true;

		work().catch( function ( error ) {
			say( ( error && error.message ) || text.failed );
		} ).then( function () {
			busy = false;
			scanButton.disabled = false;
			updateButtons();
		} );
	}

	function scan( offset ) {
		return call( 'POST', 'media/credit/scan', { offset: offset, limit: 100 } ).then( function ( result ) {
			if ( ! result.done ) {
				say( fmt( text.scanned, [ result.scanned, result.total ] ) );

				return scan( result.next );
			}

			return refresh().then( function () {
				status.hidden = true;
			} );
		} );
	}

	function filled( photos ) {
		say( photos === 1 ? text.filled_1 : fmt( text.filled, [ photos ] ), batches.length > 0 );
	}

	function fillSelected() {
		var ids = checkedRows().map( function ( box ) {
			return parseInt( box.value, 10 );
		} );

		say( text.filling );

		return call( 'POST', 'media/credit/fill', { ids: ids } ).then( function ( result ) {
			batches = result.batch ? [ result.batch ] : [];

			return refresh().then( function () {
				filled( result.photos );
			} );
		} );
	}

	function fillAll() {
		var photos = 0;

		batches = [];
		say( text.filling );

		function step() {
			return call( 'POST', 'media/credit/fill', { all: true } ).then( function ( result ) {
				photos += result.photos;

				if ( result.photos > 0 ) {
					batches.push( result.batch );
				}

				if ( result.remaining > 0 && result.photos > 0 ) {
					say( text.filling + ' ' + photos );

					return step();
				}

				return refresh().then( function () {
					filled( photos );
				} );
			} );
		}

		return step();
	}

	function undo() {
		var pending = batches.slice();
		var restored = 0;

		function step() {
			if ( ! pending.length ) {
				batches = [];

				return refresh().then( function () {
					say( fmt( text.undone, [ restored ] ) );
				} );
			}

			return call( 'POST', 'media/credit/undo', { batch: pending.shift() } ).then( function ( result ) {
				restored += result.restored;

				return step();
			} );
		}

		return step();
	}

	scanButton.addEventListener( 'click', function () {
		guard( function () {
			say( text.scanning );

			return scan( 0 );
		} );
	} );

	fillButton.addEventListener( 'click', function () {
		guard( fillSelected );
	} );

	allButton.addEventListener( 'click', function () {
		guard( fillAll );
	} );

	root.addEventListener( 'change', function ( event ) {
		if ( event.target && event.target.hasAttribute( 'data-credit-row' ) ) {
			updateButtons();
		}
	} );

	root.addEventListener( 'click', function ( event ) {
		if ( event.target && event.target.hasAttribute( 'data-credit-undo' ) ) {
			guard( undo );
		}
	} );

	updateButtons();
}() );
