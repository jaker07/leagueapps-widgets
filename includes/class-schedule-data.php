<?php
namespace LeagueApps_Widgets;

defined( 'ABSPATH' ) || exit;

/**
 * Reads games and teams out of LeagueApps schedule / teams responses.
 *
 * Field names follow the LeagueApps API documentation
 * (https://leagueapps.notion.site/LeagueApps-API-Documentation-e32aa52e1b0a47bfa20e4c0563fafeca);
 * the first candidate for each field is the documented one, and the others
 * are fallbacks (dotted paths reach into nested objects). Adjust them with
 * the `leagueapps_widgets_game_fields` filter.
 */
class Schedule_Data {

	public static function field_map() {
		return apply_filters(
			'leagueapps_widgets_game_fields',
			array(
				'games'        => array( 'games', 'schedule', 'events' ),
				// Documented: games have gameId; events (practices etc.) have eventId.
				'game_id'      => array( 'gameId', 'eventId', 'id' ),
				'item_type'    => array( 'scheduleItemType' ),
				// Events (practices) have a single teamId / teamName.
				'home_id'      => array( 'team1Id', 'homeTeamId', 'team1.teamId', 'homeTeam.teamId', 'homeTeam.id', 'teamId' ),
				'away_id'      => array( 'team2Id', 'awayTeamId', 'team2.teamId', 'awayTeam.teamId', 'awayTeam.id' ),
				'home_name'    => array( 'team1', 'homeTeamName', 'team1Name', 'team1.name', 'homeTeam.name', 'teamName' ),
				'away_name'    => array( 'team2', 'awayTeamName', 'team2Name', 'team2.name', 'awayTeam.name' ),
				'home_score'   => array( 'team1Score', 'homeScore', 'homeTeamScore', 'team1.score', 'homeTeam.score' ),
				'away_score'   => array( 'team2Score', 'awayScore', 'awayTeamScore', 'team2.score', 'awayTeam.score' ),
				'state'        => array( 'state', 'status', 'gameStatus' ),
				'type'         => array( 'type', 'eventType', 'gameType', 'event_type' ),
				'start'        => array( 'startTime', 'startDateTime', 'start', 'startDate', 'date' ),
				'location'     => array( 'locationName', 'location.name', 'location' ),
				'location_id'  => array( 'locationId', 'location.locationId', 'location.id' ),
				// Events have locationLabel, e.g. "Enter on Amsterdam Ave".
				'sub_location' => array( 'subLocationName', 'subLocation.name', 'subLocation', 'fieldName', 'courtName', 'locationLabel' ),
				'lat'          => array( 'latitude', 'locationLatitude' ),
				'lng'          => array( 'longitude', 'locationLongitude' ),
				'title'        => array( 'name', 'title', 'eventName', 'description' ),
				'team_list'    => array( 'teams' ),
				'team_id'      => array( 'teamId', 'id' ),
				'team_name'    => array( 'name', 'teamName' ),
			)
		);
	}

	/** Game states that mean a game doesn't count toward standings. */
	public static function excluded_states() {
		return apply_filters( 'leagueapps_widgets_excluded_game_states', array( 'CANCELED', 'CANCELLED', 'POSTPONED', 'SCHEDULED', 'FORFEIT_PENDING' ) );
	}

