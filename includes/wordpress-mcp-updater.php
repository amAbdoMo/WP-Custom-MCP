<?php
/**
 * GitHub Release update bootstrap for WordPress MCP.
 *
 * @package WordPressMcp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register updates distributed through the canonical GitHub Release asset.
 */
function wordpress_mcp_register_release_updater(): void {
	static $update_checker;

	if ( $update_checker ) {
		return;
	}

	$puc_file = WORDPRESS_MCP_PATH . 'vendor/plugin-update-checker/plugin-update-checker.php';
	if ( ! is_readable( $puc_file ) ) {
		return;
	}

	require_once $puc_file;

	$update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/amAbdoMo/WP-Custom-MCP',
		WORDPRESS_MCP_PATH . 'wordpress-mcp.php',
		'wordpress-mcp'
	);

	$update_checker->getVcsApi()->enableReleaseAssets(
		'/^wordpress-mcp\.zip$/',
		\YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api::REQUIRE_RELEASE_ASSETS
	);

	add_filter(
		$update_checker->getUniqueName( 'vcs_update_detection_strategies' ),
		'wordpress_mcp_release_only_update_strategy'
	);
}

/**
 * Prevent fallback to GitHub tag and branch source archives.
 *
 * @param array $strategies Available update detection strategies.
 * @return array
 */
function wordpress_mcp_release_only_update_strategy( array $strategies ): array {
	$release_strategy = \YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api::STRATEGY_LATEST_RELEASE;
	if ( ! isset( $strategies[ $release_strategy ] ) ) {
		return array();
	}

	return array( $release_strategy => $strategies[ $release_strategy ] );
}
