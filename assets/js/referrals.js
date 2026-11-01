/* Estasoft News Distribution: counts one visit by the site the reader came from.
 * Sends the article number, the host of the page the reader came from and the utm_source, once.
 * No cookie, no storage, no identifier. Printed only when an administrator turned counting on. */
( function () {
	'use strict';

	var c = window.estasoftNDReferral;

	if ( ! c || ! c.post || ! c.url || navigator.webdriver || typeof fetch !== 'function' ) {
		return;
	}

	function send() {
		var host = '', utm = '', body = new URLSearchParams();

		try {
			host = new URL( document.referrer ).hostname.toLowerCase();
		} catch ( e ) {}

		// A reader who moved from one page of this site to another is not a visit from outside.
		if ( host && host === location.hostname.toLowerCase() ) {
			return;
		}

		if ( host && ! /^[a-z0-9][a-z0-9.-]{0,99}$/.test( host ) ) {
			host = 'unknown';
		}

		try {
			utm = ( new URLSearchParams( location.search ).get( 'utm_source' ) || '' ).toLowerCase().slice( 0, 40 );
		} catch ( e ) {}

		body.set( 'post', String( c.post ) );
		body.set( 'ref', host );

		if ( /^[a-z0-9_.-]+$/.test( utm ) ) {
			body.set( 'utm', utm );
		}

		fetch( c.url, { method: 'POST', body: body, credentials: 'omit', keepalive: true, cache: 'no-store', referrerPolicy: 'no-referrer' } ).catch( function () {} );
	}

	if ( document.prerendering ) {
		document.addEventListener( 'prerenderingchange', send, { once: true } );
	} else {
		send();
	}
}() );
