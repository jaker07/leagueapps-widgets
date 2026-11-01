<?php
namespace LeagueApps_Widgets\Widgets;

use LeagueApps_Widgets\Standings_Calculator;
use LeagueApps_Widgets\Widget;

defined( 'ABSPATH' ) || exit;

/**
 * Program standings for a sport.
 *
 * Shortcode: [leagueapps_standings sport="Soccer"]
 *            [leagueapps_standings program_id="123456" ranking="points"]
 *            [leagueapps_standings sport="Hockey" site="hockey"]
 */
class Standings_Widget extends Widget {

	public function get_id() {
		return 'standings';
	}

	public function get_title() {
		return __( 'LeagueApps Standings', 'leagueapps-widgets' );
	}

	public function get_description() {
		return __( 'Standings for current LeagueApps programs in a sport.', 'leagueapps-widgets' );
	}

	public function get_icon() {
		return 'awards';
	}

	public function get_attributes() {
		return array(
			'sport'       => array(
				'type'    => 'string',
				'default' => '',
				'label'       => __( 'Sport', 'leagueapps-widgets' ),
				'help'        => __( 'Shows every current program in this sport.', 'leagueapps-widgets' ),
				'control'     => 'sport',
				'empty_label' => __( '— Select a sport —', 'leagueapps-widgets' ),
			),
			'program_id'  => array(
				'type'    => 'string',
				'default' => '',
				'label'       => __( 'Program (optional)', 'leagueapps-widgets' ),
				'help'        => __( 'Show a single program instead of every program in the sport.', 'leagueapps-widgets' ),
				'control'     => 'program',
				'filter_by'   => 'sport',
				'empty_label' => __( 'All programs in the sport', 'leagueapps-widgets' ),
			),
			'ranking'     => array(
				'type'    => 'string',
				'default' => 'win_pct',
				'label'   => __( 'Rank teams by', 'leagueapps-widgets' ),
				'options' => array(
					'win_pct' => __( 'Win percentage', 'leagueapps-widgets' ),
					'points'  => __( 'Points (3 win / 1 tie)', 'leagueapps-widgets' ),
				),
			),
			'show_ties'   => array(
				'type'    => 'boolean',
				'default' => true,
				'label'   => __( 'Show ties column', 'leagueapps-widgets' ),
			),
			'show_scores' => array(
				'type'    => 'boolean',
				'default' => true,
				'label'   => __( 'Show points for / against', 'leagueapps-widgets' ),
			),
			'title'       => array(
				'type'    => 'string',
				'default' => '',
				'label'   => __( 'Heading (optional)', 'leagueapps-widgets' ),
				'help'    => __( 'Defaults to each program name.', 'leagueapps-widgets' ),
			),
		);
	}

	public function render( array $atts ) {
		if ( '' === $atts['sport'] && '' === $atts['program_id'] ) {
			return $this->render_error( __( 'Set a sport or a program ID.', 'leagueapps-widgets' ) );
		}

		$api = $this->api( $atts );
		if ( is_wp_error( $api ) ) {
			return $this->render_error( $api );
		}

		$programs = $this->find_programs( $api, $atts );
		if ( is_wp_error( $programs ) ) {
			return $this->render_error( $programs );
		}
		if ( ! $programs ) {
			return '<div class="law-widget law-standings law-empty">' . esc_html__( 'No current programs found.', 'leagueapps-widgets' ) . '</div>';
		}

		$html = '';
		foreach ( $programs as $program ) {
			$schedule = $api->get_program_schedule( $program['id'] );
			if ( is_wp_error( $schedule ) ) {
				$html .= $this->render_error( $schedule );
				continue;
			}
			$teams = $api->get_program_teams( $program['id'] );
			$rows  = Standings_Calculator::calculate( $schedule, is_wp_error( $teams ) ? array() : $teams, $atts['ranking'] );
			$html .= $this->render_table( $program, $rows, $atts );
		}

		return '<div class="law-widget law-standings">' . $html . '</div>';
	}

