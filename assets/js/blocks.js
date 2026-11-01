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

	function same( a, b ) {
		return String( a || '' ).trim().toLowerCase() === String( b || '' ).trim().toLowerCase();
	}

	/**
	 * Program lists, one request per site, shared by every block in the editor.
	 * Failed requests are forgotten so they can be retried. `programsLoaded`
	 * holds finished lists for synchronous lookups.
	 */
	var programCache = {};
	var programsLoaded = {};
	function loadPrograms( site ) {
		if ( ! programCache[ site ] ) {
			programCache[ site ] = wp.apiFetch( {
				path: wp.url.addQueryArgs( '/leagueapps-widgets/v1/programs', { site: site } )
			} ).then( function ( response ) {
				programsLoaded[ site ] = response.programs || [];
				return programsLoaded[ site ];
			} ).catch( function ( error ) {
				delete programCache[ site ];
				throw error;
			} );
		}
		return programCache[ site ];
	}

	/** The selected site's program list: { status: loading|ready|error, programs, error }. */
	function usePrograms( site ) {
		var state = useState( { status: 'loading', programs: [], error: '' } );
		var data = state[ 0 ];
		var setData = state[ 1 ];
		useEffect( function () {
			var active = true;
			setData( { status: 'loading', programs: [], error: '' } );
			loadPrograms( site ).then( function ( programs ) {
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
		}, [ site ] );
		return data;
	}

	/**
	 * A dropdown built from the site's program list, with an "Other…" choice
	 * that reveals a text field for values that aren't listed.
	 *
	 * props: def, value, onChange, data (from usePrograms), items [{value,label}],
	 *        emptyText, manualOption, manualLabel, manualHelp, unlistedHelp, clean.
	 */
	function ListControl( props ) {
		var def = props.def;
		var value = props.value || '';
		var data = props.data;
		var manualState = useState( false );
		var manualChosen = manualState[ 0 ];
		var setManualChosen = manualState[ 1 ];

		if ( data.status === 'loading' ) {
			return el( 'div', { className: 'law-list-control', style: { marginBottom: '24px' } },
				el( c.SelectControl, {
					label: def.label,
					value: '',
					disabled: true,
					options: [ { value: '', label: __( 'Loading…', 'leagueapps-widgets' ) } ],
					onChange: function () {}
				} )
			);
		}

		var match = null;
		props.items.forEach( function ( item ) {
			if ( match === null && value !== '' && same( item.value, value ) ) {
				match = item.value;
			}
		} );
		var manual = data.status === 'error' || manualChosen || ( value !== '' && match === null );

		var options = [ { value: '', label: def.empty_label || '—' } ]
			.concat( props.items )
			.concat( [ { value: MANUAL, label: props.manualOption } ] );

		var children = [ el( 'style', { key: 'css' }, '.law-list-control .law-list-notice{margin:0 0 12px}' ) ];

		if ( data.status === 'error' ) {
			children.push( el( c.Notice, { key: 'error', status: 'warning', isDismissible: false, className: 'law-list-notice' },
				data.error + ' ' + __( 'You can still type a value.', 'leagueapps-widgets' ) ) );
		} else {
			children.push( el( c.SelectControl, {
				key: 'select',
				label: def.label,
				help: manual ? undefined : def.help,
				value: manual ? MANUAL : ( match === null ? '' : match ),
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
			if ( props.items.length === 0 && props.emptyText ) {
				children.push( el( 'p', { key: 'none', className: 'components-base-control__help' }, props.emptyText ) );
			}
		}

		if ( manual ) {
			children.push( el( c.TextControl, {
				key: 'manual',
				label: data.status === 'error' ? def.label : props.manualLabel,
				help: value !== '' && data.status === 'ready' && match === null ? props.unlistedHelp : props.manualHelp,
				value: value,
				onChange: function ( v ) {
					props.onChange( props.clean ? props.clean( v ) : v );
				}
			} ) );
		}

		return el( 'div', { className: 'law-list-control', style: { marginBottom: '24px' } }, children );
	}

	/** Programs on the block's site, narrowed to a sport when one is chosen. */
	function ProgramControl( props ) {
		var data = usePrograms( props.site );
		var programs = data.programs.filter( function ( p ) {
			return ! props.sport || same( p.sport, props.sport );
		} );
		return el( ListControl, {
			def: props.def,
			value: props.value,
			onChange: props.onChange,
			data: data,
			items: programs.map( function ( p ) {
				var name = p.name || p.id;
				return { value: p.id, label: p.sport && ! props.sport ? p.sport + ': ' + name : name };
			} ),
			emptyText: props.sport
				? __( 'This site has no current programs in that sport.', 'leagueapps-widgets' )
				: __( 'This site has no current programs.', 'leagueapps-widgets' ),
			manualOption: __( 'Other program (enter ID)…', 'leagueapps-widgets' ),
			manualLabel: __( 'Program ID', 'leagueapps-widgets' ),
			manualHelp: __( 'The program’s ID number in LeagueApps.', 'leagueapps-widgets' ),
			unlistedHelp: __( 'This program isn’t one of the current programs listed above. That’s fine for a past program; otherwise check the ID.', 'leagueapps-widgets' ),
			clean: function ( v ) {
				return v.replace( /[^0-9]/g, '' );
			}
		} );
	}

	/** Sports of the current programs on the block's site. */
	function SportControl( props ) {
		var data = usePrograms( props.site );
		var sports = [];
		data.programs.forEach( function ( p ) {
			if ( p.sport && ! sports.some( function ( s ) { return same( s, p.sport ); } ) ) {
				sports.push( p.sport );
			}
		} );
		sports.sort( function ( a, b ) {
			return a.localeCompare( b );
		} );
		return el( ListControl, {
			def: props.def,
			value: props.value,
			onChange: props.onChange,
			data: data,
			items: sports.map( function ( s ) {
				return { value: s, label: s };
			} ),
			emptyText: __( 'This site has no current programs, so there are no sports to list.', 'leagueapps-widgets' ),
			manualOption: __( 'Other sport (type it)…', 'leagueapps-widgets' ),
			manualLabel: __( 'Sport name', 'leagueapps-widgets' ),
			manualHelp: __( 'As named in LeagueApps.', 'leagueapps-widgets' ),
			unlistedHelp: __( 'No current program on this site has this sport, so nothing will show until one does.', 'leagueapps-widgets' )
		} );
	}

	/**
	 * Attribute changes that make other attributes meaningless:
	 * - a new site clears every program and sport (they belong to one site);
	 * - a new sport clears a program filtered by it if that program is in another sport.
	 */
	function dependentResets( widget, name, newValue, attributes ) {
		var reset = {};
		var schema = widget.attributes;
		Object.keys( schema ).forEach( function ( other ) {
			var def = schema[ other ];
			if ( schema[ name ].is_site && ( def.control === 'program' || def.control === 'sport' ) ) {
				reset[ other ] = '';
			}
			if ( def.control === 'program' && def.filter_by === name && newValue && attributes[ other ] ) {
				var list = programsLoaded[ attributes.site || '' ] || [];
				var program = list.filter( function ( p ) { return p.id === attributes[ other ]; } )[ 0 ];
				if ( program && ! same( program.sport, newValue ) ) {
					reset[ other ] = '';
				}
			}
		} );
		return reset;
	}

	function control( widget, name, attributes, setAttributes ) {
		var def = widget.attributes[ name ];
		var value = attributes[ name ];
		var site = attributes.site || '';
		var set = function ( v ) {
			var update = v !== value ? dependentResets( widget, name, v, attributes ) : {};
			update[ name ] = v;
			setAttributes( update );
		};
		var common = { key: name, label: def.label || name, help: def.help || undefined };

		// e.g. the site picker when only one site is configured.
		if ( def.hidden && ! value ) {
			return null;
		}

		// Keyed by site so the control starts fresh whenever the site changes.
		if ( def.control === 'program' ) {
			return el( ProgramControl, {
				key: name + ':' + site,
				def: def,
				value: value,
				site: site,
				sport: def.filter_by ? attributes[ def.filter_by ] : '',
				onChange: set
			} );
		}
		if ( def.control === 'sport' ) {
			return el( SportControl, { key: name + ':' + site, def: def, value: value, site: site, onChange: set } );
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
					return control( widget, name, props.attributes, props.setAttributes );
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
