<?php //phpcs:ignore
declare( strict_types=1 );

namespace Automattic\WordpressMcp\Tools;

use Automattic\WordpressMcp\Core\RegisterMcpTool;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only checkout translation diagnostics for WPML/WooCommerce sites.
 */
class McpWpmlCheckoutTranslationTools {
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
				'name'                => 'wpml_checkout_translation_diagnose',
				'description'         => 'Diagnose WooCommerce checkout translation sources: WPML languages, WooCommerce page content, checkout fields, gateways, and static rendered checkout snapshots.',
				'type'                => 'read',
				'inputSchema'         => array(
					'type'       => 'object',
					'properties' => array(
						'language' => array(
							'type'        => 'string',
							'description' => 'Optional language code to switch to before inspecting language-aware labels.',
						),
					),
				),
				'callback'            => array( $this, 'diagnose_checkout' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'annotations'         => array(
					'title'         => 'Diagnose WPML Checkout Translation',
					'readOnlyHint'  => true,
					'openWorldHint' => false,
				),
			)
		);
	}

	/**
	 * Diagnose checkout translation state.
	 *
	 * @param array $params Input parameters.
	 * @return array
	 */
	public function diagnose_checkout( array $params = array() ): array {
		$language = isset( $params['language'] ) ? sanitize_key( (string) $params['language'] ) : '';

		if ( $language && has_action( 'wpml_switch_language' ) ) {
			do_action( 'wpml_switch_language', $language );
		}

		$checkout_page_id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'checkout' ) : 0;
		$cart_page_id     = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'cart' ) : 0;
		$account_page_id  = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'myaccount' ) : 0;

		return array(
			'language'           => array(
				'requested' => $language,
				'current'   => apply_filters( 'wpml_current_language', null ),
				'default'   => apply_filters( 'wpml_default_language', null ),
				'active'    => apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ),
			),
			'woocommerce_pages'  => array(
				'checkout'  => $this->page_summary( $checkout_page_id ),
				'cart'      => $this->page_summary( $cart_page_id ),
				'myaccount' => $this->page_summary( $account_page_id ),
			),
			'checkout_fields'    => $this->checkout_fields_summary(),
			'payment_gateways'   => $this->payment_gateways_summary(),
			'likely_root_causes' => $this->likely_root_causes( $checkout_page_id ),
		);
	}

	/**
	 * Summarize a WooCommerce system page.
	 *
	 * @param int $page_id Page ID.
	 * @return array
	 */
	private function page_summary( int $page_id ): array {
		$post    = $page_id > 0 ? get_post( $page_id ) : null;
		$content = $post ? (string) $post->post_content : '';

		return array(
			'id'                         => $page_id,
			'exists'                     => (bool) $post,
			'title'                      => $post ? get_the_title( $page_id ) : '',
			'slug'                       => $post ? $post->post_name : '',
			'wpml'                       => $post ? $this->language_info( $page_id, $post->post_type ) : array(),
			'content_hash'               => '' !== $content ? md5( $content ) : '',
			'content_length'             => strlen( $content ),
			'has_checkout_shortcode'     => has_shortcode( $content, 'woocommerce_checkout' ),
			'has_cart_shortcode'         => has_shortcode( $content, 'woocommerce_cart' ),
			'has_myaccount_shortcode'    => has_shortcode( $content, 'woocommerce_my_account' ),
			'has_woocommerce_block'      => str_contains( $content, 'wp:woocommerce/' ),
			'looks_like_static_checkout' => $this->looks_like_static_checkout( $content ),
			'content_preview'            => substr( wp_strip_all_tags( $content ), 0, 300 ),
		);
	}

	/**
	 * Detect static rendered checkout HTML snapshots.
	 *
	 * @param string $content Page content.
	 * @return bool
	 */
	private function looks_like_static_checkout( string $content ): bool {
		return str_contains( $content, 'woocommerce-checkout-review-order-table' )
			|| str_contains( $content, 'woocommerce-billing-fields' )
			|| str_contains( $content, 'place_order' );
	}

	/**
	 * Summarize checkout fields after filters.
	 *
	 * @return array
	 */
	private function checkout_fields_summary(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->checkout() ) {
			return array( 'available' => false );
		}

		$fields  = WC()->checkout()->get_checkout_fields();
		$summary = array( 'available' => true );

		foreach ( $fields as $section => $section_fields ) {
			$summary[ $section ] = array();

			foreach ( $section_fields as $key => $field ) {
				$summary[ $section ][ $key ] = array(
					'label'       => isset( $field['label'] ) ? (string) $field['label'] : '',
					'placeholder' => isset( $field['placeholder'] ) ? (string) $field['placeholder'] : '',
					'required'    => ! empty( $field['required'] ),
					'type'        => isset( $field['type'] ) ? (string) $field['type'] : '',
				);
			}
		}

		return $summary;
	}

	/**
	 * Summarize payment gateway labels/descriptions.
	 *
	 * @return array
	 */
	private function payment_gateways_summary(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return array( 'available' => false );
		}

		$summary  = array( 'available' => true );
		$gateways = WC()->payment_gateways()->payment_gateways();

		foreach ( $gateways as $id => $gateway ) {
			$summary[ $id ] = array(
				'enabled'     => isset( $gateway->enabled ) ? (string) $gateway->enabled : '',
				'title'       => method_exists( $gateway, 'get_title' ) ? (string) $gateway->get_title() : '',
				'description' => method_exists( $gateway, 'get_description' ) ? wp_strip_all_tags( (string) $gateway->get_description() ) : '',
			);
		}

		return $summary;
	}

	/**
	 * Suggest likely root causes from page content.
	 *
	 * @param int $checkout_page_id Checkout page ID.
	 * @return string[]
	 */
	private function likely_root_causes( int $checkout_page_id ): array {
		$post    = $checkout_page_id > 0 ? get_post( $checkout_page_id ) : null;
		$content = $post ? (string) $post->post_content : '';
		$causes  = array();

		if ( '' === $content ) {
			$causes[] = 'Checkout page is missing or has empty content.';
		} elseif ( ! has_shortcode( $content, 'woocommerce_checkout' ) && ! str_contains( $content, 'wp:woocommerce/' ) ) {
			$causes[] = 'Checkout page does not appear to use a WooCommerce checkout shortcode or block.';
		}

		if ( $this->looks_like_static_checkout( $content ) ) {
			$causes[] = 'Checkout page appears to contain rendered checkout HTML; replace it with shortcode or block content.';
		}

		$causes[] = 'If page content is correct, inspect checkout field editor plugin options, payment gateway settings, and WPML String Translation entries.';
		$causes[] = 'Full checkout label verification needs a real cart session because empty checkout usually redirects to cart.';

		return $causes;
	}

	/**
	 * Get WPML relationship information when WPML is active.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $post_type Post type.
	 * @return array
	 */
	private function language_info( int $post_id, string $post_type ): array {
		$details = apply_filters( 'wpml_post_language_details', null, $post_id );
		$element = 'post_' . $post_type;
		$trid    = apply_filters( 'wpml_element_trid', null, $post_id, $element );

		return array(
			'details' => is_array( $details ) ? $details : array(),
			'trid'    => $trid ? (int) $trid : null,
		);
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
