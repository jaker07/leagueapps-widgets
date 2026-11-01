<?php
namespace LeagueApps_Widgets;

defined( 'ABSPATH' ) || exit;

/**
 * Wires together settings, the API client and the widget registry.
 */
final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/** @var Settings */
	public $settings;

	/** @var Api_Client */
	public $api;

	/** @var Widget_Registry */
	public $widgets;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings = new Settings();
		$this->api      = new Api_Client( $this->settings );
		$this->widgets  = new Widget_Registry();

		$this->settings->hooks();
		$this->widgets->hooks();

		// Built-in widgets.
		$this->widgets->register( new Widgets\Standings_Widget( $this->api ) );

		/**
		 * Register additional widgets.
		 *
		 * Example:
		 *   add_action( 'leagueapps_widgets_register', function ( $registry, $api ) {
		 *       $registry->register( new My_Schedule_Widget( $api ) );
		 *   }, 10, 2 );
		 */
		do_action( 'leagueapps_widgets_register', $this->widgets, $this->api );
	}
}
