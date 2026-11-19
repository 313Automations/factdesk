<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FactDesk_Settings {

	const OPTION = 'factdesk_settings';

	private static $cache = null;

	public static function defaults() {
		return array(
			'enabled'         => (bool) get_option( 'blog_public', 1 ),
			'post_types'      => array( 'page', 'post' ),
			'products'        => true,
			'description'     => '',
			'contact_email'   => '',
			'contact_phone'   => '',
			'contact_address' => '',
			'instructions'    => '',
			'exclude_ids'     => array(),
			'rate_limit'      => 60,
			'llms_txt'        => true,
			'server_card'     => true,
			'log_searches'    => false,
		);
	}

	public static function activate() {
		if ( false === get_option( self::OPTION ) ) {
			add_option( self::OPTION, self::defaults(), '', false );
		}
	}

	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		return self::$cache;
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function flush() {
		self::$cache = null;
	}

	public static function available_post_types() {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $types['attachment'], $types['product'] );
		return $types;
	}

	public static function enabled_post_types() {
		$available = self::available_post_types();
		$chosen    = (array) self::get( 'post_types' );
		return array_values( array_intersect( $chosen, array_keys( $available ) ) );
	}

	public static function woocommerce_active() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}

	public static function products_enabled() {
		return self::woocommerce_active() && (bool) self::get( 'products' );
	}

	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = self::defaults();

		foreach ( array( 'enabled', 'products', 'llms_txt', 'server_card', 'log_searches' ) as $flag ) {
			$out[ $flag ] = ! empty( $input[ $flag ] );
		}

		$types             = isset( $input['post_types'] ) ? (array) $input['post_types'] : array();
		$available         = array_keys( self::available_post_types() );
		$out['post_types'] = array_values( array_intersect( array_map( 'sanitize_key', $types ), $available ) );

		$out['description']     = isset( $input['description'] ) ? sanitize_textarea_field( $input['description'] ) : '';
		$out['instructions']    = isset( $input['instructions'] ) ? sanitize_textarea_field( $input['instructions'] ) : '';
		$out['contact_email']   = isset( $input['contact_email'] ) ? sanitize_email( $input['contact_email'] ) : '';
		$out['contact_phone']   = isset( $input['contact_phone'] ) ? sanitize_text_field( $input['contact_phone'] ) : '';
		$out['contact_address'] = isset( $input['contact_address'] ) ? sanitize_textarea_field( $input['contact_address'] ) : '';

		$ids = array();
		if ( isset( $input['exclude_ids'] ) ) {
			$raw = is_array( $input['exclude_ids'] ) ? implode( ',', $input['exclude_ids'] ) : (string) $input['exclude_ids'];
			foreach ( preg_split( '/[\s,]+/', $raw ) as $part ) {
				if ( ! preg_match( '/^\d+$/', $part ) ) {
					continue;
				}
				$id = (int) $part;
				if ( $id > 0 ) {
					$ids[ $id ] = $id;
				}
			}
		}
		$out['exclude_ids'] = array_values( $ids );

		$rate              = isset( $input['rate_limit'] ) ? absint( $input['rate_limit'] ) : 60;
		$out['rate_limit'] = min( 600, max( 10, $rate ) );

		self::$cache = null;
		return $out;
	}
}
