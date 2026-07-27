<?php
/**
 * Newsletter signup: storage, form handling, admin screen, CSV export.
 *
 * Subscribers are stored as a private custom post type rather than a custom table.
 * That avoids schema-migration risk entirely (no dbDelta, nothing to break if the
 * activation hook never runs) and gives a usable admin list for free.
 *
 * @package tct-reader-kit
 */

defined( 'ABSPATH' ) || exit;

const TCT_RK_CPT = 'tct_subscriber';

/**
 * Register the subscriber post type.
 */
function tct_rk_register_cpt() {
	register_post_type(
		TCT_RK_CPT,
		array(
			'labels'          => array(
				'name'          => __( 'Subscribers', 'tct-reader-kit' ),
				'singular_name' => __( 'Subscriber', 'tct-reader-kit' ),
				'menu_name'     => __( 'Subscribers', 'tct-reader-kit' ),
				'search_items'  => __( 'Search Subscribers', 'tct-reader-kit' ),
				'not_found'     => __( 'No subscribers yet.', 'tct-reader-kit' ),
			),
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => true,
			'show_in_rest'    => false,
			'menu_icon'       => 'dashicons-email-alt',
			'menu_position'   => 26,
			'supports'        => array( 'title' ),
			/*
			 * A subscriber list is personal data, so it must not inherit the ordinary
			 * post capabilities — that would expose every address to any Editor or
			 * Author. Primitive capabilities are therefore mapped to manage_options.
			 *
			 * CRITICAL: do NOT add 'edit_post', 'read_post' or 'delete_post' here.
			 * Those three are *meta* capabilities, and WordPress feeds them through
			 * _post_type_meta_capabilities(), which registers whatever they point at
			 * into the global $post_type_meta_caps map. Pointing them at
			 * manage_options registers manage_options itself as a meta capability, so
			 * every current_user_can('manage_options') call site-wide gets re-mapped
			 * to edit_post with no post ID and resolves to do_not_allow — which
			 * silently strips the Settings menu and every other admin screen gated on
			 * that capability. Only ever remap the primitive capabilities below.
			 *
			 * A dedicated capability_type also keeps the generated meta-cap names
			 * ('edit_tct_subscriber' etc.) unique so they cannot collide with core.
			 */
			'capability_type' => array( 'tct_subscriber', 'tct_subscribers' ),
			'capabilities'    => array(
				'create_posts'           => 'do_not_allow', // Signups come through the form only.
				'edit_posts'             => 'manage_options',
				'edit_others_posts'      => 'manage_options',
				'edit_published_posts'   => 'manage_options',
				'edit_private_posts'     => 'manage_options',
				'delete_posts'           => 'manage_options',
				'delete_others_posts'    => 'manage_options',
				'delete_published_posts' => 'manage_options',
				'delete_private_posts'   => 'manage_options',
				'publish_posts'          => 'manage_options',
				'read_private_posts'     => 'manage_options',
			),
			'map_meta_cap'    => true,
			'has_archive'     => false,
			'rewrite'         => false,
			'exclude_from_search' => true,
		)
	);
}
add_action( 'init', 'tct_rk_register_cpt' );

/**
 * Look up an existing subscriber by email address.
 *
 * @param string $email Email address.
 * @return int Post ID, or 0 if not found.
 */
function tct_rk_find_subscriber( $email ) {
	$existing = get_posts(
		array(
			'post_type'        => TCT_RK_CPT,
			'post_status'      => 'any',
			'title'            => $email,
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true,
		)
	);

	return ! empty( $existing ) ? (int) $existing[0] : 0;
}

/**
 * Handle a signup submission.
 *
 * Redirects back to the originating page with a status flag rather than rendering,
 * so a refresh never re-submits.
 */
