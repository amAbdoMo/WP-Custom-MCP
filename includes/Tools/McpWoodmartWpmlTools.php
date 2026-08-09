<?php //phpcs:ignore
declare( strict_types=1 );

namespace Automattic\WordpressMcp\Tools;

use Automattic\WordpressMcp\Core\RegisterMcpTool;

defined( 'ABSPATH' ) || exit;

/**
 * Woodmart/WPML diagnostics for layouts, presets, options, and generated CSS.
 */
class McpWoodmartWpmlTools {
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
				'name'                => 'woodmart_wpml_profile',
				'description'         => 'Inspect Woodmart/WPML state: layouts CPT, translated layouts, presets, preset priorities/conditions, key options, and generated CSS risk areas.',
				'type'                => 'read',
				'inputSchema'         => array( 'type' => 'object' ),
				'callback'            => array( $this, 'profile' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'annotations'         => array( 'title' => 'Woodmart WPML Profile', 'readOnlyHint' => true, 'openWorldHint' => false ),
			)
		);

		new RegisterMcpTool(
			array(
				'name'                => 'woodmart_presets_evaluate_url',
				'description'         => 'Fetch a frontend URL and evaluate Woodmart preset conditions/priority collisions against detected RTL/shop/cart/checkout/page signals.',
				'type'                => 'read',
				'inputSchema'         => array(
					'type'       => 'object',
					'properties' => array(
						'url' => array( 'type' => 'string', 'description' => 'Frontend URL to inspect.' ),
					),
					'required'   => array( 'url' ),
				),
				'callback'            => array( $this, 'evaluate_url' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'annotations'         => array( 'title' => 'Evaluate Woodmart Presets For URL', 'readOnlyHint' => true, 'openWorldHint' => false ),
			)
		);

		new RegisterMcpTool(
			array(
				'name'                => 'woodmart_generated_css_scan',
				'description'         => 'Fetch a URL and scan inline/local CSS for Woodmart theme-setting variables and font/button definitions.',
				'type'                => 'read',
				'inputSchema'         => array(
					'type'       => 'object',
					'properties' => array(
						'url'       => array( 'type' => 'string', 'description' => 'Frontend URL to inspect.' ),
						'variables' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Optional CSS variables to search for.' ),
					),
					'required'   => array( 'url' ),
				),
				'callback'            => array( $this, 'generated_css_scan' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'annotations'         => array( 'title' => 'Scan Woodmart Generated CSS', 'readOnlyHint' => true, 'openWorldHint' => false ),
			)
		);

		new RegisterMcpTool(
			array(
				'name'                => 'woodmart_preset_application_diagnose',
				'description'         => 'Diagnose why a Woodmart preset or generated theme-settings CSS is or is not applying on a frontend URL.',
				'type'                => 'read',
				'inputSchema'         => array(
					'type'       => 'object',
					'properties' => array(
						'url'               => array( 'type' => 'string', 'description' => 'Frontend URL to inspect.' ),
						'expected_preset_id'=> array( 'type' => 'integer', 'description' => 'Optional expected Woodmart preset ID.' ),
						'expected_handle'   => array( 'type' => 'string', 'description' => 'Optional expected theme settings CSS handle.' ),
					),
					'required'   => array( 'url' ),
				),
				'callback'            => array( $this, 'preset_application_diagnose' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'annotations'         => array( 'title' => 'Diagnose Woodmart Preset Application', 'readOnlyHint' => true, 'openWorldHint' => false ),
			)
		);
	}

	/**
	 * Build Woodmart/WPML profile.
	 *
	 * @return array
	 */
	public function profile( array $params = array() ): array {
		return array(
			'active'          => 'woodmart' === get_template() || defined( 'WOODMART_VERSION' ),
			'version'         => defined( 'WOODMART_VERSION' ) ? WOODMART_VERSION : null,
			'theme'           => array( 'template' => get_template(), 'stylesheet' => get_stylesheet() ),
			'layouts'         => $this->layouts_summary(),
			'presets'         => $this->presets_summary(),
			'option_keys'     => $this->option_keys_summary(),
			'wpml'            => array(
				'default_language' => apply_filters( 'wpml_default_language', null ),
				'current_language' => apply_filters( 'wpml_current_language', null ),
			),
			'known_risks'     => array(
				'Woodmart layouts are Elementor-backed CPTs and need WPML-linked translated layouts.',
				'Preset admin values are not enough; verify active preset output and generated CSS on the frontend.',
				'Preset priority collisions can prevent expected RTL/language presets from applying.',
				'Button/font CSS variables may be global and override target-language typography.',
			),
			'next_tools'      => array( 'woodmart_presets_evaluate_url', 'woodmart_generated_css_scan', 'wpml_elementor_doc_stability_check' ),
		);
	}

	/**
	 * Evaluate presets for a URL.
	 *
	 * @param array $params Input parameters.
	 * @return array
	 */
	public function evaluate_url( array $params ): array {
		$url      = esc_url_raw( (string) ( $params['url'] ?? '' ) );
		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) ) {
			return $this->error( 'fetch_failed', $response->get_error_message() );
		}

		$html    = (string) wp_remote_retrieve_body( $response );
		$signals = $this->url_signals( $url, $html );
		$presets = $this->presets_raw();
		$results = array();
		$active_by_priority = array();

		foreach ( $presets as $id => $preset ) {
			$rules   = isset( $preset['condition']['rules'] ) && is_array( $preset['condition']['rules'] ) ? $preset['condition']['rules'] : array();
			$matches = array();

			foreach ( $rules as $rule ) {
				$matches[] = $this->evaluate_rule( $rule, $signals );
			}

			$is_active = in_array( true, wp_list_pluck( $matches, 'active' ), true );
			$priority  = isset( $preset['priority'] ) ? (string) $preset['priority'] : '';
			if ( $is_active ) {
				$active_by_priority[ '' === $priority ? '0' : $priority ][] = (int) $id;
			}

			$results[] = array(
				'id'       => (int) $id,
				'name'     => isset( $preset['name'] ) ? (string) $preset['name'] : '',
				'priority' => $priority,
				'active'   => $is_active,
				'rules'    => $matches,
			);
		}

		$collisions = array_filter(
			$active_by_priority,
			static function ( array $ids ): bool {
				return count( $ids ) > 1;
			}
		);

		return array(
			'url'                 => $url,
			'signals'             => $signals,
			'active_by_priority'  => $active_by_priority,
			'priority_collisions' => $collisions,
			'presets'             => $results,
			'css_handles'         => $this->theme_settings_style_ids( $html ),
		);
	}

	/**
	 * Scan generated CSS for variables.
	 *
	 * @param array $params Input parameters.
	 * @return array
	 */
	public function generated_css_scan( array $params ): array {
		$url       = esc_url_raw( (string) ( $params['url'] ?? '' ) );
		$variables = isset( $params['variables'] ) && is_array( $params['variables'] ) ? array_map( 'sanitize_text_field', $params['variables'] ) : array();
		$response  = wp_remote_get( $url, array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) ) {
			return $this->error( 'fetch_failed', $response->get_error_message() );
		}

		$html    = (string) wp_remote_retrieve_body( $response );
		$entries = array();

		foreach ( $this->inline_styles( $html ) as $style ) {
			$scan = $this->scan_css_text( $style['css'], $variables );
			if ( ! empty( $scan['variables'] ) || $scan['cairo_count'] || $scan['gothic_count'] ) {
				$entries[] = array_merge( array( 'source' => $style['id'], 'type' => 'inline' ), $scan );
			}
		}

		return array(
			'url'          => $url,
			'entries'      => $entries,
			'style_ids'    => $this->theme_settings_style_ids( $html ),
			'html_signals' => $this->url_signals( $url, $html ),
		);
	}

	/**
	 * Diagnose preset application for a URL.
	 *
	 * @param array $params Input parameters.
	 * @return array
	 */
	public function preset_application_diagnose( array $params ): array {
		$url               = esc_url_raw( (string) ( $params['url'] ?? '' ) );
		$expected_preset_id = absint( $params['expected_preset_id'] ?? 0 );
		$expected_handle    = sanitize_text_field( (string) ( $params['expected_handle'] ?? '' ) );
		$evaluation         = $this->evaluate_url( array( 'url' => $url ) );
		$css_scan           = $this->generated_css_scan( array( 'url' => $url ) );
		$active_ids         = array();

		foreach ( $evaluation['active_by_priority'] ?? array() as $ids ) {
			$active_ids = array_merge( $active_ids, array_map( 'intval', $ids ) );
		}

		return array(
			'url'                 => $url,
			'expected_preset_id'  => $expected_preset_id ?: null,
			'expected_handle'     => $expected_handle,
			'signals'             => $evaluation['signals'] ?? array(),
			'active_preset_ids'   => array_values( array_unique( $active_ids ) ),
			'priority_collisions' => $evaluation['priority_collisions'] ?? array(),
			'css_handles'         => $evaluation['css_handles'] ?? array(),
			'css_entries'         => $css_scan['entries'] ?? array(),
			'diagnosis'           => $this->preset_diagnosis_messages( $evaluation, $css_scan, $expected_preset_id, $expected_handle ),
		);
	}

	/**
	 * Summarize Woodmart layouts.
	 *
	 * @return array
	 */
	private function layouts_summary(): array {
		if ( ! post_type_exists( 'woodmart_layout' ) ) {
			return array( 'available' => false );
		}

		$posts  = get_posts( array( 'post_type' => 'woodmart_layout', 'post_status' => 'any', 'posts_per_page' => 100, 'suppress_filters' => false ) );
		$output = array( 'available' => true, 'items' => array() );

		foreach ( $posts as $post ) {
			$output['items'][] = array(
				'id'           => (int) $post->ID,
				'title'        => get_the_title( $post ),
				'slug'         => $post->post_name,
				'status'       => $post->post_status,
				'layout_type'  => get_post_meta( $post->ID, 'wd_layout_type', true ),
				'wpml'         => $this->post_wpml_summary( (int) $post->ID, 'woodmart_layout' ),
				'elementor'    => array(
					'has_data' => '' !== (string) get_post_meta( $post->ID, '_elementor_data', true ),
					'css'      => get_post_meta( $post->ID, '_elementor_css', true ) ? 'present' : 'missing',
				),
			);
		}

		return $output;
	}

	/**
	 * Summarize presets.
	 *
	 * @return array
	 */
	private function presets_summary(): array {
		$output = array();
		foreach ( $this->presets_raw() as $id => $preset ) {
			$output[] = array(
				'id'        => (int) $id,
				'name'      => isset( $preset['name'] ) ? (string) $preset['name'] : '',
				'priority'  => isset( $preset['priority'] ) ? (string) $preset['priority'] : '',
				'condition' => $preset['condition'] ?? array(),
			);
		}

		return $output;
	}

	/**
	 * Key option summary.
	 *
	 * @return array
	 */
	private function option_keys_summary(): array {
		$options = get_option( 'xts-woodmart-options', array() );
		if ( ! is_array( $options ) ) {
			return array();
		}

		$keys = array( 'text-font', 'primary-font', 'post-titles-font', 'secondary-font', 'widget-titles-font', 'navigation-font', 'btns_default_typography', 'btns_shop_typography', 'advanced_typography_button' );
		$out  = array();

		foreach ( $keys as $key ) {
			$out[ $key ] = array_key_exists( $key, $options ) ? $this->short_value( $options[ $key ] ) : null;
		}

		return $out;
	}

	/**
	 * Build diagnosis messages.
	 *
	 * @param array  $evaluation Preset evaluation.
	 * @param array  $css_scan CSS scan.
	 * @param int    $expected_preset_id Expected preset ID.
	 * @param string $expected_handle Expected CSS handle.
	 * @return array
	 */
	private function preset_diagnosis_messages( array $evaluation, array $css_scan, int $expected_preset_id, string $expected_handle ): array {
		$messages   = array();
		$active_ids = array();
		$handles    = $evaluation['css_handles'] ?? array();

		foreach ( $evaluation['active_by_priority'] ?? array() as $ids ) {
			$active_ids = array_merge( $active_ids, array_map( 'intval', $ids ) );
		}

		if ( $expected_preset_id && ! in_array( $expected_preset_id, $active_ids, true ) ) {
			$messages[] = 'Expected preset ID is not active for this URL according to static condition evaluation.';
		}

		if ( $expected_handle && ! in_array( $expected_handle, $handles, true ) ) {
			$messages[] = 'Expected Woodmart generated CSS handle is not present in the rendered page.';
		}

		if ( ! empty( $evaluation['priority_collisions'] ) ) {
			$messages[] = 'Multiple active presets share a priority. Resolve priority collisions before changing typography/options.';
		}

		if ( empty( $handles ) ) {
			$messages[] = 'No Woodmart theme_settings CSS handle was detected. Generated CSS may be disabled, cached differently, or loaded from an unexpected handle.';
		}

		if ( empty( $css_scan['entries'] ) ) {
			$messages[] = 'No scanned inline theme-setting variables/fonts were found. Inspect external CSS generation/cache settings.';
		}

		return empty( $messages ) ? array( 'No obvious preset/CSS application blocker detected by static scan.' ) : $messages;
	}

	/**
	 * Evaluate one preset rule against URL signals.
	 *
	 * @param array $rule Rule.
	 * @param array $signals URL signals.
	 * @return array
	 */
	private function evaluate_rule( array $rule, array $signals ): array {
		$type       = (string) ( $rule['type'] ?? '' );
		$comparison = (string) ( $rule['comparison'] ?? 'equals' );
		$condition  = false;
		$reason     = 'Unsupported rule type for static URL evaluation.';

		if ( 'custom' === $type ) {
			$custom = (string) ( $rule['custom'] ?? '' );
			switch ( $custom ) {
				case 'is_rtl':
					$condition = ! empty( $signals['is_rtl'] );
					$reason    = 'Detected RTL from html dir/body class.';
					break;
				case 'shop':
					$condition = ! empty( $signals['is_shop'] );
					$reason    = 'Detected shop/archive markers.';
					break;
				case 'cart':
					$condition = ! empty( $signals['is_cart'] );
					$reason    = 'Detected cart markers.';
					break;
				case 'checkout':
					$condition = ! empty( $signals['is_checkout'] );
					$reason    = 'Detected checkout markers.';
					break;
			}
		}

		$active = 'not_equals' === $comparison ? ! $condition : $condition;

		return array(
			'rule'      => $rule,
			'condition' => $condition,
			'active'    => $active,
			'reason'    => $reason,
		);
	}

	/**
	 * Extract URL/page signals from HTML.
	 *
	 * @param string $url URL.
	 * @param string $html HTML.
	 * @return array
	 */
	private function url_signals( string $url, string $html ): array {
		$lower = strtolower( $html );

		return array(
			'url'         => $url,
			'is_rtl'      => str_contains( $lower, 'dir="rtl"' ) || str_contains( $lower, 'class="rtl' ) || str_contains( $lower, ' rtl ' ),
			'is_shop'     => str_contains( $lower, 'woocommerce-shop' ) || str_contains( $lower, 'post-type-archive-product' ),
			'is_cart'     => str_contains( $lower, 'woocommerce-cart' ) || str_contains( $lower, 'woocommerce-cart-form' ),
			'is_checkout' => str_contains( $lower, 'woocommerce-checkout' ) || str_contains( $lower, 'place_order' ),
			'lang'        => $this->html_attr( $html, 'html', 'lang' ),
			'dir'         => $this->html_attr( $html, 'html', 'dir' ),
		);
	}

	/**
	 * Scan inline styles.
	 *
	 * @param string $html HTML.
	 * @return array
	 */
	private function inline_styles( string $html ): array {
		preg_match_all( '/<style([^>]*)>(.*?)<\/style>/is', $html, $matches, PREG_SET_ORDER );
		$out = array();

		foreach ( $matches as $match ) {
			$out[] = array(
				'id'  => $this->attr_from_string( $match[1], 'id' ) ?: 'inline-style',
				'css' => html_entity_decode( $match[2] ),
			);
		}

		return $out;
	}

	/**
	 * Scan CSS text.
	 *
	 * @param string $css CSS.
	 * @param array  $variables Variables.
	 * @return array
	 */
	private function scan_css_text( string $css, array $variables ): array {
		$found = array();
		if ( empty( $variables ) ) {
			preg_match_all( '/--(?:wd|btn)-[a-z0-9\-]+\s*:\s*[^;]+;/i', $css, $matches );
			$found = array_slice( array_values( array_unique( $matches[0] ?? array() ) ), 0, 80 );
		} else {
			foreach ( $variables as $variable ) {
				$quoted = preg_quote( $variable, '/' );
				if ( preg_match_all( '/' . $quoted . '\s*:\s*[^;]+;/i', $css, $matches ) ) {
					$found = array_merge( $found, $matches[0] );
				}
			}
		}

		return array(
			'variables'    => array_values( array_unique( $found ) ),
			'cairo_count'  => substr_count( $css, 'Cairo' ),
			'gothic_count' => substr_count( $css, 'Gothic A1' ),
		);
	}

	/**
	 * Find theme settings style IDs.
	 *
	 * @param string $html HTML.
	 * @return array
	 */
	private function theme_settings_style_ids( string $html ): array {
		preg_match_all( '/<(?:style|link)[^>]+id=["\']([^"\']*theme_settings[^"\']*)["\'][^>]*>/i', $html, $matches );
		return array_values( array_unique( $matches[1] ?? array() ) );
	}

	/**
	 * WPML post summary.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $post_type Post type.
	 * @return array
	 */
	private function post_wpml_summary( int $post_id, string $post_type ): array {
		$trid         = apply_filters( 'wpml_element_trid', null, $post_id, 'post_' . $post_type );
		$translations = $trid ? apply_filters( 'wpml_get_element_translations', null, $trid, 'post_' . $post_type ) : array();
		$out          = array( 'trid' => $trid ? (int) $trid : null, 'translations' => array() );

		if ( is_array( $translations ) ) {
			foreach ( $translations as $language => $translation ) {
				$out['translations'][ $language ] = isset( $translation->element_id ) ? (int) $translation->element_id : 0;
			}
		}

		return $out;
	}

	/**
	 * Raw presets option.
	 *
	 * @return array
	 */
	private function presets_raw(): array {
		$presets = get_option( 'xts-options-presets', array() );
		return is_array( $presets ) ? $presets : array();
	}

	/**
	 * Return shortened value for output.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function short_value( $value ) {
		if ( is_array( $value ) ) {
			$encoded = wp_json_encode( $value );
			return array(
				'type'    => 'array',
				'hash'    => is_string( $encoded ) ? md5( $encoded ) : '',
				'preview' => array_slice( $value, 0, 3, true ),
			);
		}

		return $value;
	}

	/**
	 * Get an attribute from an HTML tag.
	 *
	 * @param string $html HTML.
	 * @param string $tag Tag.
	 * @param string $attr Attribute.
	 * @return string
	 */
	private function html_attr( string $html, string $tag, string $attr ): string {
		if ( preg_match( '/<' . preg_quote( $tag, '/' ) . '\b([^>]*)>/i', $html, $match ) ) {
			return $this->attr_from_string( $match[1], $attr );
		}

		return '';
	}

	/**
	 * Extract attribute from a tag attribute string.
	 *
	 * @param string $attrs Attribute string.
	 * @param string $attr Attribute name.
	 * @return string
	 */
	private function attr_from_string( string $attrs, string $attr ): string {
		if ( preg_match( '/' . preg_quote( $attr, '/' ) . '=["\']([^"\']*)["\']/i', $attrs, $match ) ) {
			return html_entity_decode( $match[1] );
		}

		return '';
	}

	/**
	 * Build error response.
	 *
	 * @param string $code Code.
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
