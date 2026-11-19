<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FactDesk_Products {

	const MAX_VARIATIONS = 30;

	public static function available( $product ) {
		if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
			return false;
		}
		if ( 'publish' !== $product->get_status() || 'hidden' === $product->get_catalog_visibility() ) {
			return false;
		}
		$post = get_post( $product->get_id() );
		if ( ! $post || '' !== $post->post_password ) {
			return false;
		}
		return FactDesk_Content::is_available( $post );
	}

	public static function category_slug( $category ) {
		$category = trim( (string) $category );
		if ( '' === $category ) {
			return '';
		}
		$term = get_term_by( 'slug', sanitize_title( $category ), 'product_cat' );
		if ( ! $term ) {
			$term = get_term_by( 'name', $category, 'product_cat' );
		}
		return $term ? $term->slug : null;
	}

	public static function search( $args ) {
		$query    = isset( $args['query'] ) ? trim( (string) $args['query'] ) : '';
		$limit    = isset( $args['limit'] ) ? max( 1, min( 20, (int) $args['limit'] ) ) : 10;
		$in_stock = ! empty( $args['in_stock_only'] );
		$min      = isset( $args['min_price'] ) && is_numeric( $args['min_price'] ) ? (float) $args['min_price'] : null;
		$max      = isset( $args['max_price'] ) && is_numeric( $args['max_price'] ) ? (float) $args['max_price'] : null;

		$params = array(
			'status'       => 'publish',
			'limit'        => min( 60, $limit * 3 ),
			'visibility'   => '' !== $query ? 'search' : 'catalog',
			'has_password' => false,
			'return'       => 'objects',
		);
		if ( '' !== $query ) {
			$params['s']       = $query;
			$params['orderby'] = 'relevance';
		} else {
			$params['orderby'] = 'popularity';
		}
		if ( isset( $args['category'] ) && '' !== trim( (string) $args['category'] ) ) {
			$slug = self::category_slug( $args['category'] );
			if ( null === $slug ) {
				return new WP_Error( 'factdesk_category', 'There is no product category called "' . $args['category'] . '". ' . self::category_hint() );
			}
			$params['category'] = array( $slug );
		}
		if ( $in_stock ) {
			$params['stock_status'] = 'instock';
		}
		if ( 'popularity' === $params['orderby'] ) {
			$params['orderby'] = 'meta_value_num';
			$params['meta_key'] = 'total_sales'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$params['order']    = 'DESC';
		}

		$out = array();
		foreach ( wc_get_products( $params ) as $product ) {
			if ( count( $out ) >= $limit ) {
				break;
			}
			if ( ! self::available( $product ) ) {
				continue;
			}
			if ( null !== $min || null !== $max ) {
				$low  = $product->is_type( 'variable' ) ? $product->get_variation_price( 'min', true ) : wc_get_price_to_display( $product );
				$high = $product->is_type( 'variable' ) ? $product->get_variation_price( 'max', true ) : $low;
				if ( '' === $low || null === $low ) {
					continue;
				}
				if ( ( null !== $min && (float) $high < $min ) || ( null !== $max && (float) $low > $max ) ) {
					continue;
				}
			}
			$out[] = $product;
		}
		return $out;
	}

	public static function category_hint() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'number'     => 30,
				'orderby'    => 'count',
				'order'      => 'DESC',
			)
		);
		if ( is_wp_error( $terms ) || ! $terms ) {
			return '';
		}
		$names = array();
		foreach ( $terms as $term ) {
			$names[] = html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . ' (' . $term->slug . ')';
		}
		return 'Categories: ' . implode( ', ', $names ) . '.';
	}

	public static function resolve( $ref ) {
		$ref = trim( (string) $ref );
		if ( '' === $ref ) {
			return null;
		}
		$id = 0;
		if ( ctype_digit( $ref ) ) {
			$id = (int) $ref;
		} elseif ( preg_match( '#^https?://#i', $ref ) || 0 === strpos( $ref, '/' ) ) {
			$found = FactDesk_Content::resolve( $ref );
			if ( is_wp_error( $found ) ) {
				return $found;
			}
			$id = ( $found instanceof WP_Post ) ? (int) $found->ID : 0;
		} else {
			$post = get_page_by_path( sanitize_title( $ref ), OBJECT, 'product' );
			if ( $post ) {
				$id = (int) $post->ID;
			} elseif ( function_exists( 'wc_get_product_id_by_sku' ) ) {
				$id = (int) wc_get_product_id_by_sku( $ref );
			}
		}
		if ( ! $id ) {
			return null;
		}
		$product = wc_get_product( $id );
		if ( $product && $product->is_type( 'variation' ) ) {
			$product = wc_get_product( $product->get_parent_id() );
		}
		return $product ? $product : null;
	}

	public static function money( $amount ) {
		if ( '' === $amount || null === $amount ) {
			return '';
		}
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	public static function price_text( $product ) {
		$html = (string) $product->get_price_html();
		if ( '' === trim( $html ) ) {
			return 'No price shown';
		}
		$html = preg_replace( '#<span[^>]*class="[^"]*screen-reader-text[^"]*"[^>]*>.*?</span>#is', '', $html );
		$html = preg_replace( '#<del[^>]*>(.*?)</del>\s*<ins[^>]*>(.*?)</ins>#is', '$2 (was $1)', $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', $text ) );
	}

	public static function stock_text( $product ) {
		$availability = $product->get_availability();
		$text         = isset( $availability['availability'] ) ? trim( wp_strip_all_tags( $availability['availability'] ) ) : '';
		if ( '' !== $text ) {
			return html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		switch ( $product->get_stock_status() ) {
			case 'outofstock':
				return 'Out of stock';
			case 'onbackorder':
				return 'Available on backorder';
		}
		return 'In stock';
	}

	public static function categories( $product ) {
		$names = array();
		$terms = get_the_terms( $product->get_id(), 'product_cat' );
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$names[] = html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}
		return $names;
	}

	public static function line( $product ) {
		$parts = array(
			self::price_text( $product ),
			self::stock_text( $product ),
		);
		$cats  = self::categories( $product );
		if ( $cats ) {
			$parts[] = implode( ', ', $cats );
		}
		$short = FactDesk_Markdown::plain_text( $product->get_short_description() ? $product->get_short_description() : $product->get_description(), 160 );
		return '**' . FactDesk_Markdown::plain_text( $product->get_name() ) . '** (ID ' . $product->get_id() . ')' . "\n   " . $product->get_permalink() . "\n   " . implode( ' | ', $parts ) . ( '' !== $short ? "\n   " . $short : '' );
	}

	public static function document( $product ) {
		$lines   = array();
		$lines[] = '# ' . FactDesk_Markdown::plain_text( $product->get_name() );
		$lines[] = '';
		$lines[] = 'URL: ' . $product->get_permalink();
		$lines[] = 'Product ID: ' . $product->get_id();
		$lines[] = 'Price: ' . self::price_text( $product );
		$lines[] = 'Currency: ' . get_woocommerce_currency();
		if ( wc_tax_enabled() ) {
			$lines[] = 'Prices shown ' . ( 'incl' === get_option( 'woocommerce_tax_display_shop' ) ? 'include' : 'exclude' ) . ' tax.';
		}
		$lines[] = 'Availability: ' . self::stock_text( $product );
		if ( $product->get_sku() ) {
			$lines[] = 'SKU: ' . $product->get_sku();
		}
		$cats = self::categories( $product );
		if ( $cats ) {
			$lines[] = 'Categories: ' . implode( ', ', $cats );
		}
		$tags = get_the_terms( $product->get_id(), 'product_tag' );
		if ( is_array( $tags ) && $tags ) {
			$lines[] = 'Tags: ' . implode( ', ', wp_list_pluck( $tags, 'name' ) );
		}
		if ( 'yes' === get_option( 'woocommerce_enable_reviews', 'yes' ) && $product->get_review_count() > 0 ) {
			$lines[] = 'Rating: ' . round( (float) $product->get_average_rating(), 1 ) . ' out of 5 from ' . $product->get_review_count() . ' ' . ( 1 === (int) $product->get_review_count() ? 'review' : 'reviews' );
		}
		if ( $product->has_weight() ) {
			$lines[] = 'Weight: ' . $product->get_weight() . ' ' . get_option( 'woocommerce_weight_unit' );
		}
		if ( $product->has_dimensions() ) {
			$lines[] = 'Dimensions: ' . html_entity_decode( wc_format_dimensions( $product->get_dimensions( false ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		$options = self::attributes( $product );
		if ( $options ) {
			$lines[] = '';
			$lines[] = '## Options';
			foreach ( $options as $name => $values ) {
				$lines[] = '- ' . $name . ': ' . $values;
			}
		}

		if ( $product->is_type( 'variable' ) ) {
			$variations = self::variations( $product );
			if ( $variations ) {
				$lines[] = '';
				$lines[] = '## Variations';
				foreach ( $variations as $variation ) {
					$lines[] = '- ' . $variation;
				}
			}
		}

		if ( $product->is_type( 'grouped' ) ) {
			$children = array();
			foreach ( $product->get_children() as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( $child && self::available( $child ) ) {
					$children[] = '- ' . FactDesk_Markdown::plain_text( $child->get_name() ) . ': ' . self::price_text( $child ) . ', ' . self::stock_text( $child ) . ' (' . $child->get_permalink() . ')';
				}
			}
			if ( $children ) {
				$lines[] = '';
				$lines[] = '## Products in this group';
				$lines   = array_merge( $lines, $children );
			}
		}

		$short = FactDesk_Markdown::convert( $product->get_short_description(), $product->get_permalink() );
		if ( '' !== $short ) {
			$lines[] = '';
			$lines[] = '## Summary';
			$lines[] = $short;
		}
		$long = FactDesk_Markdown::convert( wpautop( $product->get_description() ), $product->get_permalink() );
		if ( '' !== $long ) {
			if ( FactDesk_Markdown::length( $long ) > 15000 ) {
				$long = FactDesk_Markdown::cut( $long, 15000 ) . "\n\n[Description shortened. The full text is on the product page.]";
			}
			$lines[] = '';
			$lines[] = '## Description';
			$lines[] = $long;
		}

		$images = array();
		$ids    = array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() );
		foreach ( array_slice( array_filter( $ids ), 0, 5 ) as $image_id ) {
			$url = wp_get_attachment_image_url( (int) $image_id, 'large' );
			if ( $url ) {
				$images[] = '- ' . $url;
			}
		}
		if ( $images ) {
			$lines[] = '';
			$lines[] = '## Images';
			$lines   = array_merge( $lines, $images );
		}

		$lines[] = '';
		$lines[] = 'To buy, open the product page. Prices and stock can change; the product page is always current.';
		return implode( "\n", $lines );
	}

	public static function attributes( $product ) {
		$out = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! is_a( $attribute, 'WC_Product_Attribute' ) || ! $attribute->get_visible() ) {
				continue;
			}
			$name = wc_attribute_label( $attribute->get_name(), $product );
			if ( $attribute->is_taxonomy() ) {
				$values = wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) );
			} else {
				$values = $attribute->get_options();
			}
			$values = array_map(
				function ( $value ) {
					return html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				},
				(array) $values
			);
			if ( $values ) {
				$out[ html_entity_decode( $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ] = implode( ', ', $values );
			}
		}
		return $out;
	}

	public static function variations( $product ) {
		$out   = array();
		$count = 0;
		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( ! $variation || ! $variation->variation_is_visible() ) {
				continue;
			}
			if ( ++$count > self::MAX_VARIATIONS ) {
				$out[] = 'More variations are listed on the product page.';
				break;
			}
			$label = array();
			foreach ( $variation->get_variation_attributes( false ) as $taxonomy => $value ) {
				$name = wc_attribute_label( $taxonomy, $product );
				if ( '' === $value ) {
					$value = 'any';
				} elseif ( taxonomy_exists( $taxonomy ) ) {
					$term  = get_term_by( 'slug', $value, $taxonomy );
					$value = $term ? $term->name : $value;
				}
				$label[] = html_entity_decode( $name . ': ' . $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
			$sku   = $variation->get_sku() && $variation->get_sku() !== $product->get_sku() ? ', SKU ' . $variation->get_sku() : '';
			$out[] = implode( ', ', $label ) . ' | ' . self::price_text( $variation ) . ' | ' . self::stock_text( $variation ) . $sku;
		}
		return $out;
	}

	public static function overview() {
		$lines = array();
		$shop  = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : '';
		if ( $shop ) {
			$lines[] = 'Shop: ' . $shop;
		}
		$lines[] = 'Currency: ' . get_woocommerce_currency();
		$counts  = wp_count_posts( 'product' );
		if ( $counts && isset( $counts->publish ) ) {
			$lines[] = 'Published products: ' . (int) $counts->publish;
		}
		$hint = self::category_hint();
		if ( '' !== $hint ) {
			$lines[] = $hint;
		}
		return $lines;
	}
}
