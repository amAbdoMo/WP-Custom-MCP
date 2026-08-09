<?php //phpcs:ignore
declare( strict_types=1 );

namespace Automattic\WordpressMcp\Tools;

use Automattic\WordpressMcp\Core\RegisterMcpTool;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor/WPML manifest discovery tools.
 */
class McpWpmlElementorManifestTools {
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
				'name'                => 'wpml_elementor_manifest',
				'description'         => 'List Elementor-backed posts/templates/layouts with WPML state, JSON validity, widget counts, and missing translations.',
				'type'                => 'read',
				'inputSchema'         => array(
					'type'       => 'object',
					'properties' => array(
						'target_language' => array( 'type' => 'string', 'description' => 'Target language to check. Defaults to first non-default WPML language.' ),
						'post_types'      => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Optional post types. Defaults to common Elementor post types.' ),
						'limit'           => array( 'type' => 'integer', 'description' => 'Maximum documents. Defaults to 200.', 'default' => 200 ),
					),
				),
				'callback'            => array( $this, 'manifest' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'annotations'         => array( 'title' => 'WPML Elementor Manifest', 'readOnlyHint' => true, 'openWorldHint' => false ),
			)
		);
	}

	/**
	 * Build Elementor manifest.
	 *
	 * @param array $params Input parameters.
	 * @return array
	 */
	public function manifest( array $params = array() ): array {
		$source_language = (string) apply_filters( 'wpml_default_language', null );
		$target_language = sanitize_key( (string) ( $params['target_language'] ?? $this->first_target_language( $source_language ) ) );
		$post_types      = $this->post_types( $params );
		$limit           = min( 1000, max( 1, absint( $params['limit'] ?? 200 ) ) );
		$previous_language = (string) apply_filters( 'wpml_current_language', null );
		$this->switch_language( $source_language );
		$posts           = get_posts(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'any',
				'posts_per_page' => $limit,
				'meta_key'       => '_elementor_data',
				'suppress_filters' => false,
			)
		);
		$items           = array();

		foreach ( $posts as $post ) {
			$items[] = $this->document_summary( $post, $target_language );
		}

		$this->switch_language( $previous_language );

		return array(
			'source_language' => $source_language,
			'target_language' => $target_language,
			'post_types'      => $post_types,
			'count'           => count( $items ),
			'issues'          => $this->issues_summary( $items, $target_language ),
			'items'           => $items,
		);
	}

	/**
	 * Summarize one Elementor document.
	 *
	 * @param WP_Post $post Post.
	 * @param string  $target_language Target language.
	 * @return array
	 */
	private function document_summary( $post, string $target_language ): array {
		$data_raw     = (string) get_post_meta( $post->ID, '_elementor_data', true );
		$data         = json_decode( $data_raw, true );
		$wpml         = $this->relationship_summary( (int) $post->ID, 'post_' . $post->post_type );
		$target_id    = (int) ( $wpml['translations'][ $target_language ]['element_id'] ?? 0 );
		$target_shape = $target_id ? $this->target_shape( $target_id ) : null;

		return array(
			'id'                  => (int) $post->ID,
			'title'               => get_the_title( $post ),
			'post_type'           => $post->post_type,
			'status'              => $post->post_status,
			'elementor_type'      => get_post_meta( $post->ID, '_elementor_template_type', true ),
			'wpml'                => $wpml,
			'target_id'           => $target_id,
			'missing_translation' => ! $target_id,
			'json_valid'          => is_array( $data ),
			'data_hash'           => '' !== $data_raw ? md5( $data_raw ) : '',
			'root_count'          => is_array( $data ) ? count( $data ) : 0,
			'widget_count'        => is_array( $data ) ? $this->count_widgets( $data ) : 0,
			'target_shape'        => $target_shape,
		);
	}

	/**
	 * Target document shape.
	 *
	 * @param int $target_id Target ID.
	 * @return array
	 */
	private function target_shape( int $target_id ): array {
		$data_raw = (string) get_post_meta( $target_id, '_elementor_data', true );
		$data     = json_decode( $data_raw, true );

		return array( 'json_valid' => is_array( $data ), 'data_hash' => '' !== $data_raw ? md5( $data_raw ) : '', 'root_count' => is_array( $data ) ? count( $data ) : 0, 'widget_count' => is_array( $data ) ? $this->count_widgets( $data ) : 0 );
	}

	/**
	 * Count widgets recursively.
	 *
	 * @param array $elements Elements.
	 * @return int
	 */
	private function count_widgets( array $elements ): int {
		$count = 0;
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( 'widget' === ( $element['elType'] ?? '' ) ) {
				++$count;
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$count += $this->count_widgets( $element['elements'] );
			}
		}

		return $count;
	}

	/**
	 * Issue summary.
	 *
	 * @param array  $items Items.
	 * @param string $target_language Target language.
	 * @return array
	 */
	private function issues_summary( array $items, string $target_language ): array {
		$missing = array_filter( $items, static fn( array $item ): bool => ! empty( $item['missing_translation'] ) );
		$invalid = array_filter( $items, static fn( array $item ): bool => empty( $item['json_valid'] ) );
		$drift   = array_filter(
			$items,
			static function ( array $item ): bool {
				$target = $item['target_shape'];
				return is_array( $target ) && ( $target['root_count'] !== $item['root_count'] || $target['widget_count'] !== $item['widget_count'] );
			}
		);

		return array( 'target_language' => $target_language, 'missing_translations' => count( $missing ), 'invalid_source_json' => count( $invalid ), 'source_target_shape_drift' => count( $drift ) );
	}

	/**
	 * Relationship summary.
	 *
	 * @param int    $id ID.
	 * @param string $element_type Element type.
	 * @return array
	 */
	private function relationship_summary( int $id, string $element_type ): array {
		$trid  = apply_filters( 'wpml_element_trid', null, $id, $element_type );
		$items = $trid ? apply_filters( 'wpml_get_element_translations', null, $trid, $element_type ) : array();

		return array( 'trid' => $trid ? (int) $trid : null, 'translations' => $this->format_translations( is_array( $items ) ? $items : array() ) );
	}

	/**
	 * Format translations.
	 *
	 * @param array $translations Translations.
	 * @return array
	 */
	private function format_translations( array $translations ): array {
		$output = array();
		foreach ( $translations as $language => $translation ) {
			$output[ $language ] = array( 'element_id' => isset( $translation->element_id ) ? (int) $translation->element_id : 0, 'original' => ! empty( $translation->original ) );
		}

		return $output;
	}

	/**
	 * Get post types to inspect.
	 *
	 * @param array $params Input parameters.
	 * @return array
	 */
	private function post_types( array $params ): array {
		if ( ! empty( $params['post_types'] ) && is_array( $params['post_types'] ) ) {
			return array_values( array_filter( array_map( 'sanitize_key', $params['post_types'] ) ) );
		}

		return array_values( array_filter( array( 'page', 'post', 'product', 'elementor_library', post_type_exists( 'woodmart_layout' ) ? 'woodmart_layout' : '' ) ) );
	}

	/**
	 * Pick first non-default language.
	 *
	 * @param string $source_language Source language.
	 * @return string
	 */
	private function first_target_language( string $source_language ): string {
		$languages = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		if ( is_array( $languages ) ) {
			foreach ( array_keys( $languages ) as $language ) {
				if ( $language !== $source_language ) {
					return (string) $language;
				}
			}
		}

		return '';
	}

	/**
	 * Switch WPML language when available.
	 *
	 * @param string $language Language code.
	 */
	private function switch_language( string $language ): void {
		if ( '' !== $language && has_action( 'wpml_switch_language' ) ) {
			do_action( 'wpml_switch_language', $language );
		}
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
