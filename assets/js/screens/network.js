/*
 * Network Admin: Sites, Delivery health and AI policy.
 *
 * Every form posts the plain way and every link works without this script. The script adds:
 * a "select all" box on the list of sites, the "N sites selected" line of the template card,
 * and a site picker on Delivery health that opens the chosen site as soon as it changes.
 */
( function () {
	'use strict';

	var root = document.getElementById( 'estasoft-nd-app' );

	if ( ! root ) {
		return;
	}

	function all( selector ) {
		return Array.prototype.slice.call( root.querySelectorAll( selector ) );
	}

	/* ---------- Sites: select all, and the selected-sites line ---------- */

	( function () {
		var boxes = all( 'input[name="sites[]"]' );
		var summary = root.querySelector( '[data-selected-summary]' );
		var master = root.querySelector( 'input[data-select-all]' );

		if ( ! boxes.length ) {
			return;
		}

		var text = ( window.estasoftNDScreens || {} ).network || {};
		text = text.text || {};

		function names() {
			return boxes
				.filter( function ( box ) {
					return box.checked;
				} )
				.map( function ( box ) {
					return box.getAttribute( 'data-site-name' ) || box.value;
				} );
		}

		function refresh() {
			var chosen = names();

			if ( master ) {
				master.checked = chosen.length === boxes.length;
				master.indeterminate = chosen.length > 0 && chosen.length < boxes.length;
			}

			if ( ! summary ) {
				return;
			}

			if ( ! chosen.length ) {
				summary.textContent = text.none || summary.textContent;
				return;
			}

			var template = chosen.length === 1 ? text.one : text.many;

			if ( template ) {
				summary.textContent = template.replace( '%1$d', String( chosen.length ) ).replace( '%2$s', chosen.join( ', ' ) );
			}
		}

		boxes.forEach( function ( box ) {
			box.addEventListener( 'change', refresh );
		} );

		if ( master ) {
			master.addEventListener( 'change', function () {
				boxes.forEach( function ( box ) {
					box.checked = master.checked;
				} );
				refresh();
			} );
		}

		refresh();
	}() );

	/* ---------- Delivery health: the site picker ---------- */

	all( 'select[data-auto-submit]' ).forEach( function ( select ) {
		select.addEventListener( 'change', function () {
			if ( select.form ) {
				select.form.submit();
			}
		} );
	} );
}() );
