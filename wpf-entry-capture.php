<?php
/**
 * Plugin Name:       WPF Entry Capture
 * Plugin URI:        https://yorkcs.com/
 * Description:       Capture form entries from WPForms forms.
 * Version:           1.0
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            York Computer Solutions LLC
 * Author URI:        https://yorkcs.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wpfec
 */

// Exit if accessed directly
if( ! defined( 'ABSPATH' ) ) {
    die;
}

define( 'WPFEC_PLUGIN_FILE', __FILE__ );
define( 'WPFEC_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPFEC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPFEC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once WPFEC_PLUGIN_PATH . 'includes/class-wpfec.php';

add_action( 'plugins_loaded', array( 'WPFEC', 'init' ) );
