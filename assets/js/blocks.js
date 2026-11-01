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
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var c = wp.components;
	var ServerSideRender = wp.serverSideRender;
	var __ = wp.i18n.__;

	var MANUAL = '__manual__';

	/**
	 * Program lists, one request per site, shared by every block in the editor.
	 * Failed requests are forgotten so they can be retried.
	 */
	var programCache = {};
	function loadPrograms( site ) {
		if ( ! programCache[ site ] ) {
			programCache[ site ] = wp.apiFetch( {
				path: wp.url.addQueryArgs( '/leagueapps-widgets/v1/programs', { site: site } )
			} ).then( function ( response ) {
				return response.programs || [];
			} ).catch( function ( error ) {
				delete programCache[ site ];
				throw error;
			} );
		}
		return programCache[ site ];
	}

	/**
	 * Dropdown of the selected site's current programs, with an "enter an ID"
	 * fallback for programs that aren't listed (e.g. finished seasons).
	 * Rendered with key = site, so it starts fresh whenever the site changes.
	 */
	function ProgramControl( props ) {
		var def = props.def;
		var value = props.value || '';
		var state = useState( { status: 'loading', programs: [], error: '' } );
		var data = state[ 0 ];
		var setData = state[ 1 ];
		var manualState = useState( false );
		var manualChosen = manualState[ 0 ];
		var setManualChosen = manualState[ 1 ];

		useEffect( function () {
			var active = true;
			setData( { status: 'loading', programs: [], error: '' } );
			loadPrograms( props.site ).then( function ( programs ) {
				if ( active ) {
					setData( { status: 'ready', programs: programs, error: '' } );
				}
			}, function ( error ) {
				if ( active ) {
					setData( { status: 'error', programs: [], error: ( error && error.message ) || __( 'Could not load programs.', 'leagueapps-widgets' ) } );
				}
			} );
			return function () {
				active = false;
			};
		}, [ props.site ] );

		if ( data.status === 'loading' ) {
			return el( c.SelectControl, {
				label: def.label,
				value: '',
				disabled: true,
				options: [ { value: '', label: __( 'Loading programs…', 'leagueapps-widgets' ) } ],
				onChange: function () {}
			} );
		}

		var listed = data.programs.some( function ( p ) {
			return p.id === value;
		} );
		var manual = data.status === 'error' || manualChosen || ( value !== '' && ! listed );

		var options = [ { value: '', label: def.empty_label || __( '— Select a program —', 'leagueapps-widgets' ) } ];
		data.programs.forEach( function ( p ) {
			var name = p.name || p.id;
			options.push( { value: p.id, label: p.sport ? p.sport + ': ' + name : name } );
		} );
		options.push( { value: MANUAL, label: __( 'Other program (enter ID)…', 'leagueapps-widgets' ) } );

		var children = [];

		if ( data.status === 'error' ) {
			children.push( el( c.Notice, { key: 'error', status: 'warning', isDismissible: false, className: 'law-program-notice' },
				data.error + ' ' + __( 'You can still enter a program ID.', 'leagueapps-widgets' ) ) );
		} else {
			children.push( el( c.SelectControl, {
				key: 'select',
				label: def.label,
				help: manual ? undefined : def.help,
				value: manual ? MANUAL : value,
				options: options,
				onChange: function ( v ) {
					if ( v === MANUAL ) {
						setManualChosen( true );
						return;
					}
					setManualChosen( false );
					props.onChange( v );
				}
			} ) );
			if ( data.programs.length === 0 ) {
				children.push( el( 'p', { key: 'none', className: 'components-base-control__help' },
					__( 'This site has no current programs.', 'leagueapps-widgets' ) ) );
			}
		}

		if ( manual ) {
			var help = __( 'The program’s ID number in LeagueApps.', 'leagueapps-widgets' );
			if ( value !== '' && data.status === 'ready' && ! listed ) {
				help = __( 'This program isn’t one of this site’s current programs. That’s fine for a past program; otherwise check the ID.', 'leagueapps-widgets' );
			}
			children.push( el( c.TextControl, {
				key: 'manual',
				label: data.status === 'error' ? def.label : __( 'Program ID', 'leagueapps-widgets' ),
				help: help,
				value: value,
				onChange: function ( v ) {
					props.onChange( v.replace( /[^0-9]/g, '' ) );
				}
			} ) );
		}

		return el( 'div', { className: 'law-program-control', style: { marginBottom: '24px' } },
			el( 'style', null, '.law-program-control .law-program-notice{margin:0 0 12px}' ),
			children
		);
	}

	function control( name, def, attributes, setAttributes, programAttrs ) {
		var value = attributes[ name ];
		var set = function ( v ) {
			var update = {};
			update[ name ] = v;
			// A program ID only makes sense on the site it came from.
			if ( def.is_site && v !== value ) {
				programAttrs.forEach( function ( p ) {
					update[ p ] = '';
				} );
			}
			setAttributes( update );
		};
		var common = { key: name, label: def.label || name, help: def.help || undefined };

		// e.g. the site picker when only one site is configured.
		if ( def.hidden && ! value ) {
			return null;
		}

		if ( def.control === 'program' ) {
			return el( ProgramControl, {
				key: name + ':' + ( attributes.site || '' ),
				def: def,
				value: value,
				site: attributes.site || '',
				onChange: set
			} );
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
		var programAttrs = [];
		Object.keys( widget.attributes ).forEach( function ( name ) {
			attributes[ name ] = { type: widget.attributes[ name ].type || 'string', default: widget.attributes[ name ]['default'] };
			if ( widget.attributes[ name ].control === 'program' ) {
				programAttrs.push( name );
			}
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
					return control( name, widget.attributes[ name ], props.attributes, props.setAttributes, programAttrs );
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