function tct_rk_handle_signup() {
	$referer  = wp_get_referer();
	$redirect = $referer ? $referer : home_url( '/' );

	$bail = static function ( $status ) use ( $redirect ) {
		wp_safe_redirect( add_query_arg( 'tct_sub', $status, remove_query_arg( 'tct_sub', $redirect ) ) . '#tct-newsletter' );
		exit;
	};

	/*
	 * No nonce check here, on purpose.
	 *
	 * This is a public, unauthenticated form whose only effect is adding an address to
	 * a list. A nonce would not stop the abuse that actually matters — a spammer can
	 * POST this endpoint directly without ever loading the page — while it WOULD break
	 * legitimate signups two ways: nonces expire (a visitor who opens the post and
	 * subscribes a day later fails), and any full-page cache serves one baked-in nonce
	 * to everybody until it goes stale. Spam is handled below by the honeypot, the time
	 * trap, and the per-IP rate limit.
	 *
	 * What we do check is that the request came from this site, which is cache-safe.
	 */
	if ( $referer ) {
		$ref_host  = wp_parse_url( $referer, PHP_URL_HOST );
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( $ref_host && $home_host && strtolower( $ref_host ) !== strtolower( $home_host ) ) {
			$bail( 'error' );
		}
	}

	// Honeypot: real users never fill a hidden field.
	if ( ! empty( $_POST['tct_website'] ) ) {
		$bail( 'ok' ); // Lie to bots rather than telling them why they failed.
	}

	/*
	 * Time trap: a form submitted within 2s of being rendered was not filled in by a
	 * human. There is deliberately NO upper bound — if the page is ever served from a
	 * cache the embedded timestamp will be old, and an upper bound would silently
	 * discard genuine signups.
	 */
	$rendered_at = isset( $_POST['tct_t'] ) ? (int) $_POST['tct_t'] : 0;
	if ( $rendered_at > 0 && ( time() - $rendered_at ) < 2 ) {
		$bail( 'ok' );
	}

	// Light per-IP rate limit.
	$ip_hash = tct_rk_ip_hash();
	$bucket  = 'tct_rk_rl_' . $ip_hash;
	$hits    = (int) get_transient( $bucket );
	if ( $hits >= 5 ) {
		$bail( 'slow' );
	}
	set_transient( $bucket, $hits + 1, HOUR_IN_SECONDS );

	$email = isset( $_POST['tct_email'] ) ? sanitize_email( wp_unslash( $_POST['tct_email'] ) ) : '';
	if ( ! $email || ! is_email( $email ) ) {
		$bail( 'invalid' );
	}

	$name = isset( $_POST['tct_name'] ) ? sanitize_text_field( wp_unslash( $_POST['tct_name'] ) ) : '';
	$name = mb_substr( $name, 0, 100 );

	$source = isset( $_POST['tct_source'] ) ? esc_url_raw( wp_unslash( $_POST['tct_source'] ) ) : '';

	$existing_id = tct_rk_find_subscriber( $email );

	if ( $existing_id ) {
		$status = get_post_meta( $existing_id, '_tct_status', true );
		if ( 'unsubscribed' === $status ) {
			// Treat a repeat signup as a re-subscribe.
			update_post_meta( $existing_id, '_tct_status', 'subscribed' );
			update_post_meta( $existing_id, '_tct_consent', current_time( 'mysql' ) );
			$bail( 'ok' );
		}
		$bail( 'dupe' );
	}

	$post_id = wp_insert_post(
		array(
			'post_type'   => TCT_RK_CPT,
			'post_title'  => $email,
			'post_status' => 'publish',
		),
		true
	);

	if ( is_wp_error( $post_id ) || ! $post_id ) {
		$bail( 'error' );
	}

	update_post_meta( $post_id, '_tct_name', $name );
	update_post_meta( $post_id, '_tct_status', 'subscribed' );
	update_post_meta( $post_id, '_tct_source', $source );
	update_post_meta( $post_id, '_tct_consent', current_time( 'mysql' ) );
	update_post_meta( $post_id, '_tct_ip_hash', $ip_hash );
	update_post_meta( $post_id, '_tct_token', wp_generate_password( 20, false ) );

	/**
	 * Fires after a new subscriber is stored. Use this to forward the address to an
	 * email service provider later without modifying this plugin.
	 *
	 * @param string $email
	 * @param string $name
	 * @param int    $post_id
	 */
	do_action( 'tct_rk_subscriber_added', $email, $name, $post_id );

	$bail( 'ok' );
}
add_action( 'admin_post_nopriv_tct_rk_signup', 'tct_rk_handle_signup' );
add_action( 'admin_post_tct_rk_signup', 'tct_rk_handle_signup' );

