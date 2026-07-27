<?php
/**
 * Plugin Name:       TCT Reader Kit
 * Description:       Sidebar table of contents and a newsletter signup form that collects subscriber emails in WordPress.
 * Version:           1.0.1
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Tee Corley Travels
 * License:           GPL-2.0-or-later
 * Text Domain:       tct-reader-kit
 */

defined( 'ABSPATH' ) || exit;

define( 'TCT_RK_VERSION', '1.0.1' );
define( 'TCT_RK_FILE', __FILE__ );
define( 'TCT_RK_DIR', plugin_dir_path( __FILE__ ) );
define( 'TCT_RK_URL', plugin_dir_url( __FILE__ ) );

require_once TCT_RK_DIR . 'includes/toc.php';
require_once TCT_RK_DIR . 'includes/newsletter.php';

/**
 * Front-end + editor styles.
 */
function tct_rk_register_assets() {
	wp_register_style(
		'tct-reader-kit',
		TCT_RK_URL . 'assets/frontend.css',
		array(),
		TCT_RK_VERSION
	);
}
add_action( 'init', 'tct_rk_register_assets' );

/**
 * Register both blocks. Rendering is done in PHP so no build step is needed.
 */
function tct_rk_register_blocks() {
	register_block_type(
		'tct/table-of-contents',
		array(
			'api_version'     => 3,
			'title'           => __( 'Table of Contents', 'tct-reader-kit' ),
			'category'        => 'theme',
			'icon'            => 'list-view',
			'description'     => __( 'Auto-generated list of links to the headings in this post.', 'tct-reader-kit' ),
			'style_handles'   => array( 'tct-reader-kit' ),
			'attributes'      => array(
				'heading'   => array( 'type' => 'string', 'default' => 'Contents' ),
				'maxLevel'  => array( 'type' => 'number', 'default' => 3 ),
				'sticky'    => array( 'type' => 'boolean', 'default' => true ),
			),
			'supports'        => array( 'html' => false ),
			'render_callback' => 'tct_rk_render_toc',
		)
	);

	register_block_type(
		'tct/newsletter',
		array(
			'api_version'     => 3,
			'title'           => __( 'Newsletter Signup', 'tct-reader-kit' ),
			'category'        => 'theme',
			'icon'            => 'email',
			'description'     => __( 'Email signup form. Subscribers are saved in WordPress.', 'tct-reader-kit' ),
			'style_handles'   => array( 'tct-reader-kit' ),
			'attributes'      => array(
				'heading'     => array( 'type' => 'string', 'default' => 'Join My Newsletter' ),
				'blurb'       => array( 'type' => 'string', 'default' => 'Adventure cat tips and van life stories. No spam, unsubscribe anytime.' ),
				'buttonLabel' => array( 'type' => 'string', 'default' => 'Subscribe' ),
				'showName'    => array( 'type' => 'boolean', 'default' => true ),
			),
			'supports'        => array( 'html' => false ),
			'render_callback' => 'tct_rk_render_newsletter',
		)
	);
}
add_action( 'init', 'tct_rk_register_blocks' );

/**
 * Editor-side registration (plain JS, no JSX/build).
 */
function tct_rk_enqueue_editor() {
	wp_enqueue_script(
		'tct-reader-kit-editor',
		TCT_RK_URL . 'assets/editor.js',
		array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
		TCT_RK_VERSION,
		true
	);
	wp_enqueue_style( 'tct-reader-kit' );
}
add_action( 'enqueue_block_editor_assets', 'tct_rk_enqueue_editor' );

/**
 * Shortcode fallbacks, so the features work outside the block editor too.
 */
add_shortcode( 'tct_toc', function ( $atts ) {
	$atts = shortcode_atts( array( 'heading' => 'Contents', 'maxlevel' => 3, 'sticky' => '1' ), $atts, 'tct_toc' );
	return tct_rk_render_toc(
		array(
			'heading'  => $atts['heading'],
			'maxLevel' => (int) $atts['maxlevel'],
			'sticky'   => (bool) $atts['sticky'],
		)
	);
} );

add_shortcode( 'tct_newsletter', function ( $atts ) {
	$atts = shortcode_atts(
		array(
			'heading'  => 'Join My Newsletter',
			'blurb'    => 'Adventure cat tips and van life stories. No spam, unsubscribe anytime.',
			'button'   => 'Subscribe',
			'showname' => '1',
		),
		$atts,
		'tct_newsletter'
	);
	return tct_rk_render_newsletter(
		array(
			'heading'     => $atts['heading'],
			'blurb'       => $atts['blurb'],
			'buttonLabel' => $atts['button'],
			'showName'    => (bool) $atts['showname'],
		)
	);
} );
