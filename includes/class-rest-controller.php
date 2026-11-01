<?php
namespace LeagueApps_Widgets;

defined( 'ABSPATH' ) || exit;

/**
 * REST endpoints used by the block editor.
 *
 * GET /wp-json/leagueapps-widgets/v1/programs?site={slug}
 *   Current programs for a configured site ('' = default site), so block
 *   sidebars can offer a program dropdown for whichever site is selected.
 *   Only for users who can edit posts.
 */
class Rest_Controller {

	const NAMESPACE_V1 = 'leagueapps-widgets/v1';

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/programs',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_programs' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'site' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => array( Settings::class, 'normalize_slug' ),
					),
				),
			)
		);
	}

	public function get_programs( \WP_REST_Request $request ) {
		$api = $this->plugin->api( $request['site'] );
		if ( is_wp_error( $api ) ) {
			$api->add_data( array( 'status' => 400 ) );
			return $api;
		}

		$programs = $api->get_programs();
		if ( is_wp_error( $programs ) ) {
			$status = 'law_not_configured' === $programs->get_error_code() ? 400 : 502;
			$programs->add_data( array( 'status' => $status ) );
			return $programs;
		}

		usort(
			$programs,
			function ( $a, $b ) {
				return strcasecmp( $a['sport'] . ' ' . $a['name'], $b['sport'] . ' ' . $b['name'] );
			}
		);

		return rest_ensure_response(
			array(
				'site'     => $api->get_site_slug(),
				'programs' => $programs,
			)
		);
	}
}