/**
 * Hash of the requester IP. We never store the raw address.
 *
 * @return string
 */
function tct_rk_ip_hash() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	return $ip ? wp_hash( $ip ) : 'unknown';
}

/**
 * Status message for the current request, if any.
 *
 * @return array{type:string,text:string}|null
 */
function tct_rk_status_message() {
	if ( ! isset( $_GET['tct_sub'] ) ) {
		return null;
	}

	switch ( sanitize_key( wp_unslash( $_GET['tct_sub'] ) ) ) {
		case 'ok':
			return array( 'type' => 'ok', 'text' => __( "You're in! Thanks for subscribing.", 'tct-reader-kit' ) );
		case 'dupe':
			return array( 'type' => 'ok', 'text' => __( "You're already on the list — thank you!", 'tct-reader-kit' ) );
		case 'invalid':
			return array( 'type' => 'err', 'text' => __( 'That email address doesn\'t look right. Mind checking it?', 'tct-reader-kit' ) );
		case 'slow':
			return array( 'type' => 'err', 'text' => __( 'Too many attempts. Please try again later.', 'tct-reader-kit' ) );
		case 'error':
			return array( 'type' => 'err', 'text' => __( 'Something went wrong on our end. Please try again.', 'tct-reader-kit' ) );
	}

	return null;
}

/**
 * Render the newsletter block.
 *
 * @param array $attributes Block attributes.
 * @return string
 */
function tct_rk_render_newsletter( $attributes = array() ) {
	$heading = isset( $attributes['heading'] ) ? (string) $attributes['heading'] : 'Join My Newsletter';
	$blurb   = isset( $attributes['blurb'] ) ? (string) $attributes['blurb'] : '';
	$label   = isset( $attributes['buttonLabel'] ) ? (string) $attributes['buttonLabel'] : 'Subscribe';
	$show_nm = ! empty( $attributes['showName'] );

	$msg      = tct_rk_status_message();
	$msg_html = '';
	if ( $msg ) {
		$msg_html = sprintf(
			'<p class="tct-news__msg tct-news__msg--%1$s" role="status">%2$s</p>',
			esc_attr( $msg['type'] ),
			esc_html( $msg['text'] )
		);
	}

	$source = '';
	if ( is_singular() ) {
		$source = get_permalink();
	}

	// Unique suffix so two signup boxes on one page don't collide on element ids.
	$uid       = tct_rk_instance_id( 'news' );
	$email_id  = 'tct-news-email-' . $uid;
	$name_id   = 'tct-news-name-' . $uid;
	$hp_id     = 'tct-website-' . $uid;

	$name_field = '';
	if ( $show_nm ) {
		$name_field = sprintf(
			'<label class="tct-news__label" for="%1$s">%2$s</label>
			 <input class="tct-news__input" type="text" id="%1$s" name="tct_name" autocomplete="given-name" maxlength="100">',
			esc_attr( $name_id ),
			esc_html__( 'First name (optional)', 'tct-reader-kit' )
		);
	}

	return sprintf(
		'<div class="tct-news" id="tct-newsletter">
			<p class="tct-news__title">%1$s</p>
			%2$s
			%3$s
			<form class="tct-news__form" action="%4$s" method="post">
				<input type="hidden" name="action" value="tct_rk_signup">
				<input type="hidden" name="tct_t" value="%5$d">
				<input type="hidden" name="tct_source" value="%6$s">
				<div class="tct-news__hp" aria-hidden="true">
					<label for="%7$s">%8$s</label>
					<input type="text" id="%7$s" name="tct_website" tabindex="-1" autocomplete="off">
				</div>
				%9$s
				<label class="tct-news__label" for="%10$s">%11$s</label>
				<input class="tct-news__input" type="email" id="%10$s" name="tct_email" required autocomplete="email" placeholder="you@example.com">
				<button class="tct-news__button" type="submit">%12$s</button>
			</form>
		</div>',
		esc_html( $heading ),
		'' !== trim( $blurb ) ? '<p class="tct-news__blurb">' . esc_html( $blurb ) . '</p>' : '',
		$msg_html,
		esc_url( admin_url( 'admin-post.php' ) ),
		time(),
		esc_url( $source ),
		esc_attr( $hp_id ),
		esc_html__( 'Leave this field empty', 'tct-reader-kit' ),
		$name_field,
		esc_attr( $email_id ),
		esc_html__( 'Email address', 'tct-reader-kit' ),
		esc_html( $label )
	);
}

