<?php //phpcs:ignore
declare( strict_types=1 );

namespace Automattic\WordpressMcp\Tools;

use Automattic\WordpressMcp\Core\RegisterMcpTool;

defined( 'ABSPATH' ) || exit;

/**
 * Effective WPML String Translation search and update tools.
 */
class McpWpmlStringTranslationTools {
	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wordpress_mcp_init', array( $this, 'register_tools' ) );
	}

	/**
	 * Register tools.
	 */
	public function register_tools(): void {
		new RegisterMcpTool(
			array(
				'name'                => 'wpml_string_effective_search',
				'description'         => 'Search WPML String Translation rows and report likely effective rows, duplicates, domains, names, and translation status.',
				'type'                => 'read',
				'inputSchema'         => array(
					'type'       => 'object',
					'properties' => array(
						'search'   => array( 'type' => 'string', 'description' => 'Source string or partial text to search.' ),
						'context'  => array( 'type' => 'string', 'description' => 'Optional WPML string domain/context.' ),
						'name'     => array( 'type' => 'string', 'description' => 'Optional exact WPML string name.' ),
						'language' => array( 'type' => 'string', 'description' => 'Optional target language to include translation value/status.' ),
						'exact'    => array( 'type' => 'boolean', 'description' => 'Use exact source value match. Defaults to false.', 'default' => false ),
						'limit'    => array( 'type' => 'integer', 'description' => 'Maximum rows. Defaults to 20.', 'default' => 20 ),
					),
				),
				'callback'            => array( $this, 'search_strings' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'annotations'         => array( 'title' => 'Search WPML Strings', 'readOnlyHint' => true, 'openWorldHint' => false ),
			)
		);

		new RegisterMcpTool(
			array(
				'name'                => 'wpml_string_effective_update',
				'description'         => 'Update an existing effective WPML String Translation row by string ID or exact domain/name/source lookup. Supports dry-run.',
				'type'                => 'update',
				'inputSchema'         => array(
					'type'       => 'object',
					'properties' => array(
						'string_id'    => array( 'type' => 'integer', 'description' => 'WPML string ID. Preferred.' ),
						'context'      => array( 'type' => 'string', 'description' => 'Exact WPML string domain/context when string_id is not provided.' ),
						'name'         => array( 'type' => 'string', 'description' => 'Exact WPML string name when string_id is not provided.' ),
						'source_value' => array( 'type' => 'string', 'description' => 'Exact source value when string_id is not provided.' ),
						'language'     => array( 'type' => 'string', 'description' => 'Target language code.' ),
						'value'        => array( 'type' => 'string', 'description' => 'Translated value.' ),
						'status'       => array( 'type' => 'integer', 'description' => 'WPML translation status. Defaults to 10 (complete).', 'default' => 10 ),
						'dry_run'      => array( 'type' => 'boolean', 'description' => 'Preview without saving. Defaults to true.', 'default' => true ),
					),
					'required'   => array( 'language', 'value' ),
				),
				'callback'            => array( $this, 'update_string' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'annotations'         => array( 'title' => 'Update WPML String', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
			)
		);
	}

	/**
	 * Search WPML strings.
	 *
	 * @param array $params Input parameters.
	 * @return array
	 */
	public function search_strings( array $params ): array {
		global $wpdb;

		$tables = $this->tables();
		if ( ! $this->tables_available() ) {
			return $this->error( 'wpml_string_tables_missing', 'WPML String Translation tables are not available.' );
		}

		$search   = isset( $params['search'] ) ? (string) $params['search'] : '';
		$context  = isset( $params['context'] ) ? (string) $params['context'] : '';
		$name     = isset( $params['name'] ) ? (string) $params['name'] : '';
		$language = isset( $params['language'] ) ? sanitize_key( (string) $params['language'] ) : '';
		$exact    = ! empty( $params['exact'] );
		$limit    = min( 100, max( 1, absint( $params['limit'] ?? 20 ) ) );

		$where = array( '1=1' );
		$args  = array();

		if ( '' !== $search ) {
			$where[] = $exact ? 's.value = %s' : 's.value LIKE %s';
			$args[]  = $exact ? $search : '%' . $wpdb->esc_like( $search ) . '%';
		}

		if ( '' !== $context ) {
			$where[] = 's.context = %s';
			$args[]  = $context;
		}

		if ( '' !== $name ) {
			$where[] = 's.name = %s';
			$args[]  = $name;
		}

		$args[] = $limit;
		$sql    = "SELECT s.id, s.language, s.context, s.name, s.value FROM {$tables['strings']} s WHERE " . implode( ' AND ', $where ) . ' ORDER BY s.id ASC LIMIT %d';
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		$output = array();
		foreach ( $rows as $row ) {
			$output[] = $this->string_row_summary( $row, $language );
		}

		return array(
			'count'       => count( $output ),
			'language'    => $language,
			'results'     => $output,
			'warning'     => count( $output ) > 1 ? 'Multiple rows matched. Update by string_id to avoid changing a duplicate/non-effective row.' : '',
		);
	}

	/**
	 * Update a WPML string translation.
	 *
	 * @param array $params Input parameters.
	 * @return array
	 */
	public function update_string( array $params ): array {
		global $wpdb;

		if ( ! $this->tables_available() ) {
			return $this->error( 'wpml_string_tables_missing', 'WPML String Translation tables are not available.' );
		}

		$tables    = $this->tables();
		$string_id = absint( $params['string_id'] ?? 0 );
		$language  = sanitize_key( (string) ( $params['language'] ?? '' ) );
		$value     = (string) ( $params['value'] ?? '' );
		$status    = absint( $params['status'] ?? 10 );
		$dry_run   = ! array_key_exists( 'dry_run', $params ) || ! empty( $params['dry_run'] );

		if ( ! $string_id ) {
			$string_id = $this->find_exact_string_id( $params );
		}

		if ( ! $string_id ) {
			return $this->error( 'string_not_found', 'No exact WPML string row found. Use wpml_string_effective_search and update by string_id.' );
		}

		$source = $wpdb->get_row( $wpdb->prepare( "SELECT id, language, context, name, value FROM {$tables['strings']} WHERE id = %d", $string_id ), ARRAY_A );
		if ( ! $source ) {
			return $this->error( 'string_not_found', 'String ID does not exist.' );
		}

		$before = $wpdb->get_row( $wpdb->prepare( "SELECT id, value, status FROM {$tables['translations']} WHERE string_id = %d AND language = %s", $string_id, $language ), ARRAY_A );

		if ( ! $dry_run ) {
			if ( $before ) {
				$wpdb->update(
					$tables['translations'],
					array( 'value' => $value, 'status' => $status ),
					array( 'id' => (int) $before['id'] ),
					array( '%s', '%d' ),
					array( '%d' )
				);
			} else {
				$wpdb->insert(
					$tables['translations'],
					array( 'string_id' => $string_id, 'language' => $language, 'value' => $value, 'status' => $status ),
					array( '%d', '%s', '%s', '%d' )
				);
			}
		}

		return array(
			'dry_run'   => $dry_run,
			'string'    => $source,
			'language'  => $language,
			'before'    => $before ?: null,
			'after'     => array( 'value' => $value, 'status' => $status ),
			'changed'   => ! $dry_run,
		);
	}

	/**
	 * Summarize a string row.
	 *
	 * @param array  $row Row.
	 * @param string $language Target language.
	 * @return array
	 */
	private function string_row_summary( array $row, string $language ): array {
		global $wpdb;

		$tables      = $this->tables();
		$translation = null;
		if ( '' !== $language ) {
			$translation = $wpdb->get_row( $wpdb->prepare( "SELECT id, value, status FROM {$tables['translations']} WHERE string_id = %d AND language = %s", (int) $row['id'], $language ), ARRAY_A );
		}

		$duplicate_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$tables['strings']} WHERE context = %s AND name = %s",
				(string) $row['context'],
				(string) $row['name']
			)
		);

		return array(
			'id'              => (int) $row['id'],
			'source_language' => (string) $row['language'],
			'context'         => (string) $row['context'],
			'name'            => (string) $row['name'],
			'value'           => (string) $row['value'],
			'translation'     => $translation,
			'duplicate_count' => $duplicate_count,
			'effective_hint'  => $duplicate_count > 1 ? 'Duplicate context/name rows exist. Prefer verifying frontend source before updating.' : 'Single context/name row.',
		);
	}

	/**
	 * Find an exact string ID from context/name/source.
	 *
	 * @param array $params Input parameters.
	 * @return int
	 */
	private function find_exact_string_id( array $params ): int {
		global $wpdb;

		$tables = $this->tables();
		$where  = array();
		$args   = array();

		foreach ( array( 'context', 'name' ) as $key ) {
			if ( ! empty( $params[ $key ] ) ) {
				$where[] = 's.' . $key . ' = %s';
				$args[]  = (string) $params[ $key ];
			}
		}

		if ( ! empty( $params['source_value'] ) ) {
			$where[] = 's.value = %s';
			$args[]  = (string) $params['source_value'];
		}

		if ( empty( $where ) ) {
			return 0;
		}

		$sql = "SELECT s.id FROM {$tables['strings']} s WHERE " . implode( ' AND ', $where ) . ' ORDER BY s.id ASC LIMIT 2';
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );

		return 1 === count( $ids ) ? (int) $ids[0] : 0;
	}

	/**
	 * Table names.
	 *
	 * @return array
	 */
	private function tables(): array {
		global $wpdb;

		return array(
			'strings'      => $wpdb->prefix . 'icl_strings',
			'translations' => $wpdb->prefix . 'icl_string_translations',
		);
	}

	/**
	 * Check table availability.
	 *
	 * @return bool
	 */
	private function tables_available(): bool {
		global $wpdb;

		$tables = $this->tables();
		foreach ( $tables as $table ) {
			if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Build error response.
	 *
	 * @param string $code Error code.
	 * @param string $message Message.
	 * @return array
	 */
	private function error( string $code, string $message ): array {
		return array( 'code' => $code, 'error' => $message );
	}

	/**
	 * Permission callback.
	 *
	 * @return bool
	 */
	public function permission_callback(): bool {
		return current_user_can( 'manage_options' );
	}
}
