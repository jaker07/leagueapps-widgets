<?php
namespace LeagueApps_Widgets;

defined( 'ABSPATH' ) || exit;

/**
 * Thin, cached client for one LeagueApps site's Public API.
 *
 * Authentication uses the site's public API key in the `la-api-key` header.
 * Get an instance with Plugin::instance()->api( $slug ) — one per configured site.
 */
class Api_Client {

	const BASE_URL         = 'https://public.leagueapps.io';
	const CACHE_VERSION    = 'leagueapps_widgets_cache_version';
	const TRANSIENT_PREFIX = 'law_';

	/** @var Settings */
	private $settings;

	/** @var array slug, label, site_id, api_key */
	private $site;

	public function __construct( Settings $settings, array $site ) {
		$this->settings = $settings;
		$this->site     = wp_parse_args( $site, array( 'slug' => '', 'label' => '', 'site_id' => '', 'api_key' => '' ) );
	}

	public function get_site_slug() {
		return $this->site['slug'];
	}

	public function get_site_label() {
		return $this->site['label'];
	}

	public function is_configured() {
		return '' !== (string) $this->site['site_id'] && '' !== (string) $this->site['api_key'];
	}

	/** Programs currently open/running for the site. */
	public function get_current_programs() {
		return $this->site_get( 'programs/current' );
	}

	/**
	 * Current programs, normalized.
	 *
	 * @return array[]|\WP_Error Each: id (string), name, sport.
	 */
	public function get_programs() {
		$list = $this->get_current_programs();
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		$list     = Schedule_Data::list_from( $list, array( 'programs' ) );
		$programs = array();
		foreach ( $list as $program ) {
			$id = Schedule_Data::pick( $program, array( 'programId', 'id' ) );
			if ( null === $id || ! is_array( $program ) ) {
				continue;
			}
			$programs[] = array(
				'id'    => (string) $id,
				'name'  => (string) Schedule_Data::pick( $program, array( 'name', 'programName' ) ),
				'sport' => (string) Schedule_Data::pick( $program, array( 'sport', 'sportName', 'sport.name' ) ),
			);
		}
		return apply_filters( 'leagueapps_widgets_programs', $programs, $this->get_site_slug() );
	}

	/**
	 * The site's locations (with addresses), keyed for Schedule_Data::find_location().
	 * Returns an empty list if they can't be loaded, so widgets still render.
	 *
	 * @return array[]
	 */
	public function get_locations() {
		$response = $this->site_get( 'locations' );
		return is_wp_error( $response ) ? array() : Schedule_Data::locations( $response );
	}

	/** Posted site announcements, newest first. */
	public function get_announcements() {
		return $this->site_get( 'announcements' );
	}

	public function get_program_schedule( $program_id ) {
		return $this->site_get( 'programs/' . rawurlencode( (string) $program_id ) . '/schedule' );
	}

	public function get_program_teams( $program_id ) {
		return $this->site_get( 'programs/' . rawurlencode( (string) $program_id ) . '/teams' );
	}

	/**
	 * GET a path under /v1/sites/{siteId}/.
	 *
	 * @return array|\WP_Error Decoded JSON.
	 */
	public function site_get( $path, array $query = array() ) {
		if ( ! $this->is_configured() ) {
			return new \WP_Error(
				'law_not_configured',
				/* translators: %s: site label */
				sprintf( __( 'The site ID or API key for the LeagueApps site "%s" is not set (Settings > LeagueApps).', 'leagueapps-widgets' ), $this->site['label'] )
			);
		}
		$site_id = $this->site['site_id'];
		return $this->get( '/v1/sites/' . rawurlencode( $site_id ) . '/' . ltrim( $path, '/' ), $query );
	}

	/**
	 * Cached GET against the API.
	 *
	 * @return array|\WP_Error
	 */
	public function get( $path, array $query = array() ) {
		$base = apply_filters( 'leagueapps_widgets_api_base_url', self::BASE_URL );
		$url  = untrailingslashit( $base ) . $path;
		if ( $query ) {
			$url = add_query_arg( $query, $url );
		}

		// The key is part of the cache key so a different (or wrong) key never reuses another's responses.
		$cache_key = self::TRANSIENT_PREFIX . md5( self::cache_version() . '|' . $url . '|' . $this->site['api_key'] );
		$ttl       = (int) $this->settings->get( 'cache_ttl' ) * MINUTE_IN_SECONDS;

		if ( $ttl > 0 ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'la-api-key' => $this->site['api_key'],
					'Accept'     => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error(
				'law_http_error',
				/* translators: 1: HTTP status code, 2: request path */
				sprintf( __( 'LeagueApps API returned HTTP %1$d for %2$s.', 'leagueapps-widgets' ), $code, $path ),
				array( 'status' => $code )
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'law_bad_json', __( 'LeagueApps API returned an unreadable response.', 'leagueapps-widgets' ) );
		}

		if ( $ttl > 0 ) {
			set_transient( $cache_key, $data, $ttl );
		}
		return $data;
	}

	private static function cache_version() {
		return (string) get_option( self::CACHE_VERSION, '1' );
	}

	/** Invalidate every cached response by bumping the version baked into cache keys. */
	public static function flush_cache() {
		update_option( self::CACHE_VERSION, (string) time(), false );
	}
}
