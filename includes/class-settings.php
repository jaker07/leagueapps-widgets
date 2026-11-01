<?php
namespace LeagueApps_Widgets;

defined( 'ABSPATH' ) || exit;

/**
 * Settings > LeagueApps admin page: one or more LeagueApps sites and the
 * cache lifetime.
 *
 * Stored option shape:
 *   array(
 *       'sites'     => array(
 *           array( 'slug' => 'default', 'label' => 'Default', 'site_id' => '123', 'api_key' => '…' ),
 *           …
 *       ),
 *       'cache_ttl' => 15,
 *   )
 *
 * The first site is the default. Older versions stored a single `site_id`
 * and `api_key`; those are read as the default site.
 */
class Settings {

	const OPTION       = 'leagueapps_widgets_settings';
	const PAGE         = 'leagueapps-widgets';
	const CLEAR_HOOK   = 'leagueapps_widgets_clear_cache';
	const DEFAULT_SLUG = 'default';

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
		add_action( 'admin_post_' . self::CLEAR_HOOK, array( $this, 'handle_clear_cache' ) );
	}

	public static function defaults() {
		return array(
			'sites'     => array(),
			'cache_ttl' => 15, // Minutes.
		);
	}

	/** Full option, upgraded to the multi-site shape. */
	private function options() {
		$raw  = get_option( self::OPTION, array() );
		$raw  = is_array( $raw ) ? $raw : array();
		$opts = wp_parse_args( $raw, self::defaults() );

		if ( empty( $raw['sites'] ) && ( ! empty( $raw['site_id'] ) || ! empty( $raw['api_key'] ) ) ) {
			$opts['sites'] = array(
				array(
					'slug'    => self::DEFAULT_SLUG,
					'label'   => __( 'Default', 'leagueapps-widgets' ),
					'site_id' => isset( $raw['site_id'] ) ? (string) $raw['site_id'] : '',
					'api_key' => isset( $raw['api_key'] ) ? (string) $raw['api_key'] : '',
				),
			);
		}
		unset( $opts['site_id'], $opts['api_key'] );
		return $opts;
	}

	public function get( $key ) {
		$opts = $this->options();
		return isset( $opts[ $key ] ) ? $opts[ $key ] : null;
	}

	/**
	 * Configured sites, in order, keyed by slug. The first one is the default.
	 *
	 * @return array[]
	 */
	public function get_sites() {
		$sites = array();
		foreach ( (array) $this->get( 'sites' ) as $site ) {
			if ( ! empty( $site['slug'] ) ) {
				$sites[ $site['slug'] ] = $site;
			}
		}
		return $sites;
	}

	public function default_slug() {
		$sites = $this->get_sites();
		return $sites ? (string) array_key_first( $sites ) : '';
	}

	/**
	 * A site by slug; '' means the default site.
	 *
	 * @return array|null
	 */
	public function get_site( $slug = '' ) {
		$sites = $this->get_sites();
		$slug  = '' === (string) $slug ? $this->default_slug() : self::normalize_slug( $slug );
		return isset( $sites[ $slug ] ) ? $sites[ $slug ] : null;
	}

	/**
	 * Lowercase letters, numbers and dashes, never purely numeric (numeric
	 * keys would be reordered in PHP and JavaScript objects).
	 */
	public static function normalize_slug( $value ) {
		$slug = sanitize_title( (string) $value );
		if ( '' !== $slug && ctype_digit( str_replace( '-', '', $slug ) ) ) {
			$slug = 'site-' . $slug;
		}
		return $slug;
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
	}

	public function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$previous = $this->get_sites();
		$sites    = array();

		$rows = isset( $input['sites'] ) && is_array( $input['sites'] ) ? $input['sites'] : array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label   = isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '';
			$site_id = isset( $row['site_id'] ) ? preg_replace( '/[^0-9]/', '', (string) $row['site_id'] ) : '';
			$api_key = isset( $row['api_key'] ) ? trim( sanitize_text_field( $row['api_key'] ) ) : '';
			$orig    = isset( $row['orig_slug'] ) ? self::normalize_slug( $row['orig_slug'] ) : '';

			if ( '' === $label && '' === $site_id && '' === $api_key ) {
				continue; // Empty row.
			}

			// Blank API key means "keep the key saved for this row".
			if ( '' === $api_key && '' !== $orig && isset( $previous[ $orig ] ) ) {
				$api_key = $previous[ $orig ]['api_key'];
			}

			$slug = self::normalize_slug( isset( $row['slug'] ) && '' !== trim( $row['slug'] ) ? $row['slug'] : $label );
			if ( '' === $slug ) {
				$slug = '' !== $site_id ? self::normalize_slug( $site_id ) : 'site';
			}
			$base = $slug;
			for ( $n = 2; isset( $sites[ $slug ] ); $n++ ) {
				$slug = $base . '-' . $n;
			}

			$sites[ $slug ] = array(
				'slug'    => $slug,
				'label'   => '' !== $label ? $label : $slug,
				'site_id' => $site_id,
				'api_key' => $api_key,
			);
		}

		Api_Client::flush_cache();

		return array(
			'sites'     => array_values( $sites ),
			'cache_ttl' => isset( $input['cache_ttl'] ) ? absint( $input['cache_ttl'] ) : 15,
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$sites = array_values( $this->get_sites() );
		if ( ! $sites ) {
			$sites = array( array( 'slug' => '', 'label' => '', 'site_id' => '', 'api_key' => '' ) );
		}
		$name = self::OPTION;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'LeagueApps Widgets', 'leagueapps-widgets' ); ?></h1>
			<?php if ( isset( $_GET['law_cleared'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Cache cleared.', 'leagueapps-widgets' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="options.php">
				<?php settings_fields( self::PAGE ); ?>

				<h2><?php esc_html_e( 'LeagueApps sites', 'leagueapps-widgets' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Most organizations have one LeagueApps site. If you have a separate site per sport, add each one here. The first site is the default; choose another per widget with the block’s "LeagueApps site" setting or a shortcode’s site="slug" attribute.', 'leagueapps-widgets' ); ?>
				</p>

				<table class="widefat striped law-sites" style="max-width:1100px;margin-top:1em">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Label', 'leagueapps-widgets' ); ?></th>
							<th><?php esc_html_e( 'Slug', 'leagueapps-widgets' ); ?></th>
							<th><?php esc_html_e( 'Site ID', 'leagueapps-widgets' ); ?></th>
							<th><?php esc_html_e( 'Public API key', 'leagueapps-widgets' ); ?></th>
							<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'leagueapps-widgets' ); ?></span></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $sites as $i => $site ) : ?>
							<?php $this->render_site_row( $name . '[sites][' . $i . ']', $site, 0 === $i ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">
					<?php esc_html_e( 'The slug is what shortcodes and blocks use to pick a site. It is filled in from the label if left blank. Changing a slug later breaks widgets that already use the old one.', 'leagueapps-widgets' ); ?>
				</p>
				<p>
					<button type="button" class="button law-add-site"><?php esc_html_e( 'Add site', 'leagueapps-widgets' ); ?></button>
				</p>
				<template id="law-site-template">
					<?php $this->render_site_row( $name . '[sites][__i__]', array( 'slug' => '', 'label' => '', 'site_id' => '', 'api_key' => '' ), false ); ?>
				</template>

				<h2><?php esc_html_e( 'Caching', 'leagueapps-widgets' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="law-cache-ttl"><?php esc_html_e( 'Cache lifetime (minutes)', 'leagueapps-widgets' ); ?></label></th>
						<td>
							<input id="law-cache-ttl" type="number" min="0" step="1" class="small-text" name="<?php echo esc_attr( $name ); ?>[cache_ttl]" value="<?php echo (int) $this->get( 'cache_ttl' ); ?>" />
							<p class="description"><?php esc_html_e( 'How long LeagueApps responses are cached. 0 disables caching (not recommended).', 'leagueapps-widgets' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
			<hr />
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::CLEAR_HOOK ); ?>" />
				<?php wp_nonce_field( self::CLEAR_HOOK ); ?>
				<?php submit_button( __( 'Clear cached LeagueApps data', 'leagueapps-widgets' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<script>
		( function () {
			var tbody = document.querySelector( '.law-sites tbody' );
			var tpl = document.getElementById( 'law-site-template' );
			var next = tbody.rows.length;
			document.querySelector( '.law-add-site' ).addEventListener( 'click', function () {
				tbody.insertAdjacentHTML( 'beforeend', tpl.innerHTML.replace( /__i__/g, String( next++ ) ) );
			} );
			tbody.addEventListener( 'click', function ( e ) {
				if ( e.target.classList.contains( 'law-remove-site' ) ) {
					e.target.closest( 'tr' ).remove();
				}
			} );
		}() );
		</script>
		<?php
	}

	private function render_site_row( $prefix, array $site, $is_default ) {
		$has_key = '' !== (string) $site['api_key'];
		?>
		<tr>
			<td>
				<input type="text" class="regular-text" style="width:100%" name="<?php echo esc_attr( $prefix ); ?>[label]" value="<?php echo esc_attr( $site['label'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Hockey', 'leagueapps-widgets' ); ?>" />
				<?php if ( $is_default ) : ?>
					<p class="description"><?php esc_html_e( 'Default site', 'leagueapps-widgets' ); ?></p>
				<?php endif; ?>
			</td>
			<td>
				<input type="text" style="width:100%" name="<?php echo esc_attr( $prefix ); ?>[slug]" value="<?php echo esc_attr( $site['slug'] ); ?>" placeholder="<?php esc_attr_e( 'From label', 'leagueapps-widgets' ); ?>" />
				<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[orig_slug]" value="<?php echo esc_attr( $site['slug'] ); ?>" />
			</td>
			<td>
				<input type="text" inputmode="numeric" style="width:100%" name="<?php echo esc_attr( $prefix ); ?>[site_id]" value="<?php echo esc_attr( $site['site_id'] ); ?>" />
			</td>
			<td>
				<input type="password" autocomplete="off" style="width:100%" name="<?php echo esc_attr( $prefix ); ?>[api_key]" value="" placeholder="<?php echo esc_attr( $has_key ? __( 'Saved — leave blank to keep', 'leagueapps-widgets' ) : '' ); ?>" />
			</td>
			<td>
				<button type="button" class="button-link law-remove-site"><?php esc_html_e( 'Remove', 'leagueapps-widgets' ); ?></button>
			</td>
		</tr>
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
