<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FactDesk_Discovery {

	const CARD_PATH = '.well-known/mcp/server-card.json';
	const LLMS_PATH = 'llms.txt';

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve' ), 1 );
	}

	public static function card_url() {
		return home_url( '/' . self::CARD_PATH );
	}

	public static function llms_url() {
		return home_url( '/' . self::LLMS_PATH );
	}

	public static function request_path() {
		global $wp;
		return isset( $wp->request ) ? trim( (string) $wp->request, '/' ) : '';
	}

	public static function maybe_serve() {
		if ( ! is_404() || ! FactDesk_Server::enabled() ) {
			return;
		}
		$path = self::request_path();
		if ( self::CARD_PATH === $path && FactDesk_Settings::get( 'server_card' ) ) {
			self::send( wp_json_encode( self::card(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), 'application/json' );
		}
		if ( self::LLMS_PATH === $path && FactDesk_Settings::get( 'llms_txt' ) ) {
			self::send( self::llms(), 'text/plain' );
		}
	}

	private static function send( $body, $type ) {
		status_header( 200 );
		header( 'Content-Type: ' . $type . '; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'X-Robots-Tag: noindex' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public static function card() {
		$tools = array();
		foreach ( FactDesk_Server::tools() as $tool ) {
			$tools[] = array(
				'name'        => $tool['name'],
				'title'       => isset( $tool['title'] ) ? $tool['title'] : $tool['name'],
				'description' => $tool['description'],
				'inputSchema' => $tool['inputSchema'],
			);
		}
		$description = trim( (string) FactDesk_Settings::get( 'description' ) );
		if ( '' === $description ) {
			$description = FactDesk_Markdown::plain_text( get_bloginfo( 'description' ) );
		}
		$card = array(
			'serverInfo'     => array(
				'name'    => 'factdesk',
				'title'   => FactDesk_Markdown::plain_text( get_bloginfo( 'name' ) ),
				'version' => FACTDESK_VERSION,
			),
			'description'    => 'Read-only information from ' . FactDesk_Markdown::plain_text( get_bloginfo( 'name' ) ) . ( '' !== $description ? ': ' . $description : '' ) . '. No sign-in.',
			'url'            => FactDesk_Server::endpoint(),
			'transport'      => array(
				'type'     => 'streamable-http',
				'endpoint' => FactDesk_Server::endpoint(),
			),
			'protocolVersion' => FactDesk_Server::VERSIONS[0],
			'capabilities'   => array( 'tools' => array( 'listChanged' => false ) ),
			'authentication' => array( 'required' => false ),
			'tools'          => $tools,
			'websiteUrl'     => home_url( '/' ),
		);
		$email = (string) FactDesk_Settings::get( 'contact_email' );
		if ( '' !== $email ) {
			$card['contact'] = array( 'email' => $email );
		}
		return apply_filters( 'factdesk_server_card', $card );
	}

	public static function llms() {
		$name  = FactDesk_Markdown::plain_text( get_bloginfo( 'name' ) );
		$lines = array( '# ' . $name, '' );

		$about = trim( (string) FactDesk_Settings::get( 'description' ) );
		if ( '' === $about ) {
			$about = FactDesk_Markdown::plain_text( get_bloginfo( 'description' ) );
		}
		if ( '' !== $about ) {
			$lines[] = '> ' . preg_replace( '/\s*\n\s*/', ' ', $about );
			$lines[] = '';
		}

		$lines[] = 'AI agents can read this site through its MCP server at ' . FactDesk_Server::endpoint() . ' (Streamable HTTP, read-only, no sign-in). Server card: ' . self::card_url();
		$lines[] = '';

		$pages = get_pages(
			array(
				'sort_column' => 'menu_order,post_title',
				'number'      => 60,
			)
		);
		$items = array();
		foreach ( $pages as $page ) {
			if ( count( $items ) >= 40 ) {
				break;
			}
			if ( FactDesk_Content::is_available( $page ) ) {
				$summary = FactDesk_Content::summary( $page, 140 );
				$items[] = '- [' . FactDesk_Content::title( $page ) . '](' . get_permalink( $page ) . ')' . ( '' !== $summary ? ': ' . $summary : '' );
			}
		}
		if ( $items ) {
			$lines[] = '## Pages';
			$lines[] = '';
			$lines   = array_merge( $lines, $items, array( '' ) );
		}

		if ( in_array( 'post', FactDesk_Settings::enabled_post_types(), true ) ) {
			$posts = get_posts(
				array(
					'numberposts' => 30,
					'post_status' => 'publish',
				)
			);
			$items = array();
			foreach ( $posts as $post ) {
				if ( count( $items ) >= 20 ) {
					break;
				}
				if ( FactDesk_Content::is_available( $post ) ) {
					$items[] = '- [' . FactDesk_Content::title( $post ) . '](' . get_permalink( $post ) . ')';
				}
			}
			if ( $items ) {
				$lines[] = '## Recent posts';
				$lines[] = '';
				$lines   = array_merge( $lines, $items, array( '' ) );
			}
		}

		if ( FactDesk_Settings::products_enabled() ) {
			$terms = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => true,
					'number'     => 20,
					'orderby'    => 'count',
					'order'      => 'DESC',
				)
			);
			$items = array();
			$shop  = wc_get_page_permalink( 'shop' );
			if ( $shop ) {
				$items[] = '- [Shop](' . $shop . ')';
			}
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$link = get_term_link( $term );
					if ( ! is_wp_error( $link ) ) {
						$items[] = '- [' . html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . '](' . $link . ')';
					}
				}
			}
			if ( $items ) {
				$lines[] = '## Products';
				$lines[] = '';
				$lines   = array_merge( $lines, $items, array( '' ) );
			}
		}

		return (string) apply_filters( 'factdesk_llms_txt', implode( "\n", $lines ) );
	}
}
