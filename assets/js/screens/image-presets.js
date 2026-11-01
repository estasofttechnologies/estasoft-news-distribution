/*
 * Article check, Image presets: drag the focal point, reset it, and choose another featured image.
 * Plain script, no build step. Without it, the page still makes copies and resets the focal point with its forms.
 */
( function () {
	'use strict';

	var cfg = window.estasoftND;
	var screen = ( window.estasoftNDScreens || {} )[ 'image-presets' ] || {};
	var text = screen.text || {};
	var stage = document.querySelector( '[data-stage]' );

	if ( ! cfg ) {
		return;
	}

	function call( method, url, body ) {
		return window.fetch( url, {
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

	function say( message ) {
		var line = document.querySelector( '[data-focal-status]' );

		if ( line ) {
			line.textContent = message;
		}
	}

	function numbers( value ) {
		return String( value || '' ).split( ',' ).map( parseFloat );
	}

	function place( node, left, top, width, height ) {
		node.style.left = left * 100 + '%';
		node.style.top = top * 100 + '%';

		if ( width !== undefined ) {
			node.style.width = width * 100 + '%';
			node.style.height = height * 100 + '%';
			node.style.right = 'auto';
			node.style.bottom = 'auto';
		}
	}

	/* ---------- The crop frame and the focal point ---------- */

	if ( stage ) {
		var frame = stage.querySelector( '[data-frame]' );
		var point = stage.querySelector( '[data-point]' );
		var attachment = stage.getAttribute( 'data-attachment' );
		var preset = stage.getAttribute( 'data-preset' );
		var focal = numbers( stage.getAttribute( 'data-focal' ) );
		var crop = numbers( stage.getAttribute( 'data-crop' ) );
		var dragging = false;

		var draw = function () {
			place( frame, crop[ 0 ], crop[ 1 ], crop[ 2 ], crop[ 3 ] );
			place( point, focal[ 0 ], focal[ 1 ] );
		};

		// The crop is worked out on the server from the focal point. While dragging, the frame follows locally.
		var follow = function () {
			var w = crop[ 2 ];
			var h = crop[ 3 ];

			crop[ 0 ] = Math.max( 0, Math.min( 1 - w, focal[ 0 ] - w / 2 ) );
			crop[ 1 ] = Math.max( 0, Math.min( 1 - h, focal[ 1 ] - h / 2 ) );
		};

		point.hidden = false;
		draw();

		var move = function ( event ) {
			var box = stage.getBoundingClientRect();

			focal[ 0 ] = Math.max( 0, Math.min( 1, ( event.clientX - box.left ) / box.width ) );
			focal[ 1 ] = Math.max( 0, Math.min( 1, ( event.clientY - box.top ) / box.height ) );
			follow();
			draw();
		};

		stage.addEventListener( 'pointerdown', function ( event ) {
			dragging = true;
			stage.classList.add( 'is-dragging' );

			if ( stage.setPointerCapture ) {
				stage.setPointerCapture( event.pointerId );
			}

			move( event );
		} );

		stage.addEventListener( 'pointermove', function ( event ) {
			if ( dragging ) {
				move( event );
			}
		} );

		var finish = function () {
			if ( ! dragging ) {
				return;
			}

			dragging = false;
			stage.classList.remove( 'is-dragging' );
			say( text.working );

			call( 'POST', cfg.rest + 'media/' + encodeURIComponent( attachment ) + '/focal', { x: focal[ 0 ], y: focal[ 1 ] } ).then( function ( result ) {
				result.presets.forEach( function ( item ) {
					if ( item.id === preset && item.crop_fraction ) {
						crop = [ item.crop_fraction.x, item.crop_fraction.y, item.crop_fraction.width, item.crop_fraction.height ];
						draw();
					}
				} );

				say( text.saved );
			} ).catch( function ( error ) {
				say( ( error && error.message ) || text.failed );
			} );
		};

		stage.addEventListener( 'pointerup', finish );
		stage.addEventListener( 'pointercancel', finish );

		var reset = document.querySelector( '[data-focal-reset]' );

		if ( reset ) {
			reset.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				say( text.working );

				call( 'POST', cfg.rest + 'media/' + encodeURIComponent( attachment ) + '/focal', { reset: true } ).then( function ( result ) {
					focal = [ 0.5, 0.5 ];

					result.presets.forEach( function ( item ) {
						if ( item.id === preset && item.crop_fraction ) {
							crop = [ item.crop_fraction.x, item.crop_fraction.y, item.crop_fraction.width, item.crop_fraction.height ];
						}
					} );

					draw();
					say( text.saved );
				} ).catch( function ( error ) {
					say( ( error && error.message ) || text.failed );
				} );
			} );
		}
	}

	/* ---------- Choose another image ---------- */

	var choose = document.querySelector( '[data-choose-image]' );

	if ( choose && window.wp && window.wp.media && screen.postRest ) {
		choose.hidden = false;

		choose.addEventListener( 'click', function () {
			var frameMedia = window.wp.media( {
				title: text.choose,
				button: { text: text.use },
				library: { type: 'image' },
				multiple: false
			} );

			frameMedia.on( 'select', function () {
				var picked = frameMedia.state().get( 'selection' ).first();

				if ( ! picked ) {
					return;
				}

				call( 'POST', screen.postRest, { featured_media: picked.get( 'id' ) } ).then( function () {
					window.location.reload();
				} ).catch( function ( error ) {
					say( ( error && error.message ) || text.failed );
				} );
			} );

			frameMedia.open();
		} );
	}
}() );
