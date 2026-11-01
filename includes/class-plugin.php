<?php
namespace LeagueApps_Widgets;

defined( 'ABSPATH' ) || exit;

/**
 * Wires together settings, the per-site API clients and the widget registry.
 */
final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/** @var Settings */
	public $settings;

	/** @var Widget_Registry */
	public $widgets;

	/** @var Api_Client[] Keyed by site slug. */
	private $clients = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings = new Settings();
		$this->widgets  = new Widget_Registry( $this->settings );

		$this->settings->hooks();
		$this->widgets->hooks();
		( new Rest_Controller( $this ) )->hooks();
		( new Integrations\Bold_Builder( $this->widgets, $this->settings ) )->hooks();

		// Settings can change mid-request (saving the settings page).
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'reset_clients' ) );

		// Built-in widgets.
		$this->widgets->register( new Widgets\Standings_Widget() );
		$this->widgets->register( new Widgets\Schedule_Widget() );
		$this->widgets->register( new Widgets\Announcements_Widget() );

		/**
		 * Register additional widgets.
		 *
		 * Example:
		 *   add_action( 'leagueapps_widgets_register', function ( $registry, $plugin ) {
		 *       $registry->register( new My_Locations_Widget() );
		 *   }, 10, 2 );
		 */
		do_action( 'leagueapps_widgets_register', $this->widgets, $this );
	}

	/**
	 * API client for a configured LeagueApps site.
	 *
	 * @param string $slug Site slug; '' for the default (first) site.
	 * @return Api_Client|\WP_Error
	 */
	public function api( $slug = '' ) {
		$site = $this->settings->get_site( $slug );
		if ( null === $site ) {
			if ( '' === (string) $slug || ! $this->settings->get_sites() ) {
				return new \WP_Error( 'law_not_configured', __( 'No LeagueApps site is set up yet (Settings > LeagueApps).', 'leagueapps-widgets' ) );
			}
			return new \WP_Error(
				'law_unknown_site',
				/* translators: %s: site slug */
				sprintf( __( 'There is no LeagueApps site with the slug "%s" (Settings > LeagueApps).', 'leagueapps-widgets' ), $slug )
			);
		}
		if ( ! isset( $this->clients[ $site['slug'] ] ) ) {
			$this->clients[ $site['slug'] ] = new Api_Client( $this->settings, $site );
		}
		return $this->clients[ $site['slug'] ];
	}

	public function reset_clients() {
		$this->clients = array();
	}
}
