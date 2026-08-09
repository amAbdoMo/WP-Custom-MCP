<?php //phpcs:ignore
declare( strict_types=1 );

namespace Automattic\WordpressMcp\Tools;

use Automattic\WordpressMcp\Core\RegisterMcpTool;

defined( 'ABSPATH' ) || exit;

/**
 * Frontend translation verification tools.
 */
class McpWpmlFrontendVerificationTools {
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
				'name'                => 'frontend_translation_scan',
				'description'         => 'Fetch frontend URLs and scan visible text for likely wrong-language leftovers while ignoring common multilingual artifacts.',
				'type'                => 'read',
				'inputSchema'         => array(
					'type'       => 'object',
					'properties' => array(
						'urls'            => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Frontend URLs to scan.' ),
						'target_language' => array( 'type' => 'string', 'description' => 'Target language code, e.g. ar.' ),
						'source_terms'    => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Optional source-language terms to search for.' ),
						'allow_terms'     => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Optional expected terms to ignore.' ),
					),
					'required'   => array( 'urls' ),
				),
				'callback'            => array( $this, 'scan' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'annotations'         => array( 'title' => 'Scan Frontend Translation', 'readOnlyHint' => true, 'openWorldHint' => true ),
			)
		);
	}

	/**
	 * Scan URLs.
	 *
	 * @param array $params Input parameters.
	 * @return array
	 */
	public function scan( array $params ): array {
		$urls            = isset( $params['urls'] ) && is_array( $params['urls'] ) ? array_slice( $params['urls'], 0, 20 ) : array();
		$target_language = sanitize_key( (string) ( $params['target_language'] ?? '' ) );
		$source_terms    = isset( $params['source_terms'] ) && is_array( $params['source_terms'] ) ? array_map( 'sanitize_text_field', $params['source_terms'] ) : array();
		$allow_terms     = array_merge( $this->default_allow_terms(), isset( $params['allow_terms'] ) && is_array( $params['allow_terms'] ) ? array_map( 'sanitize_text_field', $params['allow_terms'] ) : array() );
		$results         = array();

		foreach ( $urls as $url ) {
			$results[] = $this->scan_url( esc_url_raw( (string) $url ), $target_language, $source_terms, $allow_terms );
		}

		return array( 'target_language' => $target_language, 'count' => count( $results ), 'results' => $results );
	}

	/**
	 * Scan one URL.
	 *
	 * @param string $url URL.
	 * @param string $target_language Target language.
	 * @param array  $source_terms Source terms.
	 * @param array  $allow_terms Allow terms.
	 * @return array
	 */
	private function scan_url( string $url, string $target_language, array $source_terms, array $allow_terms ): array {
		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) ) {
			return array( 'url' => $url, 'error' => $response->get_error_message() );
		}

		$html      = (string) wp_remote_retrieve_body( $response );
		$text      = $this->visible_text( $html );
		$findings  = empty( $source_terms ) ? $this->ascii_findings( $text, $allow_terms ) : $this->term_findings( $text, $source_terms, $allow_terms );
		$direction = $this->html_attr( $html, 'html', 'dir' );
		$lang      = $this->html_attr( $html, 'html', 'lang' );

		return array(
			'url'              => $url,
			'http_status'      => (int) wp_remote_retrieve_response_code( $response ),
			'html_lang'        => $lang,
			'html_dir'         => $direction,
			'target_language'  => $target_language,
			'finding_count'    => count( $findings ),
			'findings'         => array_slice( $findings, 0, 80 ),
			'recommendation'   => empty( $findings ) ? 'No obvious visible source-language leftovers found.' : 'Review findings in browser; ignore brand names and intentionally untranslated labels.',
		);
	}

	/**
	 * Extract visible text.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function visible_text( string $html ): string {
		$html = preg_replace( '/<script\b[^>]*>.*?<\/script>/is', ' ', $html );
		$html = preg_replace( '/<style\b[^>]*>.*?<\/style>/is', ' ', $html );
		$html = preg_replace( '/<noscript\b[^>]*>.*?<\/noscript>/is', ' ', $html );
		$text = html_entity_decode( wp_strip_all_tags( (string) $html ) );

		return preg_replace( '/\s+/', ' ', $text ) ?: '';
	}

	/**
	 * Find ASCII words in target-language visible text.
	 *
	 * @param string $text Text.
	 * @param array  $allow_terms Allow terms.
	 * @return array
	 */
	private function ascii_findings( string $text, array $allow_terms ): array {
		preg_match_all( '/\b[A-Za-z][A-Za-z\'’\-]{2,}\b/', $text, $matches, PREG_OFFSET_CAPTURE );
		$findings = array();
		$seen     = array();

		foreach ( $matches[0] ?? array() as $match ) {
			$term = $match[0];
			$key  = strtolower( $term );
			if ( isset( $seen[ $key ] ) || $this->is_allowed( $term, $allow_terms ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$findings[]   = array( 'term' => $term, 'snippet' => $this->snippet( $text, (int) $match[1], strlen( $term ) ) );
		}

		return $findings;
	}

	/**
	 * Find explicit source terms.
	 *
	 * @param string $text Text.
	 * @param array  $source_terms Source terms.
	 * @param array  $allow_terms Allow terms.
	 * @return array
	 */
	private function term_findings( string $text, array $source_terms, array $allow_terms ): array {
		$findings = array();

		foreach ( $source_terms as $term ) {
			if ( '' === $term || $this->is_allowed( $term, $allow_terms ) ) {
				continue;
			}

			$position = stripos( $text, $term );
			if ( false !== $position ) {
				$findings[] = array( 'term' => $term, 'snippet' => $this->snippet( $text, (int) $position, strlen( $term ) ) );
			}
		}

		return $findings;
	}

	/**
	 * Build context snippet.
	 *
	 * @param string $text Text.
	 * @param int    $position Position.
	 * @param int    $length Match length.
	 * @return string
	 */
	private function snippet( string $text, int $position, int $length ): string {
		$start = max( 0, $position - 60 );
		return trim( substr( $text, $start, $length + 120 ) );
	}

	/**
	 * Check allowed term.
	 *
	 * @param string $term Term.
	 * @param array  $allow_terms Allow terms.
	 * @return bool
	 */
	private function is_allowed( string $term, array $allow_terms ): bool {
		$term = strtolower( trim( $term ) );
		return '' === $term || in_array( $term, array_map( 'strtolower', $allow_terms ), true ) || str_contains( $term, 'http' );
	}

	/**
	 * Default allowed artifacts.
	 *
	 * @return array
	 */
	private function default_allow_terms(): array {
		return array( 'English', 'Arabic', 'EGP', 'USD', 'EUR', 'SKU', 'ID', 'URL', 'Email', 'WhatsApp', 'Facebook', 'Instagram', 'TikTok', 'YouTube', 'Google', 'Apple', 'PayPal', 'Visa', 'Mastercard', 'WooCommerce', 'WordPress', 'Elementor', 'Woodmart' );
	}

	/**
	 * Get an HTML attribute.
	 *
	 * @param string $html HTML.
	 * @param string $tag Tag.
	 * @param string $attr Attribute.
	 * @return string
	 */
	private function html_attr( string $html, string $tag, string $attr ): string {
		if ( preg_match( '/<' . preg_quote( $tag, '/' ) . '\b([^>]*)>/i', $html, $match ) && preg_match( '/' . preg_quote( $attr, '/' ) . '=["\']([^"\']*)["\']/i', $match[1], $attr_match ) ) {
			return html_entity_decode( $attr_match[1] );
		}

		return '';
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
