/*
 * Settings: Page labels, Republished content, Image credits and Video.
 * Plain script, no build step. Every screen works without it; it only makes the controls quicker.
 */
( function () {
	'use strict';

	var root = document.querySelector( '.estasoft-nd' );

	if ( ! root ) {
		return;
	}

	/** Tell the settings form something changed, so its save bar notices. */
	function changed( node ) {
		var form = node.closest ? node.closest( 'form' ) : null;

		if ( form ) {
			form.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}
	}

	/* ---------- A switch flips the word in front of its text ---------- */

	root.addEventListener( 'change', function ( event ) {
		var box = event.target;

		if ( ! box || ! box.classList || ! box.classList.contains( 'estasoft-nd-toggle' ) ) {
			return;
		}

		var label = box.closest( 'label' );
		var word = label ? label.querySelector( '[data-switch-word]' ) : null;
		var text = label ? label.querySelector( '[data-switch-text]' ) : null;

		if ( word ) {
			word.textContent = box.checked ? word.getAttribute( 'data-on' ) : word.getAttribute( 'data-off' );
		}

		if ( text ) {
			text.textContent = box.checked ? text.getAttribute( 'data-on' ) : text.getAttribute( 'data-off' );
		}
	} );

	/* ---------- Republished content: the Google News switch is locked while the handling is a noindex ---------- */

	var lockNote = root.querySelector( '[data-lock-note]' );
	var newsBox = root.querySelector( 'input[type=checkbox][name="estasoft_nd[exclude_news]"]' );

	function syncLock() {
		if ( ! newsBox ) {
			return;
		}

		var chosen = root.querySelector( 'input[type=radio][name="estasoft_nd[handling]"]:checked' );
		var locked = !! chosen && ( chosen.value === 'noindex' || chosen.value === 'news_noindex' );

		if ( locked ) {
			newsBox.checked = true;
		}

		newsBox.disabled = locked;

		if ( lockNote ) {
			lockNote.hidden = ! locked;
		}
	}

	if ( newsBox ) {
		root.addEventListener( 'change', function ( event ) {
			if ( event.target && event.target.name === 'estasoft_nd[handling]' ) {
				syncLock();
			}
		} );

		syncLock();
	}

	/* ---------- Republished content: the list of sources ---------- */

	var sources = root.querySelector( '[data-sources]' );

	if ( sources ) {
		var add = root.querySelector( '[data-add-source]' );
		var pendingRow = null;
		var next = parseInt( sources.getAttribute( 'data-next-index' ) || '0', 10 );

		if ( add ) {
			add.hidden = false;
		}

		sources.querySelectorAll( '[data-edit-source]' ).forEach( function ( link ) {
			link.hidden = false;
		} );

		// With no source in the list, the table's header would sit over nothing, so only the note shows.
		function refresh() {
			var rows = sources.querySelectorAll( '.estasoft-nd-grid-row:not(.estasoft-nd-grid-row--head)' );
			var count = 0;

			rows.forEach( function ( row ) {
				if ( ! row.querySelector( '[data-source-blank]' ) ) {
					count += 1;
				}
			} );

			sources.classList.toggle( 'is-empty', 0 === count );

			var empty = root.querySelector( '[data-sources-empty]' );

			if ( empty ) {
				empty.hidden = 0 !== count;
			}
		}

		refresh();

		// With a script, a row is text with an Edit button, and the blank row is hidden until Add a source.
		function rowOf( node ) {
			return node.closest( '[role="row"]' );
		}

		function renumber( row, index ) {
			row.querySelectorAll( '[name]' ).forEach( function ( input ) {
				input.name = input.name.replace( /\[sources\]\[\d+\]/, '[sources][' + index + ']' );
			} );
			row.querySelectorAll( '[id]' ).forEach( function ( node ) {
				node.id = node.id.replace( /estasoft-nd-source-\d+/, 'estasoft-nd-source-' + index );
			} );
			row.querySelectorAll( '[for]' ).forEach( function ( node ) {
				node.setAttribute( 'for', node.getAttribute( 'for' ).replace( /estasoft-nd-source-\d+/, 'estasoft-nd-source-' + index ) );
			} );
		}

		sources.addEventListener( 'click', function ( event ) {
			var edit = event.target.closest ? event.target.closest( '[data-edit-source]' ) : null;
			var del = event.target.closest ? event.target.closest( '[data-delete-source]' ) : null;

			if ( edit ) {
				event.preventDefault();

				var row = rowOf( edit );

				row.classList.add( 'is-editing' );

				var field = row.querySelector( 'input[type=text]' );

				if ( field ) {
					field.focus();
				}
			} else if ( del ) {
				pendingRow = rowOf( del );
			}
		} );

		if ( add ) {
			add.addEventListener( 'click', function () {
				var blank = sources.querySelector( '[data-source-blank]' );

				if ( ! blank ) {
					return;
				}

				var template = rowOf( blank );
				var row = template.cloneNode( true );
				next += 1;
				row.querySelectorAll( '[data-source-blank]' ).forEach( function ( cell ) {
					cell.removeAttribute( 'data-source-blank' );
				} );
				row.classList.add( 'is-editing' );

				// The copy keeps the template's number, and the template moves on, so no two rows share one.
				renumber( template, next );

				template.parentNode.insertBefore( row, template );
				refresh();

				var field = row.querySelector( 'input[type=text]' );

				if ( field ) {
					field.focus();
				}

				changed( row );
			} );
		}

		// The dialog's button removes the row; the source is gone from the list once the form is saved.
		var confirmButton = root.querySelector( '[data-confirm-delete-source]' );

		if ( confirmButton ) {
			confirmButton.addEventListener( 'click', function () {
				if ( pendingRow && pendingRow.parentNode ) {
					var parent = pendingRow.parentNode;

					parent.removeChild( pendingRow );
					refresh();
					changed( parent );
				}

				pendingRow = null;
			} );
		}

		// A script saves the form itself, so the plain "Delete" button (which posts) must not submit it.
		sources.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
		} );
	}
}() );
