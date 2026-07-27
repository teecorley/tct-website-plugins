/**
 * Editor registration for the TCT Reader Kit blocks.
 *
 * Written in plain JS with wp.element.createElement rather than JSX, so the plugin
 * needs no build step — the file ships as-is and can be edited in place.
 *
 * Both blocks render server-side (PHP render_callback), so `save` returns null and
 * `edit` only needs to show an editable preview plus its settings panel.
 */
( function ( blocks, element, blockEditor, components, i18n ) {
	var el = element.createElement;
	var __ = i18n.__;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var ToggleControl = components.ToggleControl;
	var SelectControl = components.SelectControl;

	/* ----------------------------------------------------- Table of contents */

	blocks.registerBlockType( 'tct/table-of-contents', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;

			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{ title: __( 'Contents settings', 'tct-reader-kit' ) },
						el( TextControl, {
							label: __( 'Box title', 'tct-reader-kit' ),
							value: a.heading,
							onChange: function ( v ) {
								set( { heading: v } );
							},
							help: __( 'Leave empty to hide the title.', 'tct-reader-kit' ),
						} ),
						el( SelectControl, {
							label: __( 'Include headings up to', 'tct-reader-kit' ),
							value: String( a.maxLevel ),
							options: [
								{ label: __( 'H2 only', 'tct-reader-kit' ), value: '2' },
								{ label: __( 'H2 and H3', 'tct-reader-kit' ), value: '3' },
							],
							onChange: function ( v ) {
								set( { maxLevel: parseInt( v, 10 ) } );
							},
						} ),
						el( ToggleControl, {
							label: __( 'Stick to viewport while scrolling', 'tct-reader-kit' ),
							checked: !! a.sticky,
							onChange: function ( v ) {
								set( { sticky: v } );
							},
						} )
					)
				),
				el(
					'div',
					{ className: 'tct-toc' },
					a.heading
						? el( 'p', { className: 'tct-toc__title' }, a.heading )
						: null,
					el(
						'p',
						{
							style: {
								margin: 0,
								fontSize: '0.85rem',
								fontStyle: 'italic',
								opacity: 0.7,
							},
						},
						__(
							'Links to this post’s headings appear here automatically when the page is viewed.',
							'tct-reader-kit'
						)
					)
				)
			);
		},
		save: function () {
			return null;
		},
	} );

	/* ---------------------------------------------------------- Newsletter */

	blocks.registerBlockType( 'tct/newsletter', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;

			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{ title: __( 'Signup box settings', 'tct-reader-kit' ) },
						el( TextControl, {
							label: __( 'Heading', 'tct-reader-kit' ),
							value: a.heading,
							onChange: function ( v ) {
								set( { heading: v } );
							},
						} ),
						el( TextControl, {
							label: __( 'Button label', 'tct-reader-kit' ),
							value: a.buttonLabel,
							onChange: function ( v ) {
								set( { buttonLabel: v } );
							},
						} ),
						el( ToggleControl, {
							label: __( 'Ask for first name', 'tct-reader-kit' ),
							checked: !! a.showName,
							onChange: function ( v ) {
								set( { showName: v } );
							},
						} )
					)
				),
				el(
					'div',
					{ className: 'tct-news' },
					el( 'p', { className: 'tct-news__title' }, a.heading ),
					el(
						blockEditor.RichText,
						{
							tagName: 'p',
							className: 'tct-news__blurb',
							value: a.blurb,
							allowedFormats: [],
							placeholder: __( 'Short description…', 'tct-reader-kit' ),
							onChange: function ( v ) {
								set( { blurb: v } );
							},
						}
					),
					a.showName
						? el( 'input', {
								className: 'tct-news__input',
								type: 'text',
								placeholder: __( 'First name (optional)', 'tct-reader-kit' ),
								disabled: true,
						  } )
						: null,
					el( 'input', {
						className: 'tct-news__input',
						type: 'email',
						placeholder: 'you@example.com',
						disabled: true,
						style: { marginTop: '0.35rem' },
					} ),
					el(
						'button',
						{ className: 'tct-news__button', type: 'button', disabled: true },
						a.buttonLabel
					)
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.i18n
);
