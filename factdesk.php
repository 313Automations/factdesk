<?php
/**
 * Plugin Name:       FactDesk – Read-Only MCP Server for AI Assistants
 * Plugin URI:        https://313automations.com/blog/factdesk-wordpress-plugin
 * Description:       A public, read-only MCP server that lets AI assistants and agents search your pages, posts, and custom post types, read content as Markdown, and look up WooCommerce products, prices, and stock availability. Only publicly accessible content is shared, and assistants cannot modify your site.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Ghulam Mustafa
 * Author URI:        https://313automations.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       factdesk
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FACTDESK_VERSION', '1.0.0' );
define( 'FACTDESK_FILE', __FILE__ );
define( 'FACTDESK_DIR', plugin_dir_path( __FILE__ ) );
define( 'FACTDESK_URL', plugin_dir_url( __FILE__ ) );

require_once FACTDESK_DIR . 'includes/class-factdesk-settings.php';
require_once FACTDESK_DIR . 'includes/class-factdesk-markdown.php';
require_once FACTDESK_DIR . 'includes/class-factdesk-content.php';
require_once FACTDESK_DIR . 'includes/class-factdesk-products.php';
require_once FACTDESK_DIR . 'includes/class-factdesk-stats.php';
require_once FACTDESK_DIR . 'includes/class-factdesk-server.php';
require_once FACTDESK_DIR . 'includes/class-factdesk-discovery.php';
require_once FACTDESK_DIR . 'includes/class-factdesk-admin.php';

register_activation_hook( __FILE__, array( 'FactDesk_Settings', 'activate' ) );

add_action( 'plugins_loaded', 'factdesk_boot' );

function factdesk_boot() {
	FactDesk_Server::init();
	FactDesk_Discovery::init();
	FactDesk_Admin::init();
}
