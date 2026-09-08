<?php
/**
 * Plugin name: AJAX Thumbnail Rebuild
 * Plugin URI: https://wordpress.org/plugins/ajax-thumbnail-rebuild/
 * Author: ristoniinemets, junkcoder
 * Version: 2.2.0
 * Description: Rebuild the thumbnails of your media library one image at a time, without running into script timeouts.
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Tested up to: 7.1
 * Text Domain: ajax-thumbnail-rebuild
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ATR_VERSION', '2.2.0' );
define( 'ATR_FILE', __FILE__ );
define( 'ATR_PATH', plugin_dir_path( __FILE__ ) );
define( 'ATR_URL', plugin_dir_url( __FILE__ ) );

require_once ATR_PATH . 'includes/class-atr-uploads.php';
require_once ATR_PATH . 'includes/class-atr-settings.php';
require_once ATR_PATH . 'includes/class-atr-image-sizes.php';
require_once ATR_PATH . 'includes/class-atr-image-proxy.php';
require_once ATR_PATH . 'includes/class-atr-copies.php';
require_once ATR_PATH . 'includes/class-atr-webp.php';
require_once ATR_PATH . 'includes/class-atr-avif.php';
require_once ATR_PATH . 'includes/class-atr-on-demand.php';
require_once ATR_PATH . 'includes/class-atr-tools.php';
require_once ATR_PATH . 'includes/class-atr-service.php';
require_once ATR_PATH . 'includes/class-atr-optimizer.php';
require_once ATR_PATH . 'includes/class-atr-queue.php';
require_once ATR_PATH . 'includes/class-atr-cleanup.php';
require_once ATR_PATH . 'includes/class-atr-replace.php';
require_once ATR_PATH . 'includes/class-atr-media-library.php';
require_once ATR_PATH . 'includes/class-atr-attachments.php';
require_once ATR_PATH . 'includes/class-atr-regenerator.php';
require_once ATR_PATH . 'includes/class-atr-rest-controller.php';
require_once ATR_PATH . 'includes/class-atr-admin-page.php';
require_once ATR_PATH . 'includes/class-atr-plugin.php';
require_once ATR_PATH . 'includes/deprecated.php';

/**
 * Main plugin instance.
 *
 * @return ATR_Plugin
 */
function ajax_thumbnail_rebuild() {
	static $plugin = null;

	if ( null === $plugin ) {
		$plugin = new ATR_Plugin();
	}

	return $plugin;
}

register_deactivation_hook( ATR_FILE, array( ATR_Queue::class, 'clear' ) );

ajax_thumbnail_rebuild()->boot();
