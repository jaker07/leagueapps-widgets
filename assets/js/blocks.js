/**
 * Generic block registration for every LeagueApps widget.
 * Sidebar controls are generated from each widget's PHP attribute schema;
 * the preview is rendered server-side so it matches the front end exactly.
 */
( function ( wp, widgets ) {
	if ( ! wp || ! widgets ) {
		return;
	}
	var el = wp.element.createElement;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var c = wp.components;
	var ServerSideRender = wp.serverSideRender;

	function control( name, def, value, setAttributes ) {
		var set = function ( v ) {
			var update = {};
			update[ name ] = v;
			setAttributes( update );
		};
		var common = { key: name, label: def.label || name, help: def.help || undefined };

		// e.g. the site picker when only one site is configured.
		if ( def.hidden && ! value ) {
			return null;
		}

		if ( def.type === 'boolean' ) {
			return el( c.ToggleControl, Object.assign( common, { checked: !! value, onChange: set } ) );
		}
		if ( def.options ) {
			var options = Object.keys( def.options ).map( function ( k ) {
				return { value: k, label: def.options[ k ] };
			} );
			if ( value && ! def.options.hasOwnProperty( value ) ) {
				// Saved value no longer offered (e.g. a deleted site): keep it visible.
				options.push( { value: value, label: value } );
			}
			return el( c.SelectControl, Object.assign( common, { value: value, options: options, onChange: set } ) );
		}
		if ( def.type === 'integer' ) {
			return el( c.TextControl, Object.assign( common, {
				type: 'number',
				value: value,
				onChange: function ( v ) { set( parseInt( v, 10 ) || 0 ); }
			} ) );
		}
		return el( c.TextControl, Object.assign( common, { value: value || '', onChange: set } ) );
	}

	widgets.forEach( function ( widget ) {
		var attributes = {};
		Object.keys( widget.attributes ).forEach( function ( name ) {
			attributes[ name ] = { type: widget.attributes[ name ].type || 'string', default: widget.attributes[ name ]['default'] };
		} );

		wp.blocks.registerBlockType( widget.name, {
			apiVersion: 2,
			title: widget.title,
			description: widget.description,
			icon: widget.icon,
			category: 'widgets',
			attributes: attributes,
			edit: function ( props ) {
				var controls = Object.keys( widget.attributes ).map( function ( name ) {
					return control( name, widget.attributes[ name ], props.attributes[ name ], props.setAttributes );
				} );
				return el( 'div', useBlockProps(),
					el( InspectorControls, null, el( c.PanelBody, { title: widget.title, initialOpen: true }, controls ) ),
					el( ServerSideRender, { block: widget.name, attributes: props.attributes } )
				);
			},
			save: function () {
				return null;
			}
		} );
	} );
}( window.wp, window.leagueAppsWidgets ) );
