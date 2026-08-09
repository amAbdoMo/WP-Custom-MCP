<?php //phpcs:ignore
declare( strict_types=1 );

namespace Automattic\WordpressMcp\Tools;

use Automattic\WordpressMcp\Core\RegisterMcpTool;

defined( 'ABSPATH' ) || exit;

/**
 * Compact WPML translation profile for planning multilingual work.
 */
class McpWpmlSiteProfileTools {
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
				'name'                => 'wpml_site_profile',
				'description'         => 'Return a compact WPML/WooCommerce/Elementor/Woodmart translation profile for planning translation work with fewer discovery calls.',
				'type'                => 'read',
				'inputSchema'         => array(
					'type'       => 'object',
					'properties' => array(
						'include_counts' => array(
							'type'        => 'boolean',
							'description' => 'Include compact content counts. Defaults to true.',
							'default'     => true,
						),
					),
				),
				'callback'            => array( $this, 'site_profile' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'annotations'         => array(
					'title'         => 'WPML Site Profile',
					'readOnlyHint'  => true,
					'openWorldHint' => false,
				),
			)
		);
	}

	/**
	 * Build a compact multilingual site profile.
	 *
	 * @param array $params Input parameters.
	 * @return array
	 */
	public function site_profile( array $params = array() ): array {
		$include_counts = ! array_key_exists( 'include_counts', $params ) || ! empty( $params['include_counts'] );

		return array(
			'wpml'              => $this->wpml_summary(),
			'stack'             => $this->stack_summary(),
			'post_types'        => $this->post_types_summary( $include_counts ),
			'taxonomies'        => $this->taxonomies_summary(),
			'woocommerce_pages' => $this->woocommerce_pages_summary(),
			'elementor'         => $this->elementor_summary( $include_counts ),
			'woodmart'          => $this->woodmart_summary(),
			'menus'             => $this->menus_summary(),
			'next_tools'        => array(
				'wpml_relationships_missing',
				'wpml_string_effective_search',
				'wpml_elementor_manifest',
				'wpml_elementor_doc_inspect',
				'wc_wpml_catalog_translation_audit',
				'woodmart_wpml_profile',
				'woodmart_preset_application_diagnose',
				'wpml_checkout_translation_diagnose',
				'frontend_translation_scan',
			),
		);
	}

	/**
	 * WPML summary.
	 *
	 * @return array
	 */
	private function wpml_summary(): array {
		$settings = get_option( 'icl_sitepress_settings', array() );

		return array(
			'active'             => defined( 'ICL_SITEPRESS_VERSION' ) || has_filter( 'wpml_active_languages' ),
			'version'            => defined( 'ICL_SITEPRESS_VERSION' ) ? ICL_SITEPRESS_VERSION : null,
			'default_language'   => apply_filters( 'wpml_default_language', null ),
			'current_language'   => apply_filters( 'wpml_current_language', null ),
			'active_languages'   => apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ),
			'string_translation' => $this->wpml_string_translation_available(),
			'translation_mode'   => array(
				'custom_posts_sync' => isset( $settings['custom_posts_sync'] ) && is_array( $settings['custom_posts_sync'] ) ? $settings['custom_posts_sync'] : array(),
				'taxonomies_sync'   => isset( $settings['taxonomies_sync'] ) && is_array( $settings['taxonomies_sync'] ) ? $settings['taxonomies_sync'] : array(),
			),
		);
	}

	/**
	 * Installed stack summary.
	 *
	 * @return array
	 */
	private function stack_summary(): array {
		$theme = wp_get_theme();

		return array(
			'wordpress_version' => get_bloginfo( 'version' ),
			'theme'             => array(
				'name'     => $theme->get( 'Name' ),
				'template' => get_template(),
				'stylesheet' => get_stylesheet(),
				'version'  => $theme->get( 'Version' ),
			),
			'woocommerce'       => array(
				'active'  => class_exists( 'WooCommerce' ),
				'version' => defined( 'WC_VERSION' ) ? WC_VERSION : null,
			),
			'elementor'         => array(
				'active'      => defined( 'ELEMENTOR_VERSION' ),
				'version'     => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
				'pro_active'  => defined( 'ELEMENTOR_PRO_VERSION' ),
				'pro_version' => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null,
			),
			'woodmart'          => array(
				'active'  => 'woodmart' === get_template() || defined( 'WOODMART_VERSION' ),
				'version' => defined( 'WOODMART_VERSION' ) ? WOODMART_VERSION : null,
			),
		);
	}

	/**
	 * Post type summary.
	 *
	 * @param bool $include_counts Include counts.
	 * @return array
	 */
	private function post_types_summary( bool $include_counts ): array {
		$settings = get_option( 'icl_sitepress_settings', array() );
		$wpml     = isset( $settings['custom_posts_sync'] ) && is_array( $settings['custom_posts_sync'] ) ? $settings['custom_posts_sync'] : array();
		$output   = array();

		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $slug => $post_type ) {
			$output[ $slug ] = array(
				'label'     => $post_type->label,
				'rest_base' => $post_type->rest_base ?: $slug,
				'wpml_mode' => isset( $wpml[ $slug ] ) ? (int) $wpml[ $slug ] : null,
			);

			if ( $include_counts ) {
				$counts = wp_count_posts( $slug );
				$output[ $slug ]['counts'] = $counts ? (array) $counts : array();
			}
		}

		return $output;
	}

	/**
	 * Taxonomy summary.
	 *
	 * @return array
	 */
	private function taxonomies_summary(): array {
		$settings = get_option( 'icl_sitepress_settings', array() );
		$wpml     = isset( $settings['taxonomies_sync'] ) && is_array( $settings['taxonomies_sync'] ) ? $settings['taxonomies_sync'] : array();
		$output   = array();

		foreach ( get_taxonomies( array( 'show_ui' => true ), 'objects' ) as $slug => $taxonomy ) {
			$output[ $slug ] = array(
				'label'        => $taxonomy->label,
				'hierarchical' => (bool) $taxonomy->hierarchical,
				'object_type'  => $taxonomy->object_type,
				'wpml_mode'    => isset( $wpml[ $slug ] ) ? (int) $wpml[ $slug ] : null,
			);
		}

		return $output;
	}

	/**
	 * WooCommerce page summary.
	 *
	 * @return array
	 */
	private function woocommerce_pages_summary(): array {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return array( 'available' => false );
		}

		$output = array( 'available' => true );
		foreach ( array( 'shop', 'cart', 'checkout', 'myaccount' ) as $page ) {
			$id = (int) wc_get_page_id( $page );
			$output[ $page ] = array(
				'id'           => $id,
				'title'        => $id > 0 ? get_the_title( $id ) : '',
				'translations' => $id > 0 ? $this->translations_for_post( $id, 'page' ) : array(),
			);
		}

		return $output;
	}

	/**
	 * Elementor summary.
	 *
	 * @param bool $include_counts Include counts.
	 * @return array
	 */
	private function elementor_summary( bool $include_counts ): array {
		$output = array(
			'active' => defined( 'ELEMENTOR_VERSION' ),
		);

		if ( $include_counts ) {
			$output['elementor_library_count'] = (int) wp_count_posts( 'elementor_library' )->publish;
			$output['woodmart_layout_count']   = post_type_exists( 'woodmart_layout' ) ? (int) wp_count_posts( 'woodmart_layout' )->publish : 0;
		}

		return $output;
	}

	/**
	 * Woodmart summary.
	 *
	 * @return array
	 */
	private function woodmart_summary(): array {
		$presets = get_option( 'xts-options-presets', array() );

		return array(
			'active'        => 'woodmart' === get_template() || defined( 'WOODMART_VERSION' ),
			'version'       => defined( 'WOODMART_VERSION' ) ? WOODMART_VERSION : null,
			'layout_cpt'    => post_type_exists( 'woodmart_layout' ),
			'preset_count'  => is_array( $presets ) ? count( $presets ) : 0,
			'preset_names'  => is_array( $presets ) ? wp_list_pluck( $presets, 'name' ) : array(),
		);
	}

	/**
	 * Menu summary.
	 *
	 * @return array
	 */
	private function menus_summary(): array {
		$output = array();
		foreach ( get_nav_menu_locations() as $location => $menu_id ) {
			$menu = wp_get_nav_menu_object( $menu_id );
			$output[ $location ] = array(
				'menu_id' => (int) $menu_id,
				'name'    => $menu ? $menu->name : '',
			);
		}

		return $output;
	}

	/**
	 * Post translations.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $post_type Post type.
	 * @return array
	 */
	private function translations_for_post( int $post_id, string $post_type ): array {
		$trid = apply_filters( 'wpml_element_trid', null, $post_id, 'post_' . $post_type );
		if ( ! $trid ) {
			return array();
		}

		$translations = apply_filters( 'wpml_get_element_translations', null, $trid, 'post_' . $post_type );
		$output       = array();

		if ( is_array( $translations ) ) {
			foreach ( $translations as $language => $translation ) {
				$output[ $language ] = array(
					'element_id' => isset( $translation->element_id ) ? (int) $translation->element_id : 0,
					'original'   => ! empty( $translation->original ),
				);
			}
		}

		return $output;
	}

	/**
	 * Check WPML String Translation table availability.
	 *
	 * @return bool
	 */
	private function wpml_string_translation_available(): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'icl_strings';
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
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
