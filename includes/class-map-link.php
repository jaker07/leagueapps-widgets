<?php
namespace LeagueApps_Widgets;

defined( 'ABSPATH' ) || exit;

/**
 * Links a location name to a map.
 *
 * The link's href is a Google Maps search, which works everywhere (and
 * without JavaScript). assets/js/map-links.js then sends clicks to the
 * device's own maps app where there is one: Apple Maps on iPhone, iPad and
 * Mac; the Android default (via a geo: link) on Android.
 *
 * A location is only linked when its address or coordinates are known;
 * a bare name like "Field 2" would send people to the wrong place.
 */
class Map_Link {

	const SCRIPT_HANDLE = 'leagueapps-widgets-map-links';

	public static function register_script() {
		wp_register_script( self::SCRIPT_HANDLE, LAW_URL . 'assets/js/map-links.js', array(), LAW_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
	}

	/**
	 * @param array|null $location From Schedule_Data::location().
	 * @return bool
	 */
	public static function can_link( $location ) {
		return is_array( $location ) && ( '' !== $location['address'] || null !== $location['lat'] );
	}

	/** Google Maps search URL for a location (address preferred, then coordinates). */
	public static function google_url( array $location ) {
		return add_query_arg(
			array(
				'api'   => 1,
				'query' => rawurlencode( self::query( $location ) ),
			),
			'https://www.google.com/maps/search/'
		);
	}

	/** What to search for: "Name, address", or coordinates when there's no address. */
	public static function query( array $location ) {
		if ( '' !== $location['address'] ) {
			$name = trim( $location['name'] );
			// Leading the address with the venue name helps maps pick the right place.
			return '' !== $name && false === stripos( $location['address'], $name ) ? $name . ', ' . $location['address'] : $location['address'];
		}
		return $location['lat'] . ',' . $location['lng'];
	}

	/**
	 * The location text, linked to a map when possible.
	 *
	 * @param string     $text     Text to show (already plain text, e.g. "Riverside Park – Field 2").
	 * @param array|null $location From Schedule_Data::location().
	 * @return string HTML.
	 */
	public static function html( $text, $location ) {
		if ( '' === (string) $text ) {
			return '';
		}
		if ( ! apply_filters( 'leagueapps_widgets_link_location', self::can_link( $location ), $location ) ) {
			return esc_html( $text );
		}

		wp_enqueue_script( self::SCRIPT_HANDLE );

		$data = array(
			'q'    => self::query( $location ),
			'name' => (string) $location['name'],
		);
		if ( null !== $location['lat'] ) {
			$data['lat'] = $location['lat'];
			$data['lng'] = $location['lng'];
		}

		return sprintf(
			'<a class="law-map-link" href="%1$s" target="_blank" rel="noopener" data-law-map="%2$s">%3$s<span class="screen-reader-text law-sr-only"> %4$s</span></a>',
			esc_url( self::google_url( $location ) ),
			esc_attr( wp_json_encode( $data ) ),
			esc_html( $text ),
			/* translators: shown to screen readers after a location link */
			esc_html__( '(opens map)', 'leagueapps-widgets' )
		);
	}
}
