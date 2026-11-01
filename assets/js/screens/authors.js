/*
 * Authors settings: drag and arrows for the order of the sources, and chips for the
 * names that are not people.
 *
 * The form works without this script. Each source has a position number that the
 * browser posts, and each name is a box that is ticked while the name is on the list.
 * The script hides those controls, adds the handle, the arrows and the "Add" button, and
 * keeps the numbers and boxes in step so the same data is posted either way.
 */
( function () {
	'use strict';

	var list = document.querySelector( '[data-sources]' );
	var chips = document.querySelector( '[data-chips]' );

	if ( ! list && ! chips ) {
		return;
	}

	var form = ( list || chips ).closest( 'form' );

	function changed( element ) {
		// The save bar listens for change events on the form.
		element.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	/* ---------- the order of the sources ---------- */

	function movable() {
		return Array.prototype.slice.call( list.querySelectorAll( '.estasoft-nd-source:not(.estasoft-nd-source--fixed)' ) );
	}

	function renumber() {
		var items = movable();

		items.forEach( function ( item, index ) {
			var select = item.querySelector( 'select' );
			var state = item.querySelector( '.estasoft-nd-source__state[data-kind="position"] .estasoft-nd-source__state-text' );
			var up = item.querySelector( '[data-move="up"]' );
			var down = item.querySelector( '[data-move="down"]' );
			var value = String( index + 1 );

			if ( select && select.value !== value ) {
				select.value = value;
				changed( select );
			}

			if ( state ) {
				var holder = state.parentNode;
				state.textContent = 0 === index ? holder.getAttribute( 'data-first' ) : holder.getAttribute( 'data-later' );
			}

			if ( up ) {
				up.disabled = 0 === index;
			}

			if ( down ) {
				down.disabled = items.length - 1 === index;
			}
		} );
	}

	function enhanceSources() {
		Array.prototype.forEach.call( list.querySelectorAll( '.estasoft-nd-source__handle, .estasoft-nd-source__buttons' ), function ( element ) {
			element.hidden = false;
		} );

		Array.prototype.forEach.call( list.querySelectorAll( '.estasoft-nd-source__position' ), function ( label ) {
			label.hidden = true;
		} );

		var plain = list.parentNode.querySelector( '[data-order-help="plain"]' );
		var script = list.parentNode.querySelector( '[data-order-help="script"]' );

		if ( plain ) {
			plain.hidden = true;
		}

		if ( script ) {
			script.hidden = false;
		}

		renumber();
	}

	function move( item, direction ) {
		var items = movable();
		var index = items.indexOf( item );
		var other = items[ index + direction ];

		if ( ! other ) {
			return;
		}

		if ( direction < 0 ) {
			list.insertBefore( item, other );
		} else {
			list.insertBefore( item, other.nextSibling );
		}

		renumber();
	}

	var dragging = null;

	function bindSources() {
		list.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-move]' );

			if ( ! button || button.disabled ) {
				return;
			}

			var item = button.closest( '.estasoft-nd-source' );

			move( item, 'up' === button.getAttribute( 'data-move' ) ? -1 : 1 );

			// A button that just became disabled cannot keep the focus.
			var target = button.disabled ? item.querySelector( '[data-move]:not([disabled])' ) : button;

			if ( target ) {
				target.focus();
			}
		} );

		list.addEventListener( 'mousedown', function ( event ) {
			var handle = event.target.closest( '.estasoft-nd-source__handle' );
			var item = handle ? handle.closest( '.estasoft-nd-source' ) : null;

			if ( item && ! item.classList.contains( 'estasoft-nd-source--fixed' ) ) {
				item.draggable = true;
			}
		} );

		list.addEventListener( 'dragstart', function ( event ) {
			var item = event.target.closest ? event.target.closest( '.estasoft-nd-source' ) : null;

			if ( ! item || ! item.draggable ) {
				return;
			}

			dragging = item;
			item.classList.add( 'is-dragging' );

			if ( event.dataTransfer ) {
				event.dataTransfer.effectAllowed = 'move';
				event.dataTransfer.setData( 'text/plain', item.getAttribute( 'data-source' ) || '' );
			}
		} );

		list.addEventListener( 'dragover', function ( event ) {
			if ( ! dragging ) {
				return;
			}

			var over = event.target.closest( '.estasoft-nd-source' );

			if ( ! over || over === dragging ) {
				return;
			}

			event.preventDefault();

			var box = over.getBoundingClientRect();
			var after = event.clientY > box.top + box.height / 2;

			// The newsroom name always stays last.
			if ( over.classList.contains( 'estasoft-nd-source--fixed' ) ) {
				after = false;
			}

			list.insertBefore( dragging, after ? over.nextSibling : over );
		} );

		function finish() {
			if ( dragging ) {
				dragging.classList.remove( 'is-dragging' );
				dragging.draggable = false;
				dragging = null;
				renumber();
			}
		}

		list.addEventListener( 'drop', function ( event ) {
			if ( dragging ) {
				event.preventDefault();
			}

			finish();
		} );

		list.addEventListener( 'dragend', finish );
	}

	/* ---------- the names that are not people ---------- */

	function chip( name, field ) {
		var label = document.createElement( 'label' );
		var box = document.createElement( 'input' );
		var text = document.createElement( 'span' );
		var mark = document.createElement( 'span' );
		var hint = document.createElement( 'span' );
		var hintText = chips.getAttribute( 'data-remove-text' );

		label.className = 'estasoft-nd-chip';
		box.type = 'checkbox';
		box.name = field.name;
		box.value = name;
		box.checked = true;
		text.className = 'estasoft-nd-chip__name';
		text.textContent = name;
		mark.className = 'estasoft-nd-chip__x';
		mark.setAttribute( 'aria-hidden', 'true' );
		mark.textContent = '×';
		hint.className = 'estasoft-nd-sr';
		hint.textContent = hintText || '';

		label.appendChild( box );
		label.appendChild( text );
		label.appendChild( mark );
		label.appendChild( hint );

		return label;
	}

	function bindNames() {
		var field = document.getElementById( 'estasoft-nd-add-name' );
		var add = field ? field.parentNode.querySelector( '[data-add-name]' ) : null;

		if ( ! field ) {
			return;
		}

		// The hint on each chip says what the box does. Reuse the one the server printed.
		var first = chips.querySelector( '.estasoft-nd-sr' );

		if ( first ) {
			chips.setAttribute( 'data-remove-text', first.textContent );
		}

		if ( add ) {
			add.hidden = false;
		}

		function exists( name ) {
			return Array.prototype.some.call( chips.querySelectorAll( 'input[type="checkbox"]' ), function ( box ) {
				return box.value.toLowerCase() === name.toLowerCase();
			} );
		}

		function addName() {
			var name = field.value.replace( /^\s+|\s+$/g, '' );

			if ( '' === name ) {
				return;
			}

			field.value = '';

			if ( exists( name ) ) {
				return;
			}

			var created = chip( name, field );

			chips.appendChild( created );
			changed( created.querySelector( 'input' ) );
		}

		if ( add ) {
			add.addEventListener( 'click', function () {
				addName();
				field.focus();
			} );
		}

		field.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key ) {
				event.preventDefault();
				addName();
			}
		} );

		// Clearing a box removes the name from the list.
		chips.addEventListener( 'change', function ( event ) {
			var box = event.target;

			if ( box && 'checkbox' === box.type && ! box.checked ) {
				var item = box.closest( '.estasoft-nd-chip' );

				if ( item ) {
					item.parentNode.removeChild( item );
					field.focus();
				}
			}
		} );
	}

	/* ---------- start ---------- */

	var initial = {};

	if ( list ) {
		enhanceSources();
		bindSources();
		initial.list = list.innerHTML;
	}

	if ( chips ) {
		bindNames();
		initial.chips = chips.innerHTML;
	}

	// Discard puts back what the page was opened with.
	if ( form ) {
		form.addEventListener( 'reset', function () {
			window.setTimeout( function () {
				if ( list ) {
					list.innerHTML = initial.list;
					renumber();
				}

				if ( chips ) {
					chips.innerHTML = initial.chips;
				}
			}, 0 );
		} );
	}
}() );
