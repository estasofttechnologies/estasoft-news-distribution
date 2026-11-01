/*
 * Article editor: the "News Distribution: video" section.
 *
 * Plain script, no build step. It lists the videos in the article, shows what is known about each one, and lets an
 * editor type the details Google asks for (name, upload date, duration, description, and a picture when there is none).
 * What is typed is kept in the article's meta `_estasoft_nd_video` and saved with the article. Nothing is sent to a
 * video site from the editor: the section asks this site's own REST route what it knows, and the route makes no request
 * to a video site either.
 *
 * It adds its section to the News Distribution panel through the `estasoft_nd.panel_sections` filter. If no panel
 * applies that filter, it shows the same section in its own panel in the Post tab, so the editor never loses it.
 */
( function ( wp ) {
	'use strict';

	var settings = window.estasoftNDVideo;

	if ( ! settings || ! settings.enabled || ! wp || ! wp.element || ! wp.hooks || ! wp.data || ! wp.components || ! wp.i18n || ! wp.apiFetch ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var __ = wp.i18n.__;
	var _n = wp.i18n._n;
	var sprintf = wp.i18n.sprintf;
	var Text = wp.components.TextControl;
	var Area = wp.components.TextareaControl;
	var Button = wp.components.Button;

	var FILTER = 'estasoft_nd.panel_sections';
	var EARLIEST = '1990-01-01';

	/** A small round icon ("check", "info") or a triangle ("warn"). Drawn with presentational attributes only. */
	function icon( kind ) {
		var shapes = {
			check: [ el( 'circle', { key: 'c', cx: 12, cy: 12, r: 9 } ), el( 'path', { key: 'a', d: 'M8.5 12.5l2.5 2.5 4.5-5' } ) ],
			info: [ el( 'circle', { key: 'c', cx: 12, cy: 12, r: 9 } ), el( 'path', { key: 'a', d: 'M12 11v5' } ), el( 'path', { key: 'b', d: 'M12 8v.01' } ) ],
			warn: [ el( 'path', { key: 'a', d: 'M12 4l9.5 16.5h-19z' } ), el( 'path', { key: 'b', d: 'M12 10v4.5' } ), el( 'path', { key: 'c', d: 'M12 17.5v.01' } ) ]
		};

		return el(
			'svg',
			{ className: 'estasoft-nd-ed-icon estasoft-nd-ed-icon--' + kind, viewBox: '0 0 24 24', width: 16, height: 16, fill: 'none', stroke: 'currentColor', strokeWidth: 1.8, strokeLinecap: 'round', strokeLinejoin: 'round', 'aria-hidden': 'true', focusable: 'false' },
			shapes[ kind ] || []
		);
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

	function siteName( site ) {
		var map = {
			youtube: 'YouTube',
			vimeo: 'Vimeo',
			own: __( 'Video on this site', 'estasoft-news-distribution' )
		};

		return map[ site ] || site;
	}

	/** "a", "a and b", "a, b and c". */
	function joinList( items ) {
		if ( items.length < 2 ) {
			return items.join( '' );
		}

		var head = items.slice( 0, -1 ).join( ', ' );

		return sprintf( /* translators: 1: the first items, such as "a, b". 2: the last item. */ __( '%1$s and %2$s', 'estasoft-news-distribution' ), head, items[ items.length - 1 ] );
	}

	/** What is missing, as words after "Google asks for". */
	function neededWords( missing ) {
		var map = {
			name: __( 'a name', 'estasoft-news-distribution' ),
			thumbnail: __( 'a thumbnail', 'estasoft-news-distribution' ),
			upload_date: __( 'an upload date', 'estasoft-news-distribution' )
		};

		return joinList( missing.map( function ( field ) {
			return map[ field ] || field;
		} ) );
	}

	/** What is missing, as the names of the details. */
	function missingWords( missing ) {
		var map = {
			name: __( 'name', 'estasoft-news-distribution' ),
			thumbnail: __( 'thumbnail', 'estasoft-news-distribution' ),
			upload_date: __( 'upload date', 'estasoft-news-distribution' )
		};

		return joinList( missing.map( function ( field ) {
			return map[ field ] || field;
		} ) );
	}

	function pad( number ) {
		return ( number < 10 ? '0' : '' ) + number;
	}

	/** Today in the browser's time zone, as YYYY-MM-DD. */
	function today() {
		var now = new Date();

		return now.getFullYear() + '-' + pad( now.getMonth() + 1 ) + '-' + pad( now.getDate() );
	}

	/** Whether a text is a real date that is not in the future and not before 1990. */
	function validDate( text ) {
		var found = /^(\d{4})-(\d{2})-(\d{2})$/.exec( text || '' );

		if ( ! found ) {
			return false;
		}

		var date = new Date( Number( found[ 1 ] ), Number( found[ 2 ] ) - 1, Number( found[ 3 ] ) );

		if ( date.getFullYear() !== Number( found[ 1 ] ) || date.getMonth() !== Number( found[ 2 ] ) - 1 || date.getDate() !== Number( found[ 3 ] ) ) {
			return false;
		}

		return text <= today() && text >= EARLIEST;
	}

	/** "2:14" or "1:02:03" or "134" to seconds; 0 for an empty text; null when it is not a duration. */
	function parseDuration( text ) {
		var value = String( text || '' ).trim();
		var found;

		if ( value === '' ) {
			return 0;
		}

		if ( /^\d+$/.test( value ) ) {
			return Number( value );
		}

		found = /^(\d{1,3}):(\d{2})$/.exec( value );

		if ( found && Number( found[ 2 ] ) < 60 ) {
			return Number( found[ 1 ] ) * 60 + Number( found[ 2 ] );
		}

		found = /^(\d{1,3}):(\d{1,2}):(\d{2})$/.exec( value );

		if ( found && Number( found[ 2 ] ) < 60 && Number( found[ 3 ] ) < 60 ) {
			return Number( found[ 1 ] ) * 3600 + Number( found[ 2 ] ) * 60 + Number( found[ 3 ] );
		}

		return null;
	}

	/** Seconds to "2:14" or "1:02:03"; an empty text for none. */
	function formatDuration( seconds ) {
		var total = Number( seconds ) || 0;

		if ( total <= 0 ) {
			return '';
		}

		var hours = Math.floor( total / 3600 );
		var minutes = Math.floor( ( total % 3600 ) / 60 );
		var rest = total % 60;

		return hours > 0 ? hours + ':' + pad( minutes ) + ':' + pad( rest ) : minutes + ':' + pad( rest );
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
				overrides: Array.isArray( meta[ settings.metaKey ] ) ? meta[ settings.metaKey ] : []
			};
		}, [] );
	}

	/** The blocks of the article. The reference changes when they do. */
	function useBlocks() {
		return useSelect( function ( select ) {
			var blockEditor = select( 'core/block-editor' );

			return blockEditor && blockEditor.getBlocks ? blockEditor.getBlocks() : null;
		}, [] );
	}

	/** The text of the article as it is now in the editor, saved or not. */
	function currentContent( blocks ) {
		if ( blocks && wp.blocks && wp.blocks.serialize ) {
			return wp.blocks.serialize( blocks );
		}

		var editor = wp.data.select( 'core/editor' );

		return editor && editor.getEditedPostContent ? editor.getEditedPostContent() : '';
	}

	/** The videos in the editor's text and what is known about each, from this site's own REST route. */
	function useVideos( article, blocks, on ) {
		var state = useState( null );
		var data = state[ 0 ];
		var setData = state[ 1 ];
		var key = JSON.stringify( [ article.id, on, article.overrides ] );

		useEffect( function () {
			if ( ! on ) {
				return undefined;
			}

			var cancelled = false;
			var timer = window.setTimeout( function () {
				wp.apiFetch( {
					path: '/estasoft-nd/v1/' + settings.path.replace( '%d', String( article.id ) ),
					method: 'POST',
					data: {
						content: currentContent( blocks ),
						overrides: article.overrides
					}
				} ).then( function ( response ) {
					if ( ! cancelled ) {
						setData( response );
					}
				} ).catch( function () {
					if ( ! cancelled ) {
						setData( { failed: true } );
					}
				} );
			}, 500 );

			return function () {
				cancelled = true;
				window.clearTimeout( timer );
			};
		}, [ blocks, key ] );

		return data;
	}

	/** The row typed for a video, if any. */
	function entryFor( overrides, key ) {
		for ( var i = 0; i < overrides.length; i++ ) {
			if ( overrides[ i ] && overrides[ i ].key === key ) {
				return overrides[ i ];
			}
		}

		return null;
	}

	/** The text of a typed field, or the fallback when nothing was typed for it. */
	function typedText( entry, field, fallback ) {
		return entry && typeof entry[ field ] === 'string' ? entry[ field ] : ( fallback || '' );
	}

	/** A field that takes a duration as text and keeps what the editor types until it is a duration. */
	function DurationField( props ) {
		var state = useState( formatDuration( props.value ) );
		var text = state[ 0 ];
		var setText = state[ 1 ];
		var wrong = parseDuration( text ) === null;

		useEffect( function () {
			setText( function ( current ) {
				return parseDuration( current ) === Number( props.value || 0 ) ? current : formatDuration( props.value );
			} );
		}, [ props.value ] );

		return el( Text, {
			__nextHasNoMarginBottom: true,
			__next40pxDefaultSize: true,
			label: __( 'Duration', 'estasoft-news-distribution' ),
			value: text,
			placeholder: '2:14',
			onChange: function ( value ) {
				var seconds = parseDuration( value );

				setText( value );

				if ( seconds !== null ) {
					props.onCommit( seconds );
				}
			},
			help: wrong ? __( 'Use minutes and seconds, such as 2:14.', 'estasoft-news-distribution' ) : undefined
		} );
	}

	/** One line of the summary. */
	function SummaryLine( props ) {
		return el( 'li', { className: 'estasoft-nd-ed-list__item' }, icon( props.kind ), el( 'span', null, props.text ) );
	}

	/** What the summary says about one video. */
	function summaryFor( video, number ) {
		if ( ! video.included ) {
			return { kind: 'info', text: sprintf( /* translators: %d: the number of a video. */ __( 'Video %d: no markup, as Settings say', 'estasoft-news-distribution' ), number ) };
		}

		if ( video.skip ) {
			return { kind: 'info', text: sprintf( /* translators: %d: the number of a video. */ __( 'Video %d: left out of the markup', 'estasoft-news-distribution' ), number ) };
		}

		if ( video.status === 'unavailable' ) {
			return { kind: 'warn', text: sprintf( /* translators: %d: the number of a video. */ __( 'Video %d: private or removed, so no markup', 'estasoft-news-distribution' ), number ) };
		}

		if ( video.missing.length ) {
			return {
				kind: 'warn',
				text: sprintf(
					/* translators: 1: the number of a video. 2: what is missing, such as "upload date". 3: "is missing" or "are missing". */
					__( 'Video %1$d: %2$s %3$s', 'estasoft-news-distribution' ),
					number,
					missingWords( video.missing ),
					_n( 'is missing', 'are missing', video.missing.length, 'estasoft-news-distribution' )
				)
			};
		}

		return { kind: 'check', text: sprintf( /* translators: %d: the number of a video. */ __( 'Video %d: Clean, nothing to fix', 'estasoft-news-distribution' ), number ) };
	}

	/** The line that says where a video's details came from. */
	function sourceTag( video ) {
		var map = {
			typed: __( 'Typed by hand', 'estasoft-news-distribution' ),
			lookup: __( 'From the video site', 'estasoft-news-distribution' ),
			page: __( 'From the page', 'estasoft-news-distribution' )
		};

		return map[ video.from ] || '';
	}

	/** What the details of a video depend on, as help under its fields. */
	function lookupHelp( video ) {
		if ( video.site === 'own' ) {
			return __( 'Videos on this site are not looked up, so you type these.', 'estasoft-news-distribution' );
		}

		if ( ! settings.lookup ) {
			return sprintf(
				/* translators: %s: the name of a video site, such as YouTube. */
				__( 'Look up details is off in Settings, Video, so you type these. Turn it on to have %s fill them in.', 'estasoft-news-distribution' ),
				siteName( video.site )
			);
		}

		if ( video.status === 'unavailable' ) {
			return __( 'The video site says this video is private or was removed, so it gets no markup.', 'estasoft-news-distribution' );
		}

		if ( video.status === 'error' ) {
			return __( 'The video site could not be reached last time. It is asked again after the article is saved.', 'estasoft-news-distribution' );
		}

		if ( video.status === 'pending' ) {
			return __( 'Waiting for the video site. Its answer arrives in the background after the article is saved. What you type is used first.', 'estasoft-news-distribution' );
		}

		return __( 'What you type is used first. The rest comes from the video site’s answer, where it gave one.', 'estasoft-news-distribution' );
	}

	function VideoBlock( props ) {
		var video = props.video;
		var entry = props.entry;
		var number = props.number;
		var committed = typedText( entry, 'upload_date', video.upload_day ).slice( 0, 10 );
		var dateState = useState( committed );
		var draft = dateState[ 0 ];
		var setDraft = dateState[ 1 ];
		var tag = sourceTag( video );
		var showThumbField = video.site !== 'youtube' && ( video.needs_thumb || !! typedText( entry, 'thumbnail' ) );
		var needed = video.included && ! video.skip && video.status !== 'unavailable' && video.missing.length > 0;
		var dateNeeded = video.missing.indexOf( 'upload_date' ) !== -1;
		var dateOpen = video.included && ( dateNeeded || draft !== committed );

		useEffect( function () {
			setDraft( committed );
		}, [ committed ] );

		return el(
			'section',
			{ className: 'estasoft-nd-ed-block estasoft-nd-ed-video', 'data-video': video.key },
			el( 'h3', { className: 'estasoft-nd-ed-label' }, sprintf( /* translators: %d: the number of a video. */ __( 'Video %d', 'estasoft-news-distribution' ), number ) ),
			video.thumbnail
				? el( 'img', { className: 'estasoft-nd-ed-thumb', src: video.thumbnail, alt: '', loading: 'lazy', referrerPolicy: 'no-referrer' } )
				: el( 'div', { className: 'estasoft-nd-ed-thumb estasoft-nd-ed-thumb--none', role: 'img', 'aria-label': __( 'No thumbnail yet', 'estasoft-news-distribution' ) } ),
			el(
				'div',
				{ className: 'estasoft-nd-ed-kind' },
				el( 'strong', null, siteName( video.site ) ),
				tag ? el( 'span', { className: 'estasoft-nd-ed-tag' }, icon( 'info' ), tag ) : null
			),
			! video.included ? el(
				'p',
				{ className: 'estasoft-nd-ed-muted' },
				video.reason === 'later'
					? __( 'Settings, Video, adds markup for the first video only, so this one gets none.', 'estasoft-news-distribution' )
					: sprintf(
						/* translators: %s: the name of a video site, such as YouTube. */
						__( 'Markup for %s is off in Settings, Video, so this video gets none.', 'estasoft-news-distribution' ),
						siteName( video.site )
					)
			) : null,
			video.included && video.skip ? el( 'p', { className: 'estasoft-nd-ed-note estasoft-nd-ed-note--info', role: 'status' }, __( 'Left out of the markup. Nothing is added to the page for this video.', 'estasoft-news-distribution' ) ) : null,
			video.included && ! video.skip && video.status === 'unavailable' ? el( 'p', { className: 'estasoft-nd-ed-note estasoft-nd-ed-note--warn', role: 'status' }, __( 'The video site says this video is private or was removed, so it gets no markup.', 'estasoft-news-distribution' ) ) : null,
			needed ? el(
				'div',
				{ className: 'estasoft-nd-ed-note estasoft-nd-ed-note--warn estasoft-nd-ed-note--lead', role: 'status' },
				icon( 'warn' ),
				el(
					'p',
					null,
					el( 'strong', null, sprintf( /* translators: %s: what Google asks for, such as "an upload date". */ __( 'Google asks for %s', 'estasoft-news-distribution' ), neededWords( video.missing ) ) ),
					el( 'span', null, video.missing.length > 1 ? __( 'Enter them, or leave this video out of the markup.', 'estasoft-news-distribution' ) : __( 'Enter it, or leave this video out of the markup.', 'estasoft-news-distribution' ) )
				)
			) : null,
			video.included ? el(
				Fragment,
				null,
				el( Text, {
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
					label: __( 'Name', 'estasoft-news-distribution' ),
					value: typedText( entry, 'name', video.name ),
					placeholder: video.name,
					maxLength: 200,
					onChange: function ( value ) {
						props.onChange( video.key, { name: value } );
					}
				} ),
				el( Text, {
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
					label: __( 'Upload date', 'estasoft-news-distribution' ),
					type: 'date',
					value: draft,
					min: EARLIEST,
					max: today(),
					onChange: setDraft,
					help: dateOpen ? __( 'A date in the future is not accepted. “Use this date” turns on once the date is valid.', 'estasoft-news-distribution' ) : undefined
				} ),
				el( DurationField, {
					value: entry && typeof entry.duration === 'number' && entry.duration > 0 ? entry.duration : video.duration,
					onCommit: function ( seconds ) {
						props.onChange( video.key, { duration: seconds } );
					}
				} ),
				el( Area, {
					__nextHasNoMarginBottom: true,
					label: __( 'Description', 'estasoft-news-distribution' ),
					value: typedText( entry, 'description', video.description ),
					placeholder: video.description,
					rows: 2,
					maxLength: 1000,
					onChange: function ( value ) {
						props.onChange( video.key, { description: value } );
					}
				} ),
				showThumbField ? el( Text, {
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
					label: __( 'Thumbnail address', 'estasoft-news-distribution' ),
					type: 'url',
					value: typedText( entry, 'thumbnail' ),
					placeholder: 'https://',
					onChange: function ( value ) {
						props.onChange( video.key, { thumbnail: value } );
					},
					help: __( 'The address of a picture for this video. Google asks for one.', 'estasoft-news-distribution' )
				} ) : null,
				el( 'p', { className: 'estasoft-nd-ed-field-help' }, lookupHelp( video ) )
			) : null,
			el(
				'div',
				{ className: 'estasoft-nd-ed-actions' },
				dateOpen ? el(
					Button,
					{
						variant: 'secondary',
						size: 'small',
						disabled: ! validDate( draft ) || draft === committed,
						onClick: function () {
							props.onChange( video.key, { upload_date: draft } );
						}
					},
					__( 'Use this date', 'estasoft-news-distribution' )
				) : null,
				video.included ? el(
					Button,
					{
						variant: 'secondary',
						size: 'small',
						onClick: function () {
							props.onChange( video.key, { skip: ! video.skip } );
						}
					},
					video.skip ? __( 'Put this video back', 'estasoft-news-distribution' ) : __( 'Leave this video out', 'estasoft-news-distribution' )
				) : null
			)
		);
	}

	/** "Ask the video sites now", when Look up details is on. */
	function AskButton( props ) {
		var state = useState( '' );
		var result = state[ 0 ];
		var setResult = state[ 1 ];

		function ask() {
			setResult( 'busy' );

			wp.apiFetch( {
				path: '/estasoft-nd/v1/' + settings.lookupPath.replace( '%d', String( props.postId ) ),
				method: 'POST'
			} ).then( function ( response ) {
				setResult( response && response.queued ? 'queued' : 'nothing' );
			} ).catch( function () {
				setResult( 'failed' );
			} );
		}

		var messages = {
			queued: __( 'Asked for in the background. The answers are saved with the article and show here when you reload it.', 'estasoft-news-distribution' ),
			nothing: __( 'Nothing to ask: every video that can be looked up has an answer already. Save the article first if you added a video.', 'estasoft-news-distribution' ),
			failed: __( 'The request could not be made. Save the article and try again.', 'estasoft-news-distribution' )
		};

		return el(
			Fragment,
			null,
			el( Button, { variant: 'secondary', size: 'small', disabled: result === 'busy', onClick: ask }, __( 'Look up details now', 'estasoft-news-distribution' ) ),
			messages[ result ] ? el( 'p', { className: 'estasoft-nd-ed-field-help', role: 'status' }, messages[ result ] ) : null
		);
	}

	function VideoSection() {
		var found = useArticle();
		var blocks = useBlocks();
		var editor = useDispatch( 'core/editor' );
		var valid = !! found && settings.postTypes.indexOf( found.type ) !== -1;
		var article = valid ? found : { id: 0, type: '', title: '', status: 'draft', overrides: [] };
		var data = useVideos( article, blocks, valid );

		if ( ! valid ) {
			return null;
		}

		/** Saves what was typed for one video in the editor's copy of the meta. Only the fields that were touched are kept. */
		function change( key, changes ) {
			var current = entryFor( article.overrides, key ) || {};
			var row = { key: key };

			Object.keys( current ).forEach( function ( field ) {
				if ( field !== 'key' ) {
					row[ field ] = current[ field ];
				}
			} );

			Object.keys( changes ).forEach( function ( field ) {
				row[ field ] = changes[ field ];
			} );

			var next = [];
			var placed = false;

			article.overrides.forEach( function ( item ) {
				if ( item && item.key === key ) {
					next.push( row );
					placed = true;
				} else {
					next.push( item );
				}
			} );

			if ( ! placed ) {
				next.push( row );
			}

			var meta = {};

			meta[ settings.metaKey ] = next;
			editor.editPost( { meta: meta } );
		}

		var videos = data && Array.isArray( data.videos ) ? data.videos : [];
		var lines = videos.map( function ( video, index ) {
			return summaryFor( video, index + 1 );
		} );
		var counted = videos.length;
		var summary;

		if ( ! data ) {
			summary = el( 'p', { className: 'estasoft-nd-ed-muted', role: 'status' }, __( 'Looking for videos…', 'estasoft-news-distribution' ) );
		} else if ( data.failed ) {
			summary = el( 'p', { className: 'estasoft-nd-ed-muted', role: 'status' }, __( 'The videos could not be listed. What you type is still saved with the article.', 'estasoft-news-distribution' ) );
		} else if ( ! counted ) {
			summary = el( 'p', { className: 'estasoft-nd-ed-muted', role: 'status' }, __( 'No videos found in this article. A video block, an embed block, or a YouTube or Vimeo address on a line of its own is found.', 'estasoft-news-distribution' ) );
		} else {
			summary = el(
				Fragment,
				null,
				el( 'p', { className: 'estasoft-nd-ed-muted' }, sprintf( /* translators: %d: a number of videos. */ _n( '%d video in this article', '%d videos in this article', counted, 'estasoft-news-distribution' ), counted ) ),
				el(
					'ul',
					{ className: 'estasoft-nd-ed-list' },
					lines.map( function ( line, index ) {
						return el( SummaryLine, { key: index, kind: line.kind, text: line.text } );
					} )
				)
			);
		}

		return el(
			'div',
			{ className: 'estasoft-nd-ed estasoft-nd-ed--video' },
			el(
				'section',
				{ className: 'estasoft-nd-ed-block' },
				el( 'h3', { className: 'estasoft-nd-ed-label' }, __( 'Summary', 'estasoft-news-distribution' ) ),
				el( 'p', { className: 'estasoft-nd-ed-muted' }, ( article.title || __( '(no title)', 'estasoft-news-distribution' ) ) + ' · ' + statusWord( article.status ) ),
				summary,
				settings.lookup && counted ? el( AskButton, { postId: article.id } ) : null
			),
			videos.map( function ( video, index ) {
				return el( VideoBlock, {
					key: video.key,
					video: video,
					number: index + 1,
					entry: entryFor( article.overrides, video.key ),
					onChange: change
				} );
			} ),
			el(
				'section',
				{ className: 'estasoft-nd-ed-block' },
				el( 'h3', { className: 'estasoft-nd-ed-label' }, __( 'Markup', 'estasoft-news-distribution' ) ),
				el( 'p', { className: 'estasoft-nd-ed-muted' }, __( 'Added to the page as video details for search, one block per video. Nothing is added for a video you leave out of the markup. Technical name: VideoObject.', 'estasoft-news-distribution' ) ),
				el( 'p', null, el( 'a', { href: settings.settingsUrl }, __( 'Open video settings', 'estasoft-news-distribution' ) ) )
			)
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

	wp.hooks.addFilter( FILTER, 'estasoft-nd/video', function ( sections ) {
		announceHost();

		var list = Array.isArray( sections ) ? sections.slice() : [];

		list.push( {
			id: 'video',
			name: 'video',
			title: __( 'News Distribution: video', 'estasoft-news-distribution' ),
			order: 50,
			render: function () {
				return el( VideoSection );
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

		return el( Panel, { name: 'estasoft-nd-video', title: __( 'News Distribution: video', 'estasoft-news-distribution' ), className: 'estasoft-nd-ed-panel' }, el( VideoSection ) );
	}

	if ( wp.plugins && wp.plugins.registerPlugin ) {
		wp.plugins.registerPlugin( 'estasoft-nd-video', { render: Fallback } );
	}
}( window.wp ) );
