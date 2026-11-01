<?php
/**
 * Plugin Name:       LeagueApps Widgets
 * Description:       Display LeagueApps data (standings and more) on your site via shortcodes and blocks.
 * Version:           0.1.0
 * Requires at least: 6.1
 * Requires PHP:      7.4
 * Author:            jaker07
 * License:           GPL-2.0-or-later
 * Text Domain:       leagueapps-widgets
 */

defined( 'ABSPATH' ) || exit;

define( 'LAW_VERSION', '0.1.0' );
define( 'LAW_FILE', __FILE__ );
define( 'LAW_DIR', plugin_dir_path( __FILE__ ) );
define( 'LAW_URL', plugin_dir_url( __FILE__ ) );

require_once LAW_DIR . 'includes/class-api-client.php';
require_once LAW_DIR . 'includes/class-settings.php';
require_once LAW_DIR . 'includes/abstract-class-widget.php';
require_once LAW_DIR . 'includes/class-widget-registry.php';
require_once LAW_DIR . 'includes/class-rest-controller.php';
require_once LAW_DIR . 'includes/class-schedule-data.php';
require_once LAW_DIR . 'includes/class-standings-calculator.php';
require_once LAW_DIR . 'includes/widgets/class-standings-widget.php';
require_once LAW_DIR . 'includes/widgets/class-schedule-widget.php';
require_once LAW_DIR . 'includes/class-plugin.php';

add_action( 'plugins_loaded', array( 'LeagueApps_Widgets\\Plugin', 'instance' ) );
