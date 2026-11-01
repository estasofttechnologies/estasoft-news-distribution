/*
 * Apple News, SmartNews and News Break pages.
 *
 * Every form posts the plain way and every link works without this script. The
 * script adds: the Test feed button (answers in place), the save bar that
 * appears when something changed, the chosen file's name, the count of the site
 * description, the chips of the News Break rules, rows that depend on another
 * setting and the live preview of the readiness report.
 */
( function () {
	'use strict';

	var root = document.getElementById( 'estasoft-nd-app' );

	if ( ! root ) {
		return;
	}

	var cfg = window.estasoftND || { rest: '', nonce: '' };
	var data = ( window.estasoftNDScreens || {} ).partner || {};
	var text = data.text || {};

	function all( selector, scope ) {
		return Array.prototype.slice.call( ( scope || root ).querySelectorAll( selector ) );
	}

	/* ---------- Test feed ---------- */

	( function () {
		var button = root.querySelector( '[data-test-feed]' );
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

	/* ---------- The save bar ---------- */

	all( 'form[data-partner-form]' ).forEach( function ( form ) {
		var bar = form.querySelector( '[data-savebar]' );
		var state = form.querySelector( '[data-savebar-state]' );
		var discard = form.querySelector( '[data-savebar-discard]' );
		var saving = false;

		function snapshot() {
			var parts = [];

			new FormData( form ).forEach( function ( value, key ) {
				if ( key === '_wpnonce' || key === '_wp_http_referer' ) {
					return;
				}

				// A chosen file counts as a change; its bytes do not need comparing.
				parts.push( key + '=' + ( typeof value === 'string' ? value : 'file:' + value.name + ':' + value.size ) );
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
		form.addEventListener( 'estasoft-nd-chips', refresh );
		form.addEventListener( 'submit', function () {
			saving = true;
		} );

		if ( discard ) {
			// Chips and chosen files are not form state a reset can undo, so the page is loaded again.
			discard.addEventListener( 'click', function () {
				saving = true;
				window.location.reload();
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

	/* ---------- Logos: show the chosen file's name ---------- */

	all( '.estasoft-nd-drop__file' ).forEach( function ( input ) {
		input.addEventListener( 'change', function () {
			var box = input.closest( '.estasoft-nd-drop' );
			var name = box ? box.querySelector( '[data-file-name]' ) : null;
			var file = input.files && input.files[ 0 ];

			if ( ! box || ! name || ! file ) {
				return;
			}

			name.textContent = file.name;

			var image = box.querySelector( '.estasoft-nd-drop__preview img' );

			if ( image && window.URL && window.URL.createObjectURL ) {
				image.src = window.URL.createObjectURL( file );
			}
		} );
	} );

	/* ---------- The site description: how many characters are used ---------- */

	( function () {
		var input = root.querySelector( 'input[name="estasoft_nd[description]"]' );
		var help = input ? document.getElementById( input.id + '-help' ) : null;

		if ( ! input || ! help || ! text.describe ) {
			return;
		}

		input.addEventListener( 'input', function () {
			help.textContent = text.describe.replace( '{used}', String( Array.from( input.value.trim() ).length ) );
		} );
	}() );

	/* ---------- Rows that depend on another setting ---------- */

	all( 'form[data-partner-form]' ).forEach( function ( form ) {
		var rows = all( '[data-show-if]', form );

		if ( ! rows.length ) {
			return;
		}

		function update() {
			rows.forEach( function ( row ) {
				var rule;

				try {
					rule = JSON.parse( row.getAttribute( 'data-show-if' ) );
				} catch ( error ) {
					return;
				}

				row.hidden = ! Object.keys( rule ).every( function ( name ) {
					var control = form.elements[ 'estasoft_nd[' + name + ']' ];

					return control && String( control.value ) === String( rule[ name ] );
				} );
			} );
		}

		form.addEventListener( 'change', update );
		update();
	} );

	/* ---------- Chips of categories and tags ---------- */

	all( '[data-chips]' ).forEach( function ( box ) {
		var field = box.getAttribute( 'data-chips' );
		var list = box.querySelector( '.estasoft-nd-chips' );
		var add = box.querySelector( '[data-chips-add]' );
		var form = box.closest( 'form' );

		if ( ! list || ! add ) {
			return;
		}

		function announce() {
			if ( form ) {
				form.dispatchEvent( new Event( 'estasoft-nd-chips', { bubbles: true } ) );
			}
		}

		function makeChip( value, label ) {
			var item = document.createElement( 'li' );
			var wrap = document.createElement( 'label' );
			var check = document.createElement( 'input' );
			var name = document.createElement( 'span' );
			var cross = document.createElement( 'span' );
			var mark = document.createElement( 'span' );
			var hint = document.createElement( 'span' );

			wrap.className = 'estasoft-nd-chip';
			check.type = 'checkbox';
			check.name = 'estasoft_nd[' + field + '][]';
			check.value = value;
			check.checked = true;
			name.className = 'estasoft-nd-chip__text';
			name.textContent = label;
			cross.className = 'estasoft-nd-chip__x';
			mark.setAttribute( 'aria-hidden', 'true' );
			mark.textContent = '×';
			hint.className = 'estasoft-nd-sr';
			hint.textContent = text.remove || 'Remove';
			cross.appendChild( mark );
			cross.appendChild( hint );
			wrap.appendChild( check );
			wrap.appendChild( name );
			wrap.appendChild( cross );
			item.appendChild( wrap );

			return item;
		}

		function has( value ) {
			return all( 'input[type="checkbox"]', list ).some( function ( check ) {
				return check.value.toLowerCase() === value.toLowerCase();
			} );
		}

		if ( add.tagName === 'SELECT' ) {
			add.addEventListener( 'change', function () {
				var option = add.options[ add.selectedIndex ];

				if ( ! option || option.value === '' ) {
					return;
				}

				list.appendChild( makeChip( option.value, option.textContent ) );
				add.removeChild( option );
				add.selectedIndex = 0;
				announce();
			} );
		} else {
			add.addEventListener( 'keydown', function ( event ) {
				if ( event.key !== 'Enter' ) {
					return;
				}

				event.preventDefault();

				add.value.split( /[,\n]+/ ).forEach( function ( tag ) {
					tag = tag.trim();

					if ( tag !== '' && ! has( tag ) ) {
						list.appendChild( makeChip( tag, tag ) );
					}
				} );

				add.value = '';
				announce();
			} );
		}

		// Unticking a chip takes it out of the list, and a category goes back into the list to add.
		list.addEventListener( 'change', function ( event ) {
			var check = event.target;

			if ( ! check || check.type !== 'checkbox' || check.checked ) {
				return;
			}

			var item = check.closest( 'li' );
			var label = item ? item.querySelector( '.estasoft-nd-chip__text' ) : null;

			if ( add.tagName === 'SELECT' && label ) {
				var option = document.createElement( 'option' );
				option.value = check.value;
				option.textContent = label.textContent;
				add.appendChild( option );

				var first = add.options[ 0 ];
				var rest = Array.prototype.slice.call( add.options, 1 ).sort( function ( a, b ) {
					return a.textContent.localeCompare( b.textContent );
				} );

				rest.forEach( function ( entry ) {
					add.appendChild( entry );
				} );
				add.insertBefore( first, add.firstChild );
			}

			if ( item ) {
				item.remove();
			}

			add.focus();
			announce();
		} );
	} );

	/* ---------- The readiness report: a live preview of what is included ---------- */

	( function () {
		var form = root.querySelector( 'form[data-report-form]' );

		if ( ! form ) {
			return;
		}

		all( '[data-report-toggle]', form ).forEach( function ( box ) {
			box.addEventListener( 'change', function () {
				var section = root.querySelector( '[data-report-section="' + box.getAttribute( 'data-report-toggle' ) + '"]' );

				if ( section ) {
					section.hidden = ! box.checked;
				}
			} );
		} );

		function contact() {
			var section = root.querySelector( '[data-report-section="contact"] p' );

			if ( ! section ) {
				return;
			}

			var parts = [];

			[ 'name', 'email' ].forEach( function ( kind ) {
				var input = form.querySelector( '[data-report-contact="' + kind + '"]' );

				if ( input && input.value.trim() !== '' ) {
					parts.push( input.value.trim() );
				}
			} );

			section.textContent = parts.join( ' · ' );
		}

		all( '[data-report-contact]', form ).forEach( function ( input ) {
			input.addEventListener( 'input', contact );
		} );
	}() );
}() );
