<?php
/**
 * Front-end styles.
 *
 * @package NM_AI_Badger
 */

declare( strict_types = 1 );

namespace NM\AIBadger\Assets;

use NM\AIBadger\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Register hooks.
 */
function bootstrap(): void {
	add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue' );
}

/**
 * Print the default style plus any custom CSS.
 *
 * Registered against a source-less handle so WordPress prints everything inline in the head. The
 * stylesheet is a few hundred bytes; an extra HTTP request would cost more than it saves.
 */
function enqueue(): void {
	$css = default_css() . Settings\get( 'custom_css' );

	wp_register_style( 'nm-ai-badger', false, array(), \NM\AIBadger\VERSION );
	wp_enqueue_style( 'nm-ai-badger' );
	wp_add_inline_style( 'nm-ai-badger', $css );
}

/**
 * The default badge style.
 *
 * Deliberately single-class selectors with no `!important`, so the custom CSS field can override
 * every declaration without a specificity fight.
 */
function default_css(): string {
	$css = '
.nm-ai-badge-wrap {
	position: relative;
	display: inline-block;
	max-width: 100%;
	line-height: 0;
}

/* Elements the badge is positioned against when it sits beside the image instead of inside a
   wrapper — gallery items, media columns, blocks with a background image. */
.nm-ai-badge-host {
	position: relative;
}

/* Dark tab flush to the right edge of the image. Uses Automatic.css tokens where the site has
   them (--bg-ultra-dark, --text-xs, --space-xs) and plain values elsewhere. */
.nm-ai-badge {
	position: absolute;
	right: 0;
	bottom: var(--space-xs, 0.5em);
	z-index: 2;
	padding: 0.2rem 0.4rem;
	border-radius: 0;
	background: var(--bg-ultra-dark, #111);
	color: #fff;
	font-family: inherit;
	font-size: var(--text-xs, 0.75rem);
	font-weight: 600;
	line-height: 1.2;
	letter-spacing: 1px;
	text-transform: uppercase;
	white-space: nowrap;
	text-decoration: none;
	pointer-events: none;
}

/* A badge beside its image (no wrapper) is positioned against the parent. Zero specificity, so a
   parent that is positioned already — e.g. an absolutely placed background figure — keeps that. */
:where(:has(> .nm-ai-badge)) {
	position: relative;
}

/* Where supported, anchor the badge to the image box itself, so it sits on the image even when the
   parent is wider (a centred, contained image). The fallbacks in anchor() keep the plain position
   for badges without an image beside them (CSS background images). */
@supports (anchor-scope: all) {
	:where(:has(> img + .nm-ai-badge)) {
		anchor-scope: --nm-ai-badge;
	}

	:where(img:has(+ .nm-ai-badge)) {
		anchor-name: --nm-ai-badge;
	}

	.nm-ai-badge {
		position-anchor: --nm-ai-badge;
		right: anchor(right, 0px);
		bottom: calc(anchor(bottom, 0px) + var(--space-xs, 0.5em));
	}
}

@media print {
	.nm-ai-badge {
		position: static;
		display: block;
		background: none;
		color: inherit;
	}
}
';

	/**
	 * Filters the plugin's default CSS. Return an empty string to ship no default style at all.
	 *
	 * @param string $css Default CSS.
	 */
	return (string) apply_filters( 'nm_ai_badger_default_css', $css );
}
