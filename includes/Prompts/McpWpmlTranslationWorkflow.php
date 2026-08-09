<?php //phpcs:ignore
declare( strict_types=1 );

namespace Automattic\WordpressMcp\Prompts;

use Automattic\WordpressMcp\Core\RegisterMcpPrompt;

defined( 'ABSPATH' ) || exit;

/**
 * Prompt for safe WPML, WooCommerce, Elementor, and Woodmart translation work.
 */
class McpWpmlTranslationWorkflow {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wordpress_mcp_init', array( $this, 'register_prompt' ) );
	}

	/**
	 * Register the prompt.
	 */
	public function register_prompt(): void {
		new RegisterMcpPrompt(
			array(
				'name'        => 'wpml-woocommerce-elementor-translation-workflow',
				'description' => 'Plan and execute safe multilingual WordPress translation work across WPML, WooCommerce, Elementor, Woodmart, menus, products, checkout, and custom post types.',
				'arguments'   => array(
					array(
						'name'        => 'source_language',
						'description' => 'Source language code, if known. If unknown, discover it first.',
						'required'    => false,
						'type'        => 'string',
					),
					array(
						'name'        => 'target_language',
						'description' => 'Target language code, if known. If unknown, discover it first.',
						'required'    => false,
						'type'        => 'string',
					),
					array(
						'name'        => 'task_scope',
						'description' => 'Scope such as full-site, menus, products, checkout, Elementor templates, Woodmart layouts/options, pages, CPTs, strings, or verification.',
						'required'    => false,
						'type'        => 'string',
					),
				),
			),
			$this->messages()
		);
	}

	/**
	 * Get the prompt messages.
	 *
	 * @return array
	 */
	public function messages(): array {
		$workflow = <<<PROMPT
You are working on a multilingual WordPress site using WPML, WooCommerce, Elementor, and possibly Woodmart.

Task scope: {{task_scope}}
Source language: {{source_language}}
Target language: {{target_language}}

Modes:
- manifest: discover and report the translation manifest only. Do not edit.
- full-site: discover, translate safely, verify, and report.
- products: focus on WooCommerce products, terms, brands, and product templates.
- checkout: focus on cart, checkout, account, gateway, field, and policy strings.
- elementor: focus on Elementor-backed pages/templates/layouts.
- woodmart: focus on Woodmart layouts, widgets, options, presets, and generated CSS.
- verify: scan frontend output and report remaining issues only.

Use this token-efficient workflow:
1. Start with wpml_site_profile. Discover languages, post types, WooCommerce pages, Elementor availability, Woodmart state, and menus from WPML/site data. Do not ask the user for languages unless WPML is not configured or multiple target languages require a choice.
2. Load wpml_translation_know_how for reusable rules. Use the focused know-how area matching the scope when possible.
3. Build a compact manifest. Separate terms, posts/pages, products, Elementor documents, strings, menus, WooCommerce pages, Woodmart layouts, and theme options.
4. Use wpml_relationships_missing and wpml_relationships_get to find missing or broken WPML links. WPML relationships are source of truth. Never create standalone translated products/pages/layouts when WPML-linked duplicates are expected.
5. For WooCommerce stores, run wc_wpml_catalog_translation_audit before product edits. Preserve SKU, type, price, stock, images, translated terms, visibility, and Woo meta.
6. For Elementor, run wpml_elementor_manifest first, then wpml_elementor_doc_inspect and wpml_elementor_doc_stability_check for documents being edited. Preserve JSON shape, widget IDs, repeater _id values, template IDs, popup IDs, loop IDs, selectors, and dynamic tag structures.
7. For visible strings, use wpml_string_effective_search first and update only the effective row with wpml_string_effective_update. Do not trust PO-import duplicates unless the effective frontend row is confirmed.
8. For WooCommerce checkout, run wpml_checkout_translation_diagnose. Keep cart/checkout/my-account pages shortcode/block based. Checkout verification requires a real cart session.
9. For Woodmart, run woodmart_wpml_profile before changing layouts, widgets, theme options, typography, presets, or generated CSS. Use woodmart_preset_application_diagnose, woodmart_presets_evaluate_url, and woodmart_generated_css_scan before any preset/typography/design change.
10. Verify frontend with frontend_translation_scan and browser checks. Ignore expected artifacts: hreflang, x-default, URLs, emails, filenames, brand names, currency codes, and language switch labels.

Stop and ask before high-risk operations:
- changing global theme options or Woodmart typography/presets
- deleting translations or translation-management rows
- changing source-language content in bulk
- replacing checkout/cart/account shortcode/block content
- creating snippets, MU plugins, custom plugins, theme patches, or CSS workarounds
- applying any non-dry-run bulk repair when the manifest has not been reviewed

Report format: profile summary, manifest summary, high-risk gates, changes made, verification URLs/results, skipped items, remaining blockers, and next tool/action.
PROMPT;

		return array(
			array(
				'role'    => 'user',
				'content' => array(
					'type' => 'text',
					'text' => $workflow,
				),
			),
		);
	}
}
