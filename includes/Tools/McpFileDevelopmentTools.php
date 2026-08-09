<?php //phpcs:ignore
declare( strict_types=1 );

namespace Automattic\WordpressMcp\Tools;

use Automattic\WordpressMcp\Core\RegisterMcpTool;

defined( 'ABSPATH' ) || exit;

/**
 * Constrained file development tools for plugin/theme work.
 */
class McpFileDevelopmentTools {
	/**
	 * Allowed file extensions for read/write operations.
	 */
	private const ALLOWED_EXTENSIONS = array(
		'php',
		'js',
		'jsx',
		'ts',
		'tsx',
		'css',
		'scss',
		'json',
		'jsonc',
		'txt',
		'md',
		'html',
		'htm',
		'xml',
		'yml',
		'yaml',
		'pot',
		'po',
	);

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
				'name'                => 'wp_file_dev_list',
				'description'         => 'List plugin/theme files and directories within constrained WordPress development roots. No arbitrary server paths are allowed.',
				'type'                => 'read',
				'inputSchema'         => $this->path_schema(
					array(
						'recursive' => array(
							'type'        => 'boolean',
							'description' => 'Whether to list recursively. Defaults to false. Results are capped.',
							'default'     => false,
						),
					)
				),
				'callback'            => array( $this, 'list_files' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'annotations'         => array(
					'title'         => 'List Development Files',
					'readOnlyHint'  => true,
					'openWorldHint' => false,
				),
			)
		);

		new RegisterMcpTool(
			array(
				'name'                => 'wp_file_dev_read',
				'description'         => 'Read a plugin/theme text file from constrained WordPress development roots.',
				'type'                => 'read',
				'inputSchema'         => $this->path_schema(),
				'callback'            => array( $this, 'read_file' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'annotations'         => array(
					'title'         => 'Read Development File',
					'readOnlyHint'  => true,
					'openWorldHint' => false,
				),
			)
		);

		new RegisterMcpTool(
			array(
				'name'                => 'wp_file_dev_write',
				'description'         => 'Write a plugin/theme text file within constrained WordPress development roots. Use carefully: invalid PHP can break the site.',
				'type'                => 'update',
				'inputSchema'         => $this->path_schema(
					array(
						'content'            => array(
							'type'        => 'string',
							'description' => 'Complete file content to write. This replaces the existing file content.',
						),
						'create_directories' => array(
							'type'        => 'boolean',
							'description' => 'Create missing parent directories inside the selected root. Defaults to false.',
							'default'     => false,
						),
					),
					array( 'base', 'path', 'content' )
				),
				'callback'            => array( $this, 'write_file' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'annotations'         => array(
					'title'           => 'Write Development File',
					'readOnlyHint'    => false,
					'destructiveHint' => true,
					'idempotentHint'  => true,
					'openWorldHint'   => false,
				),
			)
		);
	}

	/**
	 * Shared path schema.
	 *
	 * @param array $extra_properties Extra schema properties.
	 * @param array $required Required fields.
	 * @return array
	 */
	private function path_schema( array $extra_properties = array(), array $required = array( 'base', 'path' ) ): array {
		return array(
			'type'       => 'object',
			'properties' => array_merge(
				array(
					'base' => array(
						'type'        => 'string',
						'enum'        => array( 'plugins', 'themes', 'mu-plugins' ),
						'description' => 'Development root to use. Paths are constrained to this root.',
					),
					'path' => array(
						'type'        => 'string',
						'description' => 'Relative path inside the selected root. Use forward slashes. Traversal and absolute paths are rejected.',
					),
				),
				$extra_properties
			),
			'required'   => $required,
		);
	}

	/**
	 * List files.
	 *
	 * @param array $params Input params.
	 * @return array
	 */
	public function list_files( array $params ): array {
		$base      = (string) ( $params['base'] ?? '' );
		$path      = (string) ( $params['path'] ?? '' );
		$recursive = ! empty( $params['recursive'] );
		$root      = $this->get_root( $base );
		$dir       = $this->resolve_existing_path( $root, $path );

		if ( ! is_dir( $dir ) ) {
			return $this->error( 'not_directory', 'The requested path is not a directory.' );
		}

		$items = array();
		$this->collect_directory_items( $root, $dir, $recursive, $items );

		return array(
			'base'  => $base,
			'path'  => $path,
			'count' => count( $items ),
			'items' => $items,
		);
	}

	/**
	 * Read a file.
	 *
	 * @param array $params Input params.
	 * @return array
	 */
	public function read_file( array $params ): array {
		$base = (string) ( $params['base'] ?? '' );
		$path = (string) ( $params['path'] ?? '' );
		$root = $this->get_root( $base );
		$file = $this->resolve_existing_path( $root, $path );

		if ( ! is_file( $file ) ) {
			return $this->error( 'not_file', 'The requested path is not a file.' );
		}

		if ( ! $this->is_allowed_extension( $file ) ) {
			return $this->error( 'extension_not_allowed', 'This file extension is not allowed for development access.' );
		}

		$content = file_get_contents( $file );
		if ( false === $content ) {
			return $this->error( 'read_failed', 'Could not read the file.' );
		}

		return array(
			'base'     => $base,
			'path'     => $this->relative_path( $root, $file ),
			'size'     => filesize( $file ),
			'modified' => gmdate( 'c', (int) filemtime( $file ) ),
			'content'  => $content,
		);
	}

	/**
	 * Write a file.
	 *
	 * @param array $params Input params.
	 * @return array
	 */
	public function write_file( array $params ): array {
		$base               = (string) ( $params['base'] ?? '' );
		$path               = (string) ( $params['path'] ?? '' );
		$content            = (string) ( $params['content'] ?? '' );
		$create_directories = ! empty( $params['create_directories'] );
		$root               = $this->get_root( $base );
		$file               = $this->resolve_writable_path( $root, $path, $create_directories );

		if ( ! $this->is_allowed_extension( $file ) ) {
			return $this->error( 'extension_not_allowed', 'This file extension is not allowed for development access.' );
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		global $wp_filesystem;
		if ( ! WP_Filesystem() || ! $wp_filesystem || ! $wp_filesystem->put_contents( $file, $content, FS_CHMOD_FILE ) ) {
			return $this->error( 'write_failed', 'Could not write the file.' );
		}

		$bytes = strlen( $content );

		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $file, true );
		}

		return array(
			'base'     => $base,
			'path'     => $this->relative_path( $root, $file ),
			'bytes'    => $bytes,
			'size'     => filesize( $file ),
			'modified' => gmdate( 'c', (int) filemtime( $file ) ),
		);
	}

	/**
	 * Collect directory items.
	 *
	 * @param string $root Root path.
	 * @param string $dir Directory path.
	 * @param bool   $recursive Whether to recurse.
	 * @param array  $items Output items.
	 */
	private function collect_directory_items( string $root, string $dir, bool $recursive, array &$items ): void {
		$entries = scandir( $dir );
		if ( false === $entries ) {
			return;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$full_path = $dir . DIRECTORY_SEPARATOR . $entry;
			$real_path = realpath( $full_path );
			if ( false === $real_path || ! $this->is_within_root( $root, $real_path ) ) {
				continue;
			}

			$items[] = array(
				'path'     => $this->relative_path( $root, $real_path ),
				'type'     => is_dir( $real_path ) ? 'directory' : 'file',
				'size'     => is_file( $real_path ) ? filesize( $real_path ) : null,
				'modified' => gmdate( 'c', (int) filemtime( $real_path ) ),
				'writable' => is_writable( $real_path ),
			);

			if ( count( $items ) >= 500 ) {
				return;
			}

			if ( $recursive && is_dir( $real_path ) ) {
				$this->collect_directory_items( $root, $real_path, true, $items );
				if ( count( $items ) >= 500 ) {
					return;
				}
			}
		}
	}

	/**
	 * Resolve a configured root.
	 *
	 * @param string $base Base key.
	 * @return string
	 */
	private function get_root( string $base ): string {
		$roots = array(
			'plugins'    => WP_PLUGIN_DIR,
			'themes'     => get_theme_root(),
			'mu-plugins' => defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins',
		);

		if ( ! isset( $roots[ $base ] ) ) {
			throw new \InvalidArgumentException( 'Invalid development root.' );
		}

		$root = realpath( $roots[ $base ] );
		if ( false === $root || ! is_dir( $root ) ) {
			throw new \InvalidArgumentException( 'Development root does not exist.' );
		}

		return wp_normalize_path( $root );
	}

	/**
	 * Resolve existing path.
	 *
	 * @param string $root Root path.
	 * @param string $path Relative path.
	 * @return string
	 */
	private function resolve_existing_path( string $root, string $path ): string {
		$relative = $this->sanitize_relative_path( $path );
		$target   = realpath( $root . '/' . $relative );

		if ( false === $target ) {
			throw new \InvalidArgumentException( 'Requested path does not exist.' );
		}

		$target = wp_normalize_path( $target );
		if ( ! $this->is_within_root( $root, $target ) ) {
			throw new \InvalidArgumentException( 'Requested path is outside the allowed root.' );
		}

		return $target;
	}

	/**
	 * Resolve writable path.
	 *
	 * @param string $root Root path.
	 * @param string $path Relative path.
	 * @param bool   $create_directories Whether to create directories.
	 * @return string
	 */
	private function resolve_writable_path( string $root, string $path, bool $create_directories ): string {
		$relative = $this->sanitize_relative_path( $path );
		$target   = wp_normalize_path( $root . '/' . $relative );
		$parent   = dirname( $target );

		if ( ! is_dir( $parent ) ) {
			if ( ! $create_directories ) {
				throw new \InvalidArgumentException( 'Parent directory does not exist.' );
			}

			if ( ! wp_mkdir_p( $parent ) ) {
				throw new \InvalidArgumentException( 'Could not create parent directory.' );
			}
		}

		$real_parent = realpath( $parent );
		if ( false === $real_parent || ! $this->is_within_root( $root, wp_normalize_path( $real_parent ) ) ) {
			throw new \InvalidArgumentException( 'Parent directory is outside the allowed root.' );
		}

		if ( file_exists( $target ) ) {
			$real_target = realpath( $target );
			if ( false === $real_target || ! $this->is_within_root( $root, wp_normalize_path( $real_target ) ) || ! is_file( $real_target ) ) {
				throw new \InvalidArgumentException( 'Target file is invalid or outside the allowed root.' );
			}
		}

		return $target;
	}

	/**
	 * Sanitize a relative path.
	 *
	 * @param string $path Relative path.
	 * @return string
	 */
	private function sanitize_relative_path( string $path ): string {
		$path = wp_normalize_path( trim( $path ) );
		$path = ltrim( $path, '/' );

		if ( '' === $path ) {
			return '.';
		}

		if ( str_contains( $path, "\0" ) || str_contains( $path, ':' ) || str_contains( $path, '..' ) || str_starts_with( $path, '/' ) ) {
			throw new \InvalidArgumentException( 'Invalid relative path.' );
		}

		return $path;
	}

	/**
	 * Check if path is within root.
	 *
	 * @param string $root Root path.
	 * @param string $path Path.
	 * @return bool
	 */
	private function is_within_root( string $root, string $path ): bool {
		$root = rtrim( wp_normalize_path( $root ), '/' ) . '/';
		$path = rtrim( wp_normalize_path( $path ), '/' );

		return str_starts_with( $path . ( is_dir( $path ) ? '/' : '' ), $root );
	}

	/**
	 * Return relative path.
	 *
	 * @param string $root Root path.
	 * @param string $path Full path.
	 * @return string
	 */
	private function relative_path( string $root, string $path ): string {
		$root = rtrim( wp_normalize_path( $root ), '/' ) . '/';
		$path = wp_normalize_path( $path );

		return ltrim( str_replace( $root, '', $path ), '/' );
	}

	/**
	 * Check extension allow-list.
	 *
	 * @param string $file File path.
	 * @return bool
	 */
	private function is_allowed_extension( string $file ): bool {
		$extension = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		return in_array( $extension, self::ALLOWED_EXTENSIONS, true );
	}

	/**
	 * Build an error response.
	 *
	 * @param string $code Error code.
	 * @param string $message Error message.
	 * @return array
	 */
	private function error( string $code, string $message ): array {
		return array(
			'error' => $message,
			'code'  => $code,
		);
	}

	/**
	 * Permission callback.
	 *
	 * @return bool
	 */
	public function permission_callback(): bool {
		return current_user_can( 'manage_options' ) && ( current_user_can( 'edit_plugins' ) || current_user_can( 'edit_themes' ) );
	}
}
