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

.nm-ai-badge {
	position: absolute;
	bottom: 0.5em;
	left: 0.5em;
	z-index: 2;
	padding: 0.25em 0.6em;
	border-radius: 2em;
	background: rgba(0, 0, 0, 0.65);
	color: #fff;
	font-family: inherit;
	font-size: 0.75rem;
	font-weight: 400;
	line-height: 1.4;
	letter-spacing: 0.01em;
	text-decoration: none;
	pointer-events: none;
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