	/**
	 * Normalized games from a schedule response.
	 *
	 * @return array[] Each: id, start (Unix timestamp or null), has_time (false
	 *                 for date-only values), home_id, home,
	 *                 away_id, away, home_score, away_score (float or null),
	 *                 has_score, state (uppercase string), type (uppercase
	 *                 string, may be ''), category ('game', 'practice' or
	 *                 'event'), counts (bool: a final game result that
	 *                 belongs in standings), title,
	 *                 location, location_id, place (an embedded location from
	 *                 Schedule_Data::location(), or null), sub_location.
	 */
	public static function games( $schedule ) {
		$map      = self::field_map();
		$excluded = self::excluded_states();
		$games    = array();

		foreach ( self::list_from( $schedule, $map['games'] ) as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$hs        = self::pick( $raw, $map['home_score'] );
			$as        = self::pick( $raw, $map['away_score'] );
			$has_score = is_numeric( $hs ) && is_numeric( $as );
			$state     = self::pick( $raw, $map['state'] );
			$state     = is_string( $state ) ? strtoupper( $state ) : '';
			$start     = self::pick( $raw, $map['start'] );
			$type      = self::pick( $raw, $map['type'] );
			$type      = is_string( $type ) ? strtoupper( trim( $type ) ) : '';
			$item_type = self::pick( $raw, $map['item_type'] );
			$category  = self::category(
				$type,
				null !== self::pick( $raw, $map['home_id'] ) || '' !== (string) self::pick( $raw, $map['home_name'] ),
				null !== self::pick( $raw, $map['away_id'] ) || '' !== (string) self::pick( $raw, $map['away_name'] ),
				is_string( $item_type ) ? strtoupper( $item_type ) : ''
			);

			$games[] = array(
				'id'           => self::pick( $raw, $map['game_id'] ),
				'start'        => self::parse_time( $start ),
				'has_time'     => ! ( is_string( $start ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', trim( $start ) ) ),
				'home_id'      => self::pick( $raw, $map['home_id'] ),
				'home'         => (string) self::pick( $raw, $map['home_name'] ),
				'away_id'      => self::pick( $raw, $map['away_id'] ),
				'away'         => (string) self::pick( $raw, $map['away_name'] ),
				'home_score'   => $has_score ? (float) $hs : null,
				'away_score'   => $has_score ? (float) $as : null,
				'has_score'    => $has_score,
				'state'        => $state,
				'type'         => $type,
				'category'     => $category,
				'counts'       => 'game' === $category && $has_score && ! in_array( $state, $excluded, true ),
				'title'        => (string) self::pick( $raw, $map['title'] ),
				'location'     => (string) self::pick( $raw, $map['location'] ),
				'location_id'  => self::pick( $raw, $map['location_id'] ),
				'place'        => self::embedded_place( $raw, $map ),
				'sub_location' => (string) self::pick( $raw, $map['sub_location'] ),
				'raw'          => $raw,
			);
		}
		return $games;
	}

	/**
	 * Groups an entry as 'game', 'practice' or 'event' (anything else).
	 *
	 * LeagueApps marks each schedule item with scheduleItemType:
	 * STRUCTURED_GAME items are games (their `type` is the game type, e.g.
	 * SCHEDULED or a playoff, not game-vs-practice); EVENT items have a type
	 * such as "Practice". Types are matched by keyword so variants like
	 * "TEAM_PRACTICE" are covered. Without either, an entry with two teams is
	 * a game and anything else is an event.
	 */
	public static function category( $type, $has_home, $has_away, $item_type = '' ) {
		if ( '' !== $item_type && false !== strpos( $item_type, 'GAME' ) ) {
			return 'game';
		}
		$keywords = apply_filters(
			'leagueapps_widgets_event_type_keywords',
			array(
				'practice' => array( 'PRACTICE', 'TRAINING' ),
				'game'     => array( 'GAME', 'MATCH', 'SCRIMMAGE', 'PLAYOFF' ),
			)
		);
		if ( '' !== $type ) {
			foreach ( $keywords as $category => $words ) {
				foreach ( (array) $words as $word ) {
					if ( false !== strpos( $type, strtoupper( $word ) ) ) {
						return $category;
					}
				}
			}
			return 'event';
		}
		if ( '' !== $item_type ) {
			return 'event'; // An EVENT item with no type.
		}
		return $has_home && $has_away ? 'game' : 'event';
	}

	/**
	 * Teams from a teams response.
	 *
	 * @return array[] Each: id, name.
	 */
	public static function teams( $teams ) {
		$map = self::field_map();
		$out = array();
		foreach ( self::list_from( $teams, $map['team_list'] ) as $team ) {
			if ( ! is_array( $team ) ) {
				continue;
			}
			if ( ! empty( $team['deleted'] ) ) {
				continue; // Deleted teams come back as { teamId, deleted: true }.
			}
			$id   = self::pick( $team, $map['team_id'] );
			$name = self::pick( $team, $map['team_name'] );
			if ( null !== $id || null !== $name ) {
				$out[] = array( 'id' => $id, 'name' => null === $name ? null : (string) $name );
			}
		}
		return $out;
	}

	/**
	 * Locations from a locations response, keyed by location ID.
	 *
	 * @return array[] See location().
	 */
	public static function locations( $response ) {
		$out = array();
		foreach ( self::list_from( $response, array( 'locations' ) ) as $raw ) {
			if ( is_array( $raw ) ) {
				$location = self::location( $raw );
				$key      = null !== $location['id'] ? 'id:' . $location['id'] : 'name:' . strtolower( $location['name'] );
				$out[ $key ] = $location;
			}
		}
		return $out;
	}

	/**
	 * One location, normalized.
	 *
	 * Documented fields: locationId, name, address1, city, state, zipCode,
	 * country (the locations list has no coordinates). Others are fallbacks;
	 * adjust them with the `leagueapps_widgets_location_fields` filter.
	 *
	 * @return array id, name, address (one line, may be ''), lat, lng (floats or null).
	 */
	public static function location( array $raw ) {
		$map = apply_filters(
			'leagueapps_widgets_location_fields',
			array(
				'id'      => array( 'locationId', 'id' ),
				'name'    => array( 'name', 'locationName' ),
				'full'    => array( 'formattedAddress', 'fullAddress' ),
				'street'  => array( 'address1', 'streetAddress', 'street', 'address.address1', 'address.street', 'address' ),
				'city'    => array( 'city', 'address.city' ),
				'state'   => array( 'state', 'stateProvince', 'region', 'address.state' ),
				'zip'     => array( 'zipCode', 'zip', 'postalCode', 'address.zipCode', 'address.zip', 'address.postalCode' ),
				'country' => array( 'country', 'address.country' ),
				'lat'     => array( 'latitude', 'lat', 'geo.lat', 'coordinates.lat' ),
				'lng'     => array( 'longitude', 'lng', 'lon', 'geo.lng', 'geo.lon', 'coordinates.lng' ),
			)
		);

		$address = trim( (string) self::pick( $raw, $map['full'] ) );
		if ( '' === $address ) {
			// The second address line ("Suite 2", "Rear entrance") tends to confuse map searches, so it's left out.
			$street   = trim( (string) self::pick( $raw, $map['street'] ) );
			$region   = trim( implode( ' ', array_filter( array( (string) self::pick( $raw, $map['state'] ), (string) self::pick( $raw, $map['zip'] ) ) ) ) );
			$address  = implode( ', ', array_filter( array( $street, (string) self::pick( $raw, $map['city'] ), $region, (string) self::pick( $raw, $map['country'] ) ) ) );
		}

		$lat = self::pick( $raw, $map['lat'] );
		$lng = self::pick( $raw, $map['lng'] );
		$has_coords = is_numeric( $lat ) && is_numeric( $lng ) && ! ( 0.0 === (float) $lat && 0.0 === (float) $lng );

		return array(
			'id'      => self::pick( $raw, $map['id'] ),
			'name'    => (string) self::pick( $raw, $map['name'] ),
			'address' => $address,
			'lat'     => $has_coords ? (float) $lat : null,
			'lng'     => $has_coords ? (float) $lng : null,
		);
	}

	/**
	 * The full location for a game: its embedded place, or a match in the
	 * site's locations by ID, then by name.
	 *
	 * @param array   $game      From games().
	 * @param array[] $locations From locations().
	 * @return array|null See location().
	 */
	public static function find_location( array $game, array $locations ) {
		$place = $game['place'];
		if ( $place && '' !== $place['address'] ) {
			return $place;
		}
		$found = null;
		if ( null !== $game['location_id'] && isset( $locations[ 'id:' . $game['location_id'] ] ) ) {
			$found = $locations[ 'id:' . $game['location_id'] ];
		} elseif ( '' !== $game['location'] ) {
			foreach ( $locations as $location ) {
				if ( 0 === strcasecmp( trim( $location['name'] ), trim( $game['location'] ) ) ) {
					$found = $location;
					break;
				}
			}
		}
		if ( $found ) {
			// Games can carry coordinates the locations list doesn't have.
			if ( null === $found['lat'] && $place && null !== $place['lat'] ) {
				$found['lat'] = $place['lat'];
				$found['lng'] = $place['lng'];
			}
			return $found;
		}
		return $place;
	}

	/**
	 * Location details carried by the schedule item itself: a nested location
	 * object, or coordinates on the item (games have latitude/longitude).
	 *
	 * @return array|null See location().
	 */
	private static function embedded_place( array $raw, array $map ) {
		if ( isset( $raw['location'] ) && is_array( $raw['location'] ) ) {
			return self::location( $raw['location'] );
		}
		$lat = self::pick( $raw, $map['lat'] );
		$lng = self::pick( $raw, $map['lng'] );
		if ( is_numeric( $lat ) && is_numeric( $lng ) && ! ( 0.0 === (float) $lat && 0.0 === (float) $lng ) ) {
			return array(
				'id'      => self::pick( $raw, $map['location_id'] ),
				'name'    => (string) self::pick( $raw, $map['location'] ),
				'address' => '',
				'lat'     => (float) $lat,
				'lng'     => (float) $lng,
			);
		}
		return null;
	}

	/**
	 * Unix timestamp from an API time value.
	 *
	 * Numbers are epoch seconds or milliseconds. Strings without a time zone
	 * (e.g. "2026-11-05T18:00:00") are read in the WordPress site's time zone.
	 */
	public static function parse_time( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( is_numeric( $value ) ) {
			$n = (float) $value;
			return (int) ( $n > 100000000000 ? $n / 1000 : $n );
		}
		try {
			$date = new \DateTimeImmutable( (string) $value, wp_timezone() );
			return $date->getTimestamp();
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/** A response may be a bare list or wrap the list under a key. */
	public static function list_from( $data, array $keys ) {
		if ( ! is_array( $data ) ) {
			return array();
		}
		if ( array_values( $data ) === $data ) {
			// The schedule comes back as [ { "games": [...], "notes": [...] } ]:
			// a list of wrappers rather than the items themselves.
			if ( isset( $data[0] ) && is_array( $data[0] ) && array_intersect( $keys, array_keys( $data[0] ) ) ) {
				$items = array();
				foreach ( $data as $wrapper ) {
					$items = array_merge( $items, self::list_from( $wrapper, $keys ) );
				}
				return $items;
			}
			return $data;
		}
		foreach ( $keys as $key ) {
			if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
				return $data[ $key ];
			}
		}
		return array();
	}

	/** First non-null scalar among dotted-path candidates. */
	public static function pick( $item, array $candidates ) {
		foreach ( $candidates as $path ) {
			$value = $item;
			foreach ( explode( '.', $path ) as $part ) {
				if ( is_array( $value ) && array_key_exists( $part, $value ) ) {
					$value = $value[ $part ];
				} else {
					$value = null;
					break;
				}
			}
			if ( null !== $value && ! is_array( $value ) ) {
				return $value;
			}
		}
		return null;
	}
}
