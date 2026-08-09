<?php //phpcs:ignore
/**
 * Class McpWordPressRestApi
 *
 * Registers generic MCP tools for CRUD actions on any WordPress REST API endpoint.
 *
 * @package Automattic\WordpressMcp\Tools
 */
declare( strict_types=1 );

namespace Automattic\WordpressMcp\Tools;

use Automattic\WordpressMcp\Core\RegisterMcpTool;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Class McpWordPressRestApi
 *
 * Registers generic MCP tools for CRUD actions on any WordPress REST API endpoint.
 *
 * @package Automattic\WordpressMcp\Tools
 */
class McpRestApiCrud {
	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wordpress_mcp_init', array( $this, 'register_tools' ) );
	}

	/**
	 * Register generic CRUD tools for a given REST API endpoint.
	 *
	 * Example usage: You can extend this to register tools for any custom endpoint.
	 */
	public function register_tools(): void {
		// Check if REST API CRUD tools are enabled in settings.
		$settings = get_option( 'wordpress_mcp_settings', array() );
		if ( empty( $settings['enable_rest_api_crud_tools'] ) ) {
			return;
		}

		// Example: Register CRUD tools for a custom endpoint '/wp/v2/example'.
		// To use for other endpoints, duplicate and adjust the route/method/name/description as needed.

		new RegisterMcpTool(
			array(
				'name'                => 'list_api_functions',
				'description'         => 'List all available WordPress REST API endpoints that support CRUD operations (Create, Read, Update, Delete). Use this first to discover what API functions are available before inspecting or calling them.',
				'type'                => 'read',
				'inputSchema'         => array(
					'type'       => 'object',
				),
				'callback'            => array( $this, 'get_available_tools' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'annotations'         => array(
					'title'         => 'List API Functions',
					'readOnlyHint'  => true,
					'openWorldHint' => false,
				),
			)
		);

		new RegisterMcpTool(
			array(
				'name'                => 'get_function_details',
				'description'         => 'Get detailed metadata for a specific WordPress REST API endpoint and HTTP method. Includes available parameters, required fields, authentication needs, and expected response structure. Use this to get the details of a specific function before calling it.',
				'type'                => 'read',
				'inputSchema'         => array(
					'type'       => 'object',
					'properties' => array(
						'route'  => array(
							'type'        => 'string',
							'description' => 'The REST API route (e.g., "/wp/v2/posts", "/wp/v2/users")',
						),
						'method' => array(
							'type'        => 'string',
							'enum'        => array( 'GET', 'POST', 'PATCH', 'DELETE' ),
							'description' => 'The HTTP method to retrieve metadata for',
						),
					),
					'required'   => array( 'route', 'method' ),
				),
				'callback'            => array( $this, 'get_tool_details' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'annotations'         => array(
					'title'         => 'Get Function Details',
					'readOnlyHint'  => true,
					'openWorldHint' => false,
				),
			)
		);

		new RegisterMcpTool(
			array(
				'name'                => 'run_api_function',
				'description'         => 'Execute a specific WordPress REST API function by providing the endpoint route, HTTP method, and any required parameters or request body. Supports standard CRUD operations: GET (read), POST (create), PATCH (update), DELETE (remove).',
				'type'                => 'action',
				'inputSchema'         => array(
					'type'       => 'object',
					'properties' => array(
						'route'  => array(
							'type'        => 'string',
							'description' => 'The REST API route (e.g., "/wp/v2/posts", "/wp/v2/users/123")',
						),
						'method' => array(
							'type'        => 'string',
							'enum'        => array( 'GET', 'POST', 'PATCH', 'DELETE' ),
							'description' => 'The HTTP method to use: GET, POST, PATCH, or DELETE',
						),
						'data'   => array(
							'type'        => 'object',
							'description' => 'Payload for POST or PATCH requests. Not required for GET or DELETE.',
						),
					),
					'required'   => array( 'route', 'method' ),
				),
				'callback'            => array( $this, 'handle_tool_run_request' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'annotations'         => array(
					'title'           => 'Run API Function',
					'readOnlyHint'    => false,
					'destructiveHint' => true,
					'idempotentHint'  => false,
					'openWorldHint'   => false,
				),
			)
		);
	}

	/**
	 * Handle a REST API request.
	 *
	 * @param array $data The request data.
	 * @return array The response data.
	 */
	public function handle_tool_run_request( array $data ): array {
		$route   = isset( $data['route'] ) ? sanitize_text_field( $data['route'] ) : '';
		$method  = isset( $data['method'] ) ? strtoupper( sanitize_text_field( $data['method'] ) ) : '';
		$payload = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array();

		// Get settings to check if operations are enabled.
		$settings = get_option( 'wordpress_mcp_settings', array() );

		// Check if the method is allowed based on settings.
		switch ( $method ) {
			case 'DELETE':
				if ( empty( $settings['enable_delete_tools'] ) ) {
					return array(
						'error' => 'Delete operations are disabled in MCP settings.',
						'code'  => 'operation_disabled',
					);
				}
				break;
			case 'POST':
				if ( empty( $settings['enable_create_tools'] ) ) {
					return array(
						'error' => 'Create operations are disabled in MCP settings.',
						'code'  => 'operation_disabled',
					);
				}
				break;
			case 'PATCH':
			case 'PUT':
				if ( empty( $settings['enable_update_tools'] ) ) {
					return array(
						'error' => 'Update operations are disabled in MCP settings.',
						'code'  => 'operation_disabled',
					);
				}
				break;
		}

		$rest_request = new WP_REST_Request( $method, $route );
		if ( in_array( $method, array( 'GET', 'DELETE' ), true ) ) {
			$rest_request->set_query_params( $payload );
		} else {
			$rest_request->set_body_params( $payload );
		}

		$response = rest_do_request( $rest_request );
		return $response->get_data();
	}

	/**
	 * Get all routes and methods from the WordPress REST API.
	 *
	 * @return array The routes and methods.
	 */
	public function get_available_tools(): array {
		$exact_ignore_routes       = array(
			'/',
			'/batch/v1',
		);
		$containing_ignore_strings = array(
			'oembed',
			'autosaves',
			'revisions',
			'jwt-auth',
		);
		// Get all routes and methods from the WordPress REST API.
		$routes = rest_get_server()->get_routes();
		$result = array();
		foreach ( $routes as $route => $methods ) {
			// Skip if route exactly matches any ignore route.
			if ( in_array( $route, $exact_ignore_routes, true ) ) {
				continue;
			}
			// Skip if route contains any of the ignore strings.
			foreach ( $containing_ignore_strings as $ignore_string ) {
				if ( strpos( $route, $ignore_string ) !== false ) {
					continue 2;
				}
			}
			foreach ( $methods as $the_methods ) {
				$result[] = array(
					'route'  => $route,
					'method' => key( $the_methods['methods'] ),
				);
			}
		}
		return $result;
	}

	/**
	 * Get details of a WordPress REST API tool.
	 *
	 * @param array $data The request data.
	 * @return array|null The response data.
	 */
	public function get_tool_details( array $data ): array {
		$requested_route  = isset( $data['route'] ) ? sanitize_text_field( $data['route'] ) : '';
		$requested_method = isset( $data['method'] ) ? strtoupper( sanitize_text_field( $data['method'] ) ) : '';

		$routes = rest_get_server()->get_routes();
		if ( ! isset( $routes[ $requested_route ] ) ) {
			return array();
		}

		foreach ( $routes[ $requested_route ] as $endpoint ) {
			if ( ! empty( $endpoint['methods'][ $requested_method ] ) ) {
				unset( $endpoint['callback'], $endpoint['permission_callback'] );
				return $endpoint;
			}
		}
		return array();
	}

	/**
	 * Permission callback for generic REST API CRUD tools.
	 *
	 * @return bool
	 */
	public function permission_callback( array $params = array() ): bool {
		return current_user_can( 'manage_options' );
	}
}
