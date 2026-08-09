<?php //phpcs:ignore
declare( strict_types=1 );

namespace Automattic\WordpressMcp\Tools;

use Automattic\WordpressMcp\Core\RegisterMcpTool;

defined( 'ABSPATH' ) || exit;

/**
 * WPML relationship inspection and safe linking tools.
 */
class McpWpmlRelationshipTools {
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
				'name'                => 'wpml_relationships_get',
				'description'         => 'Inspect WPML trid, language, source language, and translations for a post or term.',
				'type'                => 'read',
				'inputSchema'         => array(
					'type'       => 'object',
					'properties' => array(
						'id'           => array( 'type' => 'integer', 'description' => 'Post or term ID.' ),
						'element_type' => array( 'type' => 'string', 'description' => 'WPML element type, e.g. post_page, post_product, tax_product_cat.' ),
					),
					'required'   => array( 'id', 'element_type' ),
				),
				'callback'            => array( $this, 'get_relationships' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'annotations'         => array( 'title' => 'Get WPML Relationships', 'readOnlyHint' => true, 'openWorldHint' => false ),
			)
		);

		new RegisterMcpTool(
			array(
				'name'                => 'wpml_relationships_missing',
				'description'         => 'List source-language posts or terms missing translations in one target language.',
				'type'                => 'read',
				'inputSchema'         => array(
					'type'       => 'object',
					'properties' => array(
						'kind'            => array( 'type' => 'string', 'description' => 'post or term.' ),
						'slug'            => array( 'type' => 'string', 'description' => 'Post type or taxonomy slug.' ),
						'target_language' => array( 'type' => 'string', 'description' => 'Target language code.' ),
						'source_language' => array( 'type' => 'string', 'description' => 'Optional source language code. Defaults to WPML default language.' ),
						'limit'           => array( 'type' => 'integer', 'description' => 'Maximum rows. Defaults to 100.', 'default' => 100 ),
					),
					'required'   => array( 'kind', 'slug', 'target_language' ),
				),
				'callback'            => array( $this, 'missing_relationships' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'annotations'         => array( 'title' => 'Find Missing WPML Translations', 'readOnlyHint' => true, 'openWorldHint' => false ),
			)
		);

		new RegisterMcpTool(
			array(
				'name'                => 'wpml_relationships_repair',
				'description'         => 'Link an existing translated post or term to a source WPML translation group. Defaults to dry-run.',
				'type'                => 'update',
				'inputSchema'         => array(
					'type'       => 'object',
					'properties' => array(
						'source_id'       => array( 'type' => 'integer', 'description' => 'Source post or term ID.' ),
						'target_id'       => array( 'type' => 'integer', 'description' => 'Target post or term ID.' ),
						'element_type'    => array( 'type' => 'string', 'description' => 'WPML element type, e.g. post_page, post_product, tax_product_cat.' ),
						'target_language' => array( 'type' => 'string', 'description' => 'Target language code.' ),
						'source_language' => array( 'type' => 'string', 'description' => 'Optional source language code. Defaults to WPML default language.' ),
						'dry_run'         => array( 'type' => 'boolean', 'description' => 'Preview without saving. Defaults to true.', 'default' => true ),
					),
					'required'   => array( 'source_id', 'target_id', 'element_type', 'target_language' ),
				),
				'callback'            => array( $this, 'repair_relationship' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'annotations'         => array( 'title' => 'Repair WPML Relationship', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
			)
		);
	}

	/**
	 * Get relationship information.
	 *
	 * @param array $params Input parameters.
	 * @return array
	 */
	public function get_relationships( array $params ): array {
		$id           = absint( $params['id'] ?? 0 );
		$element_type = sanitize_text_field( (string) ( $params['element_type'] ?? '' ) );

		return $this->relationship_summary( $id, $element_type );
	}

	/**
	 * Find missing translations.
	 *
	 * @param array $params Input parameters.
	 * @return array
	 */
	public function missing_relationships( array $params ): array {
		$kind            = sanitize_key( (string) ( $params['kind'] ?? '' ) );
		$slug            = sanitize_key( (string) ( $params['slug'] ?? '' ) );
		$target_language = sanitize_key( (string) ( $params['target_language'] ?? '' ) );
		$source_language = sanitize_key( (string) ( $params['source_language'] ?? apply_filters( 'wpml_default_language', null ) ) );
		$limit           = min( 500, max( 1, absint( $params['limit'] ?? 100 ) ) );

		if ( 'term' === $kind ) {
			return $this->missing_terms( $slug, $source_language, $target_language, $limit );
		}

		return $this->missing_posts( $slug, $source_language, $target_language, $limit );
	}

	/**
	 * Repair a relationship.
	 *
	 * @param array $params Input parameters.
	 * @return array
	 */
	public function repair_relationship( array $params ): array {
		$source_id       = absint( $params['source_id'] ?? 0 );
		$target_id       = absint( $params['target_id'] ?? 0 );
		$element_type    = sanitize_text_field( (string) ( $params['element_type'] ?? '' ) );
		$target_language = sanitize_key( (string) ( $params['target_language'] ?? '' ) );
		$source_language = sanitize_key( (string) ( $params['source_language'] ?? apply_filters( 'wpml_default_language', null ) ) );
		$dry_run         = ! array_key_exists( 'dry_run', $params ) || ! empty( $params['dry_run'] );
		$source          = $this->relationship_summary( $source_id, $element_type );
		$before          = $this->relationship_summary( $target_id, $element_type );
		$trid            = (int) ( $source['trid'] ?? 0 );

		if ( ! $trid ) {
			return $this->error( 'source_trid_missing', 'Source item does not have a WPML trid.' );
		}

		if ( ! $dry_run ) {
			do_action(
				'wpml_set_element_language_details',
				array(
					'element_id'           => $target_id,
					'element_type'         => $element_type,
					'trid'                 => $trid,
					'language_code'        => $target_language,
					'source_language_code' => $source_language,
				)
			);
		}

		return array(
			'dry_run' => $dry_run,
			'before'  => $before,
			'after'   => $dry_run ? null : $this->relationship_summary( $target_id, $element_type ),
			'planned' => array( 'target_id' => $target_id, 'element_type' => $element_type, 'trid' => $trid, 'language_code' => $target_language, 'source_language_code' => $source_language ),
		);
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

		return array(
			'id'           => $id,
			'element_type' => $element_type,
			'trid'         => $trid ? (int) $trid : null,
			'language'     => is_object( $language ) ? (array) $language : $language,
			'translations' => $this->format_translations( is_array( $items ) ? $items : array() ),
		);
	}

	/**
	 * Missing post translations.
	 *
	 * @param string $post_type Post type.
	 * @param string $source_language Source language.
	 * @param string $target_language Target language.
	 * @param int    $limit Limit.
	 * @return array
	 */
	private function missing_posts( string $post_type, string $source_language, string $target_language, int $limit ): array {
		$previous_language = (string) apply_filters( 'wpml_current_language', null );
		$this->switch_language( $source_language );
		$posts   = get_posts( array( 'post_type' => $post_type, 'post_status' => 'any', 'posts_per_page' => $limit, 'suppress_filters' => false ) );
		$missing = array();

		foreach ( $posts as $post ) {
			$summary = $this->relationship_summary( (int) $post->ID, 'post_' . $post_type );
			if ( $this->is_source_missing_target( $summary, $source_language, $target_language ) ) {
				$missing[] = array( 'id' => (int) $post->ID, 'title' => get_the_title( $post ), 'status' => $post->post_status, 'trid' => $summary['trid'] );
			}
		}

		$this->switch_language( $previous_language );

		return array( 'kind' => 'post', 'slug' => $post_type, 'source_language' => $source_language, 'target_language' => $target_language, 'count' => count( $missing ), 'missing' => $missing );
	}

	/**
	 * Missing term translations.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $source_language Source language.
	 * @param string $target_language Target language.
	 * @param int    $limit Limit.
	 * @return array
	 */
	private function missing_terms( string $taxonomy, string $source_language, string $target_language, int $limit ): array {
		$previous_language = (string) apply_filters( 'wpml_current_language', null );
		$this->switch_language( $source_language );
		$terms   = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => $limit ) );
		$missing = array();

		if ( is_wp_error( $terms ) ) {
			$this->switch_language( $previous_language );
			return $this->error( 'term_query_failed', $terms->get_error_message() );
		}

		foreach ( $terms as $term ) {
			$summary = $this->relationship_summary( (int) $term->term_taxonomy_id, 'tax_' . $taxonomy );
			if ( $this->is_source_missing_target( $summary, $source_language, $target_language ) ) {
				$missing[] = array( 'term_id' => (int) $term->term_id, 'term_taxonomy_id' => (int) $term->term_taxonomy_id, 'name' => $term->name, 'trid' => $summary['trid'] );
			}
		}

		$this->switch_language( $previous_language );

		return array( 'kind' => 'term', 'slug' => $taxonomy, 'source_language' => $source_language, 'target_language' => $target_language, 'count' => count( $missing ), 'missing' => $missing );
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
	 * Check whether a source item lacks target translation.
	 *
	 * @param array  $summary Relationship summary.
	 * @param string $source_language Source language.
	 * @param string $target_language Target language.
	 * @return bool
	 */
	private function is_source_missing_target( array $summary, string $source_language, string $target_language ): bool {
		$language = is_array( $summary['language'] ?? null ) ? (string) ( $summary['language']['language_code'] ?? '' ) : '';

		return $language === $source_language && empty( $summary['translations'][ $target_language ] );
	}

	/**
	 * Format translations.
	 *
	 * @param array $translations Translation objects.
	 * @return array
	 */
	private function format_translations( array $translations ): array {
		$output = array();

		foreach ( $translations as $language => $translation ) {
			$output[ $language ] = array(
				'element_id' => isset( $translation->element_id ) ? (int) $translation->element_id : 0,
				'original'   => ! empty( $translation->original ),
				'post_title' => isset( $translation->post_title ) ? (string) $translation->post_title : '',
			);
		}

		return $output;
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
