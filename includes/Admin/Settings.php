<?php //phpcs:ignore
declare(strict_types=1);

namespace Automattic\WordpressMcp\Admin;

use Automattic\WordpressMcp\Core\WpMcp;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the MCP settings page in WordPress admin.
 */
class Settings {
	/**
	 * The option name in the WordPress options table.
	 */
	const OPTION_NAME = 'wordpress_mcp_settings';

	/**
	 * The tool states option name.
	 */
	const TOOL_STATES_OPTION = 'wordpress_mcp_tool_states';

	/**
	 * The resource states option name.
	 */
	const RESOURCE_STATES_OPTION = 'wordpress_mcp_resource_states';

	/**
	 * The prompt states option name.
	 */
	const PROMPT_STATES_OPTION = 'wordpress_mcp_prompt_states';

	/**
	 * The MCP connection page hook suffix returned by WordPress.
	 *
	 * @var string
	 */
	private string $connection_page_hook = '';

	/**
	 * The MCP abilities page hook suffix returned by WordPress.
	 *
	 * @var string
	 */
	private string $abilities_page_hook = '';

	/**
	 * Initialize the settings page.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_form_submission' ) );
		add_action( 'wp_ajax_wordpress_mcp_generate_application_password', array( $this, 'handle_generate_application_password' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WORDPRESS_MCP_PATH . 'wordpress-mcp.php' ), array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Add the settings page to the WordPress admin menu.
	 */
	public function add_settings_page(): void {
		$this->connection_page_hook = add_options_page(
			__( 'MCP Connection', 'wordpress-mcp' ),
			__( 'MCP Connection', 'wordpress-mcp' ),
			'manage_options',
			'wordpress-mcp-settings',
			array( $this, 'render_settings_page' )
		);

		$this->abilities_page_hook = add_options_page(
			__( 'MCP Abilities', 'wordpress-mcp' ),
			__( 'MCP Abilities', 'wordpress-mcp' ),
			'manage_options',
			'wordpress-mcp-abilities',
			array( $this, 'render_abilities_page' )
		);

		foreach ( array( $this->connection_page_hook, $this->abilities_page_hook ) as $page_hook ) {
			if ( $page_hook ) {
				add_action( 'load-' . $page_hook, array( $this, 'suppress_external_admin_notices' ) );
			}
		}
	}

