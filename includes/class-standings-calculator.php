<?php
namespace LeagueApps_Widgets;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a standings table from a program's teams and game results.
 *
 * The LeagueApps Public API does not document a standings endpoint, so
 * standings are computed from completed games in the program schedule.
 * Games and teams are read with Schedule_Data, which handles the varying
 * field names in API responses.
 */
class Standings_Calculator {

	/**
	 * @param array  $schedule Decoded schedule response.
	 * @param array  $teams    Decoded teams response (may be empty).
	 * @param string $ranking  'win_pct' or 'points'.
	 * @param array  $points   array( 'win' => 3, 'tie' => 1, 'loss' => 0 ).
	 * @return array[] Rows sorted best-first, each with rank, team, gp, w, l, t, pf, pa, diff, pct, pts.
	 */
	public static function calculate( $schedule, $teams, $ranking = 'win_pct', $points = array() ) {
		$points = wp_parse_args( $points, array( 'win' => 3, 'tie' => 1, 'loss' => 0 ) );
		$rows   = array();

		foreach ( Schedule_Data::teams( $teams ) as $team ) {
			self::ensure_row( $rows, $team['id'], $team['name'] );
		}

		foreach ( Schedule_Data::games( $schedule ) as $game ) {
			if ( ! $game['counts'] ) {
				continue; // Not played, no result, or canceled/postponed.
			}
			$home = self::ensure_row( $rows, $game['home_id'], $game['home'] );
			$away = self::ensure_row( $rows, $game['away_id'], $game['away'] );
			if ( null === $home || null === $away || $home === $away ) {
				continue;
			}
			self::apply_result( $rows[ $home ], $game['home_score'], $game['away_score'] );
			self::apply_result( $rows[ $away ], $game['away_score'], $game['home_score'] );
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
}
