<?php
namespace LeagueApps_Widgets\Widgets;

use LeagueApps_Widgets\Schedule_Data;
use LeagueApps_Widgets\Widget;

defined( 'ABSPATH' ) || exit;

/**
 * Game schedule for one program.
 *
 * Shortcode: [leagueapps_schedule program_id="123456"]
 *            [leagueapps_schedule program_id="123456" show="upcoming" limit="5" team="Lions"]
 *            [leagueapps_schedule program_id="123456" site="hockey"]
 */
class Schedule_Widget extends Widget {

	public function get_id() {
		return 'schedule';
	}

	public function get_title() {
		return __( 'LeagueApps Schedule', 'leagueapps-widgets' );
	}

	public function get_description() {
		return __( 'Game schedule and results for a LeagueApps program.', 'leagueapps-widgets' );
	}

	public function get_icon() {
		return 'calendar-alt';
	}

	public function get_attributes() {
		return array(
			'program_id'    => array(
				'type'    => 'string',
				'default' => '',
				'label'   => __( 'Program ID', 'leagueapps-widgets' ),
				'help'    => __( 'The program’s ID number in LeagueApps.', 'leagueapps-widgets' ),
			),
			'show'          => array(
				'type'    => 'string',
				'default' => 'all',
				'label'   => __( 'Games to show', 'leagueapps-widgets' ),
				'options' => array(
					'all'      => __( 'All games', 'leagueapps-widgets' ),
					'upcoming' => __( 'Upcoming games', 'leagueapps-widgets' ),
					'past'     => __( 'Past games (most recent first)', 'leagueapps-widgets' ),
				),
			),
			'limit'         => array(
				'type'    => 'integer',
				'default' => 0,
				'label'   => __( 'Maximum games', 'leagueapps-widgets' ),
				'help'    => __( '0 shows every game.', 'leagueapps-widgets' ),
			),
			'team'          => array(
				'type'    => 'string',
				'default' => '',
				'label'   => __( 'Team (optional)', 'leagueapps-widgets' ),
				'help'    => __( 'Only show games for this team. Use the team name or team ID.', 'leagueapps-widgets' ),
			),
			'show_location' => array(
				'type'    => 'boolean',
				'default' => true,
				'label'   => __( 'Show location', 'leagueapps-widgets' ),
			),
			'show_results'  => array(
				'type'    => 'boolean',
				'default' => true,
				'label'   => __( 'Show scores', 'leagueapps-widgets' ),
			),
			'title'         => array(
				'type'    => 'string',
				'default' => '',
				'label'   => __( 'Heading (optional)', 'leagueapps-widgets' ),
				'help'    => __( 'Defaults to the program name.', 'leagueapps-widgets' ),
			),
		);
	}

	public function render( array $atts ) {
		if ( '' === $atts['program_id'] ) {
			return $this->render_error( __( 'Set a program ID.', 'leagueapps-widgets' ) );
		}

		$api = $this->api( $atts );
		if ( is_wp_error( $api ) ) {
			return $this->render_error( $api );
		}

		$schedule = $api->get_program_schedule( $atts['program_id'] );
		if ( is_wp_error( $schedule ) ) {
			return $this->render_error( $schedule );
		}

		$games   = $this->select_games( Schedule_Data::games( $schedule ), $atts );
		$heading = '' !== $atts['title'] ? $atts['title'] : $this->program_name( $api, $atts['program_id'] );

		return '<div class="law-widget law-schedule">' . $this->render_list( $games, $heading, $atts ) . '</div>';
	}

	/** Filter by team and time, sort, and apply the limit. */
	private function select_games( array $games, array $atts ) {
		$now  = time();
		$team = strtolower( trim( $atts['team'] ) );

		$games = array_filter(
			$games,
			function ( $game ) use ( $atts, $now, $team ) {
				if ( '' !== $team && ! $this->involves_team( $game, $team ) ) {
					return false;
				}
				$past = $game['has_score'] || ( null !== $game['start'] && $game['start'] < $now );
				if ( 'upcoming' === $atts['show'] ) {
					return ! $past;
				}
				if ( 'past' === $atts['show'] ) {
					return $past;
				}
				return true;
			}
		);

		// Games without a date go last.
		$desc = 'past' === $atts['show'];
		usort(
			$games,
			function ( $a, $b ) use ( $desc ) {
				$ta = null === $a['start'] ? PHP_INT_MAX : $a['start'];
				$tb = null === $b['start'] ? PHP_INT_MAX : $b['start'];
				if ( $desc && null !== $a['start'] && null !== $b['start'] ) {
					return $tb <=> $ta;
				}
				return $ta <=> $tb;
			}
		);

		if ( $atts['limit'] > 0 ) {
			$games = array_slice( $games, 0, $atts['limit'] );
		}
		return apply_filters( 'leagueapps_widgets_schedule_games', $games, $atts );
	}

	private function involves_team( array $game, $team ) {
		foreach ( array( 'home', 'away' ) as $side ) {
			if ( strtolower( $game[ $side ] ) === $team || ( null !== $game[ $side . '_id' ] && (string) $game[ $side . '_id' ] === $team ) ) {
				return true;
			}
		}
		return false;
	}

	/** Program name from the site's current programs, if it's listed there. */
	private function program_name( $api, $program_id ) {
		$list = $api->get_current_programs();
		if ( is_wp_error( $list ) ) {
			return '';
		}
		$list = isset( $list['programs'] ) && is_array( $list['programs'] ) ? $list['programs'] : $list;
		foreach ( $list as $program ) {
			$id = Schedule_Data::pick( $program, array( 'programId', 'id' ) );
			if ( null !== $id && (string) $id === (string) $program_id ) {
				return (string) Schedule_Data::pick( $program, array( 'name' ) );
			}
		}
		return '';
	}

