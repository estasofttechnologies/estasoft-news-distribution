/*
 * Site verification: show only the fields of the chosen method. Without this
 * script every method's fields stay visible and the form still posts.
 */
( function () {
	'use strict';

	var form = document.querySelector( 'form[data-verify-form]' );

	if ( ! form ) {
		return;
	}

	function chosen() {
		var checked = form.querySelector( 'input[name="verification[method]"]:checked' );

		return checked ? checked.value : 'none';
	}

	function update() {
		var method = chosen();

		Array.prototype.slice.call( form.querySelectorAll( '[data-method-group]' ) ).forEach( function ( group ) {
			group.hidden = group.getAttribute( 'data-method-group' ) !== method;
		} );
	}

	form.addEventListener( 'change', function ( event ) {
		if ( event.target && event.target.name === 'verification[method]' ) {
			update();
		}
	} );

	update();
}() );