	/**
	 * @return array[]|\WP_Error List of array( 'id' => ..., 'name' => ... ).
	 */
	private function find_programs( $api, array $atts ) {
		$list = $api->get_programs();
		if ( is_wp_error( $list ) ) {
			// A specific program can still be shown without the program list.
			return '' !== $atts['program_id'] ? array( array( 'id' => $atts['program_id'], 'name' => '' ) ) : $list;
		}

		if ( '' !== $atts['program_id'] ) {
			foreach ( $list as $program ) {
				if ( $program['id'] === $atts['program_id'] ) {
					return array( $program );
				}
			}
			// Not in the current list (e.g. a finished program): try it directly.
			return array( array( 'id' => $atts['program_id'], 'name' => '' ) );
		}

		$found = array();
		foreach ( $list as $program ) {
			if ( 0 === strcasecmp( trim( $program['sport'] ), trim( $atts['sport'] ) ) ) {
				$found[] = $program;
			}
		}
		return apply_filters( 'leagueapps_widgets_standings_programs', $found, $atts );
	}

	private function render_table( array $program, array $rows, array $atts ) {
		$heading = '' !== $atts['title'] ? $atts['title'] : $program['name'];
		$cols    = array(
			'gp' => __( 'GP', 'leagueapps-widgets' ),
			'w'  => __( 'W', 'leagueapps-widgets' ),
			'l'  => __( 'L', 'leagueapps-widgets' ),
		);
		if ( $atts['show_ties'] ) {
			$cols['t'] = __( 'T', 'leagueapps-widgets' );
		}
		if ( 'points' === $atts['ranking'] ) {
			$cols['pts'] = __( 'Pts', 'leagueapps-widgets' );
		} else {
			$cols['pct'] = __( 'Pct', 'leagueapps-widgets' );
		}
		if ( $atts['show_scores'] ) {
			$cols['pf']   = __( 'PF', 'leagueapps-widgets' );
			$cols['pa']   = __( 'PA', 'leagueapps-widgets' );
			$cols['diff'] = __( 'Diff', 'leagueapps-widgets' );
		}
		$cols = apply_filters( 'leagueapps_widgets_standings_columns', $cols, $atts );

		ob_start();
		?>
		<section class="law-standings-program">
			<?php if ( '' !== $heading ) : ?>
				<h3 class="law-standings-title"><?php echo esc_html( $heading ); ?></h3>
			<?php endif; ?>
			<?php if ( ! $rows ) : ?>
				<p class="law-empty"><?php esc_html_e( 'No results yet.', 'leagueapps-widgets' ); ?></p>
			<?php else : ?>
				<div class="law-table-wrap">
					<table class="law-standings-table">
						<thead>
							<tr>
								<th scope="col" class="law-col-rank">#</th>
								<th scope="col" class="law-col-team"><?php esc_html_e( 'Team', 'leagueapps-widgets' ); ?></th>
								<?php foreach ( $cols as $label ) : ?>
									<th scope="col" class="law-col-num"><?php echo esc_html( $label ); ?></th>
								<?php endforeach; ?>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $row ) : ?>
								<tr>
									<td class="law-col-rank"><?php echo (int) $row['rank']; ?></td>
									<th scope="row" class="law-col-team"><?php echo esc_html( $row['team'] ); ?></th>
									<?php foreach ( array_keys( $cols ) as $key ) : ?>
										<td class="law-col-num"><?php echo esc_html( $this->format_cell( $key, isset( $row[ $key ] ) ? $row[ $key ] : '' ) ); ?></td>
									<?php endforeach; ?>
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

	private function format_cell( $key, $value ) {
		switch ( $key ) {
			case 'pct':
				// Baseball-style ".750"; a perfect record shows "1.000".
				return $value >= 1 ? '1.000' : ltrim( number_format( (float) $value, 3 ), '0' );
			case 'diff':
				return ( $value > 0 ? '+' : '' ) . $this->format_number( $value );
			case 'pf':
			case 'pa':
			case 'pts':
				return $this->format_number( $value );
			default:
				return (string) $value;
		}
	}

	private function format_number( $value ) {
		return floor( $value ) == $value ? (string) (int) $value : (string) round( $value, 1 ); // phpcs:ignore Universal.Operators.StrictComparisons
	}
}
