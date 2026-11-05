<?php
/**
 * Census service: reads every Elementor document of the site and builds the report.
 *
 * Read-only: it never writes to the database.
 *
 * @package EPT
 */

namespace EPT\Census;

defined( 'ABSPATH' ) || exit;

/**
 * Service.
 */
final class Service {

	public const REPORT_VERSION = 1;

	/**
	 * Control types that only structure the editor panel.
	 */
	private const LAYOUT_CONTROLS = array( 'section', 'tabs', 'tab', 'divider', 'heading', 'raw_html', 'notice', 'alert', 'deprecated_notice', 'button', 'hidden' );

	/**
	 * Control types that can hold text or links even in style/advanced tabs.
	 */
	private const TEXTUAL_CONTROLS = array( 'text', 'textarea', 'wysiwyg', 'url', 'code', 'repeater', 'media', 'gallery' );

	/**
	 * Build the full report.
	 *
	 * @param bool $include_content Also include the full JSON of every document.
	 * @return array<string,mixed>
	 */
	public static function build( bool $include_content = false ): array {
		$collector = new Collector();
		$contents  = array();

		foreach ( self::document_ids() as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}
			$elements = self::decode( get_post_meta( $post_id, '_elementor_data', true ) );
			$settings = get_post_meta( $post_id, '_elementor_page_settings', true );
			$meta     = array(
				'id'            => $post_id,
				'post_type'     => $post->post_type,
				'status'        => $post->post_status,
				'title'         => $post->post_title,
				'slug'          => $post->post_name,
				'parent'        => (int) $post->post_parent,
				'template'      => (string) get_post_meta( $post_id, '_wp_page_template', true ),
				'elementor_ver' => (string) get_post_meta( $post_id, '_elementor_version', true ),
				'edit_mode'     => (string) get_post_meta( $post_id, '_elementor_edit_mode', true ),
				'doc_type'      => (string) get_post_meta( $post_id, '_elementor_template_type', true ),
				'json_ok'       => null !== $elements,
			);
			if ( 'elementor-hf' === $post->post_type ) {
				$meta['uae'] = array(
					'template_type' => get_post_meta( $post_id, 'ehf_template_type', true ),
					'include'       => get_post_meta( $post_id, 'ehf_target_include_locations', true ),
					'exclude'       => get_post_meta( $post_id, 'ehf_target_exclude_locations', true ),
					'user_roles'    => get_post_meta( $post_id, 'ehf_target_user_roles', true ),
				);
			}
			$collector->add_document( $meta, $elements ?? array(), is_array( $settings ) ? $settings : array() );
			if ( $include_content ) {
				$contents[ $post_id ] = array(
					'content'       => $elements,
					'page_settings' => is_array( $settings ) ? $settings : array(),
				);
			}
		}

		$report = $collector->report();
		$used   = array_keys( $report['types'] );

