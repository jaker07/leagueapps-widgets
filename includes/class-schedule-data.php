<?php
namespace LeagueApps_Widgets;

defined( 'ABSPATH' ) || exit;

/**
 * Reads games and teams out of LeagueApps schedule / teams responses.
 *
 * Field names in these responses aren't fully documented and vary, so every
 * lookup tries several candidate keys (dotted paths reach into nested
 * objects). Adjust them with the `leagueapps_widgets_game_fields` filter.
 */
class Schedule_Data {

	public static function field_map() {
		return apply_filters(
			'leagueapps_widgets_game_fields',
			array(
				'games'        => array( 'games', 'schedule', 'events' ),
				'game_id'      => array( 'gameId', 'id', 'eventId' ),
				'home_id'      => array( 'team1Id', 'homeTeamId', 'team1.teamId', 'homeTeam.teamId', 'homeTeam.id' ),
				'away_id'      => array( 'team2Id', 'awayTeamId', 'team2.teamId', 'awayTeam.teamId', 'awayTeam.id' ),
				'home_name'    => array( 'team1', 'homeTeamName', 'team1Name', 'team1.name', 'homeTeam.name' ),
				'away_name'    => array( 'team2', 'awayTeamName', 'team2Name', 'team2.name', 'awayTeam.name' ),
				'home_score'   => array( 'team1Score', 'homeScore', 'homeTeamScore', 'team1.score', 'homeTeam.score' ),
				'away_score'   => array( 'team2Score', 'awayScore', 'awayTeamScore', 'team2.score', 'awayTeam.score' ),
				'state'        => array( 'state', 'status', 'gameStatus' ),
				'type'         => array( 'type', 'eventType', 'gameType', 'event_type' ),
				'start'        => array( 'startTime', 'startDateTime', 'start', 'startDate', 'date' ),
				'location'     => array( 'locationName', 'location.name', 'location' ),
				'sub_location' => array( 'subLocationName', 'subLocation.name', 'subLocation', 'fieldName', 'courtName' ),
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
	 *                 location, sub_location.
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
			$category  = self::category(
				$type,
				null !== self::pick( $raw, $map['home_id'] ) || '' !== (string) self::pick( $raw, $map['home_name'] ),
				null !== self::pick( $raw, $map['away_id'] ) || '' !== (string) self::pick( $raw, $map['away_name'] )
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
				'sub_location' => (string) self::pick( $raw, $map['sub_location'] ),
				'raw'          => $raw,
			);
		}
		return $games;
	}

	/**
	 * Groups an entry as 'game', 'practice' or 'event' (anything else).
	 *
	 * Uses the entry's type when it has one, matched by keyword so variants
	 * like "SCRIMMAGE" or "TEAM_PRACTICE" are covered. Without a type, an
	 * entry with two teams is a game and anything else is an event.
	 */
	public static function category( $type, $has_home, $has_away ) {
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
			$id   = self::pick( $team, $map['team_id'] );
			$name = self::pick( $team, $map['team_name'] );
			if ( null !== $id || null !== $name ) {
				$out[] = array( 'id' => $id, 'name' => null === $name ? null : (string) $name );
			}
		}
		return $out;
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
