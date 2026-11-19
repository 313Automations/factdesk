<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FactDesk_Markdown {

	private static $skip = array( 'script', 'style', 'noscript', 'template', 'form', 'iframe', 'svg', 'button', 'input', 'select', 'textarea', 'canvas', 'object', 'embed', 'audio', 'video', 'source', 'link', 'meta', 'head', 'title' );

	private static $blocks = array( 'p', 'div', 'section', 'article', 'header', 'footer', 'main', 'aside', 'figure', 'figcaption', 'address', 'details', 'summary', 'dl', 'dt', 'dd', 'center', 'nav' );

	private $base;

	private function __construct( $base ) {
		$this->base = untrailingslashit( $base );
	}

	public static function convert( $html, $base = '' ) {
		$html = (string) $html;
		if ( '' === trim( $html ) ) {
			return '';
		}
		if ( ! class_exists( 'DOMDocument' ) ) {
			return self::plain_text( $html );
		}
		$base = '' !== $base ? $base : home_url( '/' );
		$self = new self( $base );

		$previous = libxml_use_internal_errors( true );
		$doc      = new DOMDocument();
		$loaded   = $doc->loadHTML( '<?xml encoding="UTF-8"><html><body><div id="factdesk-root">' . $html . '</div></body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded ) {
			return self::plain_text( $html );
		}
		$xpath = new DOMXPath( $doc );
		$root  = $xpath->query( "//div[@id='factdesk-root']" )->item( 0 );
		if ( ! $root ) {
			return self::plain_text( $html );
		}
		$md = $self->children( $root, 0 );
		return self::tidy( $md );
	}

	public static function plain_text( $html, $length = 0 ) {
		$text = wp_strip_all_tags( (string) $html, true );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', $text ) );
		if ( $length > 0 && self::length( $text ) > $length ) {
			$text = self::cut( $text, $length );
		}
		return $text;
	}

	public static function length( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}

	public static function cut( $text, $length ) {
		$short = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length, 'UTF-8' ) : substr( $text, 0, $length );
		$space = strrpos( $short, ' ' );
		if ( false !== $space && $space > strlen( $short ) * 0.6 ) {
			$short = substr( $short, 0, $space );
		}
		return rtrim( $short, " ,.;:-" ) . '...';
	}

	private static function tidy( $md ) {
		$md    = str_replace( array( "\r\n", "\r" ), "\n", $md );
		$lines = explode( "\n", $md );
		$fence = false;
		foreach ( $lines as $i => $line ) {
			if ( 0 === strpos( ltrim( $line ), '```' ) ) {
				$fence       = ! $fence;
				$lines[ $i ] = trim( $line );
				continue;
			}
			if ( $fence ) {
				$lines[ $i ] = rtrim( $line );
				continue;
			}
			if ( preg_match( '/^(\s+)([-*]|\d+\.)\s/', $line, $m ) ) {
				$lines[ $i ] = $m[1] . preg_replace( '/(?<=\S) {2,}/', ' ', ltrim( rtrim( $line ) ) );
				continue;
			}
			$lines[ $i ] = preg_replace( '/(?<=\S) {2,}/', ' ', trim( $line ) );
			$lines[ $i ] = preg_replace( '/ ([,.;:!?])(?=\s|$)/', '$1', $lines[ $i ] );
		}
		$md = implode( "\n", $lines );
		$md = preg_replace( "/\n{3,}/", "\n\n", $md );
		return trim( $md );
	}

	private function children( $node, $depth ) {
		$out = '';
		foreach ( $node->childNodes as $child ) {
			$out .= $this->node( $child, $depth );
		}
		return $out;
	}

	private function inline( $node, $depth ) {
		$text = $this->children( $node, $depth );
		return trim( preg_replace( '/[ \t]*\n[ \t]*/', ' ', preg_replace( '/\s{2,}/u', ' ', $text ) ) );
	}

	private function node( $node, $depth ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			return preg_replace( '/[\s\x{00A0}]+/u', ' ', $node->nodeValue );
		}
		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return '';
		}
		$tag = strtolower( $node->nodeName );
		if ( in_array( $tag, self::$skip, true ) ) {
			return '';
		}
		if ( $node->hasAttribute( 'hidden' ) || 'true' === $node->getAttribute( 'aria-hidden' ) ) {
			return '';
		}

		switch ( $tag ) {
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				$text = $this->inline( $node, $depth );
				return '' === $text ? '' : "\n\n" . str_repeat( '#', (int) substr( $tag, 1 ) ) . ' ' . $text . "\n\n";
			case 'br':
				return "\n";
			case 'hr':
				return "\n\n---\n\n";
			case 'strong':
			case 'b':
				$text = $this->inline( $node, $depth );
				return '' === $text ? '' : ' **' . $text . '** ';
			case 'em':
			case 'i':
				$text = $this->inline( $node, $depth );
				return '' === $text ? '' : ' *' . $text . '* ';
			case 'code':
				if ( $node->parentNode && 'pre' === strtolower( $node->parentNode->nodeName ) ) {
					return $node->textContent;
				}
				$text = trim( $node->textContent );
				return '' === $text ? '' : ' `' . str_replace( '`', "'", $text ) . '` ';
			case 'pre':
				$text = rtrim( $node->textContent );
				return '' === trim( $text ) ? '' : "\n\n```\n" . $text . "\n```\n\n";
			case 'a':
				return $this->link( $node, $depth );
			case 'img':
				return $this->image( $node );
			case 'ul':
			case 'ol':
				return $this->listing( $node, $depth, 'ol' === $tag );
			case 'blockquote':
				$inner = self::tidy( $this->children( $node, $depth ) );
				if ( '' === $inner ) {
					return '';
				}
				return "\n\n> " . str_replace( "\n", "\n> ", $inner ) . "\n\n";
			case 'table':
				return $this->table( $node, $depth );
			case 'li':
				return $this->inline( $node, $depth );
		}

		if ( in_array( $tag, self::$blocks, true ) ) {
			$inner = $this->children( $node, $depth );
			return '' === trim( $inner ) ? '' : "\n\n" . trim( $inner ) . "\n\n";
		}
		return $this->children( $node, $depth );
	}

	private function absolute( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( preg_match( '#^(https?:|mailto:|tel:)#i', $url ) ) {
			return $url;
		}
		if ( 0 === strpos( $url, '//' ) ) {
			return ( is_ssl() ? 'https:' : 'http:' ) . $url;
		}
		if ( 0 === strpos( $url, '#' ) ) {
			return '';
		}
		if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url ) ) {
			return '';
		}
		$parts = wp_parse_url( $this->base );
		if ( empty( $parts['host'] ) ) {
			return '';
		}
		$origin = ( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		if ( 0 === strpos( $url, '/' ) ) {
			return $origin . $url;
		}
		return $this->base . '/' . $url;
	}

	private function link( $node, $depth ) {
		$text = $this->inline( $node, $depth );
		$url  = $this->absolute( $node->getAttribute( 'href' ) );
		if ( '' === $text ) {
			return '';
		}
		if ( '' === $url ) {
			return $text;
		}
		return ' [' . str_replace( array( '[', ']' ), array( '(', ')' ), $text ) . '](' . str_replace( array( ' ', ')' ), array( '%20', '%29' ), $url ) . ') ';
	}

	private function image( $node ) {
		$src = $node->getAttribute( 'src' );
		if ( '' === $src || 0 === strpos( $src, 'data:' ) ) {
			$src = $node->getAttribute( 'data-src' );
		}
		$src = $this->absolute( $src );
		if ( '' === $src || ! preg_match( '#^https?:#i', $src ) ) {
			return '';
		}
		$alt = trim( preg_replace( '/\s+/', ' ', $node->getAttribute( 'alt' ) ) );
		return ' ![' . str_replace( array( '[', ']' ), '', $alt ) . '](' . str_replace( ' ', '%20', $src ) . ') ';
	}

	private function listing( $node, $depth, $ordered ) {
		$out    = '';
		$number = 1;
		$indent = str_repeat( '  ', $depth );
		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType || 'li' !== strtolower( $child->nodeName ) ) {
				continue;
			}
			$text   = '';
			$nested = '';
			foreach ( $child->childNodes as $part ) {
				$name = XML_ELEMENT_NODE === $part->nodeType ? strtolower( $part->nodeName ) : '';
				if ( 'ul' === $name || 'ol' === $name ) {
					$nested .= $this->listing( $part, $depth + 1, 'ol' === $name );
				} else {
					$text .= $this->node( $part, $depth + 1 );
				}
			}
			$text   = trim( preg_replace( '/\s+/u', ' ', $text ) );
			$marker = $ordered ? $number . '.' : '-';
			if ( '' !== $text || '' !== $nested ) {
				$out .= $indent . $marker . ' ' . $text . "\n" . $nested;
				++$number;
			}
		}
		return 0 === $depth ? "\n\n" . $out . "\n" : $out;
	}

	private function table( $node, $depth ) {
		$rows = array();
		$xp   = new DOMXPath( $node->ownerDocument );
		foreach ( $xp->query( './/tr', $node ) as $tr ) {
			$cells = array();
			foreach ( $tr->childNodes as $cell ) {
				if ( XML_ELEMENT_NODE !== $cell->nodeType ) {
					continue;
				}
				$name = strtolower( $cell->nodeName );
				if ( 'td' === $name || 'th' === $name ) {
					$cells[] = str_replace( '|', '\|', $this->inline( $cell, $depth ) );
				}
			}
			if ( $cells ) {
				$rows[] = $cells;
			}
		}
		if ( ! $rows ) {
			return '';
		}
		$width = 0;
		foreach ( $rows as $cells ) {
			$width = max( $width, count( $cells ) );
		}
		$out = "\n\n";
		foreach ( $rows as $i => $cells ) {
			$cells = array_pad( $cells, $width, '' );
			$out  .= '| ' . implode( ' | ', $cells ) . " |\n";
			if ( 0 === $i ) {
				$out .= '|' . str_repeat( ' --- |', $width ) . "\n";
			}
		}
		return $out . "\n";
	}
}
