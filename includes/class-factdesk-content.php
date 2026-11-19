<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FactDesk_Content {

	const EXCLUDE_META = '_factdesk_exclude';
	const MAX_BODY     = 40000;
	const PER_PAGE     = 50;

	public static function is_available( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return false;
		}
		$types = FactDesk_Settings::enabled_post_types();
		if ( 'product' === $post->post_type && FactDesk_Settings::products_enabled() ) {
			$types[] = 'product';
		}
		$ok = 'publish' === $post->post_status
			&& '' === $post->post_password
			&& in_array( $post->post_type, $types, true )
			&& ! in_array( (int) $post->ID, array_map( 'intval', (array) FactDesk_Settings::get( 'exclude_ids' ) ), true )
			&& ! get_post_meta( $post->ID, self::EXCLUDE_META, true )
			&& ! self::is_noindex( $post );
		return (bool) apply_filters( 'factdesk_post_available', $ok, $post );
	}

	public static function is_noindex( $post ) {
		$id   = (int) $post->ID;
		$type = $post->post_type;

		$yoast = (string) get_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', true );
		if ( '1' === $yoast ) {
			return true;
		}
		if ( '2' !== $yoast && defined( 'WPSEO_VERSION' ) ) {
			$titles = get_option( 'wpseo_titles' );
			if ( is_array( $titles ) && ! empty( $titles[ 'noindex-' . $type ] ) ) {
				return true;
			}
		}

		$rank_math = get_post_meta( $id, 'rank_math_robots', true );
		if ( is_array( $rank_math ) && in_array( 'noindex', $rank_math, true ) ) {
			return true;
		}

		if ( 'yes' === get_post_meta( $id, '_seopress_robots_index', true ) ) {
			return true;
		}

		if ( '1' === (string) get_post_meta( $id, '_genesis_noindex', true ) ) {
			return true;
		}

		return false;
	}

	public static function type_label( $type ) {
		$object = get_post_type_object( $type );
		return $object ? $object->labels->singular_name : $type;
	}

	public static function summary( $post, $length = 240 ) {
		$text = has_excerpt( $post ) ? $post->post_excerpt : strip_shortcodes( $post->post_content );
		return FactDesk_Markdown::plain_text( $text, $length );
	}

	public static function search( $query, $type = '', $limit = 5 ) {
		$types = FactDesk_Settings::enabled_post_types();
		if ( '' !== $type ) {
			if ( ! in_array( $type, $types, true ) ) {
				return new WP_Error( 'factdesk_type', self::type_error( $type ) );
			}
			$types = array( $type );
		}
		if ( ! $types ) {
			return array();
		}
		$q = new WP_Query(
			array(
				's'                   => $query,
				'post_type'           => $types,
				'post_status'         => 'publish',
				'has_password'        => false,
				'posts_per_page'      => min( 30, $limit * 3 ),
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			)
		);
		$out = array();
		foreach ( $q->posts as $post ) {
			if ( count( $out ) >= $limit ) {
				break;
			}
			if ( self::is_available( $post ) ) {
				$out[] = $post;
			}
		}
		return $out;
	}

	public static function listing( $type = '', $page = 1 ) {
		$types = FactDesk_Settings::enabled_post_types();
		if ( '' !== $type ) {
			if ( ! in_array( $type, $types, true ) ) {
				return new WP_Error( 'factdesk_type', self::type_error( $type ) );
			}
			$types = array( $type );
		}
		if ( ! $types ) {
			return array(
				'posts' => array(),
				'total' => 0,
				'pages' => 0,
			);
		}
		$q     = new WP_Query(
			array(
				'post_type'           => $types,
				'post_status'         => 'publish',
				'has_password'        => false,
				'posts_per_page'      => self::PER_PAGE,
				'paged'               => max( 1, (int) $page ),
				'orderby'             => array(
					'type'     => 'ASC',
					'modified' => 'DESC',
				),
				'ignore_sticky_posts' => true,
			)
		);
		$posts = array();
		foreach ( $q->posts as $post ) {
			if ( self::is_available( $post ) ) {
				$posts[] = $post;
			}
		}
		return array(
			'posts' => $posts,
			'total' => (int) $q->found_posts,
			'pages' => (int) $q->max_num_pages,
		);
	}

	public static function type_error( $type ) {
		$names = FactDesk_Settings::enabled_post_types();
		return 'The type "' . $type . '" is not available. Available types: ' . ( $names ? implode( ', ', $names ) : 'none' ) . '.';
	}

	public static function resolve( $ref ) {
		$ref = trim( (string) $ref );
		if ( '' === $ref ) {
			return null;
		}
		if ( ctype_digit( $ref ) ) {
			return get_post( (int) $ref );
		}
		$home = wp_parse_url( home_url( '/' ) );
		if ( preg_match( '#^https?://#i', $ref ) ) {
			$parts = wp_parse_url( $ref );
			if ( empty( $parts['host'] ) || empty( $home['host'] ) || strtolower( preg_replace( '/^www\./i', '', $parts['host'] ) ) !== strtolower( preg_replace( '/^www\./i', '', $home['host'] ) ) ) {
				return new WP_Error( 'factdesk_host', 'Only addresses on ' . $home['host'] . ' can be read here.' );
			}
			$path  = isset( $parts['path'] ) ? $parts['path'] : '/';
			$query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		} else {
			$path  = '/' . ltrim( $ref, '/' );
			$query = '';
		}
		$base = isset( $home['path'] ) ? rtrim( $home['path'], '/' ) : '';
		if ( '' !== $base && 0 === strpos( $path, $base ) ) {
			$path = substr( $path, strlen( $base ) );
		}
		if ( '' === trim( $path, '/' ) && '' === $query ) {
			if ( 'page' === get_option( 'show_on_front' ) && get_option( 'page_on_front' ) ) {
				return get_post( (int) get_option( 'page_on_front' ) );
			}
			return 'home';
		}
		$id = url_to_postid( home_url( $path . $query ) );
		if ( $id ) {
			return get_post( $id );
		}
		$types = FactDesk_Settings::enabled_post_types();
		if ( FactDesk_Settings::products_enabled() ) {
			$types[] = 'product';
		}
		if ( $types ) {
			$found = get_page_by_path( trim( $path, '/' ), OBJECT, $types );
			if ( $found ) {
				return $found;
			}
		}
		return null;
	}

	public static function render( $post ) {
		global $wp_query;
		$previous_post  = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );
		$was_in_loop = $wp_query instanceof WP_Query ? $wp_query->in_the_loop : false;
		if ( $wp_query instanceof WP_Query ) {
			$wp_query->in_the_loop = true;
		}
		$html = apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		if ( $wp_query instanceof WP_Query ) {
			$wp_query->in_the_loop = $was_in_loop;
		}
		$GLOBALS['post'] = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		if ( $previous_post ) {
			setup_postdata( $previous_post );
		}
		$markdown = FactDesk_Markdown::convert( $html, get_permalink( $post ) );
		return (string) apply_filters( 'factdesk_post_markdown', $markdown, $post );
	}

	public static function document( $post ) {
		$lines   = array();
		$lines[] = '# ' . self::title( $post );
		$lines[] = '';
		$lines[] = 'URL: ' . get_permalink( $post );
		$lines[] = 'Type: ' . self::type_label( $post->post_type );
		if ( 'post' === $post->post_type ) {
			$lines[] = 'Published: ' . get_the_date( 'Y-m-d', $post );
		}
		$lines[] = 'Last updated: ' . get_the_modified_date( 'Y-m-d', $post );
		$terms   = self::term_names( $post );
		if ( $terms ) {
			$lines[] = 'Topics: ' . implode( ', ', $terms );
		}
		$body = self::render( $post );
		if ( FactDesk_Markdown::length( $body ) > self::MAX_BODY ) {
			$body = FactDesk_Markdown::cut( $body, self::MAX_BODY ) . "\n\n[The rest of this page is not shown. Open the URL above to read it all.]";
		}
		$lines[] = '';
		$lines[] = '' !== $body ? $body : '(This page has no text content.)';
		return implode( "\n", $lines );
	}

	public static function title( $post ) {
		$title = get_the_title( $post );
		$title = html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return '' !== trim( $title ) ? $title : '(untitled)';
	}

	public static function term_names( $post ) {
		$names      = array();
		$taxonomies = get_object_taxonomies( $post->post_type, 'objects' );
		foreach ( $taxonomies as $taxonomy ) {
			if ( ! $taxonomy->public || 'post_format' === $taxonomy->name || 0 === strpos( $taxonomy->name, 'product_visibility' ) || 'product_type' === $taxonomy->name || 'product_shipping_class' === $taxonomy->name ) {
				continue;
			}
			$terms = get_the_terms( $post, $taxonomy->name );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					if ( 'uncategorized' !== $term->slug ) {
						$names[] = html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
					}
				}
			}
		}
		return array_values( array_unique( $names ) );
	}

	public static function key_links() {
		$links     = array();
		$locations = get_nav_menu_locations();
		$menu_id   = 0;
		foreach ( array( 'primary', 'main', 'menu-1', 'header', 'main-menu', 'primary-menu' ) as $preferred ) {
			if ( ! empty( $locations[ $preferred ] ) ) {
				$menu_id = (int) $locations[ $preferred ];
				break;
			}
		}
		if ( ! $menu_id && $locations ) {
			$menu_id = (int) reset( $locations );
		}
		if ( $menu_id ) {
			$items = wp_get_nav_menu_items( $menu_id );
			if ( is_array( $items ) ) {
				foreach ( $items as $item ) {
					if ( (int) $item->menu_item_parent > 0 || count( $links ) >= 20 ) {
						continue;
					}
					$title = FactDesk_Markdown::plain_text( $item->title );
					if ( '' !== $title && $item->url && '#' !== $item->url ) {
						$links[] = array(
							'title' => $title,
							'url'   => $item->url,
						);
					}
				}
			}
		}
		if ( ! $links ) {
			$pages = get_pages(
				array(
					'sort_column' => 'menu_order,post_title',
					'parent'      => 0,
					'number'      => 15,
				)
			);
			foreach ( $pages as $page ) {
				if ( self::is_available( $page ) ) {
					$links[] = array(
						'title' => self::title( $page ),
						'url'   => get_permalink( $page ),
					);
				}
			}
		}
		return $links;
	}
}
