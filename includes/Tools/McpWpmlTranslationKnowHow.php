<?php //phpcs:ignore
declare( strict_types=1 );

namespace Automattic\WordpressMcp\Tools;

use Automattic\WordpressMcp\Core\RegisterMcpTool;

defined( 'ABSPATH' ) || exit;

/**
 * Reusable WPML/WooCommerce/Elementor translation know-how for MCP clients.
 */
class McpWpmlTranslationKnowHow {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wordpress_mcp_init', array( $this, 'register_tools' ) );
	}

	/**
	 * Register the tool.
	 */
	public function register_tools(): void {
		new RegisterMcpTool(
			array(
				'name'                => 'wpml_translation_know_how',
				'description'         => 'Reusable know-how for safely translating WPML, WooCommerce, Elementor, menus, templates, products, checkout, and custom post types across WordPress sites.',
				'type'                => 'read',
				'inputSchema'         => array(
					'type'       => 'object',
					'properties' => array(
						'focus' => array(
							'type'        => 'string',
							'description' => 'Optional area to focus on: discovery, wpml, menus, elementor, elementor_stability, woocommerce, checkout, woodmart, safety_gates, verification, troubleshooting, or all.',
							'default'     => 'all',
						),
					),
				),
				'callback'            => array( $this, 'get_know_how' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'annotations'         => array(
					'title'         => 'WPML Translation Know-How',
					'readOnlyHint'  => true,
					'openWorldHint' => false,
				),
			)
		);
	}

	/**
	 * Return reusable translation workflow guidance.
	 *
	 * @param array $params Input parameters.
	 * @return array
	 */
	public function get_know_how( array $params = array() ): array {
		$focus = isset( $params['focus'] ) ? sanitize_key( (string) $params['focus'] ) : 'all';

		$guide = array(
			'purpose' => 'Use this as generic operational guidance for multilingual WordPress work. It is intentionally not tied to one website. Prefer discovery first, small safe changes, WPML APIs/relationships as source of truth, and verification after every change.',
			'recommended_first_tools' => array(
				'wpml_site_profile',
				'wpml_relationships_missing',
				'wpml_string_effective_search',
				'wpml_elementor_manifest',
				'wpml_elementor_doc_inspect',
				'wpml_checkout_translation_diagnose',
				'wc_wpml_catalog_translation_audit',
				'woodmart_wpml_profile',
				'woodmart_preset_application_diagnose',
				'woodmart_presets_evaluate_url',
				'woodmart_generated_css_scan',
				'frontend_translation_scan',
			),
			'core_principles' => array(
				'Discover active languages, default language, WPML translation groups, post types, taxonomies, WooCommerce pages, theme/plugin stack, and page-builder usage before modifying content.',
				'Never hardcode one site\'s post type, taxonomy, page IDs, product IDs, endpoint paths, or language codes into reusable tools. Detect them dynamically and pass IDs as parameters.',
				'Translate/link taxonomy terms before translating posts/products so relationships can be preserved.',
				'Preserve IDs and selectors used by JavaScript, CSS, Elementor widgets, pricing logic, checkout logic, and third-party product add-on plugins.',
				'Prefer WPML-managed translations for WooCommerce system pages and menus. Prefer manual/independent Elementor translations only when the user explicitly wants free Elementor editing.',
				'For menus, use WPML Menu Sync where possible. Do not create unrelated independent menus unless the user explicitly asks for separate menu structures per language.',
				'For Elementor, update raw _elementor_data carefully. Preserve JSON shape and validate JSON before saving. Avoid rebuilding nested arrays unless necessary.',
				'For WooCommerce checkout/cart/my-account/shop, use WooCommerce shortcode/block pages, not static rendered checkout/cart HTML snapshots.',
				'Treat hreflang alternate URLs, x-default URLs, Arabic image filenames, CSS comments, and language switcher labels as expected multilingual artifacts unless they are visible wrong-language UI.',
			),
			'discovery_steps' => array(
				'Get site info: plugins, theme, WordPress/WooCommerce/WPML versions, active page builder, custom checkout/product-add-on plugins.',
				'List REST post types and taxonomies. Record each type slug, REST base, hierarchical flag, and WPML translation setting.',
				'Identify source language and target language. Do not assume ar/en; read active languages from WPML where possible.',
				'Inspect WooCommerce page options and actual translated page IDs for shop, cart, checkout, and my-account.',
				'Inspect Elementor templates: headers, footers, popups, single product templates, archives, loop items, and page-level template references.',
				'Inspect menus and locations. If WPML is active, identify source menu, translated menu, language relationships, and whether WPML Menu Sync is expected.',
			),
			'wpml_workflow' => array(
				'Use WPML translation relationships as the canonical model: trid/source_language_code/language_code for posts and terms.',
				'Use wpml_relationships_get for one item, wpml_relationships_missing for scope discovery, and wpml_relationships_repair only after dry-run review.',
				'Create or repair term translations first. Copy slugs only when appropriate; otherwise create target-language slugs naturally.',
				'Create post/product translations linked to the source trid. Preserve status, author when needed, featured media, template, menu order, relevant meta, and taxonomy assignments mapped to translated terms.',
				'Do not delete translation-management rows casually. Only switch to manual/independent editing when the user chooses manual Elementor editing and understands ATE synchronization implications.',
				'WooCommerce system pages should normally remain WPML/WooCommerce-managed. Regular marketing pages and Elementor templates may be manual if requested.',
			),
			'menus' => array(
				'Use WPML Menu Sync for normal multilingual menus. Keep source and translated menu items related through WPML rather than creating unrelated standalone menus.',
				'After sync, translate menu item labels and URLs to target-language pages/products/anchors. Keep anchors language-safe, e.g. /en/#section for English when needed.',
				'If Elementor nav-menu widgets point at a specific translated menu, verify the menu was produced by WPML sync or is intentionally custom.',
				'Changing menu item URLs by REST can be acceptable for a quick fix, but document it and prefer WPML Menu Sync for the durable workflow.',
			),
			'elementor' => array(
				'Use wpml_elementor_manifest before one-by-one Elementor inspection to find missing translations, invalid JSON, and source/target shape drift.',
				'Find all related templates: header, footer, popup, single product, archives, loop items, page templates, and nested template IDs used by widgets.',
				'When duplicating Elementor content, preserve widget IDs/selectors used by scripts. Translate visible text, button labels, links, dynamic tag prefixes/suffixes, and page/template settings.',
				'For LTR target languages from RTL sources, set document/widget/container direction, alignment, icon positioning, button alignment, nav menu alignment, tabs, taxonomy filters, and loop card alignment where necessary.',
				'Patch _elementor_data as raw JSON only when necessary. Validate JSON before saving. Clear Elementor cache and verify rendered CSS after changes.',
				'If REST meta update triggers WPML Elementor hook fatals, avoid rebuilding JSON. Prefer exact raw-string replacements or a WordPress-side helper that updates post meta with wp_slash and validated JSON.',
			),
			'elementor_stability' => array(
				'Frontend-correct Elementor JSON can still break the Elementor editor if widget IDs, item _id values, selectors, template IDs, popup IDs, loop IDs, or dynamic tag shapes are changed.',
				'For translated Elementor documents, prefer rebuilding the target from the source document shape, then apply the smallest targeted text/link/setting replacements.',
				'Do not regenerate Elementor IDs unless the user explicitly wants a detached design. Preserving IDs is safer for CSS, JavaScript, popups, loop templates, menu widgets, and editor data integrity.',
				'Return compact summaries and hashes first. Fetch full _elementor_data only when a repair requires it, because Elementor payloads are large and token-expensive.',
				'Use dry-run repairs first. Compare before/after hashes, changed counts, JSON validity, WPML trid relationships, and important Elementor meta before saving.',
				'After duplicating or translating any Elementor-backed post type (pages, posts, CPTs, products, templates, headers, footers, loop items, popups), verify both frontend rendering and editor interactivity. A document can render correctly but still be blocked in the editor by duplicated wrappers, overlays, stale generated CSS, or nested template/popup side effects.',
				'When translated Elementor content looks visible but cannot be selected from the canvas, inspect the preview iframe with document.elementsFromPoint(x, y) at the blocked widget coordinates. Identify the topmost element, its data-id, wrapper ID/classes, pointer-events, opacity, and whether it actually contains .elementor-element children.',
				'For any duplicate/translated Elementor document, compare the iframe stack against the expected editable element tree. Empty wrappers, duplicate modal IDs, hidden overlays, or wrong-language header/footer/template inclusions usually indicate preview composition or cache/stale-wrapper issues, not corrupted _elementor_data.',
				'Prefer real content/meta fixes first: verify WPML relationship, _elementor_data JSON validity, widget IDs, rendered text/links, document template, nested template IDs, popup IDs, Elementor CSS presence, and save/regenerate through Elementor where possible. Do not add a plugin just to mask an editor overlay issue.',
				'If Elementor still creates an empty editor-only wrapper after the real fixes, use the narrowest no-plugin workaround: WordPress Additional CSS scoped to body.elementor-editor-active plus the exact document/popup/template ID and only the empty wrapper selector, such as :not(:has(.elementor-element)). Never target all Elementor documents or frontend visitor states.',
			),
			'woocommerce' => array(
				'Use wc_wpml_catalog_translation_audit before catalog edits to detect missing translations, SKU mismatches, status mismatches, term gaps, and standalone translated-looking products.',
				'Products: preserve product type, SKU, prices, sale dates, stock status, stock quantity, dimensions, shipping/tax fields, categories/tags/brands, images, and relevant custom meta.',
				'Product add-ons/pricing plugins: create language-specific add-on groups when labels/options must be translated, but preserve internal block IDs and map target-language labels back to canonical pricing keys if scripts depend on source labels.',
				'Checkout/cart/account/shop pages: use [woocommerce_checkout], [woocommerce_cart], [woocommerce_my_account], or current WooCommerce blocks. Never save a rendered checkout form as page content.',
				'Checkout field labels may come from checkout field editor plugins, theme filters, WooCommerce settings, or WPML String Translation. Identify source before patching.',
				'Payment gateway titles/descriptions are WooCommerce settings and may require WPML String Translation or per-language filters if the setting itself stores one language only.',
			),
			'checkout_translation' => array(
				'If checkout appears mixed-language, first check page content. It should be shortcode/block content, not static form/table HTML.',
				'If page content is correct but labels remain wrong, inspect checkout field editor plugin options and WooCommerce gateway settings.',
				'Prefer registering/translating strings through WPML String Translation. If unavailable, add language-aware filters for woocommerce_checkout_fields, gateway title/description, and order button text.',
				'Always test checkout with a real cart session. Empty checkout often redirects to cart and cannot validate checkout labels.',
			),
			'woodmart' => array(
				'Run woodmart_wpml_profile before changing Woodmart layouts, widgets, presets, typography, buttons, theme options, or generated CSS.',
				'Treat woodmart_layout as a translatable Elementor-backed CPT. Duplicate/link layouts through WPML, translate independently, preserve Elementor JSON shape, and verify source and target layouts separately.',
				'For visible Woodmart strings, identify the real source first: Elementor layout data, widget option, WPML String Translation row, WooCommerce setting, Woodmart option, or generated CSS. Do not rely on imported duplicate PO rows unless wpml_string_effective_search confirms the effective row.',
				'For design/font issues, admin option values are not enough. Use woodmart_preset_application_diagnose, woodmart_presets_evaluate_url, and woodmart_generated_css_scan to check active preset matching, priority collisions, CSS handles, and variable ownership.',
				'Before saving Woodmart options, confirm whether the target is global, preset-specific, or language-specific. If isolation is ambiguous, stop instead of changing global options.',
			),
			'safety_gates' => array(
				'Stop before changing global theme options or Woodmart typography/presets unless the user approves the exact target and scope.',
				'Stop before deleting translations, translation-management jobs, or WPML relationship rows.',
				'Stop before changing source-language content in bulk.',
				'Stop before replacing WooCommerce cart, checkout, or account shortcode/block content.',
				'Stop before creating snippets, MU plugins, custom plugins, theme patches, or CSS workarounds.',
				'Stop before non-dry-run bulk repairs when the manifest has not been reviewed.',
			),
			'verification' => array(
				'Verify saved data: post/page content, _elementor_data JSON validity, WPML translation linkage, term assignments, product meta, and WooCommerce page options.',
				'Clear Elementor cache, object cache, WooCommerce transients, and rewrite rules where relevant.',
				'Render test URLs in each language. Scan for wrong visible-language strings, bad links, default-language links in target pages, broken loop template IDs, and checkout/cart behavior.',
				'Use frontend_translation_scan for target-language URLs, then browser-check the reported snippets before editing source data.',
				'Ignore expected multilingual artifacts: hreflang alternates, x-default links, source-language image filenames, admin-only comments, and language switcher labels.',
				'For checkout, create a temporary cart session and load checkout without placing an order.',
			),
			'troubleshooting' => array(
				'MCP endpoint: WordPress MCP usually expects JWT Bearer auth and JSON-RPC POST to /wp-json/wp/v2/wpmcp or a language-prefixed equivalent. Plain GET may return 401 or 404 depending on method/auth.',
				'Old adapter endpoints such as /wp-json/mcp/mcp-adapter-default-server are different from the WordPress MCP endpoint. Use the endpoint shown in the plugin connection panel.',
				'If REST edit context works with Basic/application-password auth but not JWT, use the auth mode with correct capabilities for the operation.',
				'If WPML menu sync is expected, avoid manually maintaining separate menu structures. Use sync, then translate item labels/URLs.',
				'If WooCommerce checkout strings are stuck in one language, inspect Checkout Field Editor and gateway settings before changing page translations.',
				'If a translated Elementor page/post/template is editable from Structure/Navigator but not from the canvas, suspect an overlay or duplicate preview wrapper rather than bad widget data. Confirm by selecting a widget programmatically or from Structure, then inspect the iframe element stack for an empty duplicate wrapper above the real content.',
			),
		);

		if ( 'all' !== $focus && isset( $guide[ $focus ] ) ) {
			return array(
				'focus' => $focus,
				$focus  => $guide[ $focus ],
			);
		}

		return $guide;
	}

	/**
	 * Permissions callback.
	 *
	 * @return bool
	 */
	public function permission_callback(): bool {
		return current_user_can( 'manage_options' );
	}
}
