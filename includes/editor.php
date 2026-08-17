<?php
/**
 * Block editor integration: preview the badge on the editor canvas.
 *
 * @package NM_AI_Badger
 */

declare( strict_types = 1 );

namespace NM\AIBadger\Editor;

use NM\AIBadger\Media;
use NM\AIBadger\Settings;
use const NM\AIBadger\EXCLUDE_CLASS;
use const NM\AIBadger\LABELS;

defined( 'ABSPATH' ) || exit;

/**
 * The data attribute the badge text is carried in.
 */
const DATA_ATTRIBUTE = 'data-nm-ai-badge';

/**
 * Register hooks.
 */
function bootstrap(): void {
	add_action( 'enqueue_block_editor_assets', __NAMESPACE__ . '\\enqueue_script' );
	add_filter( 'block_editor_settings_all', __NAMESPACE__ . '\\add_canvas_styles' );
}

/**
 * Cache-busting version for a bundled asset.
 *
 * The plugin version alone is not enough: an asset edited between two releases keeps the same URL,
 * and browsers then serve the stale copy. Falls back to the plugin version if the file cannot be
 * read, so a missing file never produces an empty version string.
 *
 * @param string $relative_path Path relative to the plugin directory.
 */
function asset_version( string $relative_path ): string {
	$file = \NM\AIBadger\PLUGIN_DIR . $relative_path;
	$time = is_readable( $file ) ? filemtime( $file ) : false;

	return false === $time ? \NM\AIBadger\VERSION : \NM\AIBadger\VERSION . '.' . $time;
}

/**
 * Enqueue the script that tags labelled image blocks.
 */
function enqueue_script(): void {
	$handle = 'nm-ai-badger-editor';

	wp_enqueue_script(
		$handle,
		plugins_url( 'assets/js/editor.js', \NM\AIBadger\PLUGIN_FILE ),
		array( 'wp-hooks', 'wp-element', 'wp-compose', 'wp-data', 'wp-block-editor', 'wp-components' ),
		asset_version( 'assets/js/editor.js' ),
		true
	);

	$texts = array();

	foreach ( LABELS as $label ) {
		$texts[ $label ] = Settings\badge_text( $label );
	}

	$choices = array();

	foreach ( Media\choices() as $value => $text ) {
		$choices[] = array(
			'value' => $value,
			'label' => $text,
		);
	}

	wp_add_inline_script(
		$handle,
		'window.nmAiBadger = ' . wp_json_encode(
			array(
				'attribute'    => DATA_ATTRIBUTE,
				'texts'        => $texts,
				'metaKey'      => \NM\AIBadger\META_KEY,
				'excludeClass' => EXCLUDE_CLASS,
				'choices'      => $choices,
				// Passed through from PHP so the existing .po/.mo stays the single translation
				// source and no separate JSON translation files are needed.
				'ui'           => array(
					'panelTitle' => __( 'AI labelling', 'nm-wp-ai-badger' ),
					'warning'    => __( 'This setting belongs to the image, not to this block. Changing it affects every page where the image is used.', 'nm-wp-ai-badger' ),
					'saving'     => __( 'Saving…', 'nm-wp-ai-badger' ),
					'saved'      => __( 'Saved.', 'nm-wp-ai-badger' ),
					'error'      => __( 'Could not save the AI labelling.', 'nm-wp-ai-badger' ),
					'hideLabel'  => __( 'Hide the badge here', 'nm-wp-ai-badger' ),
					'hideHelp'   => __( 'Only for this one image on this page. The labelling on the image stays as it is.', 'nm-wp-ai-badger' ),
				),
			)
		) . ';',
		'before'
	);
}

/**
 * Add the badge styles to the editor canvas.
 *
 * The canvas is an iframe, so styles enqueued through `enqueue_block_editor_assets` land in the
 * surrounding document and never reach the blocks. Editor settings styles are injected into the
 * iframe itself, which is the only reliable route.
 *
 * @param array<string, mixed> $settings Editor settings.
 * @return array<string, mixed>
 */
function add_canvas_styles( array $settings ): array {
	$settings['styles'][] = array( 'css' => canvas_css() );

	return $settings;
}

/**
 * The badge preview styles.
 *
 * Rendered as a pseudo-element rather than a real node: nothing is added to the block markup, the
 * editor's own drag/select behaviour stays untouched, and if these styles ever fail to load the
 * result is no badge at all rather than stray text that reads like a caption.
 */
function canvas_css(): string {
	$attr = '[' . DATA_ATTRIBUTE . ']';

	$css = "
.wp-block{$attr} {
	position: relative;
}

.wp-block{$attr}::after {
	content: attr(" . DATA_ATTRIBUTE . ");
	position: absolute;
	bottom: 0.5em;
	left: 0.5em;
	z-index: 21;
	padding: 0.25em 0.6em;
	border-radius: 2em;
	background: rgba(0, 0, 0, 0.65);
	color: #fff;
	font-family: inherit;
	font-size: 0.75rem;
	font-weight: 400;
	line-height: 1.4;
	letter-spacing: 0.01em;
	pointer-events: none;
}

/* With a caption the bottom edge belongs to the text, so the badge moves to the top of the image.
   Direct child only: a cover block may contain a captioned image without being captioned itself. */
.wp-block{$attr}:has( > figcaption )::after {
	top: 0.5em;
	bottom: auto;
}
";

	/**
	 * Filters the badge styles used on the editor canvas.
	 *
	 * @param string $css Canvas CSS.
	 */
	return (string) apply_filters( 'nm_ai_badger_editor_css', $css );
}
