<?php
namespace LeagueApps_Widgets;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for every LeagueApps widget.
 *
 * A widget declares an ID, a title and an attribute schema, and renders HTML.
 * The registry turns each widget into a shortcode `[leagueapps_{id}]` and a
 * dynamic block `leagueapps-widgets/{id}` with sidebar controls generated
 * from the schema — no per-widget JavaScript needed.
 *
 * Every widget also gets a `site` attribute (the slug of a site from
 * Settings > LeagueApps, '' for the default site). Call $this->api( $atts )
 * inside render() to get that site's API client.
 *
 * Attribute schema entries:
 *   'name' => array(
 *       'type'    => 'string' | 'integer' | 'boolean',
 *       'default' => mixed,
 *       'label'   => 'Shown in the block sidebar',
 *       'help'    => 'Optional help text',
 *       'options' => array( 'value' => 'Label', ... ), // Optional: renders a select.
 *   )
 */
abstract class Widget {

	/** Unique slug, lowercase letters, numbers and dashes (e.g. "standings"). */
	abstract public function get_id();

	abstract public function get_title();

	abstract public function get_attributes();

	/** Widget attributes plus the shared `site` attribute. */
	final public function get_all_attributes() {
		return array_merge(
			array(
				'site' => array(
					'type'    => 'string',
					'default' => '',
					'label'   => __( 'LeagueApps site', 'leagueapps-widgets' ),
					'help'    => __( 'Which site from Settings > LeagueApps to read from.', 'leagueapps-widgets' ),
					'is_site' => true,
				),
			),
			$this->get_attributes()
		);
	}

	/**
	 * API client for the site chosen in the widget's attributes.
	 *
	 * @return Api_Client|\WP_Error
	 */
	protected function api( array $atts ) {
		return Plugin::instance()->api( isset( $atts['site'] ) ? $atts['site'] : '' );
	}

	/**
	 * Render the widget.
	 *
	 * @param array $atts Sanitized attributes (all schema keys present).
	 * @return string HTML.
	 */
	abstract public function render( array $atts );

	public function get_description() {
		return '';
	}

	/** Dashicon name used for the block. */
	public function get_icon() {
		return 'awards';
	}

	public function get_shortcode_tag() {
		return 'leagueapps_' . str_replace( '-', '_', $this->get_id() );
	}

	public function get_block_name() {
		return 'leagueapps-widgets/' . $this->get_id();
	}

	/**
	 * Fill defaults and coerce types. Accepts snake_case (shortcode) or the
	 * same keys from block attributes.
	 */
	public function normalize_attributes( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();
		$out = array();
		foreach ( $this->get_all_attributes() as $name => $def ) {
			$value = array_key_exists( $name, $raw ) ? $raw[ $name ] : ( isset( $def['default'] ) ? $def['default'] : null );
			switch ( isset( $def['type'] ) ? $def['type'] : 'string' ) {
				case 'integer':
					$value = (int) $value;
					break;
				case 'boolean':
					$value = is_bool( $value ) ? $value : in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
					break;
				default:
					$value = ! empty( $def['is_site'] ) ? Settings::normalize_slug( $value ) : sanitize_text_field( (string) $value );
			}
			if ( ! empty( $def['options'] ) && ! array_key_exists( $value, $def['options'] ) ) {
				$value = $def['default'];
			}
			$out[ $name ] = $value;
		}
		return $out;
	}

	/**
	 * Error output: details for admins, nothing for visitors.
	 */
	protected function render_error( $message ) {
		if ( $message instanceof \WP_Error ) {
			$message = $message->get_error_message();
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}
		return '<div class="law-widget law-error"><strong>' . esc_html__( 'LeagueApps widget:', 'leagueapps-widgets' ) . '</strong> ' . esc_html( $message ) . '</div>';
	}
}
