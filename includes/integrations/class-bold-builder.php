<?php
namespace LeagueApps_Widgets\Integrations;

use LeagueApps_Widgets\Settings;
use LeagueApps_Widgets\Widget;
use LeagueApps_Widgets\Widget_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Bold Builder support: every LeagueApps widget becomes a Bold Builder
 * element, with settings generated from the widget's attribute schema.
 *
 * Elements are the widgets' own shortcodes, so pages built with Bold Builder
 * store the same [leagueapps_*] shortcodes as anywhere else.
 *
 * Bold Builder's dropdowns are fixed lists, so the site is a dropdown and
 * program and sport are typed (the block editor's site-dependent dropdowns
 * aren't possible there).
 */
class Bold_Builder {

	const ADMIN_STYLE = 'assets/css/bold-builder-admin.css';

	/** @var Widget_Registry */
	private $registry;

	/** @var Settings */
	private $settings;

	public function __construct( Widget_Registry $registry, Settings $settings ) {
		$this->registry = $registry;
		$this->settings = $settings;
	}

	public function hooks() {
		// Bold Builder maps its own elements on admin_bar_init at priority 8 and
		// builds its front-end editor's element list at priority 9.
		add_action( 'admin_bar_init', array( $this, 'map_elements' ), 8 );
		add_filter( 'leagueapps_widgets_shortcode_output', array( $this, 'mark_for_front_end_editor' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_front_end_editor_style' ) );
	}

	public static function is_active() {
		return function_exists( 'bt_bb_map' );
	}

	public function map_elements() {
		if ( ! self::is_active() ) {
			return;
		}
		foreach ( $this->registry->all() as $widget ) {
			$tag = $widget->get_shortcode_tag();
			bt_bb_map(
				$tag,
				apply_filters(
					'leagueapps_widgets_bold_builder_element',
					array(
						'name'              => $widget->get_title(),
						'description'       => $widget->get_description(),
						'icon'              => 'bt_bb_icon_' . $tag,
						'admin_enqueue_css' => array( LAW_URL . self::ADMIN_STYLE ),
						'params'            => $this->params( $widget ),
					),
					$widget
				)
			);
		}
	}

	/**
	 * Bold Builder params from the widget's attribute schema.
	 */
	private function params( Widget $widget ) {
		$params = array();
		foreach ( $widget->get_all_attributes() as $name => $def ) {
			$type  = isset( $def['type'] ) ? $def['type'] : 'string';
			$param = array(
				'param_name' => $name,
				'heading'    => isset( $def['label'] ) ? $def['label'] : $name,
			);
			$help = isset( $def['help'] ) ? $def['help'] : '';

			if ( ! empty( $def['is_site'] ) ) {
				$sites = $this->settings->get_sites();
				if ( count( $sites ) < 2 ) {
					continue; // Nothing to choose; widgets use the default site.
				}
				$param['type']    = 'dropdown';
				$param['value']   = $this->site_values( $sites );
				$param['preview'] = true;
				$help             = __( 'Which site from Settings > LeagueApps to read from.', 'leagueapps-widgets' );
			} elseif ( 'boolean' === $type ) {
				// A Bold Builder checkbox stores nothing when unchecked, which can't
				// turn off an option that defaults to on; use Yes/No instead.
				$yes             = __( 'Yes', 'leagueapps-widgets' );
				$no              = __( 'No', 'leagueapps-widgets' );
				$param['type']   = 'dropdown';
				$param['value']  = ! empty( $def['default'] ) ? array( $yes => 'true', $no => 'false' ) : array( $no => 'false', $yes => 'true' );
				$param['group']  = __( 'Display', 'leagueapps-widgets' );
			} elseif ( ! empty( $def['options'] ) ) {
				$param['type']    = 'dropdown';
				$param['value']   = $this->default_first( array_flip( $def['options'] ), isset( $def['default'] ) ? $def['default'] : null );
				$param['preview'] = true;
			} else {
				$param['type'] = 'textfield';
				if ( isset( $def['control'] ) && 'program' === $def['control'] ) {
					$param['heading'] = __( 'Program ID', 'leagueapps-widgets' );
					$help             = trim( $help . ' ' . __( 'The program’s ID number in LeagueApps.', 'leagueapps-widgets' ) );
					$param['preview'] = true;
				} elseif ( isset( $def['control'] ) && 'sport' === $def['control'] ) {
					$help             = trim( $help . ' ' . __( 'As named in LeagueApps, e.g. Soccer.', 'leagueapps-widgets' ) );
					$param['preview'] = true;
				} elseif ( 'integer' === $type && isset( $def['default'] ) ) {
					$param['placeholder'] = (string) $def['default'];
				}
			}

			if ( '' !== $help ) {
				$param['description'] = $help;
			}
			$params[] = $param;
		}
		return apply_filters( 'leagueapps_widgets_bold_builder_params', $params, $widget );
	}

	/** Default site first (stored as '', like an empty block attribute). */
	private function site_values( array $sites ) {
		$values = array();
		$first  = true;
		foreach ( $sites as $slug => $site ) {
			if ( $first ) {
				/* translators: %s: default site label */
				$values[ sprintf( __( '%s (default)', 'leagueapps-widgets' ), $site['label'] ) ] = '';
				$first = false;
				continue;
			}
			// Labels are dropdown keys; keep duplicates distinct.
			$label = isset( $values[ $site['label'] ] ) ? $site['label'] . ' (' . $slug . ')' : $site['label'];
			$values[ $label ] = $slug;
		}
		return $values;
	}

	/** Bold Builder preselects the first value, so put the default there. */
	private function default_first( array $values, $default ) {
		$label = array_search( $default, $values, true );
		if ( false === $label ) {
			return $values;
		}
		return array( $label => $values[ $label ] ) + $values;
	}

	/**
	 * Bold Builder's front-end editor finds elements by data attributes on the
	 * outermost tag; add them the way its own elements do.
	 */
	public function mark_for_front_end_editor( $html, $widget, $atts ) {
		if ( '' === $html || ! class_exists( 'BT_BB_FE' ) || ! current_user_can( 'edit_pages' ) ) {
			return $html;
		}
		if ( array_key_exists( 'ignore_fe_editor', $atts ) ) {
			return $html;
		}
		\BT_BB_FE::$fe_id++;
		$attrs = sprintf(
			' data-base="%s" data-bt-bb-fe-atts="%s" data-fe-id="%d"',
			esc_attr( $widget->get_shortcode_tag() ),
			esc_attr( wp_json_encode( (object) $atts ) ),
			\BT_BB_FE::$fe_id
		);
		return preg_replace( '/^(\s*<\w+)/', '$1' . str_replace( '$', '\\$', $attrs ), $html, 1 );
	}

	/** Elements added in the front-end editor arrive without a page reload. */
	public function enqueue_front_end_editor_style() {
		if ( class_exists( 'BT_BB_FE' ) && ! empty( \BT_BB_FE::$editor_active ) ) {
			wp_enqueue_style( Widget_Registry::STYLE_HANDLE );
		}
	}
}