		$out = array(
			'report'   => array(
				'name'         => 'ept-census',
				'version'      => self::REPORT_VERSION,
				'plugin'       => EPT_VERSION,
				'generated_at' => gmdate( 'c' ),
			),
			'site'     => self::site_info(),
			'summary'  => self::summary( $report ),
			'census'   => $report,
			'registry' => self::registry( $used ),
			'menus'    => self::menus(),
		);
		if ( $include_content ) {
			$out['contents'] = $contents;
		}
		return $out;
	}

	/**
	 * IDs of all non-trashed posts of any type with Elementor data (revisions excluded).
	 *
	 * @return list<int>
	 */
	public static function document_ids(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin tool, one read.
		$ids = $wpdb->get_col(
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_data'
			 WHERE p.post_type NOT IN ('revision','nav_menu_item')
			   AND p.post_status NOT IN ('trash','auto-draft','inherit')
			 ORDER BY p.post_type, p.ID"
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * Decode _elementor_data (JSON string, sometimes already an array).
	 *
	 * @param mixed $raw Raw meta value.
	 * @return array<int,mixed>|null
	 */
	private static function decode( $raw ): ?array {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Short human summary: counts per type.
	 *
	 * @param array<string,mixed> $report Collector report.
	 * @return array<string,mixed>
	 */
	private static function summary( array $report ): array {
		$by_post_type = array();
		foreach ( $report['documents'] as $doc ) {
			$by_post_type[ $doc['post_type'] ] = ( $by_post_type[ $doc['post_type'] ] ?? 0 ) + 1;
		}
		$types = array();
		foreach ( $report['types'] as $key => $type ) {
			$types[ $key ] = array(
				'count'     => $type['count'],
				'documents' => count( $type['documents'] ),
			);
		}
		return array(
			'documents_by_post_type' => $by_post_type,
			'types'                  => $types,
		);
	}

	/**
	 * Site and environment information.
	 *
	 * @return array<string,mixed>
	 */
	private static function site_info(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active  = (array) get_option( 'active_plugins', array() );
		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			$plugins[] = array(
				'file'    => $file,
				'name'    => $data['Name'],
				'version' => $data['Version'],
				'active'  => in_array( $file, $active, true ),
			);
		}
		$theme = wp_get_theme();

		$experiments = array();
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->experiments ) ) {
			$manager = \Elementor\Plugin::$instance->experiments;
			foreach ( $manager->get_features() as $name => $feature ) {
				$experiments[ $name ] = $manager->is_feature_active( $name ) ? 'active' : 'inactive';
			}
			ksort( $experiments );
		}

		global $wp_version;
		return array(
			'home'        => home_url( '/' ),
			'wp'          => $wp_version,
			'php'         => PHP_VERSION,
			'locale'      => get_locale(),
			'theme'       => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ),
			'elementor'   => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
			'plugins'     => $plugins,
			'experiments' => $experiments,
		);
	}

	/**
	 * Registered controls (classic widgets) and props schema (atomic elements) of the used types.
	 *
	 * @param list<string> $used Type keys from the collector ("widget:heading", "element:container").
	 * @return array<string,mixed>
	 */
	private static function registry( array $used ): array {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return array( 'error' => 'Elementor not loaded' );
		}
		$plugin = \Elementor\Plugin::$instance;
		$out    = array();
		foreach ( $used as $key ) {
			list( $kind, $name ) = explode( ':', $key, 2 );
			try {
				$instance = 'widget' === $kind
					? $plugin->widgets_manager->get_widget_types( $name )
					: $plugin->elements_manager->get_element_types( $name );
				if ( ! $instance ) {
					$out[ $key ] = array( 'registered' => false );
					continue;
				}
				$entry = array(
					'registered' => true,
					'class'      => get_class( $instance ),
				);
				if ( method_exists( $instance, 'get_props_schema' ) ) {
					$entry['atomic'] = true;
					$schema          = array();
					foreach ( $instance::get_props_schema() as $prop => $prop_type ) {
						$schema[ $prop ] = self::compact_prop( json_decode( (string) wp_json_encode( $prop_type ), true ) );
					}
					$entry['props_schema'] = $schema;
				} else {
					$entry['atomic']   = false;
					$entry['controls'] = self::controls( (array) $instance->get_controls() );
				}
				$out[ $key ] = $entry;
			} catch ( \Throwable $e ) {
				$out[ $key ] = array( 'error' => $e->getMessage() );
			}
		}
		return $out;
	}

	/**
	 * Content-relevant controls of a widget.
	 *
	 * @param array<string,array<string,mixed>> $controls Controls.
	 * @return array<string,array<string,mixed>>
	 */
	private static function controls( array $controls ): array {
		$out = array();
		foreach ( $controls as $name => $control ) {
			$type = (string) ( $control['type'] ?? '' );
			$tab  = (string) ( $control['tab'] ?? '' );
			if ( in_array( $type, self::LAYOUT_CONTROLS, true ) ) {
				continue;
			}
			if ( in_array( $tab, array( 'style', 'advanced' ), true ) && ! in_array( $type, self::TEXTUAL_CONTROLS, true ) ) {
				continue;
			}
			if ( str_starts_with( (string) $name, '_' ) && ! in_array( $type, self::TEXTUAL_CONTROLS, true ) ) {
				continue;
			}
			$entry = array(
				'type'    => $type,
				'tab'     => $tab,
				'label'   => isset( $control['label'] ) ? wp_strip_all_tags( (string) $control['label'] ) : '',
				'dynamic' => ! empty( $control['dynamic']['active'] ),
			);
			if ( isset( $control['default'] ) && is_string( $control['default'] ) && '' !== $control['default'] ) {
				$entry['default'] = mb_substr( $control['default'], 0, 120 );
			}
			if ( 'repeater' === $type && ! empty( $control['fields'] ) && is_array( $control['fields'] ) ) {
				$fields = array();
				foreach ( $control['fields'] as $field_key => $field ) {
					$field_name            = (string) ( $field['name'] ?? $field_key );
					$fields[ $field_name ] = $field;
				}
				$entry['fields'] = self::controls( $fields );
			}
			$out[ (string) $name ] = $entry;
		}
		return $out;
	}

	/**
	 * Keep only the structural part of a serialised prop type.
	 *
	 * @param mixed $prop Serialised prop type.
	 * @param int   $depth Depth.
	 * @return mixed
	 */
	private static function compact_prop( $prop, int $depth = 0 ) {
		if ( ! is_array( $prop ) || $depth > 5 ) {
			return $prop;
		}
		$out = array_intersect_key( $prop, array_flip( array( 'kind', 'key' ) ) );
		if ( ! empty( $prop['settings']['enum'] ) ) {
			$out['enum'] = $prop['settings']['enum'];
		}
		if ( ! empty( $prop['shape'] ) && is_array( $prop['shape'] ) ) {
			foreach ( $prop['shape'] as $k => $v ) {
				$out['shape'][ $k ] = self::compact_prop( $v, $depth + 1 );
			}
		}
		if ( ! empty( $prop['item_prop_type'] ) ) {
			$out['item'] = self::compact_prop( $prop['item_prop_type'], $depth + 1 );
		}
		if ( ! empty( $prop['prop_types'] ) && is_array( $prop['prop_types'] ) ) {
			foreach ( $prop['prop_types'] as $k => $v ) {
				$out['union'][ $k ] = self::compact_prop( $v, $depth + 1 );
			}
		}
		return $out;
	}

	/**
	 * Navigation menus (referenced by slug in the UAE menu widget).
	 *
	 * @return list<array<string,mixed>>
	 */
	private static function menus(): array {
		$locations = get_nav_menu_locations();
		$out       = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			$out[] = array(
				'id'        => (int) $menu->term_id,
				'slug'      => $menu->slug,
				'name'      => $menu->name,
				'items'     => (int) $menu->count,
				'locations' => array_keys( $locations, (int) $menu->term_id, true ),
			);
		}
		return $out;
	}
}
