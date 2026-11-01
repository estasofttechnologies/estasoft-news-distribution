/*
 * The print view of the shared readiness report: opens the print window once
 * the page has loaded, so "Save as PDF" is one click away.
 */
( function () {
	'use strict';

	window.addEventListener( 'load', function () {
		window.print();
	} );
}() );
