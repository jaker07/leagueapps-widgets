<?php
namespace LeagueApps_Widgets\Widgets;

use LeagueApps_Widgets\Announcement_Data;
use LeagueApps_Widgets\Widget;

defined( 'ABSPATH' ) || exit;

/**
 * The site's LeagueApps announcements, newest first.
 *
 * Shortcode: [leagueapps_announcements]
 *            [leagueapps_announcements limit="3" content="titles" site="hockey"]
 */
class Announcements_Widget extends Widget {

	public function get_id() {
		return 'announcements';
	}

	public function get_title() {
		return __( 'LeagueApps Announcements', 'leagueapps-widgets' );
	}

	public function get_description() {
		return __( 'Latest announcements from a LeagueApps site.', 'leagueapps-widgets' );
	}

	public function get_icon() {
		return 'megaphone';
	}

	public function get_attributes() {
		return array(
			'limit'        => array(
				'type'    => 'integer',
				'default' => 5,
				'label'   => __( 'Maximum announcements', 'leagueapps-widgets' ),
				'help'    => __( '0 shows all of them.', 'leagueapps-widgets' ),
			),
			'content'      => array(
				'type'    => 'string',
				'default' => 'excerpt',
				'label'   => __( 'Show', 'leagueapps-widgets' ),
				'options' => array(
					'excerpt' => __( 'Title and a short excerpt', 'leagueapps-widgets' ),
					'full'    => __( 'Title and full text', 'leagueapps-widgets' ),
					'titles'  => __( 'Titles only', 'leagueapps-widgets' ),
				),
			),
			'show_date'    => array(
				'type'    => 'boolean',
				'default' => true,
				'label'   => __( 'Show dates', 'leagueapps-widgets' ),
			),
			'show_images'  => array(
				'type'    => 'boolean',
				'default' => true,
				'label'   => __( 'Show images', 'leagueapps-widgets' ),
			),
			'hide_expired' => array(
				'type'    => 'boolean',
				'default' => true,
				'label'   => __( 'Hide expired announcements', 'leagueapps-widgets' ),
				'help'    => __( 'For announcements that have an end date in LeagueApps.', 'leagueapps-widgets' ),
			),
			'title'        => array(
				'type'    => 'string',
				'default' => '',
				'label'   => __( 'Heading (optional)', 'leagueapps-widgets' ),
			),
		);
	}

	public function render( array $atts ) {
		$api = $this->api( $atts );
		if ( is_wp_error( $api ) ) {
			return $this->render_error( $api );
		}

		$response = $api->get_announcements();
		if ( is_wp_error( $response ) ) {
			return $this->render_error( $response );
		}

		$items = Announcement_Data::announcements( $response );
		if ( $atts['hide_expired'] ) {
			$now   = time();
			$items = array_filter(
				$items,
				function ( $item ) use ( $now ) {
					return null === $item['expires'] || $item['expires'] > $now;
				}
			);
		}
		$items = array_values( $items );
		if ( $atts['limit'] > 0 ) {
			$items = array_slice( $items, 0, $atts['limit'] );
		}
		$items = apply_filters( 'leagueapps_widgets_announcements', $items, $atts );

		$heading = $atts['title'];
		$tag     = '' !== $heading ? 'h4' : 'h3';

		ob_start();
		?>
		<div class="law-widget law-announcements law-announcements-<?php echo esc_attr( $atts['content'] ); ?>">
			<?php if ( '' !== $heading ) : ?>
				<h3 class="law-announcements-title"><?php echo esc_html( $heading ); ?></h3>
			<?php endif; ?>
			<?php if ( ! $items ) : ?>
				<p class="law-empty"><?php esc_html_e( 'No announcements right now.', 'leagueapps-widgets' ); ?></p>
			<?php else : ?>
				<ul class="law-announcement-list">
					<?php foreach ( $items as $item ) : ?>
						<li class="law-announcement">
							<article>
								<?php if ( $atts['show_images'] && '' !== $item['image'] && 'titles' !== $atts['content'] ) : ?>
									<img class="law-announcement-image" src="<?php echo esc_url( $item['image'] ); ?>" alt="" loading="lazy" decoding="async" />
								<?php endif; ?>
								<div class="law-announcement-text">
									<<?php echo esc_html( $tag ); ?> class="law-announcement-heading">
										<?php if ( '' !== $item['url'] ) : ?>
											<a href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $this->title_of( $item ) ); ?></a>
										<?php else : ?>
											<?php echo esc_html( $this->title_of( $item ) ); ?>
										<?php endif; ?>
									</<?php echo esc_html( $tag ); ?>>
									<?php if ( $atts['show_date'] && null !== $item['date'] ) : ?>
										<time class="law-announcement-date" datetime="<?php echo esc_attr( wp_date( 'c', $item['date'] ) ); ?>"><?php echo esc_html( wp_date( get_option( 'date_format' ), $item['date'] ) ); ?></time>
									<?php endif; ?>
									<?php echo $this->body_html( $item, $atts ); // phpcs:ignore WordPress.Security.EscapeOutput -- sanitized in body_html(). ?>
								</div>
							</article>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private function title_of( array $item ) {
		if ( '' !== $item['title'] ) {
			return $item['title'];
		}
		// Untitled announcement: use the start of its text.
		return wp_trim_words( wp_strip_all_tags( $item['body'] ), 8, '…' );
	}

