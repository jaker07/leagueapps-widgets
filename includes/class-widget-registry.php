<?php
namespace LeagueApps_Widgets;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps track of widgets and exposes each one as a shortcode and a block.
 */
class Widget_Registry {

	const EDITOR_HANDLE = 'leagueapps-widgets-blocks';
	const STYLE_HANDLE  = 'leagueapps-widgets';

	/** @var Widget[] */
	private $widgets = array();

	public function hooks() {
		// Priority 20 so widgets registered on `leagueapps_widgets_register` are all in.
		add_action( 'init', array( $this, 'register_all' ), 20 );
	}

	public function register( Widget $widget ) {
		$this->widgets[ $widget->get_id() ] = $widget;
	}

	public function get( $id ) {
		return isset( $this->widgets[ $id ] ) ? $this->widgets[ $id ] : null;
	}

	public function all() {
		return $this->widgets;
	}

	public function register_all() {
		wp_register_style( self::STYLE_HANDLE, LAW_URL . 'assets/css/frontend.css', array(), LAW_VERSION );
		wp_register_script(
			self::EDITOR_HANDLE,
			LAW_URL . 'assets/js/blocks.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n' ),
			LAW_VERSION,
			true
		);

		$editor_data = array();

		foreach ( $this->widgets as $widget ) {
			add_shortcode( $widget->get_shortcode_tag(), $this->shortcode_callback( $widget ) );

			$schema = $widget->get_attributes();
			register_block_type(
				$widget->get_block_name(),
				array(
					'api_version'     => 2,
					'title'           => $widget->get_title(),
					'category'        => 'widgets',
					'attributes'      => $this->block_attributes( $schema ),
					'render_callback' => $this->block_callback( $widget ),
					'editor_script'   => self::EDITOR_HANDLE,
					'style'           => self::STYLE_HANDLE,
				)
			);

			$editor_data[] = array(
				'name'        => $widget->get_block_name(),
				'title'       => $widget->get_title(),
				'description' => $widget->get_description(),
				'icon'        => $widget->get_icon(),
				'attributes'  => $schema,
			);
		}

		wp_add_inline_script(
			self::EDITOR_HANDLE,
			'window.leagueAppsWidgets = ' . wp_json_encode( $editor_data ) . ';',
			'before'
		);
	}

	private function block_attributes( array $schema ) {
		$attrs = array();
		foreach ( $schema as $name => $def ) {
			$type           = isset( $def['type'] ) ? $def['type'] : 'string';
			$attrs[ $name ] = array(
				'type'    => $type,
				'default' => isset( $def['default'] ) ? $def['default'] : null,
			);
		}
		return $attrs;
	}

	private function shortcode_callback( Widget $widget ) {
		return function ( $atts ) use ( $widget ) {
			wp_enqueue_style( self::STYLE_HANDLE );
			// Shortcode attribute names are lowercased by WordPress.
			return $widget->render( $widget->normalize_attributes( (array) $atts ) );
		};
	}

	private function block_callback( Widget $widget ) {
		return function ( $attributes ) use ( $widget ) {
			$html = $widget->render( $widget->normalize_attributes( (array) $attributes ) );
			if ( '' === $html ) {
				return '';
			}
			return '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
		};
	}
}
