<?php
namespace LeagueApps_Widgets;

defined( 'ABSPATH' ) || exit;

/**
 * Settings > LeagueApps admin page: site ID, API key and cache lifetime.
 */
class Settings {

	const OPTION     = 'leagueapps_widgets_settings';
	const PAGE       = 'leagueapps-widgets';
	const CLEAR_HOOK = 'leagueapps_widgets_clear_cache';

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
		add_action( 'admin_post_' . self::CLEAR_HOOK, array( $this, 'handle_clear_cache' ) );
	}

	public static function defaults() {
		return array(
			'site_id'   => '',
			'api_key'   => '',
			'cache_ttl' => 15, // Minutes.
		);
	}

	public function get( $key ) {
		$opts = wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
		return isset( $opts[ $key ] ) ? $opts[ $key ] : null;
	}

	public function add_page() {
		add_options_page(
			__( 'LeagueApps Widgets', 'leagueapps-widgets' ),
			__( 'LeagueApps', 'leagueapps-widgets' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	public function register() {
		register_setting(
			self::PAGE,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);

		add_settings_section( 'law_api', __( 'LeagueApps API', 'leagueapps-widgets' ), '__return_false', self::PAGE );

		add_settings_field( 'site_id', __( 'Site ID', 'leagueapps-widgets' ), array( $this, 'field_site_id' ), self::PAGE, 'law_api' );
		add_settings_field( 'api_key', __( 'Public API key', 'leagueapps-widgets' ), array( $this, 'field_api_key' ), self::PAGE, 'law_api' );
		add_settings_field( 'cache_ttl', __( 'Cache lifetime (minutes)', 'leagueapps-widgets' ), array( $this, 'field_cache_ttl' ), self::PAGE, 'law_api' );
	}

	public function sanitize( $input ) {
		$current = wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
		$input   = is_array( $input ) ? $input : array();

		$out              = array();
		$out['site_id']   = isset( $input['site_id'] ) ? preg_replace( '/[^0-9]/', '', $input['site_id'] ) : '';
		// Blank API key field means "keep the saved key".
		$key              = isset( $input['api_key'] ) ? trim( sanitize_text_field( $input['api_key'] ) ) : '';
		$out['api_key']   = '' === $key ? $current['api_key'] : $key;
		$out['cache_ttl'] = isset( $input['cache_ttl'] ) ? max( 0, absint( $input['cache_ttl'] ) ) : 15;

		Api_Client::flush_cache();
		return $out;
	}

	public function field_site_id() {
		printf(
			'<input type="text" class="regular-text" name="%s[site_id]" value="%s" inputmode="numeric" />',
			esc_attr( self::OPTION ),
			esc_attr( $this->get( 'site_id' ) )
		);
	}

	public function field_api_key() {
		$has_key = '' !== $this->get( 'api_key' );
		printf(
			'<input type="password" class="regular-text" name="%s[api_key]" value="" autocomplete="off" placeholder="%s" />',
			esc_attr( self::OPTION ),
			esc_attr( $has_key ? __( 'Saved — leave blank to keep', 'leagueapps-widgets' ) : '' )
		);
	}

	public function field_cache_ttl() {
		printf(
			'<input type="number" min="0" step="1" class="small-text" name="%s[cache_ttl]" value="%d" /> <p class="description">%s</p>',
			esc_attr( self::OPTION ),
			(int) $this->get( 'cache_ttl' ),
			esc_html__( 'How long LeagueApps responses are cached. 0 disables caching (not recommended).', 'leagueapps-widgets' )
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'LeagueApps Widgets', 'leagueapps-widgets' ); ?></h1>
			<?php if ( isset( $_GET['law_cleared'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Cache cleared.', 'leagueapps-widgets' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::PAGE );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>
			<hr />
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::CLEAR_HOOK ); ?>" />
				<?php wp_nonce_field( self::CLEAR_HOOK ); ?>
				<?php submit_button( __( 'Clear cached LeagueApps data', 'leagueapps-widgets' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	public function handle_clear_cache() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'leagueapps-widgets' ), 403 );
		}
		check_admin_referer( self::CLEAR_HOOK );
		Api_Client::flush_cache();
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'law_cleared' => 1 ), admin_url( 'options-general.php' ) ) );
		exit;
	}
}