	/**
	 * Prevent unrelated plugin/theme notices from appearing inside the MCP settings UI.
	 */
	public function suppress_external_admin_notices(): void {
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'network_admin_notices' );
		remove_all_actions( 'user_admin_notices' );
	}

	/**
	 * Register the settings and their sanitization callbacks.
	 */
	public function register_settings(): void {
		register_setting(
			'wordpress_mcp_settings',
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);
	}

	/**
	 * Handle the single settings form.
	 */
	public function handle_form_submission(): void {
		if ( ! isset( $_POST['wordpress_mcp_action'] ) ) {
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_POST['wordpress_mcp_action'] ) );
		if ( 'save_settings' !== $action ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wordpress-mcp' ) );
		}

		check_admin_referer( 'wordpress_mcp_save_settings' );

		$settings = $this->sanitize_settings(
			array(
				'enabled'                    => $this->posted_bool( 'enabled' ),
				'features_adapter_enabled'   => $this->posted_bool( 'features_adapter_enabled' ),
				'enable_create_tools'        => $this->posted_bool( 'enable_create_tools' ),
				'enable_update_tools'        => $this->posted_bool( 'enable_update_tools' ),
				'enable_delete_tools'        => $this->posted_bool( 'enable_delete_tools' ),
				'enable_rest_api_crud_tools' => $this->posted_bool( 'enable_rest_api_crud_tools' ),
			)
		);

		update_option( self::OPTION_NAME, $settings, 'no' );
		$this->update_tool_group_states();
		$this->update_component_states( self::RESOURCE_STATES_OPTION, 'all_resources', 'enabled_resources' );
		$this->update_component_states( self::PROMPT_STATES_OPTION, 'all_prompts', 'enabled_prompts' );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                => 'wordpress-mcp-abilities',
					'wordpress_mcp_saved' => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Generate a WordPress application password for the current administrator.
	 */
	public function handle_generate_application_password(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to generate application passwords.', 'wordpress-mcp' ),
				),
				403
			);
		}

		check_ajax_referer( 'wordpress_mcp_generate_application_password', 'nonce' );

		$user = wp_get_current_user();
		if ( ! $user || empty( $user->ID ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No logged-in user was found.', 'wordpress-mcp' ),
				),
				400
			);
		}

		if ( ! class_exists( 'WP_Application_Passwords' ) || ! method_exists( 'WP_Application_Passwords', 'create_new_application_password' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Application passwords are not available on this WordPress installation.', 'wordpress-mcp' ),
				),
				400
			);
		}

		if ( function_exists( 'wp_is_application_passwords_available' ) && ! wp_is_application_passwords_available() ) {
			wp_send_json_error(
				array(
					'message' => __( 'Application passwords are disabled for this site. They usually require HTTPS or a local development environment.', 'wordpress-mcp' ),
				),
				400
			);
		}

		if ( function_exists( 'wp_is_application_passwords_available_for_user' ) && ! wp_is_application_passwords_available_for_user( $user ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Application passwords are disabled for this user or site.', 'wordpress-mcp' ),
				),
				400
			);
		}

		$result = \WP_Application_Passwords::create_new_application_password(
			$user->ID,
			array(
				'name' => sprintf(
					/* translators: %s: Site name. */
					__( 'WordPress MCP - %s', 'wordpress-mcp' ),
					get_bloginfo( 'name' )
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
				),
				400
			);
		}

		$password = is_array( $result ) ? (string) ( $result[0] ?? '' ) : '';
		if ( '' === $password ) {
			wp_send_json_error(
				array(
					'message' => __( 'WordPress did not return an application password.', 'wordpress-mcp' ),
				),
				500
			);
		}

		wp_send_json_success(
			array(
				'password' => $password,
				'username' => $user->user_login,
				'message'  => __( 'Application password generated. Copy your configs now; WordPress only shows this password once.', 'wordpress-mcp' ),
			)
		);
	}

	/**
	 * Check whether a checkbox value was posted.
	 *
	 * @param string $key Posted field key.
	 * @return bool
	 */
	private function posted_bool( string $key ): bool {
		return isset( $_POST[ $key ] ) && '0' !== sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
	}

	/**
	 * Update saved states for tools, resources, or prompts.
	 *
	 * @param string $option_name Option name.
	 * @param string $all_key Posted key containing all component IDs.
	 * @param string $enabled_key Posted key containing enabled component IDs.
	 */
	private function update_component_states( string $option_name, string $all_key, string $enabled_key ): void {
		$all_items = $this->posted_array( $all_key );
		if ( empty( $all_items ) ) {
			return;
		}

		$enabled_items = $this->posted_array( $enabled_key );
		$states        = array();

		foreach ( array_unique( $all_items ) as $item ) {
			$states[ $item ] = in_array( $item, $enabled_items, true );
		}

		update_option( $option_name, $states, 'no' );
	}

	/**
	 * Update tool states from grouped switch rows.
	 */
	private function update_tool_group_states(): void {
		$all_tools = $this->posted_array( 'all_tools' );
		if ( empty( $all_tools ) ) {
			return;
		}

		$enabled_groups = $this->posted_array( 'enabled_tool_groups' );
		$group_members  = $this->posted_group_members();
		$states         = array_fill_keys( array_unique( $all_tools ), false );

		foreach ( $group_members as $group_key => $members ) {
			if ( ! in_array( $group_key, $enabled_groups, true ) ) {
				continue;
			}

			foreach ( $members as $tool_name ) {
				if ( isset( $states[ $tool_name ] ) ) {
					$states[ $tool_name ] = true;
				}
			}
		}

		update_option( self::TOOL_STATES_OPTION, $states, 'no' );
	}

	/**
	 * Get sanitized grouped tool members from posted form data.
	 *
	 * @return array<string, string[]>
	 */
	private function posted_group_members(): array {
		if ( ! isset( $_POST['tool_group_members'] ) || ! is_array( $_POST['tool_group_members'] ) ) {
			return array();
		}

		$raw    = wp_unslash( $_POST['tool_group_members'] );
		$groups = array();

		foreach ( $raw as $group_key => $members ) {
			$group_key = sanitize_key( (string) $group_key );
			$members   = is_array( $members ) ? $members : array( $members );

			$groups[ $group_key ] = array_values(
				array_filter(
					array_map( 'sanitize_text_field', $members ),
					'strlen'
				)
			);
		}

		return $groups;
	}

	/**
	 * Get a sanitized posted array.
	 *
	 * @param string $key Posted key.
	 * @return array
	 */
	private function posted_array( string $key ): array {
		if ( ! isset( $_POST[ $key ] ) ) {
			return array();
		}

		$raw = wp_unslash( $_POST[ $key ] );
		$raw = is_array( $raw ) ? $raw : array( $raw );

		return array_values(
			array_filter(
				array_map( 'sanitize_text_field', $raw ),
				'strlen'
			)
		);
	}

	/**
	 * Checks if WordPress Feature API is available.
	 *
	 * @return bool True if WP Feature API is available, false otherwise.
	 */
	private function is_feature_api_available(): bool {
		return defined( 'WP_FEATURE_API_VERSION' );
	}

	/**
	 * Sanitize the settings before saving.
	 *
	 * @param array $input The input array.
	 * @return array The sanitized input array.
	 */
	public function sanitize_settings( array $input ): array {
		return array(
			'enabled'                    => ! empty( $input['enabled'] ),
			'features_adapter_enabled'   => ! empty( $input['features_adapter_enabled'] ),
			'enable_create_tools'        => ! empty( $input['enable_create_tools'] ),
			'enable_update_tools'        => ! empty( $input['enable_update_tools'] ),
			'enable_delete_tools'        => ! empty( $input['enable_delete_tools'] ),
			'enable_rest_api_crud_tools' => ! empty( $input['enable_rest_api_crud_tools'] ),
		);
	}

	/**
	 * Get saved MCP settings with defaults.
	 *
	 * @return array<string, bool>
	 */
	private function get_current_settings(): array {
		return wp_parse_args(
			get_option( self::OPTION_NAME, array() ),
			$this->sanitize_settings( array() )
		);
	}

	/**
	 * Get the initialized MCP instance when available.
	 *
	 * @param array $settings Current settings.
	 * @return WpMcp|null
	 */
	private function get_mcp_instance( array $settings ): ?WpMcp {
		if ( ! function_exists( 'WPMCP' ) ) {
			return null;
		}

		$mcp = \WPMCP();
		if ( ! $mcp instanceof WpMcp ) {
			return null;
		}

		if ( ! empty( $settings['enabled'] ) && method_exists( $mcp, 'wordpress_mcp_init' ) ) {
			$mcp->wordpress_mcp_init();
		}

		return $mcp;
	}

	/**
	 * Render the MCP connection page.
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		add_filter( 'gettext', array( $this, 'force_english_plugin_text' ), 1, 3 );

		$settings  = $this->get_current_settings();
		$mcp       = $this->get_mcp_instance( $settings );
		$tools     = $mcp instanceof WpMcp ? array_values( $mcp->get_all_tools() ) : array();
		$resources = $mcp instanceof WpMcp ? array_values( $mcp->get_all_resources() ) : array();
		$prompts   = $mcp instanceof WpMcp ? array_values( $mcp->get_all_prompts() ) : array();
		?>
		<div class="wrap wpmcp-admin">
			<?php $this->render_styles(); ?>
			<div class="wpmcp-hero">
				<div>
					<p class="wpmcp-eyebrow"><?php esc_html_e( 'Custom WordPress MCP build', 'wordpress-mcp' ); ?></p>
					<h1><?php esc_html_e( 'WordPress MCP Control Center', 'wordpress-mcp' ); ?></h1>
					<p><?php esc_html_e( 'Expose WordPress, WooCommerce, resources, and prompts to MCP clients with per-capability switches.', 'wordpress-mcp' ); ?></p>
				</div>
			</div>

			<div class="wpmcp-grid wpmcp-summary-grid">
				<?php $this->render_summary_card( __( 'Tools', 'wordpress-mcp' ), count( $tools ), $this->count_enabled( $tools ) ); ?>
				<?php $this->render_summary_card( __( 'Resources', 'wordpress-mcp' ), count( $resources ), $this->count_enabled( $resources ) ); ?>
				<?php $this->render_summary_card( __( 'Prompts', 'wordpress-mcp' ), count( $prompts ), $this->count_enabled( $prompts ) ); ?>
			</div>

			<section class="wpmcp-card">
				<div class="wpmcp-card-header"><h2><?php esc_html_e( 'Connection', 'wordpress-mcp' ); ?></h2></div>
				<div class="wpmcp-code-grid">
					<div>
						<strong><?php esc_html_e( 'STDIO proxy endpoint', 'wordpress-mcp' ); ?></strong>
						<code><?php echo esc_html( rest_url( 'wp/v2/wpmcp' ) ); ?></code>
					</div>
					<div>
						<strong><?php esc_html_e( 'Streamable HTTP endpoint', 'wordpress-mcp' ); ?></strong>
						<code><?php echo esc_html( rest_url( 'wp/v2/wpmcp/streamable' ) ); ?></code>
					</div>
					<div>
						<strong><?php esc_html_e( 'JWT token route', 'wordpress-mcp' ); ?></strong>
						<code><?php echo esc_html( rest_url( 'jwt-auth/v1/token' ) ); ?></code>
					</div>
				</div>
			</section>

			<?php $this->render_client_config_generator(); ?>
			<?php $this->render_scripts(); ?>
		</div>
		<?php
		remove_filter( 'gettext', array( $this, 'force_english_plugin_text' ), 1 );
	}

	/**
	 * Render the MCP abilities page.
	 */
	public function render_abilities_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		add_filter( 'gettext', array( $this, 'force_english_plugin_text' ), 1, 3 );

		$settings  = $this->get_current_settings();
		$mcp       = $this->get_mcp_instance( $settings );
		$tools     = $mcp instanceof WpMcp ? array_values( $mcp->get_all_tools() ) : array();
		$resources = $mcp instanceof WpMcp ? array_values( $mcp->get_all_resources() ) : array();
		$prompts   = $mcp instanceof WpMcp ? array_values( $mcp->get_all_prompts() ) : array();
		?>
		<div class="wrap wpmcp-admin">
			<?php $this->render_styles(); ?>
			<?php if ( isset( $_GET['wordpress_mcp_saved'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['wordpress_mcp_saved'] ) ) ) : ?>
				<div class="notice notice-success is-dismissible wpmcp-notice"><p><?php esc_html_e( 'MCP settings saved.', 'wordpress-mcp' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'wordpress_mcp_save_settings' ); ?>
				<input type="hidden" name="wordpress_mcp_action" value="save_settings" />

				<section class="wpmcp-card">
					<div class="wpmcp-card-header">
						<h2><?php esc_html_e( 'Global Settings', 'wordpress-mcp' ); ?></h2>
						<?php $this->render_status_badge( ! empty( $settings['enabled'] ) ); ?>
					</div>
					<div class="wpmcp-switch-list">
						<?php $this->render_switch( 'enabled', (bool) $settings['enabled'], __( 'Enable MCP server', 'wordpress-mcp' ), __( 'Turns the WordPress MCP endpoints on or off.', 'wordpress-mcp' ) ); ?>
						<?php $this->render_switch( 'enable_create_tools', (bool) $settings['enable_create_tools'], __( 'Enable create operations', 'wordpress-mcp' ), __( 'Allows create tools such as adding posts, pages, users, products, categories, and media.', 'wordpress-mcp' ) ); ?>
						<?php $this->render_switch( 'enable_update_tools', (bool) $settings['enable_update_tools'], __( 'Enable update operations', 'wordpress-mcp' ), __( 'Allows update tools for editable WordPress and WooCommerce records.', 'wordpress-mcp' ) ); ?>
						<?php $this->render_switch( 'enable_delete_tools', (bool) $settings['enable_delete_tools'], __( 'Enable delete operations', 'wordpress-mcp' ), __( 'Allows destructive delete tools. Keep this off unless you need it.', 'wordpress-mcp' ) ); ?>
						<?php $this->render_switch( 'enable_rest_api_crud_tools', (bool) $settings['enable_rest_api_crud_tools'], __( 'Enable experimental REST API CRUD tools', 'wordpress-mcp' ), __( 'Adds generic REST endpoint discovery and execution tools.', 'wordpress-mcp' ) ); ?>
					</div>
				</section>

				<?php $this->render_tools_table( $tools ); ?>
				<?php $this->render_resources_table( $resources ); ?>
				<?php $this->render_prompts_table( $prompts ); ?>

				<div class="wpmcp-actions">
					<?php submit_button( __( 'Save MCP Settings', 'wordpress-mcp' ), 'primary large', 'submit', false ); ?>
				</div>
			</form>
			<?php $this->render_scripts(); ?>
		</div>
		<?php
		remove_filter( 'gettext', array( $this, 'force_english_plugin_text' ), 1 );
	}

	/**
	 * Keep this custom admin page in English even when the site locale changes.
	 *
	 * @param string $translation Translated text.
	 * @param string $text Source text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	public function force_english_plugin_text( string $translation, string $text, string $domain ): string {
		return 'wordpress-mcp' === $domain ? $text : $translation;
	}

	/**
	 * Count enabled components.
	 *
	 * @param array $items Component list.
	 * @return int
	 */
	private function count_enabled( array $items ): int {
		$count = 0;
		foreach ( $items as $item ) {
			if ( ! isset( $item['enabled'] ) || $item['enabled'] ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Render a summary card.
	 *
	 * @param string $label Card label.
	 * @param int    $total Total count.
	 * @param int    $enabled Enabled count.
	 */
	private function render_summary_card( string $label, int $total, int $enabled ): void {
		?>
		<div class="wpmcp-summary-card">
			<span><?php echo esc_html( $label ); ?></span>
			<strong><?php echo esc_html( (string) $enabled ); ?></strong>
			<small><?php echo esc_html( sprintf( __( '%d total', 'wordpress-mcp' ), $total ) ); ?></small>
		</div>
		<?php
	}

	/**
	 * Render a settings switch.
	 *
	 * @param string $name Field name.
	 * @param bool   $checked Checked state.
	 * @param string $title Title text.
	 * @param string $description Description text.
	 * @param bool   $disabled Disabled state.
	 */
	private function render_switch( string $name, bool $checked, string $title, string $description, bool $disabled = false ): void {
		?>
		<div class="wpmcp-switch-row<?php echo $disabled ? ' is-disabled' : ''; ?>">
			<div>
				<strong><?php echo esc_html( $title ); ?></strong>
				<p><?php echo esc_html( $description ); ?></p>
			</div>
			<label class="wpmcp-switch">
				<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $checked ); ?> <?php disabled( $disabled ); ?> />
				<span></span>
			</label>
		</div>
		<?php
	}

	/**
	 * Render a bulk toggle row for a switcher section.
	 *
	 * @param string $id Input ID.
	 * @param string $title Title text.
	 * @param string $description Description text.
	 * @param string $target_name Target checkbox name.
	 * @param bool   $checked Checked state.
	 */
	private function render_bulk_toggle( string $id, string $title, string $description, string $target_name, bool $checked ): void {
		?>
		<div class="wpmcp-bulk-row">
			<div>
				<strong><?php echo esc_html( $title ); ?></strong>
				<p><?php echo esc_html( $description ); ?></p>
			</div>
			<label class="wpmcp-switch">
				<input id="<?php echo esc_attr( $id ); ?>" type="checkbox" data-wpmcp-bulk-target="<?php echo esc_attr( $target_name ); ?>" <?php checked( $checked ); ?> />
				<span></span>
			</label>
		</div>
		<?php
	}

	/**
	 * Check if every item is enabled.
	 *
	 * @param array $items Items.
	 * @return bool
	 */
	private function are_all_items_enabled( array $items ): bool {
		if ( empty( $items ) ) {
			return false;
		}

		foreach ( $items as $item ) {
			if ( isset( $item['enabled'] ) && ! $item['enabled'] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Render tools table.
	 *
	 * @param array $tools Tools.
	 */
	private function render_tools_table( array $tools ): void {
		$tool_groups = $this->group_tools( $tools );
		?>
		<section class="wpmcp-card">
			<div class="wpmcp-card-header"><h2><?php esc_html_e( 'Tool Switchers', 'wordpress-mcp' ); ?></h2></div>
			<?php if ( empty( $tool_groups ) ) : ?>
				<p class="wpmcp-empty"><?php esc_html_e( 'No tools are loaded yet. Enable MCP, save, then reload this page to populate tools.', 'wordpress-mcp' ); ?></p>
			<?php else : ?>
				<table class="widefat striped wpmcp-table wpmcp-tools-table">
					<thead><tr><th><?php esc_html_e( 'Tool Group', 'wordpress-mcp' ); ?></th><th><?php esc_html_e( 'Tools', 'wordpress-mcp' ); ?></th><th>Status</th><th><?php esc_html_e( 'Description', 'wordpress-mcp' ); ?></th><th><?php esc_html_e( 'Enabled', 'wordpress-mcp' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $tool_groups as $group ) : ?>
							<?php
							$group_key   = $group['key'];
							$enabled     = $group['enabled_count'] > 0;
							$tool_names  = array_map(
								static fn( $tool ) => (string) ( $tool['name'] ?? '' ),
								$group['tools']
							);
							$tools_count = count( $tool_names );
							?>
							<tr>
								<td><strong><?php echo esc_html( $group['label'] ); ?></strong></td>
								<td><?php echo esc_html( sprintf( _n( '%d tool', '%d tools', $tools_count, 'wordpress-mcp' ), $tools_count ) ); ?></td>
								<td><?php $this->render_tool_group_status_badge( $group ); ?></td>
								<td><?php echo esc_html( $group['description'] ); ?></td>
								<td class="wpmcp-toggle-cell">
									<?php foreach ( $tool_names as $tool_name ) : ?>
										<input type="hidden" name="all_tools[]" value="<?php echo esc_attr( $tool_name ); ?>" />
										<input type="hidden" name="tool_group_members[<?php echo esc_attr( $group_key ); ?>][]" value="<?php echo esc_attr( $tool_name ); ?>" />
									<?php endforeach; ?>
									<label class="wpmcp-switch wpmcp-small-switch">
										<input type="checkbox" name="enabled_tool_groups[]" value="<?php echo esc_attr( $group_key ); ?>" <?php checked( $enabled ); ?> />
										<span></span>
									</label>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Check if every tool group is enabled.
	 *
	 * @param array $tool_groups Tool groups.
	 * @return bool
	 */
	private function are_all_tool_groups_enabled( array $tool_groups ): bool {
		if ( empty( $tool_groups ) ) {
			return false;
		}

		foreach ( $tool_groups as $group ) {
			if ( empty( $group['enabled_count'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Group tools into short, readable rows for the settings UI.
	 *
	 * @param array $tools Tools.
	 * @return array
	 */
	private function group_tools( array $tools ): array {
		$groups = array();

		foreach ( $tools as $tool ) {
			$name      = (string) ( $tool['name'] ?? '' );
			$group_key = $this->get_tool_group_key( $name );

			if ( ! isset( $groups[ $group_key ] ) ) {
				$groups[ $group_key ] = array(
					'key'             => $group_key,
					'label'           => $this->get_tool_group_label( $group_key ),
					'description'     => $this->get_tool_group_description( $group_key ),
					'tools'           => array(),
					'enabled_count'   => 0,
					'effective_count' => 0,
					'blocked_count'   => 0,
				);
			}

			$enabled       = ! isset( $tool['enabled'] ) || $tool['enabled'];
			$type_enabled  = ! isset( $tool['tool_type_enabled'] ) || $tool['tool_type_enabled'];
			$rest_disabled = ! empty( $tool['disabled_by_rest_crud'] );
			$effective     = $enabled && $type_enabled && ! $rest_disabled;

			$groups[ $group_key ]['tools'][] = $tool;

			if ( $enabled ) {
				++$groups[ $group_key ]['enabled_count'];
			}

			if ( $effective ) {
				++$groups[ $group_key ]['effective_count'];
			} elseif ( $enabled ) {
				++$groups[ $group_key ]['blocked_count'];
			}
		}

		$preferred_order = array(
			'wordpress-content',
			'pages',
			'media',
			'users',
			'woocommerce',
			'settings',
			'custom-post-types',
			'site-information',
			'translation-know-how',
			'file-development',
			'rest-api-crud',
			'other-tools',
		);

		uasort(
			$groups,
			static function ( array $a, array $b ) use ( $preferred_order ): int {
				$a_index = array_search( $a['key'], $preferred_order, true );
				$b_index = array_search( $b['key'], $preferred_order, true );

				$a_index = false === $a_index ? 999 : $a_index;
				$b_index = false === $b_index ? 999 : $b_index;

				return $a_index <=> $b_index;
			}
		);

		return $groups;
	}

	/**
	 * Get the tool group key for a tool name.
	 *
	 * @param string $tool_name Tool name.
	 * @return string
	 */
	private function get_tool_group_key( string $tool_name ): string {
		if ( str_starts_with( $tool_name, 'wc_' ) ) {
			return 'woocommerce';
		}

		if ( in_array( $tool_name, array( 'wp_pages_search', 'wp_get_page', 'wp_add_page', 'wp_update_page', 'wp_delete_page' ), true ) ) {
			return 'pages';
		}

		if ( str_contains( $tool_name, 'media' ) ) {
			return 'media';
		}

		if ( str_contains( $tool_name, 'user' ) ) {
			return 'users';
		}

		if ( str_contains( $tool_name, 'setting' ) ) {
			return 'settings';
		}

		if ( str_contains( $tool_name, 'cpt' ) || str_contains( $tool_name, 'post_type' ) ) {
			return 'custom-post-types';
		}

		if ( in_array( $tool_name, array( 'get_site_info' ), true ) ) {
			return 'site-information';
		}

		if ( str_starts_with( $tool_name, 'wpml_' ) || str_starts_with( $tool_name, 'woodmart_' ) || str_starts_with( $tool_name, 'wc_wpml_' ) || str_starts_with( $tool_name, 'frontend_' ) ) {
			return 'translation-know-how';
		}

		if ( str_starts_with( $tool_name, 'wp_file_dev_' ) ) {
			return 'file-development';
		}

		if ( in_array( $tool_name, array( 'list_api_functions', 'get_function_details', 'run_api_function' ), true ) ) {
			return 'rest-api-crud';
		}

		if ( str_contains( $tool_name, 'post' ) || str_contains( $tool_name, 'categor' ) || str_contains( $tool_name, 'tag' ) ) {
			return 'wordpress-content';
		}

		return 'other-tools';
	}

	/**
	 * Get a human-readable tool group label.
	 *
	 * @param string $group_key Group key.
	 * @return string
	 */
	private function get_tool_group_label( string $group_key ): string {
		$labels = array(
			'wordpress-content' => __( 'WordPress Content', 'wordpress-mcp' ),
			'pages'             => __( 'Pages', 'wordpress-mcp' ),
			'media'             => __( 'Media Library', 'wordpress-mcp' ),
			'users'             => __( 'Users', 'wordpress-mcp' ),
			'woocommerce'       => __( 'WooCommerce', 'wordpress-mcp' ),
			'settings'          => __( 'Site Settings', 'wordpress-mcp' ),
			'custom-post-types' => __( 'Custom Post Types', 'wordpress-mcp' ),
			'site-information'  => __( 'Site Information', 'wordpress-mcp' ),
			'translation-know-how' => __( 'Translation Know-How', 'wordpress-mcp' ),
			'file-development'  => __( 'File Development', 'wordpress-mcp' ),
			'rest-api-crud'     => __( 'REST API CRUD', 'wordpress-mcp' ),
			'other-tools'       => __( 'Other Tools', 'wordpress-mcp' ),
		);

		return $labels[ $group_key ] ?? $labels['other-tools'];
	}

	/**
	 * Get a human-readable tool group description.
	 *
	 * @param string $group_key Group key.
	 * @return string
	 */
	private function get_tool_group_description( string $group_key ): string {
		$descriptions = array(
			'wordpress-content' => __( 'Posts, categories, tags, and related create, update, delete, and search operations.', 'wordpress-mcp' ),
			'pages'             => __( 'Page search, read, create, update, and delete operations.', 'wordpress-mcp' ),
			'media'             => __( 'Media library search, file access, upload, update, and delete operations.', 'wordpress-mcp' ),
			'users'             => __( 'User search, current user data, and user create, update, and delete operations.', 'wordpress-mcp' ),
			'woocommerce'       => __( 'All WooCommerce tools together: products, orders, reports, categories, tags, brands, and catalog management.', 'wordpress-mcp' ),
			'settings'          => __( 'Read and update general WordPress site settings.', 'wordpress-mcp' ),
			'custom-post-types' => __( 'Custom post type discovery, search, read, create, update, and delete operations.', 'wordpress-mcp' ),
			'site-information'  => __( 'General WordPress site information for MCP clients.', 'wordpress-mcp' ),
			'translation-know-how' => __( 'Reusable WPML, WooCommerce, Elementor, menu, checkout, and translation workflow guidance.', 'wordpress-mcp' ),
			'file-development'  => __( 'Constrained plugin and theme file listing, reading, and writing for development.', 'wordpress-mcp' ),
			'rest-api-crud'     => __( 'Experimental generic REST API endpoint discovery, inspection, and execution tools.', 'wordpress-mcp' ),
			'other-tools'       => __( 'Additional MCP tools that do not fit another category.', 'wordpress-mcp' ),
		);

		return $descriptions[ $group_key ] ?? $descriptions['other-tools'];
	}

	/**
	 * Render a grouped tool status badge.
	 *
	 * @param array $group Tool group.
	 */
	private function render_tool_group_status_badge( array $group ): void {
		$total     = count( $group['tools'] );
		$enabled   = (int) $group['enabled_count'];
		$effective = (int) $group['effective_count'];
		$blocked   = (int) $group['blocked_count'];

		if ( 0 === $enabled ) {
			$class = 'is-off';
			$label = __( 'Off', 'wordpress-mcp' );
		} elseif ( $effective === $total ) {
			$class = 'is-on';
			$label = __( 'On', 'wordpress-mcp' );
		} elseif ( $effective > 0 && $blocked > 0 ) {
			$class = 'is-warn';
			$label = __( 'Partially blocked', 'wordpress-mcp' );
		} else {
			$class = 'is-off';
			$label = __( 'Blocked by global switch', 'wordpress-mcp' );
		}
		?>
		<span class="wpmcp-badge <?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></span>
		<?php
	}

	/**
	 * Render resources table.
	 *
	 * @param array $resources Resources.
	 */
	private function render_resources_table( array $resources ): void {
		?>
		<section class="wpmcp-card">
			<div class="wpmcp-card-header"><h2><?php esc_html_e( 'Resource Switchers', 'wordpress-mcp' ); ?></h2></div>
			<?php if ( empty( $resources ) ) : ?>
				<p class="wpmcp-empty"><?php esc_html_e( 'No resources are loaded yet. Enable MCP, save, then reload this page to populate resources.', 'wordpress-mcp' ); ?></p>
			<?php else : ?>
				<table class="widefat striped wpmcp-table">
					<thead><tr><th><?php esc_html_e( 'Resource', 'wordpress-mcp' ); ?></th><th><?php esc_html_e( 'URI', 'wordpress-mcp' ); ?></th><th><?php esc_html_e( 'MIME Type', 'wordpress-mcp' ); ?></th><th><?php esc_html_e( 'Description', 'wordpress-mcp' ); ?></th><th><?php esc_html_e( 'Enabled', 'wordpress-mcp' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $resources as $resource ) : ?>
							<?php
							$uri     = (string) ( $resource['uri'] ?? '' );
							$enabled = ! isset( $resource['enabled'] ) || $resource['enabled'];
							?>
							<tr>
								<td><strong><?php echo esc_html( (string) ( $resource['name'] ?? '' ) ); ?></strong></td>
								<td><code><?php echo esc_html( $uri ); ?></code></td>
								<td><?php echo esc_html( (string) ( $resource['mimeType'] ?? '-' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $resource['description'] ?? '' ) ); ?></td>
								<td class="wpmcp-toggle-cell">
									<input type="hidden" name="all_resources[]" value="<?php echo esc_attr( $uri ); ?>" />
									<label class="wpmcp-switch wpmcp-small-switch">
										<input type="checkbox" name="enabled_resources[]" value="<?php echo esc_attr( $uri ); ?>" <?php checked( $enabled ); ?> />
										<span></span>
									</label>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Render prompts table.
	 *
	 * @param array $prompts Prompts.
	 */
	private function render_prompts_table( array $prompts ): void {
		?>
		<section class="wpmcp-card">
			<div class="wpmcp-card-header"><h2><?php esc_html_e( 'Prompt Switchers', 'wordpress-mcp' ); ?></h2></div>
			<?php if ( empty( $prompts ) ) : ?>
				<p class="wpmcp-empty"><?php esc_html_e( 'No prompts are loaded yet. Enable MCP, save, then reload this page to populate prompts.', 'wordpress-mcp' ); ?></p>
			<?php else : ?>
				<table class="widefat striped wpmcp-table">
					<thead><tr><th><?php esc_html_e( 'Prompt', 'wordpress-mcp' ); ?></th><th><?php esc_html_e( 'Arguments', 'wordpress-mcp' ); ?></th><th><?php esc_html_e( 'Description', 'wordpress-mcp' ); ?></th><th><?php esc_html_e( 'Enabled', 'wordpress-mcp' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $prompts as $prompt ) : ?>
							<?php
							$name     = (string) ( $prompt['name'] ?? '' );
							$enabled  = ! isset( $prompt['enabled'] ) || $prompt['enabled'];
							$args     = $prompt['arguments'] ?? array();
							$arg_text = is_array( $args ) ? implode( ', ', array_filter( array_map( static fn( $arg ) => is_array( $arg ) ? ( $arg['name'] ?? '' ) : '', $args ) ) ) : '';
							?>
							<tr>
								<td><strong><?php echo esc_html( $name ); ?></strong></td>
								<td><?php echo esc_html( $arg_text ?: '-' ); ?></td>
								<td><?php echo esc_html( (string) ( $prompt['description'] ?? '' ) ); ?></td>
								<td class="wpmcp-toggle-cell">
									<input type="hidden" name="all_prompts[]" value="<?php echo esc_attr( $name ); ?>" />
									<label class="wpmcp-switch wpmcp-small-switch">
										<input type="checkbox" name="enabled_prompts[]" value="<?php echo esc_attr( $name ); ?>" <?php checked( $enabled ); ?> />
										<span></span>
									</label>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Render copy-ready MCP client configuration snippets.
	 */
	private function render_client_config_generator(): void {
		$current_user = wp_get_current_user();
		$username     = $current_user && ! empty( $current_user->user_login ) ? $current_user->user_login : '';
		$server_name  = $this->get_mcp_server_name();
		$mcp_url      = rest_url( 'wp/v2/wpmcp' );
		$password     = '{{GENERATE_APPLICATION_PASSWORD}}';
		$configs      = $this->get_client_config_snippets( $server_name, $mcp_url, $username, $password );
		$profile_url  = get_edit_profile_url( get_current_user_id() ) . '#application-passwords-section';
		?>
		<section class="wpmcp-card wpmcp-client-config-card">
			<div class="wpmcp-card-header">
				<div>
					<h2><?php esc_html_e( 'MCP Client Config Generator', 'wordpress-mcp' ); ?></h2>
					<p><?php esc_html_e( 'Generate an application password once, then copy the matching config into your AI client.', 'wordpress-mcp' ); ?></p>
				</div>
			</div>
			<div class="wpmcp-client-config-body">
				<div class="wpmcp-client-config-controls">
					<div>
						<strong><?php esc_html_e( 'Current admin username', 'wordpress-mcp' ); ?></strong>
						<code><?php echo esc_html( $username ); ?></code>
					</div>
					<div>
						<strong><?php esc_html_e( 'MCP server name', 'wordpress-mcp' ); ?></strong>
						<code><?php echo esc_html( $server_name ); ?></code>
					</div>
					<button type="button" class="button button-primary wpmcp-primary-action" id="wpmcp-generate-application-password" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wordpress_mcp_generate_application_password' ) ); ?>">
						<?php esc_html_e( 'Generate & Fill Password', 'wordpress-mcp' ); ?>
					</button>
				</div>
				<div class="wpmcp-secret-notice">
					<strong><?php esc_html_e( 'Security note:', 'wordpress-mcp' ); ?></strong>
					<?php esc_html_e( 'WordPress shows application passwords only once. Treat generated configs like secrets and do not commit them to Git.', 'wordpress-mcp' ); ?>
					<a href="<?php echo esc_url( $profile_url ); ?>"><?php esc_html_e( 'Open profile application passwords', 'wordpress-mcp' ); ?></a>
				</div>
				<div id="wpmcp-application-password-result" class="wpmcp-password-result" hidden></div>
				<div class="wpmcp-config-snippets">
					<?php foreach ( $configs as $config ) : ?>
						<?php $this->render_client_config_snippet( $config ); ?>
					<?php endforeach; ?>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * Build a safe MCP server key from the site name.
	 *
	 * @return string
	 */
	private function get_mcp_server_name(): string {
		$site_name = trim( wp_strip_all_tags( (string) get_bloginfo( 'name' ) ) );

		if ( '' === $site_name ) {
			$host      = wp_parse_url( home_url(), PHP_URL_HOST );
			$site_name = is_string( $host ) && '' !== $host ? $host : 'WordPress MCP';
		}

		$server_name = preg_replace( '/[^A-Za-z0-9]+/', '_', $site_name );
		$server_name = is_string( $server_name ) ? trim( $server_name, '_' ) : '';

		if ( '' === $server_name ) {
			$server_name = 'WordPress_MCP';
		}

		if ( preg_match( '/^[0-9]/', $server_name ) ) {
			$server_name = 'WP_' . $server_name;
		}

		return $server_name;
	}

	/**
	 * Get generated client configuration snippets.
	 *
	 * @param string $server_name Server name.
	 * @param string $mcp_url MCP endpoint URL.
	 * @param string $username Current user login.
	 * @param string $password Application password or placeholder.
	 * @return array<int, array<string, string>>
	 */
	private function get_client_config_snippets( string $server_name, string $mcp_url, string $username, string $password ): array {
		$package = '@automattic/mcp-wordpress-remote@latest';
		$env     = array(
			'WP_API_URL'      => $mcp_url,
			'WP_API_USERNAME' => $username,
			'OAUTH_ENABLED'   => 'false',
			'WP_API_PASSWORD' => $password,
		);

		$opencode = array(
			'$schema' => 'https://opencode.ai/config.json',
			'mcp'     => array(
				$server_name => array(
					'type'        => 'local',
					'command'     => array( 'npx', '-y', $package ),
					'environment' => $env,
					'enabled'     => true,
					'timeout'     => 30000,
				),
			),
		);

		$claude_json = array(
			'mcpServers' => array(
				$server_name => array(
					'type'    => 'stdio',
					'command' => 'npx',
					'args'    => array( '-y', $package ),
					'env'     => $env,
				),
			),
		);

		$generic_json = array(
			'mcpServers' => array(
				$server_name => array(
					'command' => 'npx',
					'args'    => array( '-y', $package ),
					'env'     => $env,
				),
			),
		);

		$codex_toml = sprintf(
			"[mcp_servers.%s]\ncommand = \"npx\"\nargs = [\"-y\", \"%s\"]\nstartup_timeout_sec = 30\ntool_timeout_sec = 60\nenabled = true\n\n[mcp_servers.%s.env]\nWP_API_URL = \"%s\"\nWP_API_USERNAME = \"%s\"\nOAUTH_ENABLED = \"false\"\nWP_API_PASSWORD = \"%s\"",
			$this->toml_bare_key( $server_name ),
			$this->toml_escape( $package ),
			$this->toml_bare_key( $server_name ),
			$this->toml_escape( $mcp_url ),
			$this->toml_escape( $username ),
			$this->toml_escape( $password )
		);

		$claude_cli = sprintf(
			'claude mcp add --transport stdio %s --env WP_API_URL=%s --env WP_API_USERNAME=%s --env OAUTH_ENABLED=false --env WP_API_PASSWORD=%s -- npx -y %s',
			$this->shell_arg( $server_name ),
			$this->shell_arg( $mcp_url ),
			$this->shell_arg( $username ),
			$this->shell_arg( $password ),
			$this->shell_arg( $package )
		);

		return array(
			array(
				'title'       => __( 'OpenCode opencode.jsonc', 'wordpress-mcp' ),
				'description' => __( 'Paste this into opencode.jsonc or opencode.json.', 'wordpress-mcp' ),
				'filename'    => 'opencode.jsonc',
				'logo'        => 'OC',
				'logo_class'  => 'is-opencode',
				'content'     => $this->json_encode_pretty( $opencode ),
			),
			array(
				'title'       => __( 'Codex config.toml', 'wordpress-mcp' ),
				'description' => __( 'Paste this into ~/.codex/config.toml or a trusted project .codex/config.toml.', 'wordpress-mcp' ),
				'filename'    => 'config.toml',
				'logo'        => 'CX',
				'logo_class'  => 'is-codex',
				'content'     => $codex_toml,
			),
			array(
				'title'       => __( 'Claude Code command', 'wordpress-mcp' ),
				'description' => __( 'Run this command in your terminal to add the MCP server to Claude Code.', 'wordpress-mcp' ),
				'filename'    => 'terminal',
				'logo'        => 'CC',
				'logo_class'  => 'is-claude',
				'content'     => $claude_cli,
			),
			array(
				'title'       => __( 'Claude Code .mcp.json', 'wordpress-mcp' ),
				'description' => __( 'Use this when you prefer JSON configuration instead of the CLI command.', 'wordpress-mcp' ),
				'filename'    => '.mcp.json',
				'logo'        => 'CC',
				'logo_class'  => 'is-claude',
				'content'     => $this->json_encode_pretty( $claude_json ),
			),
			array(
				'title'       => __( 'GLM MCP JSON', 'wordpress-mcp' ),
				'description' => __( 'Generic stdio MCP JSON for GLM clients that accept mcpServers configuration.', 'wordpress-mcp' ),
				'filename'    => 'glm-mcp.json',
				'logo'        => 'GL',
				'logo_class'  => 'is-glm',
				'content'     => $this->json_encode_pretty( $generic_json ),
			),
			array(
				'title'       => __( 'MiniMax MCP JSON', 'wordpress-mcp' ),
				'description' => __( 'Generic stdio MCP JSON for MiniMax clients that accept mcpServers configuration.', 'wordpress-mcp' ),
				'filename'    => 'minimax-mcp.json',
				'logo'        => 'MM',
				'logo_class'  => 'is-minimax',
				'content'     => $this->json_encode_pretty( $generic_json ),
			),
		);
	}

	/**
	 * Render one client config snippet.
	 *
	 * @param array<string, string> $config Config data.
	 */
	private function render_client_config_snippet( array $config ): void {
		$content    = (string) ( $config['content'] ?? '' );
		$filename   = (string) ( $config['filename'] ?? __( 'config', 'wordpress-mcp' ) );
		$logo       = (string) ( $config['logo'] ?? '' );
		$logo_class = sanitize_html_class( (string) ( $config['logo_class'] ?? '' ) );
		?>
		<div class="wpmcp-config-snippet is-collapsed">
			<div class="wpmcp-config-snippet-header">
				<div class="wpmcp-config-title-row">
					<span class="wpmcp-agent-logo <?php echo esc_attr( $logo_class ); ?>" aria-hidden="true"><?php echo esc_html( $logo ); ?></span>
					<div>
						<strong><?php echo esc_html( (string) ( $config['title'] ?? '' ) ); ?></strong>
						<p><?php echo esc_html( (string) ( $config['description'] ?? '' ) ); ?></p>
					</div>
				</div>
				<div class="wpmcp-config-actions">
					<button type="button" class="button wpmcp-toggle-config" aria-expanded="false"><?php esc_html_e( 'Expand', 'wordpress-mcp' ); ?></button>
					<button type="button" class="button wpmcp-copy-config"><?php esc_html_e( 'Copy', 'wordpress-mcp' ); ?></button>
				</div>
			</div>
			<div class="wpmcp-config-panel">
				<div class="wpmcp-editor-chrome">
					<span></span><span></span><span></span>
					<strong><?php echo esc_html( $filename ); ?></strong>
				</div>
				<textarea class="wpmcp-config-raw" hidden><?php echo esc_textarea( $content ); ?></textarea>
				<pre class="wpmcp-config-output" aria-label="<?php echo esc_attr( $filename ); ?>"><code class="wpmcp-config-code"><?php echo esc_html( $content ); ?></code></pre>
			</div>
		</div>
		<?php
	}

	/**
	 * Encode JSON for display.
	 *
	 * @param array $data Data to encode.
	 * @return string
	 */
	private function json_encode_pretty( array $data ): string {
		$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		return is_string( $json ) ? $json : '';
	}

	/**
	 * Escape a TOML string value.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function toml_escape( string $value ): string {
		return str_replace(
			array( '\\', '"', "\n", "\r", "\t" ),
			array( '\\\\', '\\"', '\\n', '\\r', '\\t' ),
			$value
		);
	}

	/**
	 * Return a TOML-safe bare key, or a quoted key when needed.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private function toml_bare_key( string $key ): string {
		return preg_match( '/^[A-Za-z0-9_-]+$/', $key ) ? $key : '"' . $this->toml_escape( $key ) . '"';
	}

	/**
	 * Quote a shell argument for generated one-line commands.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function shell_arg( string $value ): string {
		return '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $value ) . '"';
	}

	/**
	 * Render status badge.
	 *
	 * @param bool        $enabled Enabled state.
	 * @param string|null $label Optional label.
	 */
	private function render_status_badge( bool $enabled, ?string $label = null ): void {
		$label = $label ?: ( $enabled ? __( 'Enabled', 'wordpress-mcp' ) : __( 'Disabled', 'wordpress-mcp' ) );
		?>
		<span class="wpmcp-badge <?php echo $enabled ? 'is-on' : 'is-off'; ?>"><?php echo esc_html( $label ); ?></span>
		<?php
	}

	/**
	 * Get readable effective status for a tool.
	 *
	 * @param bool $enabled Individual enabled state.
	 * @param bool $type_enabled Tool type enabled state.
	 * @param bool $rest_disabled Disabled because REST CRUD is enabled.
	 * @return string
	 */
	private function get_tool_status_label( bool $enabled, bool $type_enabled, bool $rest_disabled ): string {
		if ( ! $enabled ) {
			return __( 'Off', 'wordpress-mcp' );
		}

		if ( ! $type_enabled ) {
			return __( 'Blocked by global type switch', 'wordpress-mcp' );
		}

		if ( $rest_disabled ) {
			return __( 'Replaced by REST CRUD tools', 'wordpress-mcp' );
		}

		return __( 'On', 'wordpress-mcp' );
	}

	/**
	 * Add settings link to plugin actions.
	 *
	 * @param array $actions An array of plugin action links.
	 * @return array
	 */
	public function plugin_action_links( array $actions ): array {
		$settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=wordpress-mcp-abilities' ) ) . '">' . esc_html__( 'MCP Abilities', 'wordpress-mcp' ) . '</a>';
		array_unshift( $actions, $settings_link );
		return $actions;
	}

	/**
	 * Render page styles.
	 */
	private function render_styles(): void {
		?>
		<style>
			@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap');
			.wpmcp-admin { max-width: 1280px; }
			.wpmcp-admin, .wpmcp-admin button, .wpmcp-admin input, .wpmcp-admin select, .wpmcp-admin textarea { font-family: Poppins, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
			.wpmcp-admin code, .wpmcp-config-output, .wpmcp-editor-chrome strong, .wpmcp-config-line-code, .wpmcp-config-raw { font-family: Consolas, Monaco, "Courier New", monospace; }
			.wpmcp-hero { align-items: center; background: linear-gradient(135deg, #111827, #2563eb); border-radius: 16px; color: #fff; display: flex; justify-content: space-between; margin: 18px 0; padding: 28px 32px; }
			.wpmcp-hero h1 { color: #fff; font-size: 34px; line-height: 1.1; margin: 4px 0 8px; }
			.wpmcp-hero p { color: rgba(255,255,255,.86); font-size: 15px; margin: 0; max-width: 760px; }
			.wpmcp-eyebrow { color: #bfdbfe !important; font-size: 12px !important; font-weight: 700; letter-spacing: .08em; margin: 0 !important; text-transform: uppercase; }
			.wpmcp-notice.notice { background: #ecfdf5; border: 1px solid #bbf7d0; border-left: 5px solid #22c55e; border-radius: 10px; box-shadow: 0 10px 24px rgba(15,23,42,.08); margin: -4px 0 18px; padding: 2px 44px 2px 14px; }
			.wpmcp-notice.notice p { color: #14532d !important; font-size: 14px; font-weight: 700; margin: 10px 0; }
			.wpmcp-notice.notice .notice-dismiss:before { color: #14532d; }
			.wpmcp-notice.notice .notice-dismiss:hover:before { color: #052e16; }
			.wpmcp-grid { display: grid; gap: 16px; }
			.wpmcp-summary-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); margin-bottom: 16px; }
			.wpmcp-summary-card, .wpmcp-card { background: #fff; border: 1px solid #dcdcde; border-radius: 14px; box-shadow: 0 10px 24px rgba(15,23,42,.06); }
			.wpmcp-summary-card { padding: 20px 24px; }
			.wpmcp-summary-card span { color: #64748b; display: block; font-weight: 700; text-transform: uppercase; }
			.wpmcp-summary-card strong { color: #0f172a; display: block; font-size: 34px; line-height: 1; margin: 10px 0 4px; }
			.wpmcp-summary-card small { color: #64748b; }
			.wpmcp-card { margin: 18px 0; padding: 0; overflow: hidden; }
			.wpmcp-card-header { align-items: center; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; padding: 20px 24px; }
			.wpmcp-card-header h2 { margin: 0; }
			.wpmcp-switch-list { padding: 6px 24px 16px; }
			.wpmcp-switch-row { align-items: center; border-bottom: 1px solid #eef2f7; display: flex; justify-content: space-between; gap: 18px; padding: 18px 0; }
			.wpmcp-switch-row:last-child { border-bottom: 0; }
			.wpmcp-switch-row p { color: #64748b; margin: 4px 0 0; }
			.wpmcp-switch-row.is-disabled { opacity: .55; }
			.wpmcp-bulk-row { align-items: center; background: #f8fafc; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; gap: 18px; padding: 16px 20px; }
			.wpmcp-bulk-row p { color: #64748b; margin: 4px 0 0; }
			.wpmcp-switch { display: inline-block; height: 30px; min-width: 54px; position: relative; }
			.wpmcp-switch input { appearance: none !important; border: 0 !important; box-shadow: none !important; clip: rect(0 0 0 0); clip-path: inset(50%); height: 1px; margin: 0; opacity: 0; overflow: hidden; padding: 0; position: absolute; width: 1px; }
			.wpmcp-switch input:focus, .wpmcp-switch input:focus-visible { outline: 0; box-shadow: none !important; }
			.wpmcp-switch input:focus + span { box-shadow: 0 0 0 3px rgba(37,99,235,.22); }
			.wpmcp-switch span { background: #cbd5e1; border-radius: 999px; cursor: pointer; inset: 0; position: absolute; transition: .18s ease; }
			.wpmcp-switch span:before { background: #fff; border-radius: 50%; box-shadow: 0 1px 4px rgba(0,0,0,.25); content: ""; height: 24px; left: 3px; position: absolute; top: 3px; transition: .18s ease; width: 24px; }
			.wpmcp-switch input:checked + span { background: #2563eb; }
			.wpmcp-switch input:checked + span:before { transform: translateX(24px); }
			.wpmcp-small-switch { height: 24px; min-width: 44px; }
			.wpmcp-small-switch span:before { height: 18px; width: 18px; }
			.wpmcp-small-switch input:checked + span:before { transform: translateX(20px); }
			.wpmcp-table { background: #fff; border: 0; border-collapse: separate; border-spacing: 0; }
			.wpmcp-table.widefat { border: 0 !important; box-shadow: none !important; }
			.wpmcp-table thead { box-shadow: inset 0 -1px 0 #e5e7eb; }
			.wpmcp-table th { background: #fff; border: 0 !important; color: #0f172a; font-size: 13px; font-weight: 700; letter-spacing: 0; text-transform: none; }
			.wpmcp-table tbody tr, .wpmcp-table.striped > tbody > :nth-child(odd), .wpmcp-table.striped > tbody > :nth-child(even) { background: #fff; }
			.wpmcp-table td { border-top: 1px solid #e5e7eb !important; }
			.wpmcp-table tbody tr:first-child td { border-top: 0 !important; }
			.wpmcp-table td, .wpmcp-table th { padding: 18px 24px; vertical-align: middle; }
			.wpmcp-table th:first-child, .wpmcp-table td:first-child { white-space: nowrap; }
			.wpmcp-table th:last-child, .wpmcp-table td:last-child { text-align: right; width: 100px; }
			.wpmcp-tools-table th:nth-child(2), .wpmcp-tools-table td:nth-child(2) { min-width: 110px; width: 110px; }
			.wpmcp-tools-table th:nth-child(3), .wpmcp-tools-table td:nth-child(3) { min-width: 220px; width: 220px; }
			.wpmcp-toggle-cell .wpmcp-switch { margin-left: auto; }
			.wpmcp-table code, .wpmcp-code-grid code { background: #f1f5f9; border-radius: 8px; display: inline-block; padding: 6px 8px; }
			.wpmcp-table code { white-space: nowrap; }
			.wpmcp-code-grid code { overflow-wrap: anywhere; white-space: normal; word-break: break-word; }
			.wpmcp-badge { border-radius: 999px; display: inline-block; font-size: 12px; font-weight: 700; padding: 5px 10px; }
			.wpmcp-tools-table .wpmcp-badge { white-space: nowrap; }
			.wpmcp-badge.is-on { background: #dcfce7; color: #166534; }
			.wpmcp-badge.is-warn { background: #fef3c7; color: #92400e; }
			.wpmcp-badge.is-off { background: #fee2e2; color: #991b1b; }
			.wpmcp-empty { color: #64748b; font-size: 15px; margin: 0; padding: 20px 24px; }
			.wpmcp-actions { background: transparent; border: 0; bottom: 18px; box-shadow: none; display: flex; justify-content: flex-start; margin: 18px 0; padding: 0; pointer-events: none; position: sticky; z-index: 3; }
			.wpmcp-actions .button { border-radius: 8px; box-shadow: 0 14px 26px rgba(37,99,235,.28); pointer-events: auto; }
			.wpmcp-code-grid { display: grid; gap: 14px; grid-template-columns: repeat(3, minmax(0, 1fr)); padding: 24px; }
			.wpmcp-code-grid strong { display: block; margin-bottom: 8px; }
			.wpmcp-client-config-card .wpmcp-card-header { align-items: flex-start; }
			.wpmcp-client-config-card .wpmcp-card-header p { color: #64748b; margin: 6px 0 0; }
			.wpmcp-client-config-body { padding: 24px; }
			.wpmcp-client-config-controls { align-items: center; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; display: grid; gap: 16px; grid-template-columns: repeat(2, minmax(0, 1fr)) auto; padding: 16px; }
			.wpmcp-client-config-controls strong { display: block; margin-bottom: 6px; }
			.wpmcp-client-config-controls code { background: #e2e8f0; border-radius: 8px; display: inline-block; padding: 6px 8px; }
			.wpmcp-primary-action.button.button-primary { align-items: center; background: linear-gradient(135deg, #2563eb, #1d4ed8); border: 0; border-radius: 10px; box-shadow: 0 12px 22px rgba(37,99,235,.24); color: #fff; display: inline-flex; font-weight: 800; justify-content: center; min-height: 42px; padding: 0 18px; text-shadow: none; }
			.wpmcp-primary-action.button.button-primary:hover, .wpmcp-primary-action.button.button-primary:focus { background: linear-gradient(135deg, #1d4ed8, #1e40af); color: #fff; }
			.wpmcp-client-config-card .button:focus, .wpmcp-client-config-card .button:active, .wpmcp-client-config-card .button:focus-visible { border-color: transparent !important; box-shadow: 0 0 0 3px rgba(37,99,235,.22) !important; outline: 0 !important; }
			.wpmcp-secret-notice { align-items: center; background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; color: #78350f; display: flex; flex-wrap: wrap; gap: 6px 10px; margin: 16px 0; padding: 12px 14px; }
			.wpmcp-secret-notice a { color: #92400e; font-weight: 800; }
			.wpmcp-password-result { border-radius: 10px; font-weight: 700; margin: 16px 0; padding: 12px 14px; }
			.wpmcp-password-result.is-success { background: #ecfdf5; border: 1px solid #bbf7d0; color: #14532d; }
			.wpmcp-password-result.is-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
			.wpmcp-config-snippets { display: grid; gap: 14px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
			.wpmcp-config-snippet { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; box-shadow: 0 8px 18px rgba(15,23,42,.05); overflow: hidden; }
			.wpmcp-config-snippet-header { align-items: center; background: #fff; display: flex; gap: 14px; justify-content: space-between; padding: 16px; }
			.wpmcp-config-snippet-header p { color: #64748b; margin: 4px 0 0; }
			.wpmcp-config-title-row { align-items: center; display: flex; gap: 12px; min-width: 0; }
			.wpmcp-config-title-row strong { color: #0f172a; display: block; font-weight: 800; line-height: 1.25; }
			.wpmcp-agent-logo { align-items: center; border-radius: 14px; box-shadow: inset 0 1px 0 rgba(255,255,255,.28), 0 8px 18px rgba(15,23,42,.12); color: #fff; display: inline-flex; flex: 0 0 42px; font-size: 13px; font-weight: 800; height: 42px; justify-content: center; letter-spacing: -.03em; width: 42px; }
			.wpmcp-agent-logo.is-opencode { background: linear-gradient(135deg, #111827, #334155); }
			.wpmcp-agent-logo.is-codex { background: linear-gradient(135deg, #020617, #14b8a6); }
			.wpmcp-agent-logo.is-claude { background: linear-gradient(135deg, #7c2d12, #f59e0b); }
			.wpmcp-agent-logo.is-glm { background: linear-gradient(135deg, #1d4ed8, #38bdf8); }
			.wpmcp-agent-logo.is-minimax { background: linear-gradient(135deg, #6d28d9, #ec4899); }
			.wpmcp-config-actions { display: flex; flex: 0 0 auto; gap: 8px; }
			.wpmcp-config-actions .button { border-radius: 999px; font-weight: 800; min-height: 34px; padding: 0 14px; }
			.wpmcp-toggle-config { background: #eff6ff !important; border-color: #bfdbfe !important; color: #1d4ed8 !important; }
			.wpmcp-copy-config { background: #111827 !important; border-color: #111827 !important; color: #fff !important; }
			.wpmcp-copy-config:hover, .wpmcp-copy-config:focus { background: #374151 !important; border-color: #374151 !important; color: #fff !important; }
			.wpmcp-config-panel { border-top: 1px solid #e2e8f0; max-height: 520px; opacity: 1; overflow: hidden; transform: translateY(0); transition: max-height .28s ease, opacity .22s ease, transform .22s ease; }
			.wpmcp-config-snippet.is-collapsed .wpmcp-config-panel { border-top-color: transparent; max-height: 0; opacity: 0; transform: translateY(-6px); }
			.wpmcp-editor-chrome { align-items: center; background: #17130f; border-bottom: 1px solid #2f2922; color: #b9b2aa; display: flex; gap: 8px; padding: 10px 14px; }
			.wpmcp-editor-chrome span { border-radius: 50%; display: inline-block; height: 11px; width: 11px; }
			.wpmcp-editor-chrome span:nth-child(1) { background: #ff5f57; }
			.wpmcp-editor-chrome span:nth-child(2) { background: #ffbd2e; }
			.wpmcp-editor-chrome span:nth-child(3) { background: #28c840; }
			.wpmcp-editor-chrome strong { font-family: Consolas, Monaco, monospace; font-size: 12px; letter-spacing: .06em; margin-left: 14px; text-transform: uppercase; }
			.wpmcp-config-output { background: #17130f; border: 0; color: #e7e5e4; counter-reset: wpmcp-line; font-family: Consolas, Monaco, monospace; font-size: 12px; line-height: 1.7; margin: 0; max-height: 420px; min-height: 300px; overflow: auto; padding: 14px 0; tab-size: 2; }
			.wpmcp-config-line { display: grid; grid-template-columns: 44px 1fr; min-height: 20px; white-space: pre; }
			.wpmcp-config-line:before { color: #6b7280; content: counter(wpmcp-line); counter-increment: wpmcp-line; padding-right: 12px; text-align: right; user-select: none; }
			.wpmcp-config-line-code { color: #e7e5e4; overflow: visible; padding-right: 18px; }
			.wpmcp-config-line-code.is-placeholder { color: #8b8580; font-weight: 700; }
			@media (max-width: 900px) { .wpmcp-hero, .wpmcp-switch-row, .wpmcp-bulk-row { align-items: flex-start; flex-direction: column; } .wpmcp-summary-grid, .wpmcp-code-grid { grid-template-columns: 1fr; } .wpmcp-table { display: block; overflow-x: auto; } }
			@media (max-width: 900px) { .wpmcp-client-config-controls, .wpmcp-config-snippets { grid-template-columns: 1fr; } .wpmcp-config-snippet-header { align-items: flex-start; flex-direction: column; } }
		</style>
		<?php
	}

	/**
	 * Render small admin scripts for bulk switches.
	 */
	private function render_scripts(): void {
		?>
		<script>
			document.addEventListener('DOMContentLoaded', function () {
				var configStrings = <?php echo wp_json_encode( array( 'generating' => __( 'Generating...', 'wordpress-mcp' ), 'generated' => __( 'Application password generated. Copy your configs now; WordPress only shows this password once.', 'wordpress-mcp' ), 'generateError' => __( 'Could not generate an application password.', 'wordpress-mcp' ), 'copied' => __( 'Copied', 'wordpress-mcp' ), 'copy' => __( 'Copy', 'wordpress-mcp' ), 'copyError' => __( 'Copy failed', 'wordpress-mcp' ), 'expand' => __( 'Expand', 'wordpress-mcp' ), 'collapse' => __( 'Collapse', 'wordpress-mcp' ), 'generateLabel' => __( 'Generate & Fill Password', 'wordpress-mcp' ) ) ); ?>;
				var passwordPlaceholder = '{{GENERATE_APPLICATION_PASSWORD}}';
				var passwordButton = document.getElementById('wpmcp-generate-application-password');
				var passwordResult = document.getElementById('wpmcp-application-password-result');
				var configRawFields = Array.prototype.slice.call(document.querySelectorAll('.wpmcp-config-raw'));

				var renderCodeBlock = function (rawField) {
					var snippet = rawField.closest('.wpmcp-config-snippet');
					var code = snippet ? snippet.querySelector('.wpmcp-config-code') : null;

					if (!code) {
						return;
					}

					code.textContent = '';
					rawField.value.split('\n').forEach(function (line) {
						var row = document.createElement('span');
						var lineCode = document.createElement('span');

						row.className = 'wpmcp-config-line';
						lineCode.className = 'wpmcp-config-line-code';
						if (line.indexOf(passwordPlaceholder) !== -1) {
							lineCode.className += ' is-placeholder';
						}
						lineCode.textContent = line || ' ';
						row.appendChild(lineCode);
						code.appendChild(row);
					});
				};

				configRawFields.forEach(function (rawField) {
					rawField.dataset.wpmcpTemplate = rawField.value;
					renderCodeBlock(rawField);
				});

				var showPasswordResult = function (message, type) {
					if (!passwordResult) {
						return;
					}

					passwordResult.hidden = false;
					passwordResult.className = 'wpmcp-password-result is-' + type;
					passwordResult.textContent = message;
				};

				var updateConfigPasswords = function (password) {
					configRawFields.forEach(function (rawField) {
						var template = rawField.dataset.wpmcpTemplate || rawField.value;
						rawField.value = template.split(passwordPlaceholder).join(password);
						renderCodeBlock(rawField);
					});
				};

				document.querySelectorAll('.wpmcp-toggle-config').forEach(function (button) {
					button.addEventListener('click', function () {
						var snippet = button.closest('.wpmcp-config-snippet');
						var shouldOpen = snippet ? snippet.classList.contains('is-collapsed') : false;

						document.querySelectorAll('.wpmcp-config-snippet').forEach(function (otherSnippet) {
							var otherButton = otherSnippet.querySelector('.wpmcp-toggle-config');

							otherSnippet.classList.add('is-collapsed');
							if (otherButton) {
								otherButton.setAttribute('aria-expanded', 'false');
								otherButton.textContent = configStrings.expand;
							}
						});

						if (snippet && shouldOpen) {
							snippet.classList.remove('is-collapsed');
							button.setAttribute('aria-expanded', 'true');
							button.textContent = configStrings.collapse;
						}
					});
				});

				if (passwordButton) {
					passwordButton.addEventListener('click', function () {
						var formData = new FormData();
						formData.append('action', 'wordpress_mcp_generate_application_password');
						formData.append('nonce', passwordButton.getAttribute('data-nonce') || '');

						passwordButton.disabled = true;
						passwordButton.textContent = configStrings.generating;

						fetch(ajaxurl, {
							method: 'POST',
							body: formData,
							credentials: 'same-origin'
						})
							.then(function (response) {
								return response.json();
							})
							.then(function (response) {
								if (!response || !response.success || !response.data || !response.data.password) {
									throw new Error(response && response.data && response.data.message ? response.data.message : configStrings.generateError);
								}

								updateConfigPasswords(response.data.password);
								showPasswordResult(response.data.message || configStrings.generated, 'success');
							})
							.catch(function (error) {
								showPasswordResult(error.message || configStrings.generateError, 'error');
							})
							.finally(function () {
								passwordButton.disabled = false;
								passwordButton.textContent = configStrings.generateLabel;
							});
					});
				}

				var fallbackCopyText = function (text) {
					var textarea = document.createElement('textarea');
					textarea.value = text;
					textarea.setAttribute('readonly', 'readonly');
					textarea.style.position = 'fixed';
					textarea.style.left = '-9999px';
					document.body.appendChild(textarea);
					textarea.select();
					document.execCommand('copy');
					document.body.removeChild(textarea);
				};

				document.querySelectorAll('.wpmcp-copy-config').forEach(function (button) {
					button.addEventListener('click', function () {
						var snippet = button.closest('.wpmcp-config-snippet');
						var rawField = snippet ? snippet.querySelector('.wpmcp-config-raw') : null;

						if (!rawField) {
							return;
						}

						var finish = function (label) {
							button.textContent = label;
							setTimeout(function () {
								button.textContent = configStrings.copy;
							}, 1500);
						};

						if (navigator.clipboard && window.isSecureContext) {
							navigator.clipboard.writeText(rawField.value).then(function () {
								finish(configStrings.copied);
							}).catch(function () {
								fallbackCopyText(rawField.value);
								finish(configStrings.copied);
							});
							return;
						}

						fallbackCopyText(rawField.value);
						finish(configStrings.copied);
					});
				});

				var savedNotice = document.querySelector('.wpmcp-notice');
				if (savedNotice) {
					setTimeout(function () {
						savedNotice.style.transition = 'opacity .25s ease, transform .25s ease';
						savedNotice.style.opacity = '0';
						savedNotice.style.transform = 'translateY(-6px)';
						setTimeout(function () {
							if (savedNotice && savedNotice.parentNode) {
								savedNotice.parentNode.removeChild(savedNotice);
							}
						}, 300);
					}, 5500);
				}

				var bulkToggles = document.querySelectorAll('[data-wpmcp-bulk-target]');

				bulkToggles.forEach(function (bulkToggle) {
					var targetName = bulkToggle.getAttribute('data-wpmcp-bulk-target');
					var selector = 'input[name="' + targetName.replace(/["\\]/g, '\\$&') + '"]';
					var targets = Array.prototype.slice.call(document.querySelectorAll(selector));

					var syncBulkState = function () {
						bulkToggle.checked = targets.length > 0 && targets.every(function (target) {
							return target.checked;
						});
					};

					bulkToggle.addEventListener('change', function () {
						targets.forEach(function (target) {
							target.checked = bulkToggle.checked;
						});
					});

					targets.forEach(function (target) {
						target.addEventListener('change', syncBulkState);
					});

					syncBulkState();
				});
			});
		</script>
		<?php
	}
}
