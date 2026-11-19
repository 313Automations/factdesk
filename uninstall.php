<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

function factdesk_uninstall_site() {
	global $wpdb;
	delete_option( 'factdesk_settings' );
	delete_option( 'factdesk_stats' );
	delete_option( 'factdesk_searches' );
	delete_post_meta_by_key( '_factdesk_exclude' );
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_factdesk_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_factdesk_' ) . '%'
		)
	);
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $factdesk_blog_id ) {
		switch_to_blog( $factdesk_blog_id );
		factdesk_uninstall_site();
		restore_current_blog();
	}
} else {
	factdesk_uninstall_site();
}