/* -------------------------------------------------------------------------
 * Admin: columns and CSV export
 * ---------------------------------------------------------------------- */

add_filter(
	'manage_' . TCT_RK_CPT . '_posts_columns',
	static function ( $cols ) {
		return array(
			'cb'         => isset( $cols['cb'] ) ? $cols['cb'] : '',
			'title'      => __( 'Email', 'tct-reader-kit' ),
			'tct_name'   => __( 'Name', 'tct-reader-kit' ),
			'tct_status' => __( 'Status', 'tct-reader-kit' ),
			'tct_source' => __( 'Signed up from', 'tct-reader-kit' ),
			'date'       => __( 'Date', 'tct-reader-kit' ),
		);
	}
);

add_action(
	'manage_' . TCT_RK_CPT . '_posts_custom_column',
	static function ( $col, $post_id ) {
		switch ( $col ) {
			case 'tct_name':
				echo esc_html( get_post_meta( $post_id, '_tct_name', true ) );
				break;
			case 'tct_status':
				$s = get_post_meta( $post_id, '_tct_status', true );
				echo esc_html( $s ? ucfirst( $s ) : '—' );
				break;
			case 'tct_source':
				$src = get_post_meta( $post_id, '_tct_source', true );
				if ( $src ) {
					printf(
						'<a href="%1$s" target="_blank" rel="noopener">%2$s</a>',
						esc_url( $src ),
						esc_html( wp_parse_url( $src, PHP_URL_PATH ) ? wp_parse_url( $src, PHP_URL_PATH ) : $src )
					);
				} else {
					echo '—';
				}
				break;
		}
	},
	10,
	2
);

/**
 * "Export CSV" button above the subscriber list.
 */
add_action(
	'manage_posts_extra_tablenav',
	static function ( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || TCT_RK_CPT !== $screen->post_type || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=tct_rk_export' ), 'tct_rk_export' );
		printf(
			'<div class="alignleft actions"><a href="%1$s" class="button button-primary">%2$s</a></div>',
			esc_url( $url ),
			esc_html__( 'Export CSV', 'tct-reader-kit' )
		);
	}
);

/**
 * Stream subscribers as CSV.
 */
function tct_rk_export_csv() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to export subscribers.', 'tct-reader-kit' ), 403 );
	}
	check_admin_referer( 'tct_rk_export' );

	$rows = get_posts(
		array(
			'post_type'      => TCT_RK_CPT,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		)
	);

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=tct-subscribers-' . gmdate( 'Y-m-d' ) . '.csv' );

	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, array( 'Email', 'Name', 'Status', 'Signed up from', 'Consent recorded', 'Date added' ) );

	foreach ( $rows as $row ) {
		fputcsv(
			$out,
			array_map(
				'tct_rk_csv_cell',
				array(
					$row->post_title,
					get_post_meta( $row->ID, '_tct_name', true ),
					get_post_meta( $row->ID, '_tct_status', true ),
					get_post_meta( $row->ID, '_tct_source', true ),
					get_post_meta( $row->ID, '_tct_consent', true ),
					$row->post_date,
				)
			)
		);
	}

	fclose( $out );
	exit;
}

/**
 * Neutralise spreadsheet formula injection.
 *
 * A subscriber could enter a name like "=HYPERLINK(...)". Excel and Sheets execute
 * leading =, +, -, @ and tab/CR as formulas when the CSV is opened, so prefix those
 * values with a single quote to force text.
 *
 * @param mixed $value Cell value.
 * @return string
 */
function tct_rk_csv_cell( $value ) {
	$value = (string) $value;
	if ( '' !== $value && strpbrk( $value[0], "=+-@\t\r" ) !== false ) {
		return "'" . $value;
	}
	return $value;
}
add_action( 'admin_post_tct_rk_export', 'tct_rk_export_csv' );
