<?php
/**
 * Plugin name:       WordPress MCP
 * Description:       A plugin to integrate WordPress with Model Context Protocol (MCP), providing AI-accessible interfaces to WordPress data and functionality through standardized tools, resources, and prompts. Enables AI assistants to interact with posts, users, site settings, and WooCommerce data.
 * Version:           0.2.7
 * Plugin URI:        https://github.com/amAbdoMo/WP-Custom-MCP
 * Update URI:        https://github.com/amAbdoMo/WP-Custom-MCP
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Abdo
 * Author URI:        https://abdom.me/
 * License:           GPL-2.0-or-later
 * License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain:       wordpress-mcp
 * Domain Path:       /languages
 *
 * @package WordPress MCP
 */

declare(strict_types=1);

use Automattic\WordpressMcp\Core\McpStreamableTransport;
use Automattic\WordpressMcp\Core\WpMcp;
use Automattic\WordpressMcp\Core\McpStdioTransport;
use Automattic\WordpressMcp\Admin\Settings;
use Automattic\WordpressMcp\Auth\JwtAuth;
use Automattic\WordpressMcp\Cli\ValidateToolsCommand;

defined( 'ABSPATH' ) || exit;

define( 'WORDPRESS_MCP_VERSION', '0.2.7' );
define( 'WORDPRESS_MCP_PATH', plugin_dir_path( __FILE__ ) );
define( 'WORDPRESS_MCP_URL', plugin_dir_url( __FILE__ ) );

// Check if Composer autoloader exists.
if ( ! file_exists( WORDPRESS_MCP_PATH . 'vendor/autoload.php' ) ) {
	wp_die(
		sprintf(
			'Please run <code>composer install</code> in the plugin directory: <code>%s</code>',
			esc_html( WORDPRESS_MCP_PATH )
		)
	);
}

require_once WORDPRESS_MCP_PATH . 'vendor/autoload.php';
require_once WORDPRESS_MCP_PATH . 'includes/wordpress-mcp-updater.php';

add_action( 'plugins_loaded', 'wordpress_mcp_register_release_updater', 5 );

/**
 * Load the bundled MCP Adapter when the standalone adapter plugin is not active.
 */
function wordpress_mcp_load_bundled_adapter(): void {
	if ( defined( 'WP_MCP_DIR' ) || class_exists( '\\WP\\MCP\\Plugin' ) ) {
		return;
	}

	$adapter_entries = array(
		WORDPRESS_MCP_PATH . 'includes/mcp-adapter/mcp-adapter.php',
		dirname( WORDPRESS_MCP_PATH ) . '/mcp-adapter/mcp-adapter.php',
	);

	foreach ( $adapter_entries as $adapter_entry ) {
		if ( file_exists( $adapter_entry ) ) {
			require_once $adapter_entry;
			return;
		}
	}
}

add_action( 'plugins_loaded', 'wordpress_mcp_load_bundled_adapter', 20 );

/**
 * Get the WordPress MCP instance.
 *
 * @return WpMcp
 */
function WPMCP() { // phpcs:ignore
	return WpMcp::instance();
}

/**
 * Initialize the plugin.
 */
function wordpress_mcp_init() {
	$mcp = WPMCP();

	// Initialize the STDIO transport.
	new McpStdioTransport( $mcp );

	// Initialize the Streamable transport.
	new McpStreamableTransport( $mcp );

	// Initialize the settings page.
	new Settings();

	// Initialize the JWT authentication.
	new JwtAuth();
}

// Initialize the plugin.
add_action( 'init', 'wordpress_mcp_init' );

/**
 * Register WP-CLI commands when WP-CLI is available.
 */
function wordpress_mcp_register_cli_commands() {
	if ( ! class_exists( '\WP_CLI' ) ) {
		return;
	}

	\WP_CLI::add_command( 'mcp validate-tools', ValidateToolsCommand::class ); // phpcs:ignore
}

// Register WP-CLI commands when CLI is loaded.
add_action( 'cli_init', 'wordpress_mcp_register_cli_commands' );

/**
 * Declare WooCommerce feature compatibility.
 */
function wordpress_mcp_declare_woocommerce_compatibility(): void {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
}

add_action( 'before_woocommerce_init', 'wordpress_mcp_declare_woocommerce_compatibility' );
