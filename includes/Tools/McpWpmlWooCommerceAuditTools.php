<?php //phpcs:ignore
declare( strict_types=1 );

namespace Automattic\WordpressMcp\Tools;

use Automattic\WordpressMcp\Core\RegisterMcpTool;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce/WPML catalog translation audit tools.
 */
class McpWpmlWooCommerceAuditTools {
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
				'name'                => 'wc_wpml_catalog_translation_audit',
				'description'         => 'Audit WooCommerce catalog WPML translation gaps, SKU mismatches, term gaps, status mismatches, and standalone duplicates.',
				'type'                => 'read',
				'inputSchema'         => array(
					'type'       => 'object',
					'properties' => array(
						'target_language' => array( 'type' => 'string', 'description' => 'Target language code. Defaults to first non-default WPML language.' ),
						'source_language' => array( 'type' => 'string', 'description' => 'Source language code. Defaults to WPML default language.' ),
						'limit'           => array( 'type' => 'integer', 'description' => 'Maximum products to inspect. Defaults to 200.', 'default' => 200 ),
					),
				),
				'callback'            => array( $this, 'catalog_audit' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'annotations'         => array( 'title' => 'Audit WooCommerce WPML Catalog', 'readOnlyHint' => true, 'openWorldHint' => false ),
			)
		);
	}

	/**
	 * Audit catalog translations.
	 *
	 * @param array $params Input parameters.
	 * @return array
	 */
	public function catalog_audit( array $params = array() ): array {
		if ( ! post_type_exists( 'product' ) ) {
			return $this->error( 'woocommerce_missing', 'WooCommerce products are not available.' );
		}

		$source_language = sanitize_key( (string) ( $params['source_language'] ?? apply_filters( 'wpml_default_language', null ) ) );
		$target_language = sanitize_key( (string) ( $params['target_language'] ?? $this->first_target_language( $source_language ) ) );
		$limit           = min( 1000, max( 1, absint( $params['limit'] ?? 200 ) ) );
		$previous_language = (string) apply_filters( 'wpml_current_language', null );
		$this->switch_language( $source_language );
		$products        = get_posts( array( 'post_type' => 'product', 'post_status' => 'any', 'posts_per_page' => $limit, 'suppress_filters' => false ) );
		$report          = array(
			'source_language'       => $source_language,
			'target_language'       => $target_language,
			'inspected'             => 0,
			'missing_products'      => array(),
			'sku_mismatches'        => array(),
			'status_mismatches'     => array(),
			'term_translation_gaps' => array(),
			'standalone_suspects'   => array(),
		);

		foreach ( $products as $post ) {
			$summary = $this->relationship_summary( (int) $post->ID, 'post_product' );
			if ( ! $this->is_source_product( $summary, $source_language ) ) {
				$this->maybe_record_standalone( $report, $post, $summary, $source_language );
				continue;
			}

			++$report['inspected'];
			$target_id = (int) ( $summary['translations'][ $target_language ]['element_id'] ?? 0 );
			if ( ! $target_id ) {
				$report['missing_products'][] = $this->product_row( $post );
				continue;
			}

			$this->compare_product_pair( $report, (int) $post->ID, $target_id, $target_language );
		}

		$this->switch_language( $previous_language );

		$report['counts'] = array(
			'missing_products'      => count( $report['missing_products'] ),
			'sku_mismatches'        => count( $report['sku_mismatches'] ),
			'status_mismatches'     => count( $report['status_mismatches'] ),
			'term_translation_gaps' => count( $report['term_translation_gaps'] ),
			'standalone_suspects'   => count( $report['standalone_suspects'] ),
		);

		return $report;
	}

	/**
	 * Compare source and target product data.
	 *
	 * @param array  $report Report, by reference.
	 * @param int    $source_id Source product ID.
	 * @param int    $target_id Target product ID.
	 * @param string $target_language Target language.
	 */
	private function compare_product_pair( array &$report, int $source_id, int $target_id, string $target_language ): void {
		$source = get_post( $source_id );
		$target = get_post( $target_id );
		if ( ! $source || ! $target ) {
			return;
		}

		$source_sku = get_post_meta( $source_id, '_sku', true );
		$target_sku = get_post_meta( $target_id, '_sku', true );
		if ( (string) $source_sku !== (string) $target_sku ) {
			$report['sku_mismatches'][] = array( 'source_id' => $source_id, 'target_id' => $target_id, 'source_sku' => $source_sku, 'target_sku' => $target_sku );
		}

		if ( $source->post_status !== $target->post_status ) {
			$report['status_mismatches'][] = array( 'source_id' => $source_id, 'target_id' => $target_id, 'source_status' => $source->post_status, 'target_status' => $target->post_status );
		}

		foreach ( array( 'product_cat', 'product_tag', 'product_brand' ) as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				$this->compare_terms( $report, $source_id, $target_id, $taxonomy, $target_language );
			}
		}
	}

	/**
	 * Compare translated term assignments.
	 *
	 * @param array  $report Report, by reference.
	 * @param int    $source_id Source product ID.
	 * @param int    $target_id Target product ID.
	 * @param string $taxonomy Taxonomy.
	 * @param string $target_language Target language.
	 */
	private function compare_terms( array &$report, int $source_id, int $target_id, string $taxonomy, string $target_language ): void {
		$source_terms = wp_get_object_terms( $source_id, $taxonomy );
		$target_terms = wp_get_object_terms( $target_id, $taxonomy, array( 'fields' => 'ids' ) );
		$target_ids   = is_wp_error( $target_terms ) ? array() : array_map( 'intval', $target_terms );

		if ( is_wp_error( $source_terms ) ) {
			return;
		}

		foreach ( $source_terms as $term ) {
			$translated_id = (int) apply_filters( 'wpml_object_id', (int) $term->term_id, $taxonomy, false, $target_language );
			if ( ! $translated_id || ! in_array( $translated_id, $target_ids, true ) ) {
				$report['term_translation_gaps'][] = array( 'source_id' => $source_id, 'target_id' => $target_id, 'taxonomy' => $taxonomy, 'source_term_id' => (int) $term->term_id, 'source_term' => $term->name, 'expected_target_term_id' => $translated_id );
			}
		}
	}

	/**
	 * Record standalone translated-looking products.
	 *
	 * @param array   $report Report, by reference.
	 * @param WP_Post $post Product post.
	 * @param array   $summary Relationship summary.
	 * @param string  $source_language Source language.
	 */
	private function maybe_record_standalone( array &$report, $post, array $summary, string $source_language ): void {
		$language = (string) ( $summary['language']['language_code'] ?? '' );
		$source   = (string) ( $summary['language']['source_language_code'] ?? '' );
		if ( $language && $language !== $source_language && '' === $source ) {
			$report['standalone_suspects'][] = array( 'id' => (int) $post->ID, 'title' => get_the_title( $post ), 'language' => $language, 'trid' => $summary['trid'] );
		}
	}

	/**
	 * Check whether a product is source-language item.
	 *
	 * @param array  $summary Relationship summary.
	 * @param string $source_language Source language.
	 * @return bool
	 */
	private function is_source_product( array $summary, string $source_language ): bool {
		$language = (string) ( $summary['language']['language_code'] ?? '' );
		$source   = (string) ( $summary['language']['source_language_code'] ?? '' );

		return $language === $source_language && '' === $source;
	}

	/**
	 * Relationship summary.
	 *
	 * @param int    $id Element ID.
	 * @param string $element_type WPML element type.
	 * @return array
	 */
	private function relationship_summary( int $id, string $element_type ): array {
		$language = apply_filters( 'wpml_element_language_details', null, array( 'element_id' => $id, 'element_type' => $element_type ) );
		$trid     = apply_filters( 'wpml_element_trid', null, $id, $element_type );
		$items    = $trid ? apply_filters( 'wpml_get_element_translations', null, $trid, $element_type ) : array();

		return array( 'trid' => $trid ? (int) $trid : null, 'language' => is_object( $language ) ? (array) $language : array(), 'translations' => $this->format_translations( is_array( $items ) ? $items : array() ) );
	}

	/**
	 * Product row summary.
	 *
	 * @param WP_Post $post Product post.
	 * @return array
	 */
	private function product_row( $post ): array {
		return array( 'id' => (int) $post->ID, 'title' => get_the_title( $post ), 'sku' => get_post_meta( $post->ID, '_sku', true ), 'status' => $post->post_status );
	}

	/**
	 * Format WPML translations.
	 *
	 * @param array $translations Translation objects.
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
	 * Error response.
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
