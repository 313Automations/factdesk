<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FactDesk_Stats {

	const OPTION   = 'factdesk_stats';
	const SEARCHES = 'factdesk_searches';
	const DAYS     = 30;
	const TERMS    = 100;

	public static function client_name() {
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$agent = trim( $agent );
		if ( '' === $agent ) {
			return 'unknown';
		}
		$first = preg_split( '#[\s/;(]#', $agent );
		$name  = isset( $first[0] ) && '' !== $first[0] ? $first[0] : $agent;
		return substr( preg_replace( '/[^A-Za-z0-9._-]/', '', $name ), 0, 40 );
	}

	public static function is_self_test() {
		return isset( $_SERVER['HTTP_X_FACTDESK_TEST'] );
	}

	public static function record( $kind, $tool = '' ) {
		if ( self::is_self_test() ) {
			return;
		}
		$stats = get_option( self::OPTION, array() );
		$stats = is_array( $stats ) ? $stats : array();
		$day   = gmdate( 'Y-m-d' );
		if ( ! isset( $stats[ $day ] ) ) {
			$stats[ $day ] = array(
				'connections' => 0,
				'calls'       => 0,
				'tools'       => array(),
				'clients'     => array(),
			);
		}
		if ( 'connect' === $kind ) {
			++$stats[ $day ]['connections'];
		} else {
			++$stats[ $day ]['calls'];
			if ( '' !== $tool ) {
				$stats[ $day ]['tools'][ $tool ] = isset( $stats[ $day ]['tools'][ $tool ] ) ? $stats[ $day ]['tools'][ $tool ] + 1 : 1;
			}
		}
		$client = self::client_name();
		if ( count( $stats[ $day ]['clients'] ) < 50 || isset( $stats[ $day ]['clients'][ $client ] ) ) {
			$stats[ $day ]['clients'][ $client ] = isset( $stats[ $day ]['clients'][ $client ] ) ? $stats[ $day ]['clients'][ $client ] + 1 : 1;
		}
		krsort( $stats );
		$stats = array_slice( $stats, 0, self::DAYS, true );
		update_option( self::OPTION, $stats, false );
	}

	public static function record_search( $tool, $term ) {
		if ( ! FactDesk_Settings::get( 'log_searches' ) || self::is_self_test() ) {
			return;
		}
		$term = trim( sanitize_text_field( (string) $term ) );
		if ( '' === $term ) {
			return;
		}
		$list = get_option( self::SEARCHES, array() );
		$list = is_array( $list ) ? $list : array();
		array_unshift(
			$list,
			array(
				'time' => time(),
				'tool' => $tool,
				'term' => substr( $term, 0, 120 ),
			)
		);
		update_option( self::SEARCHES, array_slice( $list, 0, self::TERMS ), false );
	}

	public static function days() {
		$stats = get_option( self::OPTION, array() );
		return is_array( $stats ) ? $stats : array();
	}

	public static function searches() {
		$list = get_option( self::SEARCHES, array() );
		return is_array( $list ) ? $list : array();
	}

	public static function clear() {
		delete_option( self::OPTION );
		delete_option( self::SEARCHES );
	}
}
