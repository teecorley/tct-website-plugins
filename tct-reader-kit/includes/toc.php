<?php
/**
 * Table of contents: heading discovery, anchor injection, and rendering.
 *
 * The TOC block lives in a sidebar column, i.e. OUTSIDE the post content, and may
 * render before or after the content depending on template markup. So we never rely
 * on state left behind by the_content. Instead both passes derive anchors from the
 * same deterministic slug algorithm over headings in document order, which means the
 * sidebar links always match the ids injected into the content.
 *
 * @package tct-reader-kit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Matches h2/h3 open tag, attributes, and inner HTML.
 */
const TCT_RK_HEADING_RE = '/<h([23])\b([^>]*)>(.*?)<\/h\1>/is';

/**
 * Walk headings in $html and return a normalised list.
 *
 * Slugs are assigned in document order with a per-slug occurrence counter, so the
 * same input always yields the same anchors. An author-supplied id always wins.
 *
 * @param string $html      Content containing heading tags.
 * @param int    $max_level Deepest heading level to include (2 or 3).
 * @return array<int,array{level:int,text:string,slug:string,offset:int,length:int,attrs:string,inner:string}>
 */
function tct_rk_collect_headings( $html, $max_level = 3 ) {
	if ( ! is_string( $html ) || '' === trim( $html ) ) {
		return array();
	}

	if ( ! preg_match_all( TCT_RK_HEADING_RE, $html, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
		return array();
	}

	$headings = array();
	$seen     = array();

	foreach ( $matches as $m ) {
		$level = (int) $m[1][0];
		$attrs = $m[2][0];
		$inner = $m[3][0];

		$text = trim( html_entity_decode( wp_strip_all_tags( $inner ), ENT_QUOTES, get_bloginfo( 'charset' ) ) );
		if ( '' === $text ) {
			continue;
		}

		// Respect an id the author already set on the heading.
		$slug = '';
		if ( preg_match( '/\bid\s*=\s*("|\')(.*?)\1/i', $attrs, $id_match ) ) {
			$slug = trim( $id_match[2] );
		}

		if ( '' === $slug ) {
			$base = sanitize_title( $text );
			if ( '' === $base ) {
				$base = 'section';
			}
			$slug = $base;
			if ( isset( $seen[ $base ] ) ) {
				++$seen[ $base ];
				$slug = $base . '-' . $seen[ $base ];
			} else {
				$seen[ $base ] = 1;
			}
		}

		$headings[] = array(
			'level'  => $level,
			'text'   => $text,
			'slug'   => $slug,
			'offset' => $m[0][1],
			'length' => strlen( $m[0][0] ),
			'attrs'  => $attrs,
			'inner'  => $inner,
		);
	}

	if ( $max_level < 3 ) {
		$headings = array_values(
			array_filter(
				$headings,
				static function ( $h ) use ( $max_level ) {
					return $h['level'] <= $max_level;
				}
			)
		);
	}

	return $headings;
}

/**
 * Add id attributes to any h2/h3 in the post content that lacks one.
 *
 * Rewrites from the end backwards so earlier byte offsets stay valid.
 */
function tct_rk_inject_heading_ids( $content ) {
	/*
	 * Deliberately not using in_the_loop()/is_main_query() here: in block themes the
	 * post-content block renders outside the classic loop, so those checks can be
	 * false on exactly the pages we care about and the ids would never be injected.
	 * Comparing the post being filtered against the queried object is reliable in
	 * both classic and block themes.
	 */
	if ( ! is_singular() ) {
		return $content;
	}

	$current = get_the_ID();
	if ( ! $current || (int) $current !== (int) get_queried_object_id() ) {
		return $content;
	}

	$headings = tct_rk_collect_headings( $content, 3 );
	if ( empty( $headings ) ) {
		return $content;
	}

	foreach ( array_reverse( $headings ) as $h ) {
		// Already has an id, nothing to do.
		if ( preg_match( '/\bid\s*=\s*("|\')/i', $h['attrs'] ) ) {
			continue;
		}

		$replacement = sprintf(
			'<h%1$d%2$s id="%3$s">%4$s</h%1$d>',
			$h['level'],
			$h['attrs'],
			esc_attr( $h['slug'] ),
			$h['inner']
		);

		$content = substr_replace( $content, $replacement, $h['offset'], $h['length'] );
	}

	return $content;
}
add_filter( 'the_content', 'tct_rk_inject_heading_ids', 20 );

/**
 * Render the table of contents block.
 *
 * @param array $attributes Block attributes.
 * @return string
 */
function tct_rk_render_toc( $attributes = array() ) {
	$heading   = isset( $attributes['heading'] ) ? (string) $attributes['heading'] : 'Contents';
	$max_level = isset( $attributes['maxLevel'] ) ? (int) $attributes['maxLevel'] : 3;
	$sticky    = ! empty( $attributes['sticky'] );

	$post = get_post();
	if ( ! $post ) {
		return '';
	}

	$headings = tct_rk_collect_headings( $post->post_content, $max_level );

	// A TOC with one entry is noise, not navigation.
	if ( count( $headings ) < 2 ) {
		return '';
	}

	$classes = 'tct-toc' . ( $sticky ? ' tct-toc--sticky' : '' );

	$items = '';
	foreach ( $headings as $h ) {
		$items .= sprintf(
			'<li class="tct-toc__item tct-toc__item--h%1$d"><a class="tct-toc__link" href="#%2$s">%3$s</a></li>',
			$h['level'],
			esc_attr( $h['slug'] ),
			esc_html( $h['text'] )
		);
	}

	$has_title = '' !== trim( $heading );

	// Only point aria-labelledby at a title element that actually exists.
	if ( $has_title ) {
		$title_id = 'tct-toc-title-' . tct_rk_instance_id( 'toc' );
		return sprintf(
			'<nav class="%1$s" aria-labelledby="%2$s"><p class="tct-toc__title" id="%2$s">%3$s</p><ol class="tct-toc__list">%4$s</ol></nav>',
			esc_attr( $classes ),
			esc_attr( $title_id ),
			esc_html( $heading ),
			$items
		);
	}

	return sprintf(
		'<nav class="%1$s" aria-label="%2$s"><ol class="tct-toc__list">%3$s</ol></nav>',
		esc_attr( $classes ),
		esc_attr__( 'Table of contents', 'tct-reader-kit' ),
		$items
	);
}

/**
 * Per-request incrementing counter, so repeated blocks don't emit duplicate ids.
 *
 * @param string $key Independent counter name.
 * @return int
 */
function tct_rk_instance_id( $key ) {
	static $counters = array();
	$counters[ $key ] = isset( $counters[ $key ] ) ? $counters[ $key ] + 1 : 1;
	return $counters[ $key ];
}