	private function render_list( array $games, $heading, array $atts ) {
		$cols = 2 + ( $atts['show_location'] ? 1 : 0 ) + ( $atts['show_results'] ? 1 : 0 );
		/** Date format for the day headings; defaults to the site's date format. */
		$date_format = apply_filters( 'leagueapps_widgets_schedule_date_format', 'l, ' . get_option( 'date_format' ) );
		$time_format = get_option( 'time_format' );
		$team        = strtolower( trim( $atts['team'] ) );

		ob_start();
		?>
		<section class="law-schedule-program">
			<?php if ( '' !== $heading ) : ?>
				<h3 class="law-schedule-title"><?php echo esc_html( $heading ); ?></h3>
			<?php endif; ?>
			<?php if ( ! $games ) : ?>
				<p class="law-empty">
					<?php
					if ( 'upcoming' === $atts['show'] ) {
						esc_html_e( 'No upcoming games.', 'leagueapps-widgets' );
					} elseif ( 'past' === $atts['show'] ) {
						esc_html_e( 'No games played yet.', 'leagueapps-widgets' );
					} else {
						esc_html_e( 'No games scheduled.', 'leagueapps-widgets' );
					}
					?>
				</p>
			<?php else : ?>
				<div class="law-table-wrap">
					<table class="law-schedule-table">
						<thead>
							<tr>
								<th scope="col" class="law-col-time"><?php esc_html_e( 'Time', 'leagueapps-widgets' ); ?></th>
								<th scope="col" class="law-col-game"><?php esc_html_e( 'Game', 'leagueapps-widgets' ); ?></th>
								<?php if ( $atts['show_location'] ) : ?>
									<th scope="col" class="law-col-location"><?php esc_html_e( 'Location', 'leagueapps-widgets' ); ?></th>
								<?php endif; ?>
								<?php if ( $atts['show_results'] ) : ?>
									<th scope="col" class="law-col-result"><?php esc_html_e( 'Result', 'leagueapps-widgets' ); ?></th>
								<?php endif; ?>
							</tr>
						</thead>
						<tbody>
							<?php
							$current_day = false;
							foreach ( $games as $game ) :
								$day = null === $game['start'] ? '' : wp_date( 'Y-m-d', $game['start'] );
								if ( $day !== $current_day ) :
									$current_day = $day;
									?>
									<tr class="law-date-row">
										<th scope="colgroup" colspan="<?php echo (int) $cols; ?>">
											<?php echo esc_html( '' === $day ? __( 'Date to be announced', 'leagueapps-widgets' ) : wp_date( $date_format, $game['start'] ) ); ?>
										</th>
									</tr>
								<?php endif; ?>
								<tr class="<?php echo esc_attr( $this->row_class( $game ) ); ?>">
									<td class="law-col-time">
										<?php echo esc_html( null !== $game['start'] && $game['has_time'] ? wp_date( $time_format, $game['start'] ) : __( 'TBA', 'leagueapps-widgets' ) ); ?>
									</td>
									<?php $location = implode( ' – ', array_filter( array( $game['location'], $game['sub_location'] ) ) ); ?>
									<td class="law-col-game">
										<?php echo $this->matchup_html( $game, $team ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in matchup_html(). ?>
										<?php if ( $atts['show_location'] && '' !== $location ) : ?>
											<span class="law-game-location"><?php echo esc_html( $location ); ?></span>
										<?php endif; ?>
									</td>
									<?php if ( $atts['show_location'] ) : ?>
										<td class="law-col-location"><?php echo esc_html( $location ); ?></td>
									<?php endif; ?>
									<?php if ( $atts['show_results'] ) : ?>
										<td class="law-col-result"><?php echo esc_html( $this->result_text( $game ) ); ?></td>
									<?php endif; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</section>
		<?php
		return ob_get_clean();
	}

	private function row_class( array $game ) {
		$classes = array( 'law-game' );
		if ( $game['has_score'] ) {
			$classes[] = 'law-game-final';
		}
		if ( $this->is_canceled( $game ) ) {
			$classes[] = 'law-game-canceled';
		}
		return implode( ' ', $classes );
	}

	private function matchup_html( array $game, $team ) {
		if ( '' === $game['home'] && '' === $game['away'] ) {
			return esc_html( '' !== $game['title'] ? $game['title'] : '—' );
		}
		$side = function ( $key ) use ( $game, $team ) {
			$name = '' !== $game[ $key ] ? $game[ $key ] : __( 'TBD', 'leagueapps-widgets' );
			$mine = '' !== $team && ( strtolower( $game[ $key ] ) === $team || (string) $game[ $key . '_id' ] === $team );
			return '<span class="law-team' . ( $mine ? ' law-team-selected' : '' ) . '">' . esc_html( $name ) . '</span>';
		};
		/* translators: 1: first team, 2: second team */
		return sprintf( esc_html__( '%1$s vs %2$s', 'leagueapps-widgets' ), $side( 'home' ), $side( 'away' ) );
	}

	private function is_canceled( array $game ) {
		return in_array( $game['state'], array( 'CANCELED', 'CANCELLED' ), true );
	}

	private function result_text( array $game ) {
		if ( $this->is_canceled( $game ) ) {
			return __( 'Canceled', 'leagueapps-widgets' );
		}
		if ( 'POSTPONED' === $game['state'] ) {
			return __( 'Postponed', 'leagueapps-widgets' );
		}
		if ( $game['has_score'] ) {
			return $this->number( $game['home_score'] ) . '–' . $this->number( $game['away_score'] );
		}
		return '';
	}

	private function number( $value ) {
		return floor( $value ) == $value ? (string) (int) $value : (string) round( $value, 1 ); // phpcs:ignore Universal.Operators.StrictComparisons
	}
}
