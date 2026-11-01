/*
 * Article editor: the "News Distribution labels" block.
 *
 * It has no controls. The server draws it, so it shows what the page will show. Plain script, no build step.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element || ! wp.blockEditor || ! wp.serverSideRender || ! wp.data || ! wp.i18n ) {
		return;
	}

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var ServerSideRender = wp.serverSideRender;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var useSelect = wp.data.useSelect;

	function Empty() {
		return el( 'p', { className: 'estasoft-nd-label-placeholder' }, __( 'News Distribution labels: nothing to show yet. The labels appear on the page when they apply to the article, such as after an update or when it is marked as AI-assisted or republished.', 'estasoft-news-distribution' ) );
	}

	function Failed() {
		return el( 'p', { className: 'estasoft-nd-label-placeholder' }, __( 'News Distribution labels: the preview could not be loaded. The block still prints on the page.', 'estasoft-news-distribution' ) );
	}

	function Edit() {
		var blockProps = useBlockProps( { className: 'estasoft-nd-labels-block' } );
		var postId = useSelect( function ( select ) {
			var editor = select( 'core/editor' );

			return editor && editor.getCurrentPostId ? editor.getCurrentPostId() : 0;
		}, [] );

		return el(
			'div',
			blockProps,
			el( ServerSideRender, {
				block: 'estasoft-nd/labels',
				urlQueryArgs: { post_id: postId },
				EmptyResponsePlaceholder: Empty,
				ErrorResponsePlaceholder: Failed
			} )
		);
	}

	wp.blocks.registerBlockType( 'estasoft-nd/labels', {
		apiVersion: 3,
		title: __( 'News Distribution labels', 'estasoft-news-distribution' ),
		description: __( 'Small lines that tell readers what changed or where text came from: Updated, AI-assisted, correction and source.', 'estasoft-news-distribution' ),
		category: 'widgets',
		icon: 'tag',
		attributes: {},
		usesContext: [ 'postId' ],
		supports: { html: false, multiple: false, reusable: false },
		edit: Edit,
		save: function () {
			return null;
		}
	} );
}( window.wp ) );
