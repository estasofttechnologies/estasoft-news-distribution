/*
 * Block editor: the "Follow" block.
 *
 * The server draws the block, so the editor shows what the page will show. This script only registers the
 * block's controls: the heading, the follow button, which links show (the feed, Telegram, Apple News,
 * Mastodon and Bluesky), the placement at the end of every article, the style and the language. Plain
 * script, no build step, no JSX. The block's name, attributes and description come from
 * blocks/follow/block.json, and what the controls know about the site comes from window.estasoftNDFollow
 * (names, counts and notes: nothing secret).
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element || ! wp.blockEditor || ! wp.components || ! wp.serverSideRender || ! wp.i18n ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var __ = wp.i18n.__;
	var ServerSideRender = wp.serverSideRender;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var ToggleControl = wp.components.ToggleControl;
	var SelectControl = wp.components.SelectControl;
	var Notice = wp.components.Notice;
	var SETTINGS_PATH = '/estasoft-nd/v1/settings/follow';
	var site = window.estasoftNDFollow || { publication: '', languages: [], services: [], append: false, canManage: false, settingsUrl: '' };

	function Failed() {
		return el( 'p', { className: 'estasoft-nd-follow estasoft-nd-follow--empty' }, __( 'Follow: the preview could not be loaded. The block still prints on the page.', 'estasoft-news-distribution' ) );
	}

	/*
	 * One switch for a service. It is off, and cannot be changed, while the site has no address for the
	 * service, so the person sees why the link is missing. The block keeps its own choice meanwhile.
	 */
	function ServiceToggle( props ) {
		var service = props.service;
		var attributes = props.attributes;
		var on = !! service.available && false !== attributes[ service.attribute ];

		return el( ToggleControl, {
			key: service.id,
			label: service.label,
			help: service.note,
			checked: on,
			disabled: ! service.available,
			onChange: function ( value ) {
				var change = {};

				change[ service.attribute ] = !! value;
				props.setAttributes( change );
			},
			__nextHasNoMarginBottom: true
		} );
	}

	/*
	 * "Add to the end of every article" is a setting of the whole site, so it is saved at once through the
	 * plugin's settings route and not in the block. Only a person who manages News Distribution can change it.
	 */
	function Placement() {
		var state = useState( !! site.append );
		var checked = state[ 0 ];
		var setChecked = state[ 1 ];
		var busyState = useState( false );
		var busy = busyState[ 0 ];
		var setBusy = busyState[ 1 ];
		var errorState = useState( '' );
		var error = errorState[ 0 ];
		var setError = errorState[ 1 ];
		var help = __( 'Applies to the whole site. Off by default. A block you place by hand in an article is not added twice.', 'estasoft-news-distribution' );

		function change( value ) {
			var wanted = !! value;

			setChecked( wanted );
			setBusy( true );
			setError( '' );

			wp.apiFetch( { path: SETTINGS_PATH, method: 'PUT', data: { append: wanted } } ).then(
				function ( saved ) {
					setChecked( !! ( saved && saved.values && saved.values.append ) );
					setBusy( false );
				},
				function ( failure ) {
					setChecked( ! wanted );
					setBusy( false );
					setError( failure && failure.message ? failure.message : __( 'The setting could not be saved. Try again.', 'estasoft-news-distribution' ) );
				}
			);
		}

		return el(
			Fragment,
			null,
			el( ToggleControl, {
				label: __( 'Add to the end of every article', 'estasoft-news-distribution' ),
				help: site.canManage ? help : help + ' ' + __( 'Only people who manage News Distribution can change this.', 'estasoft-news-distribution' ),
				checked: checked,
				disabled: ! site.canManage || busy,
				onChange: change,
				__nextHasNoMarginBottom: true
			} ),
			error ? el( Notice, { status: 'error', isDismissible: false }, error ) : null,
			site.canManage && site.settingsUrl ? el( 'p', null, el( 'a', { href: site.settingsUrl }, __( 'Open Settings, Follow links', 'estasoft-news-distribution' ) ) ) : null
		);
	}

	function Edit( props ) {
		var attributes = props.attributes;
		var setAttributes = props.setAttributes;
		var blockProps = useBlockProps();
		var languages = [ { value: '', label: __( 'Match the page’s language', 'estasoft-news-distribution' ) } ].concat( site.languages || [] );
		var headingHint = site.publication ? __( 'Follow', 'estasoft-news-distribution' ) + ' ' + site.publication : '';
		var toggles = ( site.services || [] ).map( function ( service ) {
			return el( ServiceToggle, { key: service.id, service: service, attributes: attributes, setAttributes: setAttributes } );
		} );

		var controls = el(
			InspectorControls,
			null,
			el(
				PanelBody,
				{ title: __( 'Follow links', 'estasoft-news-distribution' ), initialOpen: true },
				el( TextControl, {
					label: __( 'Heading', 'estasoft-news-distribution' ),
					value: attributes.heading,
					placeholder: headingHint,
					onChange: function ( value ) {
						setAttributes( { heading: value } );
					},
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true
				} ),
				el( TextControl, {
					label: __( 'Follow address', 'estasoft-news-distribution' ),
					type: 'url',
					value: attributes.followUrl,
					placeholder: 'https://',
					help: __( 'The button sends visitors to this address. It starts with https:// or, for a page on this site, with /. Leave it empty to hide the button.', 'estasoft-news-distribution' ),
					onChange: function ( value ) {
						setAttributes( { followUrl: value } );
					},
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true
				} ),
				el( TextControl, {
					label: __( 'Button text', 'estasoft-news-distribution' ),
					value: attributes.followLabel,
					placeholder: __( 'Follow us', 'estasoft-news-distribution' ),
					onChange: function ( value ) {
						setAttributes( { followLabel: value } );
					},
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true
				} )
			),
			el(
				PanelBody,
				{ title: __( 'Show these links', 'estasoft-news-distribution' ), initialOpen: true },
				el( ToggleControl, {
					label: __( 'RSS feed', 'estasoft-news-distribution' ),
					help: __( 'One link per language. It shows only while the open feed is on.', 'estasoft-news-distribution' ),
					checked: !! attributes.showFeed,
					onChange: function ( value ) {
						setAttributes( { showFeed: !! value } );
					},
					__nextHasNoMarginBottom: true
				} ),
				toggles,
				el( 'p', { className: 'components-base-control__help' }, __( 'A link shows only for a channel that is on, so no visitor lands on an empty page.', 'estasoft-news-distribution' ) )
			),
			el(
				PanelBody,
				{ title: __( 'Placement', 'estasoft-news-distribution' ), initialOpen: true },
				el( Placement, null )
			),
			el(
				PanelBody,
				{ title: __( 'Style', 'estasoft-news-distribution' ), initialOpen: true },
				el( SelectControl, {
					label: __( 'Links look like', 'estasoft-news-distribution' ),
					value: attributes.layout,
					options: [
						{ value: 'buttons', label: __( 'Buttons', 'estasoft-news-distribution' ) },
						{ value: 'list', label: __( 'List', 'estasoft-news-distribution' ) }
					],
					onChange: function ( value ) {
						setAttributes( { layout: value } );
					},
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true
				} )
			),
			languages.length > 2 ?
				el(
					PanelBody,
					{ title: __( 'Language', 'estasoft-news-distribution' ), initialOpen: false },
					el( SelectControl, {
						label: __( 'Language', 'estasoft-news-distribution' ),
						value: attributes.language,
						options: languages,
						help: __( 'Feeds and channels follow the same rule, so an Arabic page links to the Arabic feed.', 'estasoft-news-distribution' ),
						onChange: function ( value ) {
							setAttributes( { language: value } );
						},
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true
					} )
				) :
				null
		);

		return el(
			Fragment,
			null,
			controls,
			el(
				'div',
				blockProps,
				el( ServerSideRender, {
					block: 'estasoft-nd/follow',
					attributes: attributes,
					ErrorResponsePlaceholder: Failed
				} )
			)
		);
	}

	wp.blocks.registerBlockType( 'estasoft-nd/follow', {
		edit: Edit,
		save: function () {
			return null;
		}
	} );
}( window.wp ) );
