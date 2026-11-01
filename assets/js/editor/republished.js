/*
 * Article editor: the "News Distribution: source" section for republished content.
 *
 * Plain script, no build step. It adds its section to the News Distribution panel through the
 * `estasoft_nd.panel_sections` filter. If no panel applies that filter, it shows the same section in
 * its own panel in the Post tab, so the editor never loses it.
 *
 * A section entry is { id, name, title, order, render }. `render()` returns the section's elements.
 * The host (assets/js/editor/panel.js) sets `window.estasoftND.panelHostsSections` before it renders, and
 * this script then never draws the section on its own.
 */
( function ( wp ) {
	'use strict';

	var settings = window.estasoftNDRepublished;

	if ( ! settings || ! settings.enabled || ! wp || ! wp.element || ! wp.hooks || ! wp.data || ! wp.components || ! wp.i18n ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var __ = wp.i18n.__;
	var Toggle = wp.components.ToggleControl;
	var Select = wp.components.SelectControl;
	var Text = wp.components.TextControl;

	var META_SOURCE = '_estasoft_nd_source';
	var META_OPTIONS = '_estasoft_nd_options';
	var OTHER = '__other';
	var FILTER = 'estasoft_nd.panel_sections';

	/** A small round icon: "x", "check" or "info". Drawn with presentational attributes only. */
	function icon( kind ) {
		var marks = {
			x: [ el( 'path', { key: 'a', d: 'M9 9l6 6M15 9l-6 6' } ) ],
			check: [ el( 'path', { key: 'a', d: 'M8.5 12.5l2.5 2.5 4.5-5' } ) ],
			info: [ el( 'path', { key: 'a', d: 'M12 11v5' } ), el( 'path', { key: 'b', d: 'M12 8v.01' } ) ]
		};

		return el(
			'svg',
			{ className: 'estasoft-nd-ed-icon estasoft-nd-ed-icon--' + kind, viewBox: '0 0 24 24', width: 16, height: 16, fill: 'none', stroke: 'currentColor', strokeWidth: 1.8, strokeLinecap: 'round', strokeLinejoin: 'round', 'aria-hidden': 'true', focusable: 'false' },
			[ el( 'circle', { key: 'c', cx: 12, cy: 12, r: 9 } ) ].concat( marks[ kind ] || [] )
		);
	}

	function words( key ) {
		var map = {
			title: __( 'News Distribution: source', 'estasoft-news-distribution' )
		};

		return map[ key ];
	}

	/** What the editor currently knows about the article. */
	function useArticle() {
		return useSelect( function ( select ) {
			var editor = select( 'core/editor' );

			if ( ! editor || ! editor.getCurrentPostId ) {
				return null;
			}

			var meta = editor.getEditedPostAttribute( 'meta' ) || {};

			return {
				id: editor.getCurrentPostId(),
				type: editor.getCurrentPostType(),
				title: editor.getEditedPostAttribute( 'title' ) || '',
				status: editor.getEditedPostAttribute( 'status' ) || 'draft',
				source: meta[ META_SOURCE ] || {},
				options: meta[ META_OPTIONS ] || {}
			};
		}, [] );
	}

	function statusWord( status ) {
		var map = {
			publish: __( 'Published', 'estasoft-news-distribution' ),
			future: __( 'Scheduled', 'estasoft-news-distribution' ),
			pending: __( 'Pending review', 'estasoft-news-distribution' ),
			private: __( 'Private', 'estasoft-news-distribution' ),
			draft: __( 'Draft', 'estasoft-news-distribution' ),
			'auto-draft': __( 'Draft', 'estasoft-news-distribution' )
		};

		return map[ status ] || status;
	}

	function sourceById( id ) {
		for ( var i = 0; i < settings.sources.length; i++ ) {
			if ( settings.sources[ i ].id === id ) {
				return settings.sources[ i ];
			}
		}

		return null;
	}

	/** What the article's source and the site default say about search, when the article says nothing. */
	function effectiveHandling( details ) {
		if ( details.handling ) {
			return details.handling;
		}

		var source = sourceById( details.source );

		return source && source.handling ? source.handling : settings.defaultHandling;
	}

	function usePreview( article, on ) {
		var state = useState( null );
		var preview = state[ 0 ];
		var setPreview = state[ 1 ];
		var details = article.source;
		var key = JSON.stringify( [ article.id, on, details.source || '', details.name || '', details.url || '', details.handling || '' ] );

		useEffect( function () {
			if ( ! on || ! wp.apiFetch ) {
				setPreview( null );
				return undefined;
			}

			var cancelled = false;
			var timer = window.setTimeout( function () {
				wp.apiFetch( {
					path: '/estasoft-nd/v1/' + settings.previewPath.replace( '%d', String( article.id ) ),
					method: 'POST',
					data: {
						handling: details.handling || '',
						source: details.source || '',
						name: details.name || '',
						url: details.url || ''
					}
				} ).then( function ( response ) {
					if ( ! cancelled ) {
						setPreview( response );
					}
				} ).catch( function () {
					if ( ! cancelled ) {
						setPreview( { failed: true } );
					}
				} );
			}, 350 );

			return function () {
				cancelled = true;
				window.clearTimeout( timer );
			};
		}, [ key ] );

		return preview;
	}

	function List( props ) {
		return el(
			'ul',
			{ className: 'estasoft-nd-ed-list' },
			props.items.map( function ( text, index ) {
				return el( 'li', { key: index, className: 'estasoft-nd-ed-list__item' }, icon( props.kind ), el( 'span', null, text ) );
			} )
		);
	}

	function PreviewBlock( props ) {
		var preview = props.preview;

		if ( ! preview ) {
			return el( 'p', { className: 'estasoft-nd-ed-muted', role: 'status' }, __( 'Working out what this changes…', 'estasoft-news-distribution' ) );
		}

		if ( preview.failed ) {
			return el( 'p', { className: 'estasoft-nd-ed-muted', role: 'status' }, __( 'The preview could not be loaded. The choices above are still saved with the article.', 'estasoft-news-distribution' ) );
		}

		var added = preview.preview.added;

		return el(
			Fragment,
			null,
			el( 'h4', { className: 'estasoft-nd-ed-sub' }, __( 'Added to the page', 'estasoft-news-distribution' ) ),
			added.code ? el( 'pre', { className: 'estasoft-nd-ed-code' }, el( 'code', null, added.code ) ) : null,
			added.note ? el( 'p', { className: 'estasoft-nd-ed-muted' }, added.note ) : null,
			preview.preview.skipped.length || preview.preview.notes.length ? el( 'h4', { className: 'estasoft-nd-ed-sub' }, __( 'Skipped in', 'estasoft-news-distribution' ) ) : null,
			preview.preview.skipped.length ? el( List, { items: preview.preview.skipped, kind: 'x' } ) : null,
			preview.preview.notes.length ? el( List, { items: preview.preview.notes, kind: 'info' } ) : null,
			el( 'h4', { className: 'estasoft-nd-ed-sub' }, __( 'Still on', 'estasoft-news-distribution' ) ),
			el( List, { items: preview.preview.still_on, kind: 'check' } ),
			preview.degraded ? el( 'p', { className: 'estasoft-nd-ed-note estasoft-nd-ed-note--warn', role: 'status' }, __( 'Point to the source needs the address of the original. Until you add it, the article is kept out of search instead.', 'estasoft-news-distribution' ) ) : null,
			el(
				'p',
				{ className: 'estasoft-nd-ed-muted' },
				__( 'You can change how it shows in search above. The channels it is skipped in follow the defaults in ', 'estasoft-news-distribution' ),
				el( 'a', { href: settings.settingsUrl }, __( 'Settings, Republished content', 'estasoft-news-distribution' ) ),
				'.'
			)
		);
	}

	function SourceSection() {
		var found = useArticle();
		var editor = useDispatch( 'core/editor' );
		var otherState = useState( false );
		var forceOther = otherState[ 0 ];
		var setForceOther = otherState[ 1 ];
		var valid = !! found && settings.postTypes.indexOf( found.type ) !== -1;
		var article = valid ? found : { id: 0, type: '', title: '', status: 'draft', source: {}, options: {} };
		var syndicated = article.options.content_label === 'syndicated';
		var on = !! article.options.republished || syndicated;
		var details = article.source;
		var chosenSource = sourceById( details.source );
		var typed = !! ( details.source || details.name || ! settings.sources.length || forceOther );
		var selectValue = chosenSource ? chosenSource.id : ( typed ? OTHER : '' );
		var effective = effectiveHandling( details );
		var preview = usePreview( article, valid && on );
		var addressMissing = on && effective === 'canonical' && ! details.url;

		if ( ! valid ) {
			return null;
		}

		function setDetails( changes ) {
			var next = {
				source: details.source || '',
				name: details.name || '',
				url: details.url || '',
				handling: details.handling || ''
			};

			Object.keys( changes ).forEach( function ( key ) {
				next[ key ] = changes[ key ];
			} );

			var meta = {};

			meta[ META_SOURCE ] = next;
			editor.editPost( { meta: meta } );
		}

		function setOn( value ) {
			var options = {};

			Object.keys( article.options ).forEach( function ( key ) {
				options[ key ] = article.options[ key ];
			} );

			options.republished = !! value;

			var meta = {};

			meta[ META_OPTIONS ] = options;
			editor.editPost( { meta: meta } );
		}

		function pickSource( value ) {
			var source = sourceById( value );

			if ( source ) {
				setDetails( { source: source.id, name: source.name, handling: '' } );
			} else {
				setDetails( { source: '', name: '' } );
			}
		}

		var choices = [];

		if ( ! chosenSource && selectValue === '' ) {
			choices.push( { value: '', label: __( 'Choose a source…', 'estasoft-news-distribution' ) } );
		}

		settings.sources.forEach( function ( source ) {
			choices.push( { value: source.id, label: source.name } );
		} );
		choices.push( { value: OTHER, label: __( 'Another source…', 'estasoft-news-distribution' ) } );

		return el(
			'div',
			{ className: 'estasoft-nd-ed estasoft-nd-ed--source' },
			el(
				'section',
				{ className: 'estasoft-nd-ed-block' },
				el( 'h3', { className: 'estasoft-nd-ed-label' }, __( 'Republished content', 'estasoft-news-distribution' ) ),
				el( 'p', { className: 'estasoft-nd-ed-muted' }, ( article.title || __( '(no title)', 'estasoft-news-distribution' ) ) + ' · ' + statusWord( article.status ) ),
				el( Toggle, {
					__nextHasNoMarginBottom: true,
					label: __( 'This article comes from another source', 'estasoft-news-distribution' ),
					help: syndicated ? __( 'Content label is Syndicated, so this is on.', 'estasoft-news-distribution' ) : __( 'Wire or partner copy. Also set when Content label is Syndicated.', 'estasoft-news-distribution' ),
					checked: on,
					disabled: syndicated,
					onChange: setOn
				} )
			),
			on ? el(
				Fragment,
				null,
				el(
					'section',
					{ className: 'estasoft-nd-ed-block' },
					el( 'h3', { className: 'estasoft-nd-ed-label' }, __( 'Source', 'estasoft-news-distribution' ) ),
					el( Select, {
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true,
						label: __( 'Name', 'estasoft-news-distribution' ),
						value: selectValue,
						options: choices,
						onChange: function ( value ) {
							if ( value === OTHER ) {
								setForceOther( true );
								setDetails( { source: '', name: chosenSource ? '' : ( details.name || '' ) } );
							} else {
								setForceOther( false );
								pickSource( value );
							}
						}
					} ),
					! chosenSource ? el( Text, {
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true,
						label: __( 'Name of the source', 'estasoft-news-distribution' ),
						value: details.name || '',
						maxLength: 120,
						onChange: function ( value ) {
							setDetails( { name: value } );
						}
					} ) : null,
					el( Text, {
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true,
						label: __( 'Address of the original', 'estasoft-news-distribution' ),
						type: 'url',
						value: details.url || '',
						placeholder: 'https://',
						onChange: function ( value ) {
							setDetails( { url: value } );
						},
						help: __( 'Needed for “Point to the source”. Otherwise optional. If you choose that option and leave this empty, an error asks for the address, and until you add it the article is kept out of search instead.', 'estasoft-news-distribution' )
					} ),
					addressMissing ? el( 'p', { className: 'estasoft-nd-ed-note estasoft-nd-ed-note--error', role: 'alert' }, __( 'Add the address of the original, or choose another option below.', 'estasoft-news-distribution' ) ) : null
				),
				el(
					'section',
					{ className: 'estasoft-nd-ed-block' },
					el( 'fieldset', { className: 'estasoft-nd-ed-radios' },
						el( 'legend', { className: 'estasoft-nd-ed-label' }, __( 'In search', 'estasoft-news-distribution' ) ),
						settings.handlings.map( function ( option ) {
							var id = 'estasoft-nd-ed-handling-' + option.value;

							return el(
								'div',
								{ className: 'estasoft-nd-ed-radio', key: option.value },
								el( 'input', {
									type: 'radio',
									id: id,
									name: 'estasoft-nd-ed-handling',
									value: option.value,
									checked: effective === option.value,
									onChange: function () {
										setDetails( { handling: option.value } );
									}
								} ),
								el( 'label', { htmlFor: id },
									el( 'strong', null, option.label ),
									option.help ? el( 'span', { className: 'estasoft-nd-ed-muted' }, option.help ) : null
								)
							);
						} )
					)
				),
				el(
					'section',
					{ className: 'estasoft-nd-ed-block' },
					el( 'h3', { className: 'estasoft-nd-ed-label' }, __( 'Preview', 'estasoft-news-distribution' ) ),
					el( PreviewBlock, { preview: preview } )
				)
			) : null
		);
	}

	/* ---------- The section is added to the News Distribution panel ---------- */

	var hosted = false;
	var listeners = [];

	function announceHost() {
		if ( hosted ) {
			return;
		}

		hosted = true;

		// Not during the host's own render: React would warn about updating one component while rendering another.
		window.setTimeout( function () {
			listeners.forEach( function ( listener ) {
				listener();
			} );
		}, 0 );
	}

	wp.hooks.addFilter( FILTER, 'estasoft-nd/republished', function ( sections ) {
		announceHost();

		var list = Array.isArray( sections ) ? sections.slice() : [];

		list.push( {
			id: 'source',
			name: 'source',
			title: words( 'title' ),
			order: 40,
			render: function () {
				return el( SourceSection );
			}
		} );

		return list;
	} );

	/* ---------- No panel host: the same section in its own panel ---------- */

	function Fallback() {
		var state = useState( 0 );
		var setTick = state[ 1 ];
		var Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );

		useEffect( function () {
			var listener = function () {
				setTick( function ( n ) {
					return n + 1;
				} );
			};

			listeners.push( listener );

			return function () {
				listeners = listeners.filter( function ( item ) {
					return item !== listener;
				} );
			};
		}, [] );

		var article = useArticle();

		// The host says it is on the page before it has rendered once, so the section is never shown twice.
		if ( hosted || ( window.estasoftND && window.estasoftND.panelHostsSections ) || ! Panel || ! article || settings.postTypes.indexOf( article.type ) === -1 ) {
			return null;
		}

		if ( wp.hooks.didFilter && wp.hooks.didFilter( FILTER ) > 0 ) {
			return null;
		}

		return el( Panel, { name: 'estasoft-nd-source', title: words( 'title' ), className: 'estasoft-nd-ed-panel' }, el( SourceSection ) );
	}

	if ( wp.plugins && wp.plugins.registerPlugin ) {
		wp.plugins.registerPlugin( 'estasoft-nd-source', { render: Fallback } );
	}
}( window.wp ) );
