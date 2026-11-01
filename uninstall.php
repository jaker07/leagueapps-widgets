<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'leagueapps_widgets_settings' );
delete_option( 'leagueapps_widgets_cache_version' );
// Cached responses are transients and expire on their own.
