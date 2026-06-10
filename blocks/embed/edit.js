/**
 * Vragen.ai Embed block — editor UI.
 *
 * No build step: plain ES5 using the wp.* globals (declared as script
 * dependencies in PHP). The front end is rendered server-side, so save()
 * returns null and the editor only collects the deployment slug.
 *
 * Deployments are fetched from a server-side proxy (/vragenai/v1/deployments)
 * so the API token never reaches the browser. If the list can't be loaded
 * (no credentials, API error), the editor falls back to a manual slug field.
 */
( function ( blocks, element, blockEditor, components, i18n, apiFetch ) {
	var el = element.createElement;
	var useState = element.useState;
	var useEffect = element.useEffect;
	var __ = i18n.__;
	var useBlockProps = blockEditor.useBlockProps;
	var Placeholder = components.Placeholder;
	var SelectControl = components.SelectControl;
	var TextControl = components.TextControl;
	var Spinner = components.Spinner;

	// Vragen.ai brand mark (from web-converse logo-sparkles.svg). Gradient ids
	// are namespaced so they can't collide with other inline SVGs on the page.
	var markPath =
		'M24 12a12 12 0 0 1-12 12H2.6c-.7 0-1-.8-.6-1.3L4 21a12 12 0 0 1 8-21 12 12 0 0 1 12 12Zm-8.3-8c.2 0 .5.2.5.5l.3.8a2 2 0 0 0 1.4 1.5l.9.2c.2 0 .4.3.4.5 0 .3-.2.5-.4.6l-.9.2a2 2 0 0 0-1.4 1.5l-.3.8c0 .2-.3.4-.5.4a.6.6 0 0 1-.6-.4l-.2-.8a2 2 0 0 0-1.5-1.5l-.8-.2a.6.6 0 0 1-.4-.6c0-.2.1-.5.4-.5l.8-.2A2 2 0 0 0 15 5.3l.2-.8c0-.3.3-.5.6-.5Zm-7 2.4c.2 0 .4.1.5.4L9.8 9a3 3 0 0 0 2 2l2.3.6c.2 0 .4.3.4.5 0 .3-.2.5-.4.6l-2.2.6a3 3 0 0 0-2 2l-.7 2.2c0 .3-.3.4-.6.4a.6.6 0 0 1-.5-.4l-.7-2.2a3 3 0 0 0-2-2l-2.2-.6a.6.6 0 0 1-.4-.6c0-.2.1-.4.4-.5l2.2-.6a3 3 0 0 0 2-2l.7-2.2c0-.3.3-.4.5-.4Zm6.4 8.5c-.1-.3-.3-.4-.6-.4-.2 0-.5.1-.5.4l-.4.9c0 .3-.3.6-.7.7l-1 .3c-.2 0-.3.3-.3.6 0 .2.1.4.4.5l.9.3c.4.1.6.4.7.7l.4 1c0 .2.3.3.5.3.3 0 .5-.1.6-.3l.3-1c0-.3.4-.6.7-.7l1-.3c.2 0 .3-.3.3-.5 0-.3-.1-.5-.4-.6l-.9-.3c-.3-.1-.6-.4-.7-.7l-.3-1Z';

	var brandIcon = el(
		'svg',
		{ width: 24, height: 24, viewBox: '0 0 24 24', fill: 'none', xmlns: 'http://www.w3.org/2000/svg' },
		el( 'path', { fillRule: 'evenodd', clipRule: 'evenodd', fill: 'url(#vragenai-mark-a)', d: markPath } ),
		el( 'path', { fillRule: 'evenodd', clipRule: 'evenodd', fill: 'url(#vragenai-mark-b)', d: markPath } ),
		el(
			'defs',
			null,
			el(
				'linearGradient',
				{ id: 'vragenai-mark-a', x1: '19.2', x2: '-4.1', y1: '3.7', y2: '11.2', gradientUnits: 'userSpaceOnUse' },
				el( 'stop', { stopColor: '#3BA39A' } ),
				el( 'stop', { offset: '1', stopColor: '#191654' } )
			),
			el(
				'linearGradient',
				{ id: 'vragenai-mark-b', x1: '-8.6', x2: '30.8', y1: '27.8', y2: '3', gradientUnits: 'userSpaceOnUse' },
				el( 'stop', { stopColor: '#0E1EA3' } ),
				el( 'stop', { offset: '.5', stopColor: '#33A4A2' } ),
				el( 'stop', { offset: '1', stopColor: '#299D36' } )
			)
		)
	);

	function Edit( props ) {
		var deployment = props.attributes.deployment || '';
		var stateHook = useState( { loading: true, items: [], failed: false } );
		var state = stateHook[0];
		var setState = stateHook[1];

		useEffect( function () {
			var active = true;
			apiFetch( { path: '/vragenai/v1/deployments' } )
				.then( function ( items ) {
					if ( active ) {
						setState( { loading: false, items: items || [], failed: false } );
					}
				} )
				.catch( function () {
					if ( active ) {
						setState( { loading: false, items: [], failed: true } );
					}
				} );
			return function () {
				active = false;
			};
		}, [] );

		var field;
		if ( state.loading ) {
			field = el( Spinner, null );
		} else if ( state.items.length ) {
			var options = [ { label: __( 'Selecteer een deployment…', 'vragen-ai' ), value: '' } ].concat(
				state.items.map( function ( d ) {
					return {
						label: d.build_type ? d.name + ' (' + d.build_type + ')' : d.name,
						value: d.slug,
					};
				} )
			);
			field = el( SelectControl, {
				label: __( 'Deployment', 'vragen-ai' ),
				value: deployment,
				options: options,
				onChange: function ( value ) {
					props.setAttributes( { deployment: value } );
				},
				__nextHasNoMarginBottom: true,
			} );
		} else {
			// Fallback: API unavailable or no credentials — let the user type a slug.
			field = el( TextControl, {
				label: __( 'Deployment-slug', 'vragen-ai' ),
				value: deployment,
				placeholder: 'my-deployment',
				help: state.failed
					? __( 'Kon de lijst met deployments niet laden. Voer de slug handmatig in.', 'vragen-ai' )
					: __( 'Voer de deployment-slug handmatig in.', 'vragen-ai' ),
				onChange: function ( value ) {
					props.setAttributes( { deployment: value } );
				},
				__nextHasNoMarginBottom: true,
			} );
		}

		return el(
			'div',
			useBlockProps(),
			el(
				Placeholder,
				{
					icon: brandIcon,
					label: __( 'Vragen.ai embed', 'vragen-ai' ),
					instructions: __(
						'Kies de Vragen.ai-deployment om in te sluiten. Het build-type (pagina, popup of popover) komt uit de deployment zelf.',
						'vragen-ai'
					),
				},
				field
			)
		);
	}

	blocks.registerBlockType( 'vragenai/embed', {
		icon: brandIcon,
		edit: Edit,
		// Dynamic block: the markup + embed script are output by PHP.
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
	window.wp.apiFetch
);
