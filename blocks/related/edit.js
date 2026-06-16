/**
 * Vragen.ai Related content block — editor UI.
 *
 * No build step: plain ES5 using the wp.* globals (declared as script
 * dependencies in PHP). The block is dynamic — the front end and the editor
 * preview are both rendered server-side. The preview uses ServerSideRender and
 * passes the current post id so the API can find related content for it.
 */
( function ( blocks, element, blockEditor, components, i18n, ServerSideRender, data ) {
	var el = element.createElement;
	var Fragment = element.Fragment;
	var __ = i18n.__;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var RangeControl = components.RangeControl;
	var SelectControl = components.SelectControl;

	// Vragen.ai brand mark (same as the embed block). Gradient ids are
	// namespaced per block so two inline SVGs can't collide on one page.
	var markPath =
		'M24 12a12 12 0 0 1-12 12H2.6c-.7 0-1-.8-.6-1.3L4 21a12 12 0 0 1 8-21 12 12 0 0 1 12 12Zm-8.3-8c.2 0 .5.2.5.5l.3.8a2 2 0 0 0 1.4 1.5l.9.2c.2 0 .4.3.4.5 0 .3-.2.5-.4.6l-.9.2a2 2 0 0 0-1.4 1.5l-.3.8c0 .2-.3.4-.5.4a.6.6 0 0 1-.6-.4l-.2-.8a2 2 0 0 0-1.5-1.5l-.8-.2a.6.6 0 0 1-.4-.6c0-.2.1-.5.4-.5l.8-.2A2 2 0 0 0 15 5.3l.2-.8c0-.3.3-.5.6-.5Zm-7 2.4c.2 0 .4.1.5.4L9.8 9a3 3 0 0 0 2 2l2.3.6c.2 0 .4.3.4.5 0 .3-.2.5-.4.6l-2.2.6a3 3 0 0 0-2 2l-.7 2.2c0 .3-.3.4-.6.4a.6.6 0 0 1-.5-.4l-.7-2.2a3 3 0 0 0-2-2l-2.2-.6a.6.6 0 0 1-.4-.6c0-.2.1-.4.4-.5l2.2-.6a3 3 0 0 0 2-2l.7-2.2c0-.3.3-.4.5-.4Zm6.4 8.5c-.1-.3-.3-.4-.6-.4-.2 0-.5.1-.5.4l-.4.9c0 .3-.3.6-.7.7l-1 .3c-.2 0-.3.3-.3.6 0 .2.1.4.4.5l.9.3c.4.1.6.4.7.7l.4 1c0 .2.3.3.5.3.3 0 .5-.1.6-.3l.3-1c0-.3.4-.6.7-.7l1-.3c.2 0 .3-.3.3-.5 0-.3-.1-.5-.4-.6l-.9-.3c-.3-.1-.6-.4-.7-.7l-.3-1Z';

	var brandIcon = el(
		'svg',
		{ width: 24, height: 24, viewBox: '0 0 24 24', fill: 'none', xmlns: 'http://www.w3.org/2000/svg' },
		el( 'path', { fillRule: 'evenodd', clipRule: 'evenodd', fill: 'url(#vragenai-related-a)', d: markPath } ),
		el( 'path', { fillRule: 'evenodd', clipRule: 'evenodd', fill: 'url(#vragenai-related-b)', d: markPath } ),
		el(
			'defs',
			null,
			el(
				'linearGradient',
				{ id: 'vragenai-related-a', x1: '19.2', x2: '-4.1', y1: '3.7', y2: '11.2', gradientUnits: 'userSpaceOnUse' },
				el( 'stop', { stopColor: '#3BA39A' } ),
				el( 'stop', { offset: '1', stopColor: '#191654' } )
			),
			el(
				'linearGradient',
				{ id: 'vragenai-related-b', x1: '-8.6', x2: '30.8', y1: '27.8', y2: '3', gradientUnits: 'userSpaceOnUse' },
				el( 'stop', { stopColor: '#0E1EA3' } ),
				el( 'stop', { offset: '.5', stopColor: '#33A4A2' } ),
				el( 'stop', { offset: '1', stopColor: '#299D36' } )
			)
		)
	);

	function currentPostId() {
		var editor = data.select( 'core/editor' );
		return editor && editor.getCurrentPostId ? editor.getCurrentPostId() : 0;
	}

	function Edit( props ) {
		var attributes = props.attributes;
		var setAttributes = props.setAttributes;
		var postId = currentPostId();

		return el(
			Fragment,
			null,
			el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __( 'Instellingen', 'vragen-ai' ) },
					el( TextControl, {
						label: __( 'Titel', 'vragen-ai' ),
						value: attributes.title || '',
						placeholder: __( 'Bijv. Gerelateerde content', 'vragen-ai' ),
						onChange: function ( value ) {
							setAttributes( { title: value } );
						},
						__nextHasNoMarginBottom: true,
					} ),
					el( RangeControl, {
						label: __( 'Aantal items', 'vragen-ai' ),
						value: attributes.numberOfItems || 4,
						min: 1,
						max: 12,
						onChange: function ( value ) {
							setAttributes( { numberOfItems: value } );
						},
						__nextHasNoMarginBottom: true,
					} ),
					el( SelectControl, {
						label: __( 'Weergave', 'vragen-ai' ),
						value: attributes.displayStyle || 'list',
						options: [
							{ label: __( 'Lijst', 'vragen-ai' ), value: 'list' },
							{ label: __( 'Kaarten', 'vragen-ai' ), value: 'cards' },
						],
						onChange: function ( value ) {
							setAttributes( { displayStyle: value } );
						},
						__nextHasNoMarginBottom: true,
					} ),
					el( RangeControl, {
						label: __( 'Max. semantische afstand', 'vragen-ai' ),
						value: attributes.maxDistance,
						min: 0,
						max: 1,
						step: 0.05,
						allowReset: true,
						help: __( 'Optioneel. Laat leeg voor geen limiet; lager is strenger.', 'vragen-ai' ),
						onChange: function ( value ) {
							setAttributes( { maxDistance: value } );
						},
						__nextHasNoMarginBottom: true,
					} )
				)
			),
			el(
				'div',
				useBlockProps(),
				el( ServerSideRender, {
					block: 'vragenai/related',
					attributes: attributes,
					urlQueryArgs: postId ? { post_id: postId } : {},
				} )
			)
		);
	}

	blocks.registerBlockType( 'vragenai/related', {
		icon: brandIcon,
		edit: Edit,
		// Dynamic block: the markup is output by PHP.
		save: function () {
			return null;
		},
	} );
} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.i18n,
	window.wp.serverSideRender,
	window.wp.data
);
