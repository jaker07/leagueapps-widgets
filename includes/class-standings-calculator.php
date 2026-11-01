<?php
namespace LeagueApps_Widgets;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a standings table from a program's teams and game results.
 *
 * The LeagueApps Public API does not document a standings endpoint, so
 * standings are computed from completed games in the program schedule.
 * Field names vary between API responses, so every lookup tries several
 * candidate keys; adjust with the `leagueapps_widgets_game_fields` filter.
 */
class Standings_Calculator {

	public static function field_map() {
		return apply_filters(
			'leagueapps_widgets_game_fields',
			array(
				'games'       => array( 'games', 'schedule', 'events' ),
				'home_id'     => array( 'team1Id', 'homeTeamId', 'team1.teamId', 'homeTeam.teamId', 'homeTeam.id' ),
				'away_id'     => array( 'team2Id', 'awayTeamId', 'team2.teamId', 'awayTeam.teamId', 'awayTeam.id' ),
				'home_name'   => array( 'team1', 'homeTeamName', 'team1Name', 'team1.name', 'homeTeam.name' ),
				'away_name'   => array( 'team2', 'awayTeamName', 'team2Name', 'team2.name', 'awayTeam.name' ),
				'home_score'  => array( 'team1Score', 'homeScore', 'homeTeamScore', 'team1.score', 'homeTeam.score' ),
				'away_score'  => array( 'team2Score', 'awayScore', 'awayTeamScore', 'team2.score', 'awayTeam.score' ),
				'state'       => array( 'state', 'status', 'gameStatus' ),
				'team_list'   => array( 'teams' ),
				'team_id'     => array( 'teamId', 'id' ),
				'team_name'   => array( 'name', 'teamName' ),
			)
		);
	}

	/** Game states that mean "don't count this game". */
	public static function excluded_states() {
		return apply_filters( 'leagueapps_widgets_excluded_game_states', array( 'CANCELED', 'CANCELLED', 'POSTPONED', 'SCHEDULED', 'FORFEIT_PENDING' ) );
	}

	/**
	 * @param array  $schedule Decoded schedule response.
	 * @param array  $teams    Decoded teams response (may be empty).
	 * @param string $ranking  'win_pct' or 'points'.
	 * @param array  $points   array( 'win' => 3, 'tie' => 1, 'loss' => 0 ).
	 * @return array[] Rows sorted best-first, each with rank, team, gp, w, l, t, pf, pa, diff, pct, pts.
	 */
	public static function calculate( $schedule, $teams, $ranking = 'win_pct', $points = array() ) {
		$map    = self::field_map();
		$points = wp_parse_args( $points, array( 'win' => 3, 'tie' => 1, 'loss' => 0 ) );
		$rows   = array();

		foreach ( self::list_from( $teams, $map['team_list'] ) as $team ) {
			$id   = self::pick( $team, $map['team_id'] );
			$name = self::pick( $team, $map['team_name'] );
			if ( null !== $id || null !== $name ) {
				self::ensure_row( $rows, $id, $name );
			}
		}

		$excluded = self::excluded_states();
		foreach ( self::list_from( $schedule, $map['games'] ) as $game ) {
			if ( ! is_array( $game ) ) {
				continue;
			}
			$hs = self::pick( $game, $map['home_score'] );
			$as = self::pick( $game, $map['away_score'] );
			if ( ! is_numeric( $hs ) || ! is_numeric( $as ) ) {
				continue; // Not played / no result.
			}
			$state = self::pick( $game, $map['state'] );
			if ( is_string( $state ) && in_array( strtoupper( $state ), $excluded, true ) ) {
				continue;
			}

			$home = self::ensure_row( $rows, self::pick( $game, $map['home_id'] ), self::pick( $game, $map['home_name'] ) );
			$away = self::ensure_row( $rows, self::pick( $game, $map['away_id'] ), self::pick( $game, $map['away_name'] ) );
			if ( null === $home || null === $away || $home === $away ) {
				continue;
			}

			self::apply_result( $rows[ $home ], (float) $hs, (float) $as );
			self::apply_result( $rows[ $away ], (float) $as, (float) $hs );
		}

		foreach ( $rows as &$row ) {
			$row['diff'] = $row['pf'] - $row['pa'];
			$row['pct']  = $row['gp'] > 0 ? ( $row['w'] + 0.5 * $row['t'] ) / $row['gp'] : 0.0;
			$row['pts']  = $row['w'] * $points['win'] + $row['t'] * $points['tie'] + $row['l'] * $points['loss'];
		}
		unset( $row );

		$rows = array_values( $rows );
		usort(
			$rows,
			function ( $a, $b ) use ( $ranking ) {
				$primary = 'points' === $ranking ? array( 'pts', 'diff', 'pf' ) : array( 'pct', 'w', 'diff', 'pf' );
				foreach ( $primary as $key ) {
					if ( $a[ $key ] != $b[ $key ] ) { // phpcs:ignore Universal.Operators.StrictComparisons
						return $a[ $key ] < $b[ $key ] ? 1 : -1;
					}
				}
				return strcasecmp( $a['team'], $b['team'] );
			}
		);

		// Shared rank for exact ties on the ranking keys.
		$prev = null;
		foreach ( $rows as $i => &$row ) {
			$sig = 'points' === $ranking ? array( $row['pts'], $row['diff'], $row['pf'] ) : array( $row['pct'], $row['w'], $row['diff'], $row['pf'] );
			$row['rank'] = ( null !== $prev && $prev['sig'] == $sig ) ? $prev['rank'] : $i + 1; // phpcs:ignore Universal.Operators.StrictComparisons
			$prev        = array( 'sig' => $sig, 'rank' => $row['rank'] );
		}
		unset( $row );

		return apply_filters( 'leagueapps_widgets_standings_rows', $rows, $schedule, $teams, $ranking );
	}

	private static function apply_result( array &$row, $for, $against ) {
		$row['gp']++;
		$row['pf'] += $for;
		$row['pa'] += $against;
		if ( $for > $against ) {
			$row['w']++;
		} elseif ( $for < $against ) {
			$row['l']++;
		} else {
			$row['t']++;
		}
	}

	/** Returns the row key for a team, creating the row if needed. */
	private static function ensure_row( array &$rows, $id, $name ) {
		if ( null === $id && ( null === $name || '' === $name ) ) {
			return null;
		}
		$key = null !== $id ? 'id:' . $id : 'name:' . strtolower( (string) $name );
		if ( ! isset( $rows[ $key ] ) ) {
			// A team first seen by name may later appear with an id (or vice versa); merge by name.
			foreach ( $rows as $k => $existing ) {
				if ( null !== $name && '' !== $name && 0 === strcasecmp( $existing['team'], (string) $name ) ) {
					return $k;
				}
			}
			$rows[ $key ] = array(
				'team_id' => $id,
				'team'    => null !== $name && '' !== $name ? (string) $name : (string) $id,
				'gp'      => 0,
				'w'       => 0,
				'l'       => 0,
				't'       => 0,
				'pf'      => 0,
				'pa'      => 0,
			);
		} elseif ( null !== $name && '' !== $name && $rows[ $key ]['team'] === (string) $id ) {
			$rows[ $key ]['team'] = (string) $name;
		}
		return $key;
	}

	/** A response may be a bare list or wrap the list under a key. */
	private static function list_from( $data, array $keys ) {
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

	/** First non-null value among dotted-path candidates. */
	private static function pick( $item, array $candidates ) {
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