	private function body_html( array $item, array $atts ) {
		if ( 'titles' === $atts['content'] || '' === $item['body'] ) {
			return '';
		}
		if ( '' === $item['title'] && 'excerpt' === $atts['content'] ) {
			return ''; // The title already is the start of the text.
		}

		if ( 'excerpt' === $atts['content'] ) {
			$source  = '' !== $item['summary'] && $item['summary'] !== $item['body'] ? $item['summary'] : $item['body'];
			$words   = (int) apply_filters( 'leagueapps_widgets_announcement_excerpt_words', 30 );
			$excerpt = wp_trim_words( wp_strip_all_tags( $source ), $words, '…' );
			$more    = '';
			if ( '' !== $item['url'] ) {
				$more = ' <a class="law-announcement-more" href="' . esc_url( $item['url'] ) . '">' . esc_html__( 'Read more', 'leagueapps-widgets' )
					. '<span class="law-sr-only">: ' . esc_html( $this->title_of( $item ) ) . '</span></a>';
			}
			return '<p class="law-announcement-body">' . esc_html( $excerpt ) . $more . '</p>';
		}

		// Full text: the API returns HTML, so keep basic formatting only.
		$allowed = apply_filters(
			'leagueapps_widgets_announcement_allowed_html',
			array(
				'p'      => array(),
				'br'     => array(),
				'strong' => array(),
				'b'      => array(),
				'em'     => array(),
				'i'      => array(),
				'u'      => array(),
				'ul'     => array(),
				'ol'     => array(),
				'li'     => array(),
				'h4'     => array(),
				'h5'     => array(),
				'blockquote' => array(),
				'a'      => array( 'href' => true, 'title' => true ),
			)
		);
		// Drop script/style blocks with their contents (wp_kses keeps the text inside).
		$body = preg_replace( '#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $item['body'] );
		$body = wp_kses( $body, $allowed, array( 'http', 'https', 'mailto', 'tel' ) );
		// Unwrap links whose href was stripped of a disallowed scheme (e.g. javascript:).
		$body = preg_replace_callback(
			'#<a\s[^>]*href="([^"]*)"[^>]*>(.*?)</a>#is',
			function ( $m ) {
				return preg_match( '#^(https?:|mailto:|tel:|/|\#)#i', html_entity_decode( $m[1] ) ) ? $m[0] : $m[2];
			},
			$body
		);
		if ( false === strpos( $body, '<p' ) ) {
			$body = wpautop( $body );
		}
		return '<div class="law-announcement-body">' . $body . '</div>';
	}
}
