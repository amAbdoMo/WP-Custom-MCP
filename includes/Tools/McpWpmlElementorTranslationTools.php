<?php //phpcs:ignore
declare( strict_types=1 );

namespace Automattic\WordpressMcp\Tools;

use Automattic\WordpressMcp\Core\RegisterMcpTool;

defined( 'ABSPATH' ) || exit;

/**
 * Safe WPML/Elementor document diagnostics and source-shape repair tools.
 */
class McpWpmlElementorTranslationTools {
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
				'name'                => 'wpml_elementor_doc_inspect',
				'description'         => 'Inspect Elementor document meta, WPML relationship, JSON validity, hashes, and compact widget summary.',
				'type'                => 'read',
				'inputSchema'         => array(
					'type'       => 'object',
					'properties' => array(
						'id'           => array(
							'type'        => 'integer',
							'description' => 'Elementor document ID.',
						),
						'include_data' => array(
							'type'        => 'boolean',
							'description' => 'Include decoded Elementor JSON. Defaults to false.',
							'default'     => false,
						),
					),
					'required'   => array( 'id' ),
				),
				'callback'            => array( $this, 'inspect_document' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'annotations'         => array(
					'title'         => 'Inspect WPML Elementor Document',
					'readOnlyHint'  => true,
					'openWorldHint' => false,
				),
			)
		);

		new RegisterMcpTool(
			array(
				'name'                => 'wpml_elementor_doc_repair_from_source',
				'description'         => 'Repair a translated Elementor document from source JSON shape with targeted icon-list and setting replacements. Defaults to dry-run.',
				'type'                => 'update',
				'inputSchema'         => array(
					'type'       => 'object',
					'properties' => array(
						'target_id'            => array(
							'type'        => 'integer',
							'description' => 'Translated Elementor document ID to repair.',
						),
						'source_id'            => array(
							'type'        => 'integer',
							'description' => 'Source Elementor document ID.',
						),
						'icon_list'            => array(
							'type'        => 'array',
							'description' => 'Icon-list replacements. Each item supports _id, text, and url.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'_id'  => array( 'type' => 'string' ),
									'text' => array( 'type' => 'string' ),
									'url'  => array( 'type' => 'string' ),
								),
							),
						),
						'setting_replacements' => array(
							'type'        => 'array',
							'description' => 'Element setting replacements. Each item supports element_id, key, and value.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'element_id' => array( 'type' => 'string' ),
									'key'        => array( 'type' => 'string' ),
									'value'      => array( 'type' => 'string' ),
								),
							),
						),
						'copy_meta'            => array(
							'type'        => 'boolean',
							'description' => 'Copy stable Elementor meta from source. Defaults to true.',
							'default'     => true,
						),
						'dry_run'              => array(
							'type'        => 'boolean',
							'description' => 'Preview without saving. Defaults to true.',
							'default'     => true,
						),
					),
					'required'   => array( 'target_id', 'source_id' ),
				),
				'callback'            => array( $this, 'repair_document_from_source' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'annotations'         => array(
					'title'           => 'Repair WPML Elementor Document',
					'readOnlyHint'    => false,
					'destructiveHint' => true,
					'idempotentHint'  => true,
					'openWorldHint'   => false,
				),
			)
		);

		new RegisterMcpTool(
			array(
				'name'                => 'wpml_elementor_doc_stability_check',
				'description'         => 'Check a translated Elementor document after WPML duplication/translation for JSON/meta/ID stability, source-shape drift, generated CSS status, and editor-preview risk warnings.',
				'type'                => 'read',
				'inputSchema'         => array(
					'type'       => 'object',
					'properties' => array(
						'target_id' => array(
							'type'        => 'integer',
							'description' => 'Translated Elementor document ID to check.',
						),
						'source_id' => array(
							'type'        => 'integer',
							'description' => 'Optional source Elementor document ID to compare shape/IDs against.',
						),
					),
					'required'   => array( 'target_id' ),
				),
				'callback'            => array( $this, 'stability_check' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'annotations'         => array(
					'title'         => 'Check WPML Elementor Stability',
					'readOnlyHint'  => true,
					'openWorldHint' => false,
				),
			)
		);
	}

	/**
	 * Inspect an Elementor document.
	 *
	 * @param array $params Input parameters.
	 * @return array
	 */
	public function inspect_document( array $params ): array {
		$post_id = absint( $params['id'] ?? 0 );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return $this->error( 'not_found', 'Document not found.' );
		}

		$data_raw = (string) get_post_meta( $post_id, '_elementor_data', true );
		$data     = json_decode( $data_raw, true );
		$output   = array(
			'post'           => array(
				'id'     => $post_id,
				'title'  => get_the_title( $post_id ),
				'type'   => $post->post_type,
				'status' => $post->post_status,
				'slug'   => $post->post_name,
			),
			'wpml'           => $this->language_info( $post_id, $post->post_type ),
			'elementor'      => array(
				'edit_mode'      => get_post_meta( $post_id, '_elementor_edit_mode', true ),
				'template_type'  => get_post_meta( $post_id, '_elementor_template_type', true ),
				'version'        => get_post_meta( $post_id, '_elementor_version', true ),
				'pro_version'    => get_post_meta( $post_id, '_elementor_pro_version', true ),
				'css'            => get_post_meta( $post_id, '_elementor_css', true ) ? 'present' : 'missing',
				'page_template'  => get_post_meta( $post_id, '_wp_page_template', true ),
				'data_length'    => strlen( $data_raw ),
				'data_hash'      => '' !== $data_raw ? md5( $data_raw ) : '',
				'json_valid'     => is_array( $data ),
				'json_error'     => json_last_error_msg(),
				'root_count'     => is_array( $data ) ? count( $data ) : 0,
				'widget_summary' => is_array( $data ) ? $this->summarize_elements( $data ) : array(),
			),
			'important_meta' => $this->important_meta( $post_id ),
		);

		if ( ! empty( $params['include_data'] ) ) {
			$output['elementor']['data'] = is_array( $data ) ? $data : null;
		}

		return $output;
	}

	/**
	 * Repair target Elementor data from source data.
	 *
	 * @param array $params Input parameters.
	 * @return array
	 */
	public function repair_document_from_source( array $params ): array {
		$target_id = absint( $params['target_id'] ?? 0 );
		$source_id = absint( $params['source_id'] ?? 0 );
		$dry_run   = ! array_key_exists( 'dry_run', $params ) || ! empty( $params['dry_run'] );
		$copy_meta = ! array_key_exists( 'copy_meta', $params ) || ! empty( $params['copy_meta'] );

		if ( ! get_post( $target_id ) || ! get_post( $source_id ) ) {
			return $this->error( 'not_found', 'Source or target document not found.' );
		}

		$source_raw = (string) get_post_meta( $source_id, '_elementor_data', true );
		$target_raw = (string) get_post_meta( $target_id, '_elementor_data', true );
		$data       = json_decode( $source_raw, true );

		if ( ! is_array( $data ) ) {
			return $this->error( 'invalid_source_json', 'Source Elementor JSON is invalid.', array( 'json_error' => json_last_error_msg() ) );
		}

		$changed = array(
			'icon_list_items' => 0,
			'settings'        => 0,
		);

		$this->walk_and_replace(
			$data,
			$this->icon_replacements( $params['icon_list'] ?? array() ),
			$this->setting_replacements( $params['setting_replacements'] ?? array() ),
			$changed
		);

		$new_raw = wp_json_encode( $data );
		if ( ! is_string( $new_raw ) ) {
			return $this->error( 'encode_failed', 'Could not encode repaired Elementor JSON.' );
		}

		$meta_changes = array();
		if ( $copy_meta ) {
			foreach ( $this->meta_keys() as $key ) {
				$source_value = get_post_meta( $source_id, $key, true );
				$target_value = get_post_meta( $target_id, $key, true );

				if ( '' !== $source_value && $source_value !== $target_value ) {
					$meta_changes[] = $key;

					if ( ! $dry_run ) {
						update_post_meta( $target_id, $key, $source_value );
					}
				}
			}
		}

		if ( ! $dry_run ) {
			update_post_meta( $target_id, '_elementor_data', wp_slash( $new_raw ) );
			$this->clear_elementor_cache( $target_id );
		}

		return array(
			'target_id'     => $target_id,
			'source_id'     => $source_id,
			'dry_run'       => $dry_run,
			'changed'       => $changed,
			'meta_changes'  => $meta_changes,
			'before_hash'   => '' !== $target_raw ? md5( $target_raw ) : '',
			'after_hash'    => md5( $new_raw ),
			'after_length'  => strlen( $new_raw ),
			'json_valid'    => is_array( json_decode( $new_raw, true ) ),
			'cache_cleared' => ! $dry_run,
		);
	}

	/**
	 * Check translated Elementor document stability after duplication/translation.
	 *
	 * @param array $params Input parameters.
	 * @return array
	 */
	public function stability_check( array $params ): array {
		$target_id = absint( $params['target_id'] ?? 0 );
		$source_id = absint( $params['source_id'] ?? 0 );
		$target    = get_post( $target_id );

		if ( ! $target ) {
			return $this->error( 'not_found', 'Target document not found.' );
		}

		$target_raw  = (string) get_post_meta( $target_id, '_elementor_data', true );
		$target_data = json_decode( $target_raw, true );
		$issues      = array();
		$warnings    = array();
		$checks      = array(
			'json_valid'            => is_array( $target_data ),
			'elementor_edit_mode'   => 'builder' === get_post_meta( $target_id, '_elementor_edit_mode', true ),
			'elementor_data_exists' => '' !== $target_raw,
			'generated_css'         => get_post_meta( $target_id, '_elementor_css', true ) ? 'present' : 'missing',
		);

		if ( ! $checks['json_valid'] ) {
			$issues[] = 'Target _elementor_data is invalid JSON: ' . json_last_error_msg();
		}

		if ( ! $checks['elementor_edit_mode'] ) {
			$issues[] = 'Target is not marked as Elementor builder edit mode.';
		}

		if ( ! $checks['elementor_data_exists'] ) {
			$issues[] = 'Target has no _elementor_data.';
		}

		if ( 'missing' === $checks['generated_css'] ) {
			$warnings[] = 'Generated Elementor CSS is missing. Save/regenerate through Elementor before considering the translation complete.';
		}

		$target_tree = is_array( $target_data ) ? $this->element_tree_stats( $target_data ) : array();
		if ( ! empty( $target_tree['duplicate_element_ids'] ) ) {
			$issues[] = 'Target _elementor_data contains duplicate Elementor element IDs.';
		}

		if ( ! empty( $target_tree['duplicate_item_ids'] ) ) {
			$warnings[] = 'Target _elementor_data contains duplicate repeater item _id values. Verify affected widgets in Elementor.';
		}

		$comparison = null;
		if ( $source_id ) {
			$source = get_post( $source_id );

			if ( ! $source ) {
				$issues[] = 'Source document not found.';
			} else {
				$source_raw  = (string) get_post_meta( $source_id, '_elementor_data', true );
				$source_data = json_decode( $source_raw, true );

				if ( ! is_array( $source_data ) ) {
					$issues[] = 'Source _elementor_data is invalid JSON: ' . json_last_error_msg();
				} elseif ( is_array( $target_data ) ) {
					$source_tree = $this->element_tree_stats( $source_data );
					$comparison  = $this->compare_element_trees( $source_tree, $target_tree );

					if ( ! empty( $comparison['element_id_mismatch'] ) ) {
						$issues[] = 'Target Elementor element IDs differ from the source. This can break translated editor stability, selectors, popups, or scripts.';
					}

					if ( ! empty( $comparison['widget_sequence_mismatch'] ) ) {
						$warnings[] = 'Target Elementor widget/container sequence differs from the source. Verify this was intentional.';
					}

					if ( ! empty( $comparison['repeater_item_id_mismatch'] ) ) {
						$warnings[] = 'Target repeater item _id values differ from the source. This can affect icon lists, tabs, accordions, sliders, and CSS selectors.';
					}
				}
			}
		}

		$template_type = (string) get_post_meta( $target_id, '_elementor_template_type', true );
		if ( in_array( $template_type, array( 'popup', 'header', 'footer', 'single', 'archive' ), true ) ) {
			$warnings[] = 'This is an Elementor Theme Builder document. Browser verification is required because preview composition can include headers, footers, nested templates, popups, or duplicate editor wrappers that raw _elementor_data cannot reveal.';
		}

		if ( 'popup' === $template_type ) {
			$warnings[] = 'Popup-specific guard: in Elementor editor preview, inspect duplicate #elementor-popup-modal-' . $target_id . ' layers with document.elementsFromPoint(x, y). If an empty duplicate modal sits above the real content, fix via source/template/settings first; only use scoped body.elementor-editor-active Additional CSS as a no-plugin last resort.';
		}

		return array(
			'target_id'       => $target_id,
			'source_id'       => $source_id ?: null,
			'post'            => array(
				'type'   => $target->post_type,
				'status' => $target->post_status,
				'title'  => get_the_title( $target_id ),
			),
			'wpml'            => $this->language_info( $target_id, $target->post_type ),
			'checks'          => $checks,
			'elementor'       => array(
				'template_type' => $template_type,
				'page_template' => get_post_meta( $target_id, '_wp_page_template', true ),
				'data_hash'     => '' !== $target_raw ? md5( $target_raw ) : '',
				'tree_stats'    => $target_tree,
			),
			'comparison'      => $comparison,
			'issues'          => $issues,
			'warnings'        => $warnings,
			'passed'          => empty( $issues ),
			'next_steps'      => $this->stability_next_steps( $issues, $warnings ),
		);
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
		$items   = array();

		if ( $trid ) {
			$translations = apply_filters( 'wpml_get_element_translations', null, $trid, $element );

			if ( is_array( $translations ) ) {
				foreach ( $translations as $language => $translation ) {
					$items[ $language ] = array(
						'element_id'           => isset( $translation->element_id ) ? (int) $translation->element_id : 0,
						'original'             => ! empty( $translation->original ),
						'source_language_code' => isset( $translation->source_language_code ) ? (string) $translation->source_language_code : null,
					);
				}
			}
		}

		return array(
			'details'      => is_array( $details ) ? $details : array(),
			'trid'         => $trid ? (int) $trid : null,
			'translations' => $items,
		);
	}

	/**
	 * Summarize Elementor elements without returning full data by default.
	 *
	 * @param array $elements Elementor elements.
	 * @return array
	 */
	private function summarize_elements( array $elements ): array {
		$summary = array();

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$summary[] = array(
				'id'         => (string) ( $element['id'] ?? '' ),
				'elType'     => (string) ( $element['elType'] ?? '' ),
				'widgetType' => (string) ( $element['widgetType'] ?? '' ),
			);

			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$summary = array_merge( $summary, $this->summarize_elements( $element['elements'] ) );
			}
		}

		return array_slice( $summary, 0, 80 );
	}

	/**
	 * Return stable Elementor-related meta summaries.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private function important_meta( int $post_id ): array {
		$output = array();

		foreach ( $this->meta_keys() as $key ) {
			$value   = get_post_meta( $post_id, $key, true );
			$encoded = is_array( $value ) ? wp_json_encode( $value ) : (string) $value;

			$output[ $key ] = array(
				'type'    => is_array( $value ) ? 'array' : 'scalar',
				'hash'    => '' !== $encoded ? md5( $encoded ) : '',
				'preview' => is_array( $value ) ? $value : substr( (string) $value, 0, 200 ),
			);
		}

		return $output;
	}

	/**
	 * Meta keys that affect Elementor editor/rendering stability.
	 *
	 * @return string[]
	 */
	private function meta_keys(): array {
		return array(
			'_elementor_edit_mode',
			'_elementor_template_type',
			'_elementor_version',
			'_elementor_pro_version',
			'_wp_page_template',
			'_elementor_page_settings',
			'_elementor_conditions',
			'_elementor_popup_display_settings',
		);
	}

	/**
	 * Normalize icon-list replacements.
	 *
	 * @param mixed $items Raw items.
	 * @return array
	 */
	private function icon_replacements( $items ): array {
		$output = array();

		foreach ( is_array( $items ) ? $items : array() as $item ) {
			if ( is_array( $item ) && ! empty( $item['_id'] ) ) {
				$output[ sanitize_key( (string) $item['_id'] ) ] = array(
					'text' => array_key_exists( 'text', $item ) ? sanitize_text_field( (string) $item['text'] ) : null,
					'url'  => array_key_exists( 'url', $item ) ? esc_url_raw( (string) $item['url'] ) : null,
				);
			}
		}

		return $output;
	}

	/**
	 * Normalize setting replacements.
	 *
	 * @param mixed $items Raw items.
	 * @return array
	 */
	private function setting_replacements( $items ): array {
		$output = array();

		foreach ( is_array( $items ) ? $items : array() as $item ) {
			if ( is_array( $item ) && ! empty( $item['element_id'] ) && ! empty( $item['key'] ) && array_key_exists( 'value', $item ) ) {
				$output[ sanitize_key( (string) $item['element_id'] ) ][ sanitize_key( (string) $item['key'] ) ] = sanitize_text_field( (string) $item['value'] );
			}
		}

		return $output;
	}

	/**
	 * Apply targeted replacements while preserving Elementor shape and IDs.
	 *
	 * @param array $elements Elementor elements.
	 * @param array $icon_replacements Icon list replacements.
	 * @param array $setting_replacements Setting replacements.
	 * @param array $changed Change counters.
	 */
	private function walk_and_replace( array &$elements, array $icon_replacements, array $setting_replacements, array &$changed ): void {
		foreach ( $elements as &$element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$id = sanitize_key( (string) ( $element['id'] ?? '' ) );
			if ( $id && isset( $setting_replacements[ $id ] ) ) {
				$element['settings'] = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();

				foreach ( $setting_replacements[ $id ] as $key => $value ) {
					$element['settings'][ $key ] = $value;
					++$changed['settings'];
				}
			}

			if ( isset( $element['widgetType'], $element['settings']['icon_list'] ) && 'icon-list' === $element['widgetType'] && is_array( $element['settings']['icon_list'] ) ) {
				foreach ( $element['settings']['icon_list'] as &$item ) {
					$item_id = sanitize_key( (string) ( $item['_id'] ?? '' ) );

					if ( $item_id && isset( $icon_replacements[ $item_id ] ) ) {
						if ( null !== $icon_replacements[ $item_id ]['text'] ) {
							$item['text'] = $icon_replacements[ $item_id ]['text'];
						}

						if ( null !== $icon_replacements[ $item_id ]['url'] ) {
							$item['link']        = isset( $item['link'] ) && is_array( $item['link'] ) ? $item['link'] : array();
							$item['link']['url'] = $icon_replacements[ $item_id ]['url'];
						}

						++$changed['icon_list_items'];
					}
				}
				unset( $item );
			}

			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$this->walk_and_replace( $element['elements'], $icon_replacements, $setting_replacements, $changed );
			}
		}
		unset( $element );
	}

	/**
	 * Build compact stability stats for an Elementor element tree.
	 *
	 * @param array $elements Elementor elements.
	 * @return array
	 */
	private function element_tree_stats( array $elements ): array {
		$stats = array(
			'root_count'             => count( $elements ),
			'element_count'          => 0,
			'element_ids'            => array(),
			'widget_sequence'        => array(),
			'repeater_item_ids'      => array(),
			'duplicate_element_ids'  => array(),
			'duplicate_item_ids'     => array(),
		);

		$this->collect_element_tree_stats( $elements, $stats );

		$element_counts = array_count_values( $stats['element_ids'] );
		$item_counts    = array_count_values( $stats['repeater_item_ids'] );

		$stats['duplicate_element_ids'] = array_values(
			array_keys(
				array_filter(
					$element_counts,
					static function ( int $count ): bool {
						return $count > 1;
					}
				)
			)
		);

		$stats['duplicate_item_ids'] = array_values(
			array_keys(
				array_filter(
					$item_counts,
					static function ( int $count ): bool {
						return $count > 1;
					}
				)
			)
		);

		return $stats;
	}

	/**
	 * Recursively collect Elementor element stats.
	 *
	 * @param array $elements Elementor elements.
	 * @param array $stats Stats accumulator.
	 */
	private function collect_element_tree_stats( array $elements, array &$stats ): void {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			++$stats['element_count'];

			$id = (string) ( $element['id'] ?? '' );
			if ( '' !== $id ) {
				$stats['element_ids'][] = $id;
			}

			$stats['widget_sequence'][] = implode(
				':',
				array(
					(string) ( $element['elType'] ?? '' ),
					(string) ( $element['widgetType'] ?? '' ),
				)
			);

			if ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) {
				$this->collect_repeater_item_ids( $element['settings'], $stats['repeater_item_ids'] );
			}

			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$this->collect_element_tree_stats( $element['elements'], $stats );
			}
		}
	}

	/**
	 * Recursively collect repeater item IDs from Elementor settings arrays.
	 *
	 * @param array $value Settings or nested value.
	 * @param array $ids Repeater item IDs accumulator.
	 */
	private function collect_repeater_item_ids( array $value, array &$ids ): void {
		if ( isset( $value['_id'] ) && is_scalar( $value['_id'] ) ) {
			$ids[] = (string) $value['_id'];
		}

		foreach ( $value as $child ) {
			if ( is_array( $child ) ) {
				$this->collect_repeater_item_ids( $child, $ids );
			}
		}
	}

	/**
	 * Compare source and target Elementor tree stats.
	 *
	 * @param array $source_tree Source tree stats.
	 * @param array $target_tree Target tree stats.
	 * @return array
	 */
	private function compare_element_trees( array $source_tree, array $target_tree ): array {
		return array(
			'element_count_delta'       => (int) ( $target_tree['element_count'] ?? 0 ) - (int) ( $source_tree['element_count'] ?? 0 ),
			'element_id_mismatch'       => ( $source_tree['element_ids'] ?? array() ) !== ( $target_tree['element_ids'] ?? array() ),
			'widget_sequence_mismatch'  => ( $source_tree['widget_sequence'] ?? array() ) !== ( $target_tree['widget_sequence'] ?? array() ),
			'repeater_item_id_mismatch' => ( $source_tree['repeater_item_ids'] ?? array() ) !== ( $target_tree['repeater_item_ids'] ?? array() ),
			'missing_element_ids'       => array_values( array_diff( $source_tree['element_ids'] ?? array(), $target_tree['element_ids'] ?? array() ) ),
			'extra_element_ids'         => array_values( array_diff( $target_tree['element_ids'] ?? array(), $source_tree['element_ids'] ?? array() ) ),
		);
	}

	/**
	 * Build actionable next steps for stability output.
	 *
	 * @param array $issues Issues.
	 * @param array $warnings Warnings.
	 * @return string[]
	 */
	private function stability_next_steps( array $issues, array $warnings ): array {
		$steps = array();

		if ( ! empty( $issues ) ) {
			$steps[] = 'Do not mark the translation complete until issues are fixed. Prefer rebuilding the translated document from source shape and applying targeted text/link replacements.';
		}

		if ( ! empty( $warnings ) ) {
			$steps[] = 'Verify the Elementor editor canvas manually or with a browser tool, not only frontend rendering.';
		}

		$steps[] = 'In browser verification, open the Elementor editor for the target, click a visible translated widget, and confirm the expected left-panel editor opens.';
		$steps[] = 'If canvas clicks fail but Structure/Navigator selection works, inspect the preview iframe with document.elementsFromPoint(x, y) for empty duplicate wrappers or overlays above the real .elementor-element.';

		return $steps;
	}

	/**
	 * Clear Elementor caches if Elementor is active.
	 *
	 * @param int $post_id Post ID.
	 */
	private function clear_elementor_cache( int $post_id ): void {
		if ( class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}

		if ( class_exists( '\\Elementor\\Core\\Files\\CSS\\Post' ) ) {
			$css_file = new \Elementor\Core\Files\CSS\Post( $post_id );
			$css_file->delete();
		}

		clean_post_cache( $post_id );
	}

	/**
	 * Build a consistent error response.
	 *
	 * @param string $code Error code.
	 * @param string $message Error message.
	 * @param array  $extra Extra data.
	 * @return array
	 */
	private function error( string $code, string $message, array $extra = array() ): array {
		return array_merge(
			array(
				'error' => $message,
				'code'  => $code,
			),
			$extra
		);
	}

	/**
	 * Permission callback.
	 *
	 * @return bool
	 */
	public function permission_callback(): bool {
		return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' );
	}
}
