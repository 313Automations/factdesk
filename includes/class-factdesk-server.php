<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FactDesk_Server {

	const NAMESPACE_V1 = 'factdesk/v1';
	const ROUTE        = '/mcp';
	const MAX_BODY     = 65536;
	const MAX_BATCH    = 10;
	const VERSIONS     = array( '2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05' );

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_empty' ), 10, 3 );
		add_filter( 'rest_allowed_cors_headers', array( __CLASS__, 'cors_headers' ) );
	}

	public static function enabled() {
		return (bool) FactDesk_Settings::get( 'enabled' );
	}

	public static function endpoint() {
		return rest_url( self::NAMESPACE_V1 . self::ROUTE );
	}

	public static function register() {
		if ( ! self::enabled() ) {
			return;
		}
		register_rest_route(
			self::NAMESPACE_V1,
			self::ROUTE,
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'handle' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'GET, DELETE',
					'callback'            => array( __CLASS__, 'not_allowed' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	public static function cors_headers( $headers ) {
		return array_values( array_unique( array_merge( (array) $headers, array( 'Mcp-Protocol-Version', 'Mcp-Session-Id', 'Last-Event-ID', 'X-FactDesk-Test' ) ) ) );
	}

	private static function respond( $data, $status = 200 ) {
		$response = new WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'X-Robots-Tag', 'noindex' );
		return $response;
	}

	private static function error( $id, $code, $message ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}

	private static function result( $id, $result ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	private static function text( $text, $failed = false ) {
		return array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => (string) $text,
				),
			),
			'isError' => (bool) $failed,
		);
	}

	public static function not_allowed() {
		$response = self::respond( self::error( null, -32000, 'This MCP server takes JSON-RPC requests by POST (Streamable HTTP, JSON responses, no sessions).' ), 405 );
		$response->header( 'Allow', 'POST, OPTIONS' );
		return $response;
	}

	public static function serve_empty( $served, $result, $request ) {
		if ( $served || ! ( $request instanceof WP_REST_Request ) || self::NAMESPACE_V1 . self::ROUTE !== ltrim( $request->get_route(), '/' ) ) {
			return $served;
		}
		if ( $result instanceof WP_HTTP_Response && 202 === $result->get_status() ) {
			return true;
		}
		return $served;
	}

	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip = apply_filters( 'factdesk_client_ip', $ip );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : 'unknown';
	}

	private static function over_limit() {
		$limit = (int) apply_filters( 'factdesk_rate_limit', (int) FactDesk_Settings::get( 'rate_limit' ) );
		if ( $limit <= 0 ) {
			return false;
		}
		$key   = 'factdesk_rl_' . md5( self::client_ip() . '|' . floor( time() / 60 ) );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return true;
		}
		set_transient( $key, $count + 1, 90 );
		return false;
	}

	public static function handle( WP_REST_Request $request ) {
		$body = (string) $request->get_body();
		if ( strlen( $body ) > self::MAX_BODY ) {
			return self::respond( self::error( null, -32600, 'Request too large' ), 413 );
		}
		if ( self::over_limit() ) {
			$response = self::respond( self::error( null, -32000, 'Too many requests. Wait a minute and try again.' ), 429 );
			$response->header( 'Retry-After', '60' );
			return $response;
		}
		$message = json_decode( $body, true );
		if ( ! is_array( $message ) || array() === $message ) {
			return self::respond( self::error( null, -32700, 'Parse error' ), 400 );
		}
		$batch = array_keys( $message ) === range( 0, count( $message ) - 1 );
		if ( $batch && count( $message ) > self::MAX_BATCH ) {
			return self::respond( self::error( null, -32600, 'Too many requests in one batch' ), 400 );
		}
		$out = array();
		foreach ( $batch ? $message : array( $message ) as $single ) {
			$reply = self::dispatch( $single );
			if ( null !== $reply ) {
				$out[] = $reply;
			}
		}
		if ( ! $out ) {
			return self::respond( null, 202 );
		}
		return self::respond( $batch ? $out : $out[0], 200 );
	}

	private static function dispatch( $message ) {
		if ( ! is_array( $message ) || ! isset( $message['jsonrpc'] ) || '2.0' !== $message['jsonrpc'] || ! isset( $message['method'] ) || ! is_string( $message['method'] ) ) {
			$id = is_array( $message ) && array_key_exists( 'id', $message ) ? $message['id'] : null;
			return self::error( $id, -32600, 'Invalid request' );
		}
		if ( ! array_key_exists( 'id', $message ) ) {
			return null;
		}
		$id     = $message['id'];
		$params = isset( $message['params'] ) && is_array( $message['params'] ) ? $message['params'] : array();

		switch ( $message['method'] ) {
			case 'initialize':
				FactDesk_Stats::record( 'connect' );
				$asked = isset( $params['protocolVersion'] ) && is_string( $params['protocolVersion'] ) ? $params['protocolVersion'] : '';
				return self::result(
					$id,
					array(
						'protocolVersion' => in_array( $asked, self::VERSIONS, true ) ? $asked : self::VERSIONS[0],
						'capabilities'    => array( 'tools' => array( 'listChanged' => false ) ),
						'serverInfo'      => self::server_info(),
						'instructions'    => self::instructions(),
					)
				);
			case 'ping':
				return self::result( $id, new stdClass() );
			case 'tools/list':
				return self::result( $id, array( 'tools' => self::tools() ) );
			case 'tools/call':
				$name = isset( $params['name'] ) && is_string( $params['name'] ) ? $params['name'] : '';
				$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
				if ( ! in_array( $name, wp_list_pluck( self::tools(), 'name' ), true ) ) {
					return self::error( $id, -32602, 'Unknown tool. tools/list lists the tools.' );
				}
				FactDesk_Stats::record( 'call', $name );
				return self::result( $id, self::call( $name, $args ) );
		}
		return self::error( $id, -32601, 'Method not found: ' . substr( $message['method'], 0, 100 ) );
	}

	public static function server_info() {
		return array(
			'name'       => 'factdesk',
			'title'      => FactDesk_Markdown::plain_text( get_bloginfo( 'name' ) ),
			'version'    => FACTDESK_VERSION,
			'websiteUrl' => home_url( '/' ),
		);
	}

	public static function instructions() {
		$name  = FactDesk_Markdown::plain_text( get_bloginfo( 'name' ) );
		$parts = array();

		$parts[] = 'Read-only information from ' . $name . ' (' . home_url( '/' ) . '). Start with get_site_info. Use search_content to find pages and posts, and get_content to read one as Markdown.';
		if ( FactDesk_Settings::products_enabled() ) {
			$parts[] = 'Use search_products and get_product for the shop. Quote prices and stock only from get_product, and send people to the product page to buy.';
		}
		$parts[] = 'Nothing here can place an order, book anything or send a message; send people to the site for that.';
		$extra   = trim( (string) FactDesk_Settings::get( 'instructions' ) );
		if ( '' !== $extra ) {
			$parts[] = $extra;
		}
		return implode( ' ', $parts );
	}

	public static function tools() {
		$read  = array(
			'readOnlyHint'    => true,
			'destructiveHint' => false,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		);
		$types = FactDesk_Settings::enabled_post_types();
		$type  = array(
			'type'        => 'string',
			'description' => 'Optional. Limit to one type: ' . ( $types ? implode( ', ', $types ) : 'none' ) . '.',
		);
		if ( $types ) {
			$type['enum'] = $types;
		}
		$none = array(
			'type'       => 'object',
			'properties' => new stdClass(),
		);

		$tools = array(
			array(
				'name'        => 'get_site_info',
				'title'       => 'Site information',
				'description' => 'What this site is: name, description, address, language, contact details the owner has published, its main pages and what else these tools can do.',
				'inputSchema' => $none,
				'annotations' => $read,
			),
			array(
				'name'        => 'search_content',
				'title'       => 'Search pages and posts',
				'description' => 'Find the pages and posts on this site that match some words. Returns each match with its address, type, last update and a short summary.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'query' => array(
							'type'        => 'string',
							'description' => 'Words to look for.',
						),
						'type'  => $type,
						'limit' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 10,
							'description' => 'How many results, 1 to 10. Default 5.',
						),
					),
					'required'   => array( 'query' ),
				),
				'annotations' => $read,
			),
			array(
				'name'        => 'list_content',
				'title'       => 'List pages and posts',
				'description' => 'List the published pages and posts on this site, ' . FactDesk_Content::PER_PAGE . ' at a time, with their addresses and last update dates.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'type' => $type,
						'page' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Which page of the list, starting at 1.',
						),
					),
				),
				'annotations' => $read,
			),
			array(
				'name'        => 'get_content',
				'title'       => 'Read a page or post',
				'description' => 'The full text of one page or post on this site as Markdown, with its address, type and dates.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'url' => array(
							'type'        => 'string',
							'description' => 'The page address (full URL or path such as /about), or its numeric ID from search_content. Use / for the home page.',
						),
					),
					'required'   => array( 'url' ),
				),
				'annotations' => $read,
			),
		);

		if ( FactDesk_Settings::products_enabled() ) {
			$tools[] = array(
				'name'        => 'search_products',
				'title'       => 'Search products',
				'description' => 'Find products in this shop by words, category, price range or stock. Returns each product with its ID, address, price, availability and a short summary.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'query'         => array(
							'type'        => 'string',
							'description' => 'Optional. Words to look for. Leave empty to list best sellers.',
						),
						'category'      => array(
							'type'        => 'string',
							'description' => 'Optional. A product category name or slug.',
						),
						'in_stock_only' => array(
							'type'        => 'boolean',
							'description' => 'Optional. Only products that are in stock.',
						),
						'min_price'     => array(
							'type'        => 'number',
							'description' => 'Optional. Lowest price, in the shop currency.',
						),
						'max_price'     => array(
							'type'        => 'number',
							'description' => 'Optional. Highest price, in the shop currency.',
						),
						'limit'         => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 20,
							'description' => 'How many results, 1 to 20. Default 10.',
						),
					),
				),
				'annotations' => $read,
			);
			$tools[] = array(
				'name'        => 'get_product',
				'title'       => 'Product details',
				'description' => 'Everything about one product: price, availability, options and variations, description, rating and images.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'product' => array(
							'type'        => 'string',
							'description' => 'The product ID, address, slug or SKU.',
						),
					),
					'required'   => array( 'product' ),
				),
				'annotations' => $read,
			);
		}

		return apply_filters( 'factdesk_tools', $tools );
	}

	public static function call( $name, $args ) {
		try {
			switch ( $name ) {
				case 'get_site_info':
					return self::text( self::site_info() );
				case 'search_content':
					return self::tool_search( $args );
				case 'list_content':
					return self::tool_list( $args );
				case 'get_content':
					return self::tool_get( $args );
				case 'search_products':
					return self::tool_search_products( $args );
				case 'get_product':
					return self::tool_get_product( $args );
			}
			$custom = apply_filters( 'factdesk_call_tool', null, $name, $args );
			if ( is_string( $custom ) ) {
				return self::text( $custom );
			}
			if ( is_array( $custom ) && isset( $custom['content'] ) ) {
				return $custom;
			}
			return self::text( 'This tool is not available.', true );
		} catch ( Throwable $e ) {
			return self::text( 'The tool failed on this site. Try again later.', true );
		}
	}

	private static function string_arg( $args, $key, $max ) {
		$value = isset( $args[ $key ] ) && is_scalar( $args[ $key ] ) ? trim( (string) $args[ $key ] ) : '';
		return strlen( $value ) > $max ? '' : $value;
	}

	public static function site_info() {
		$lines   = array();
		$lines[] = '# ' . FactDesk_Markdown::plain_text( get_bloginfo( 'name' ) );
		$tagline = FactDesk_Markdown::plain_text( get_bloginfo( 'description' ) );
		if ( '' !== $tagline ) {
			$lines[] = '';
			$lines[] = '> ' . $tagline;
		}
		$about = trim( (string) FactDesk_Settings::get( 'description' ) );
		if ( '' !== $about ) {
			$lines[] = '';
			$lines[] = $about;
		}
		$lines[] = '';
		$lines[] = 'Website: ' . home_url( '/' );
		$lines[] = 'Language: ' . get_bloginfo( 'language' );

		$email   = (string) FactDesk_Settings::get( 'contact_email' );
		$phone   = (string) FactDesk_Settings::get( 'contact_phone' );
		$address = (string) FactDesk_Settings::get( 'contact_address' );
		if ( '' !== $email || '' !== $phone || '' !== $address ) {
			$lines[] = '';
			$lines[] = '## Contact';
			if ( '' !== $email ) {
				$lines[] = 'Email: ' . $email;
			}
			if ( '' !== $phone ) {
				$lines[] = 'Phone: ' . $phone;
			}
			if ( '' !== $address ) {
				$lines[] = 'Address: ' . preg_replace( '/\s*\n\s*/', ', ', $address );
			}
		}

		$links = FactDesk_Content::key_links();
		if ( $links ) {
			$lines[] = '';
			$lines[] = '## Main pages';
			foreach ( $links as $link ) {
				$lines[] = '- [' . $link['title'] . '](' . $link['url'] . ')';
			}
		}

		$types = FactDesk_Settings::enabled_post_types();
		if ( $types ) {
			$lines[] = '';
			$lines[] = '## Content';
			foreach ( $types as $type ) {
				$counts  = wp_count_posts( $type );
				$object  = get_post_type_object( $type );
				$lines[] = '- ' . ( $object ? $object->labels->name : $type ) . ' (' . $type . '): ' . (int) ( isset( $counts->publish ) ? $counts->publish : 0 ) . ' published';
			}
		}

		if ( FactDesk_Settings::products_enabled() ) {
			$lines[] = '';
			$lines[] = '## Shop';
			$lines   = array_merge( $lines, FactDesk_Products::overview() );
		}

		$lines[] = '';
		$lines[] = 'Tools: ' . implode( ', ', wp_list_pluck( self::tools(), 'name' ) ) . '.';
		return apply_filters( 'factdesk_site_info', implode( "\n", $lines ) );
	}

	private static function tool_search( $args ) {
		$query = self::string_arg( $args, 'query', 200 );
		if ( '' === $query ) {
			return self::text( 'Give a few words to look for, up to 200 characters.', true );
		}
		$type  = self::string_arg( $args, 'type', 40 );
		$limit = isset( $args['limit'] ) && is_numeric( $args['limit'] ) ? max( 1, min( 10, (int) $args['limit'] ) ) : 5;
		FactDesk_Stats::record_search( 'search_content', $query );
		$found = FactDesk_Content::search( $query, sanitize_key( $type ), $limit );
		if ( is_wp_error( $found ) ) {
			return self::text( $found->get_error_message(), true );
		}
		if ( ! $found ) {
			return self::text( 'Nothing on this site matches "' . $query . '". Try other words, or list_content to see every page.' );
		}
		$lines = array();
		foreach ( $found as $i => $post ) {
			$summary = FactDesk_Content::summary( $post );
			$lines[] = ( $i + 1 ) . '. ' . FactDesk_Content::title( $post ) . ' (' . FactDesk_Content::type_label( $post->post_type ) . ', ID ' . $post->ID . ', updated ' . get_the_modified_date( 'Y-m-d', $post ) . ")\n   " . get_permalink( $post ) . ( '' !== $summary ? "\n   " . $summary : '' );
		}
		return self::text( implode( "\n\n", $lines ) . "\n\nget_content returns the full text of any of these." );
	}

	private static function tool_list( $args ) {
		$type = sanitize_key( self::string_arg( $args, 'type', 40 ) );
		$page = isset( $args['page'] ) && is_numeric( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$list = FactDesk_Content::listing( $type, $page );
		if ( is_wp_error( $list ) ) {
			return self::text( $list->get_error_message(), true );
		}
		if ( ! $list['posts'] ) {
			return self::text( $list['pages'] && $page > $list['pages'] ? 'There are only ' . $list['pages'] . ' pages in this list.' : 'There is nothing to list.' );
		}
		$lines   = array();
		$lines[] = 'Page ' . $page . ' of ' . max( 1, $list['pages'] ) . ' (' . $list['total'] . ' items).';
		$lines[] = '';
		$current = '';
		foreach ( $list['posts'] as $post ) {
			if ( $post->post_type !== $current ) {
				$current = $post->post_type;
				$lines[] = '## ' . FactDesk_Content::type_label( $current );
			}
			$lines[] = '- [' . FactDesk_Content::title( $post ) . '](' . get_permalink( $post ) . ') updated ' . get_the_modified_date( 'Y-m-d', $post );
		}
		if ( $page < $list['pages'] ) {
			$lines[] = '';
			$lines[] = 'More: call list_content with page ' . ( $page + 1 ) . '.';
		}
		return self::text( implode( "\n", $lines ) );
	}

	private static function tool_get( $args ) {
		$ref = self::string_arg( $args, 'url', 500 );
		if ( '' === $ref ) {
			return self::text( 'Give the address of a page on this site, for example /about. search_content and list_content find pages.', true );
		}
		$post = FactDesk_Content::resolve( $ref );
		if ( is_wp_error( $post ) ) {
			return self::text( $post->get_error_message(), true );
		}
		if ( 'home' === $post ) {
			return self::text( self::site_info() . "\n\nThe home page lists the latest posts. list_content with type post lists them all." );
		}
		if ( ! $post || ! FactDesk_Content::is_available( $post ) ) {
			return self::text( 'There is no page at ' . $ref . ' that can be read here. search_content and list_content find pages.', true );
		}
		if ( 'product' === $post->post_type && FactDesk_Settings::products_enabled() ) {
			$product = wc_get_product( $post->ID );
			if ( $product && FactDesk_Products::available( $product ) ) {
				return self::text( FactDesk_Products::document( $product ) );
			}
		}
		return self::text( FactDesk_Content::document( $post ) );
	}

	private static function tool_search_products( $args ) {
		$clean = array(
			'query'         => self::string_arg( $args, 'query', 200 ),
			'category'      => self::string_arg( $args, 'category', 200 ),
			'in_stock_only' => ! empty( $args['in_stock_only'] ) && 'false' !== $args['in_stock_only'],
			'min_price'     => isset( $args['min_price'] ) && is_numeric( $args['min_price'] ) ? (float) $args['min_price'] : null,
			'max_price'     => isset( $args['max_price'] ) && is_numeric( $args['max_price'] ) ? (float) $args['max_price'] : null,
			'limit'         => isset( $args['limit'] ) && is_numeric( $args['limit'] ) ? (int) $args['limit'] : 10,
		);
		if ( '' !== $clean['query'] ) {
			FactDesk_Stats::record_search( 'search_products', $clean['query'] );
		}
		$found = FactDesk_Products::search( $clean );
		if ( is_wp_error( $found ) ) {
			return self::text( $found->get_error_message(), true );
		}
		if ( ! $found ) {
			return self::text( 'No product matches. ' . FactDesk_Products::category_hint() );
		}
		$lines = array();
		foreach ( $found as $i => $product ) {
			$lines[] = ( $i + 1 ) . '. ' . FactDesk_Products::line( $product );
		}
		return self::text( 'Prices in ' . get_woocommerce_currency() . ".\n\n" . implode( "\n\n", $lines ) . "\n\nget_product returns the full details of any of these." );
	}

	private static function tool_get_product( $args ) {
		$ref = self::string_arg( $args, 'product', 500 );
		if ( '' === $ref ) {
			return self::text( 'Give a product ID, address, slug or SKU. search_products finds products.', true );
		}
		$product = FactDesk_Products::resolve( $ref );
		if ( is_wp_error( $product ) ) {
			return self::text( $product->get_error_message(), true );
		}
		if ( ! $product || ! FactDesk_Products::available( $product ) ) {
			return self::text( 'There is no product "' . $ref . '" that can be shown here. search_products finds products.', true );
		}
		return self::text( FactDesk_Products::document( $product ) );
	}
}
