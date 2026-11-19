<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FactDesk_Admin {

	const PAGE  = 'factdesk';
	const GROUP = 'factdesk';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_factdesk_clear', array( __CLASS__, 'clear_activity' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save_meta' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( FACTDESK_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		add_options_page(
			__( 'FactDesk', 'factdesk' ),
			__( 'FactDesk', 'factdesk' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	public static function register_settings() {
		register_setting(
			self::GROUP,
			FactDesk_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'FactDesk_Settings', 'sanitize' ),
				'default'           => FactDesk_Settings::defaults(),
			)
		);
	}

	public static function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'factdesk' ) . '</a>' );
		return $links;
	}

	public static function assets( $hook ) {
		if ( 'settings_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'factdesk-admin', FACTDESK_URL . 'assets/admin.css', array(), FACTDESK_VERSION );
		wp_enqueue_script( 'factdesk-admin', FACTDESK_URL . 'assets/admin.js', array(), FACTDESK_VERSION, true );
		wp_localize_script(
			'factdesk-admin',
			'factDeskAdmin',
			array(
				'endpoint' => FactDesk_Server::endpoint(),
				'enabled'  => FactDesk_Server::enabled(),
				'text'     => array(
					'copied'      => __( 'Copied.', 'factdesk' ),
					'copyFailed'  => __( 'Select the text and copy it with Ctrl+C (Cmd+C on a Mac).', 'factdesk' ),
					'testing'     => __( 'Testing as a logged-out visitor...', 'factdesk' ),
					'off'         => __( 'The server is switched off. Turn it on below and save first.', 'factdesk' ),
					'connect'     => __( 'Connection', 'factdesk' ),
					'tools'       => __( 'Tools', 'factdesk' ),
					'siteInfo'    => __( 'Site information', 'factdesk' ),
					'ok'          => __( 'Working', 'factdesk' ),
					'failed'      => __( 'Failed', 'factdesk' ),
					'protocol'    => __( 'protocol version', 'factdesk' ),
					'toolCount'   => __( 'tools available', 'factdesk' ),
					'blocked'     => __( 'The request was refused. A security plugin or firewall may be blocking the REST API for logged-out visitors.', 'factdesk' ),
					'unreachable' => __( 'The endpoint could not be reached.', 'factdesk' ),
					'allGood'     => __( 'All three checks passed. AI agents can use this server.', 'factdesk' ),
				),
			)
		);
	}

	private static function checks() {
		$s      = FactDesk_Settings::all();
		$checks = array();

		$checks[] = array(
			'ok'   => (bool) $s['enabled'],
			'text' => $s['enabled'] ? __( 'The MCP server is switched on.', 'factdesk' ) : __( 'The MCP server is switched off.', 'factdesk' ),
		);
		$public   = (bool) get_option( 'blog_public', 1 );
		$checks[] = array(
			'ok'   => $public,
			'text' => $public ? __( 'Search engines are allowed to index this site.', 'factdesk' ) : __( 'This site asks search engines not to index it (Settings, Reading). Consider whether AI agents should read it.', 'factdesk' ),
		);
		$pretty   = '' !== (string) get_option( 'permalink_structure' );
		$checks[] = array(
			'ok'   => $pretty,
			'text' => $pretty ? __( 'Pretty permalinks are on, so the server card and llms.txt can be served.', 'factdesk' ) : __( 'Permalinks are set to "Plain". The MCP server works, but the server card and llms.txt need any other permalink setting.', 'factdesk' ),
		);
		$secure   = 0 === strpos( home_url( '/' ), 'https://' );
		$checks[] = array(
			'ok'   => $secure,
			'text' => $secure ? __( 'The site uses HTTPS.', 'factdesk' ) : __( 'The site does not use HTTPS. Many AI clients only connect to HTTPS servers.', 'factdesk' ),
		);
		$types    = FactDesk_Settings::enabled_post_types();
		$checks[] = array(
			'ok'   => (bool) $types || FactDesk_Settings::products_enabled(),
			'text' => $types ? sprintf( /* translators: %s: list of content types */ __( 'Shared content types: %s.', 'factdesk' ), implode( ', ', $types ) ) : __( 'No content types are shared.', 'factdesk' ),
		);
		if ( FactDesk_Settings::woocommerce_active() ) {
			$checks[] = array(
				'ok'   => FactDesk_Settings::products_enabled(),
				'text' => FactDesk_Settings::products_enabled() ? __( 'WooCommerce products are shared: search_products and get_product are on.', 'factdesk' ) : __( 'WooCommerce is active but products are not shared.', 'factdesk' ),
			);
		}
		if ( file_exists( ABSPATH . 'llms.txt' ) ) {
			$checks[] = array(
				'ok'   => true,
				'text' => __( 'An llms.txt file already exists in the site folder, so it is served instead of the one this plugin builds.', 'factdesk' ),
			);
		}
		if ( file_exists( ABSPATH . '.well-known/mcp/server-card.json' ) ) {
			$checks[] = array(
				'ok'   => true,
				'text' => __( 'A server card file already exists in .well-known/mcp, so it is served instead of the one this plugin builds.', 'factdesk' ),
			);
		}
		return $checks;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s        = FactDesk_Settings::all();
		$name     = FactDesk_Settings::OPTION;
		$endpoint = FactDesk_Server::endpoint();
		$slug     = sanitize_title( get_bloginfo( 'name' ) );
		$config   = wp_json_encode(
			array(
				'mcpServers' => array(
					( '' !== $slug ? $slug : 'site' ) => array(
						'type' => 'http',
						'url'  => $endpoint,
					),
				),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
		$cleared  = isset( $_GET['factdesk-cleared'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap factdesk">
			<h1><?php esc_html_e( 'FactDesk', 'factdesk' ); ?></h1>
			<p class="factdesk-lead"><?php esc_html_e( 'A public, read-only MCP server for this site. AI assistants and agents can search your pages and posts, read them as Markdown and, with WooCommerce, look up products, prices and stock. Nothing is shared that visitors cannot already see, and nothing can be changed through it.', 'factdesk' ); ?></p>

			<?php if ( $cleared ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Activity cleared.', 'factdesk' ); ?></p></div>
			<?php endif; ?>

			<div class="factdesk-grid">
				<section class="factdesk-card">
					<h2><?php esc_html_e( 'Your MCP server', 'factdesk' ); ?></h2>
					<p class="factdesk-status <?php echo $s['enabled'] ? 'is-on' : 'is-off'; ?>"><?php echo $s['enabled'] ? esc_html__( 'On', 'factdesk' ) : esc_html__( 'Off', 'factdesk' ); ?></p>
					<p><strong><?php esc_html_e( 'Server address', 'factdesk' ); ?></strong></p>
					<div class="factdesk-copyrow"><code id="factdesk-endpoint"><?php echo esc_html( $endpoint ); ?></code> <button type="button" class="button" data-factdesk-copy="factdesk-endpoint"><?php esc_html_e( 'Copy', 'factdesk' ); ?></button></div>
					<?php if ( $s['enabled'] && $s['server_card'] && '' !== (string) get_option( 'permalink_structure' ) ) : ?>
						<p><?php esc_html_e( 'Server card:', 'factdesk' ); ?> <a href="<?php echo esc_url( FactDesk_Discovery::card_url() ); ?>" target="_blank" rel="noopener"><?php echo esc_html( FactDesk_Discovery::card_url() ); ?></a></p>
					<?php endif; ?>
					<?php if ( $s['enabled'] && $s['llms_txt'] && '' !== (string) get_option( 'permalink_structure' ) ) : ?>
						<p><?php esc_html_e( 'llms.txt:', 'factdesk' ); ?> <a href="<?php echo esc_url( FactDesk_Discovery::llms_url() ); ?>" target="_blank" rel="noopener"><?php echo esc_html( FactDesk_Discovery::llms_url() ); ?></a></p>
					<?php endif; ?>
					<p><button type="button" class="button button-primary" id="factdesk-test"><?php esc_html_e( 'Test the server', 'factdesk' ); ?></button></p>
					<div id="factdesk-test-result" class="factdesk-test" aria-live="polite"></div>
				</section>

				<section class="factdesk-card">
					<h2><?php esc_html_e( 'Connect an AI client', 'factdesk' ); ?></h2>
					<p><?php esc_html_e( 'In any MCP client, add a remote (HTTP) server with the address above. No API key or sign-in is needed. For clients that use a JSON file, this is the entry:', 'factdesk' ); ?></p>
					<pre id="factdesk-config" class="factdesk-pre"><?php echo esc_html( $config ); ?></pre>
					<p><button type="button" class="button" data-factdesk-copy="factdesk-config"><?php esc_html_e( 'Copy', 'factdesk' ); ?></button></p>
					<p class="description"><?php esc_html_e( 'To have agents find the server on their own, list it in public MCP directories with the server address and the server card.', 'factdesk' ); ?></p>
				</section>
			</div>

			<section class="factdesk-card">
				<h2><?php esc_html_e( 'Readiness', 'factdesk' ); ?></h2>
				<ul class="factdesk-checks">
					<?php foreach ( self::checks() as $check ) : ?>
						<li class="<?php echo $check['ok'] ? 'is-ok' : 'is-warn'; ?>"><span class="dashicons <?php echo $check['ok'] ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>" aria-hidden="true"></span> <?php echo esc_html( $check['text'] ); ?></li>
					<?php endforeach; ?>
				</ul>
			</section>

			<form method="post" action="options.php" class="factdesk-card">
				<?php settings_fields( self::GROUP ); ?>
				<h2><?php esc_html_e( 'Settings', 'factdesk' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'MCP server', 'factdesk' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1" <?php checked( $s['enabled'] ); ?>> <?php esc_html_e( 'Switch the server on', 'factdesk' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Content to share', 'factdesk' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Content to share', 'factdesk' ); ?></legend>
								<?php foreach ( FactDesk_Settings::available_post_types() as $type => $object ) : ?>
									<label class="factdesk-block"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[post_types][]" value="<?php echo esc_attr( $type ); ?>" <?php checked( in_array( $type, (array) $s['post_types'], true ) ); ?>> <?php echo esc_html( $object->labels->name ); ?> <code><?php echo esc_html( $type ); ?></code></label>
								<?php endforeach; ?>
								<?php if ( FactDesk_Settings::woocommerce_active() ) : ?>
									<label class="factdesk-block"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[products]" value="1" <?php checked( $s['products'] ); ?>> <?php esc_html_e( 'WooCommerce products, prices and stock', 'factdesk' ); ?></label>
								<?php else : ?>
									<input type="hidden" name="<?php echo esc_attr( $name ); ?>[products]" value="<?php echo $s['products'] ? '1' : ''; ?>">
								<?php endif; ?>
								<p class="description"><?php esc_html_e( 'Only published content is shared. Private, draft and password-protected content never is, and neither is anything marked noindex in Yoast SEO, Rank Math, SEOPress or The SEO Framework.', 'factdesk' ); ?></p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="factdesk-exclude"><?php esc_html_e( 'Hide these IDs', 'factdesk' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="factdesk-exclude" name="<?php echo esc_attr( $name ); ?>[exclude_ids]" value="<?php echo esc_attr( implode( ', ', (array) $s['exclude_ids'] ) ); ?>">
							<p class="description"><?php esc_html_e( 'Post, page or product IDs to keep away from AI agents, separated by commas. You can also tick "Hide from AI agents" in the editor sidebar.', 'factdesk' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="factdesk-description"><?php esc_html_e( 'About this site', 'factdesk' ); ?></label></th>
						<td>
							<textarea class="large-text" rows="3" id="factdesk-description" name="<?php echo esc_attr( $name ); ?>[description]"><?php echo esc_textarea( $s['description'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One or two sentences on who you are and what you offer. Agents read this first. If empty, the site tagline is used.', 'factdesk' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Contact details', 'factdesk' ); ?></th>
						<td>
							<p><label for="factdesk-email"><?php esc_html_e( 'Email', 'factdesk' ); ?></label><br><input type="email" class="regular-text" id="factdesk-email" name="<?php echo esc_attr( $name ); ?>[contact_email]" value="<?php echo esc_attr( $s['contact_email'] ); ?>"></p>
							<p><label for="factdesk-phone"><?php esc_html_e( 'Phone', 'factdesk' ); ?></label><br><input type="text" class="regular-text" id="factdesk-phone" name="<?php echo esc_attr( $name ); ?>[contact_phone]" value="<?php echo esc_attr( $s['contact_phone'] ); ?>"></p>
							<p><label for="factdesk-address"><?php esc_html_e( 'Address', 'factdesk' ); ?></label><br><textarea class="regular-text" rows="2" id="factdesk-address" name="<?php echo esc_attr( $name ); ?>[contact_address]"><?php echo esc_textarea( $s['contact_address'] ); ?></textarea></p>
							<p class="description"><?php esc_html_e( 'Optional. Only what you enter here is shared, so agents can tell people how to reach you.', 'factdesk' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="factdesk-instructions"><?php esc_html_e( 'Notes for AI agents', 'factdesk' ); ?></label></th>
						<td>
							<textarea class="large-text" rows="3" id="factdesk-instructions" name="<?php echo esc_attr( $name ); ?>[instructions]"><?php echo esc_textarea( $s['instructions'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Optional. Sent to every agent that connects, for example "Prices include VAT. We deliver in the UK only."', 'factdesk' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Discovery files', 'factdesk' ); ?></th>
						<td>
							<label class="factdesk-block"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[server_card]" value="1" <?php checked( $s['server_card'] ); ?>> <?php esc_html_e( 'Serve a server card at /.well-known/mcp/server-card.json', 'factdesk' ); ?></label>
							<label class="factdesk-block"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[llms_txt]" value="1" <?php checked( $s['llms_txt'] ); ?>> <?php esc_html_e( 'Serve /llms.txt (only when the site has no llms.txt of its own)', 'factdesk' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="factdesk-rate"><?php esc_html_e( 'Requests per minute', 'factdesk' ); ?></label></th>
						<td>
							<input type="number" min="10" max="600" step="1" id="factdesk-rate" name="<?php echo esc_attr( $name ); ?>[rate_limit]" value="<?php echo esc_attr( (string) $s['rate_limit'] ); ?>" class="small-text">
							<p class="description"><?php esc_html_e( 'The most requests one address can make in a minute. 60 suits most sites.', 'factdesk' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Search terms', 'factdesk' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[log_searches]" value="1" <?php checked( $s['log_searches'] ); ?>> <?php esc_html_e( 'Keep the last 100 things agents searched for', 'factdesk' ); ?></label>
							<p class="description"><?php esc_html_e( 'Off by default. Searches can contain what a person asked their assistant, so turn this on only if your privacy policy covers it. Stored in this site only.', 'factdesk' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<?php self::render_activity(); ?>
		</div>
		<?php
	}

	private static function render_activity() {
		$days     = FactDesk_Stats::days();
		$searches = FactDesk_Stats::searches();
		$calls    = 0;
		$connects = 0;
		$tools    = array();
		$clients  = array();
		foreach ( $days as $day ) {
			$calls    += isset( $day['calls'] ) ? (int) $day['calls'] : 0;
			$connects += isset( $day['connections'] ) ? (int) $day['connections'] : 0;
			foreach ( isset( $day['tools'] ) ? (array) $day['tools'] : array() as $tool => $count ) {
				$tools[ $tool ] = ( isset( $tools[ $tool ] ) ? $tools[ $tool ] : 0 ) + (int) $count;
			}
			foreach ( isset( $day['clients'] ) ? (array) $day['clients'] : array() as $client => $count ) {
				$clients[ $client ] = ( isset( $clients[ $client ] ) ? $clients[ $client ] : 0 ) + (int) $count;
			}
		}
		arsort( $tools );
		arsort( $clients );
		?>
		<section class="factdesk-card">
			<h2><?php esc_html_e( 'Activity in the last 30 days', 'factdesk' ); ?></h2>
			<?php if ( ! $days ) : ?>
				<p><?php esc_html_e( 'No AI agent has used the server yet. Counts appear here after the first connection.', 'factdesk' ); ?></p>
			<?php else : ?>
				<p class="factdesk-totals">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: "3 connections", 2: "12 tool calls" */
							__( '%1$s, %2$s.', 'factdesk' ),
							/* translators: %s: number of connections */
							sprintf( _n( '%s connection', '%s connections', $connects, 'factdesk' ), number_format_i18n( $connects ) ),
							/* translators: %s: number of tool calls */
							sprintf( _n( '%s tool call', '%s tool calls', $calls, 'factdesk' ), number_format_i18n( $calls ) )
						)
					);
					?>
				</p>
				<div class="factdesk-grid">
					<div>
						<h3><?php esc_html_e( 'Tools used', 'factdesk' ); ?></h3>
						<table class="widefat striped">
							<tbody>
							<?php foreach ( array_slice( $tools, 0, 10, true ) as $tool => $count ) : ?>
								<tr><td><code><?php echo esc_html( $tool ); ?></code></td><td class="num"><?php echo esc_html( number_format_i18n( $count ) ); ?></td></tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<div>
						<h3><?php esc_html_e( 'Clients', 'factdesk' ); ?></h3>
						<table class="widefat striped">
							<tbody>
							<?php foreach ( array_slice( $clients, 0, 10, true ) as $client => $count ) : ?>
								<tr><td><?php echo esc_html( $client ); ?></td><td class="num"><?php echo esc_html( number_format_i18n( $count ) ); ?></td></tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						<p class="description"><?php esc_html_e( 'The first word of each client\'s user agent. Only counts are kept, never addresses.', 'factdesk' ); ?></p>
					</div>
				</div>
				<h3><?php esc_html_e( 'By day', 'factdesk' ); ?></h3>
				<table class="widefat striped factdesk-days">
					<thead><tr><th><?php esc_html_e( 'Date (UTC)', 'factdesk' ); ?></th><th class="num"><?php esc_html_e( 'Connections', 'factdesk' ); ?></th><th class="num"><?php esc_html_e( 'Tool calls', 'factdesk' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( array_slice( $days, 0, 14, true ) as $date => $day ) : ?>
						<tr><td><?php echo esc_html( $date ); ?></td><td class="num"><?php echo esc_html( number_format_i18n( isset( $day['connections'] ) ? (int) $day['connections'] : 0 ) ); ?></td><td class="num"><?php echo esc_html( number_format_i18n( isset( $day['calls'] ) ? (int) $day['calls'] : 0 ) ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( $searches ) : ?>
				<h3><?php esc_html_e( 'Recent searches by agents', 'factdesk' ); ?></h3>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'When (UTC)', 'factdesk' ); ?></th><th><?php esc_html_e( 'Tool', 'factdesk' ); ?></th><th><?php esc_html_e( 'Search', 'factdesk' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( array_slice( $searches, 0, 30 ) as $row ) : ?>
						<tr><td><?php echo esc_html( gmdate( 'Y-m-d H:i', (int) $row['time'] ) ); ?></td><td><code><?php echo esc_html( $row['tool'] ); ?></code></td><td><?php echo esc_html( $row['term'] ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( $days || $searches ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="factdesk-clear">
					<input type="hidden" name="action" value="factdesk_clear">
					<?php wp_nonce_field( 'factdesk_clear' ); ?>
					<?php submit_button( __( 'Clear activity', 'factdesk' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</section>
		<?php
	}

	public static function clear_activity() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'factdesk' ), 403 );
		}
		check_admin_referer( 'factdesk_clear' );
		FactDesk_Stats::clear();
		wp_safe_redirect( add_query_arg( 'factdesk-cleared', '1', admin_url( 'options-general.php?page=' . self::PAGE ) ) );
		exit;
	}

	private static function editor_types() {
		$types = FactDesk_Settings::enabled_post_types();
		if ( FactDesk_Settings::products_enabled() ) {
			$types[] = 'product';
		}
		return $types;
	}

	public static function meta_box() {
		foreach ( self::editor_types() as $type ) {
			add_meta_box( 'factdesk-visibility', __( 'AI agents', 'factdesk' ), array( __CLASS__, 'render_meta_box' ), $type, 'side', 'low' );
		}
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( 'factdesk_meta', 'factdesk_meta_nonce' );
		$hidden = (bool) get_post_meta( $post->ID, FactDesk_Content::EXCLUDE_META, true );
		?>
		<label><input type="checkbox" name="factdesk_exclude" value="1" <?php checked( $hidden ); ?>> <?php esc_html_e( 'Hide from AI agents', 'factdesk' ); ?></label>
		<p class="description"><?php esc_html_e( 'Keeps this out of the FactDesk server and llms.txt. It does not affect search engines.', 'factdesk' ); ?></p>
		<?php
	}

	public static function save_meta( $post_id, $post ) {
		if ( ! isset( $_POST['factdesk_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['factdesk_meta_nonce'] ) ), 'factdesk_meta' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, self::editor_types(), true ) ) {
			return;
		}
		if ( ! empty( $_POST['factdesk_exclude'] ) ) {
			update_post_meta( $post_id, FactDesk_Content::EXCLUDE_META, '1' );
		} else {
			delete_post_meta( $post_id, FactDesk_Content::EXCLUDE_META );
		}
	}
}
