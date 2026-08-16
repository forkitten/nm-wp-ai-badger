<?php
/**
 * Plugin Name:       NM AI Badger
 * Plugin URI:        https://netzmaedchen.de/
 * Description:       Marks media library images as AI-generated or AI-assisted and renders a badge on the front end, server-side, for Gutenberg and Etch images.
 * Version:           0.2.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            netzmaedchen
 * Author URI:        https://netzmaedchen.de/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       nm-wp-ai-badger
 * Domain Path:       /languages
 *
 * @package NM_AI_Badger
 */

declare( strict_types = 1 );

namespace NM\AIBadger;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.2.0';

/**
 * Attachment meta key holding the label. Stable — display texts live in the settings.
 */
const META_KEY = '_nm_ai_badge';

/**
 * Option key for the settings array.
 */
const OPTION_KEY = 'nm_ai_badger_settings';

/**
 * CSS class that suppresses the badge for a single block.
 */
const EXCLUDE_CLASS = 'nm-hide-ai-badge';

/**
 * CSS class that keeps the badge but suppresses the wrapper element around the image, for layouts
 * whose own CSS targets the image with a direct-child selector.
 */
const NOWRAP_CLASS = 'nm-ai-badge-nowrap';

/**
 * The stable, code-side label values. Never change these — display texts are configurable.
 */
const LABELS = array( 'generated', 'assisted' );

define( 'NM\AIBadger\PLUGIN_FILE', __FILE__ );
define( 'NM\AIBadger\PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once PLUGIN_DIR . 'includes/settings.php';
require_once PLUGIN_DIR . 'includes/media.php';
require_once PLUGIN_DIR . 'includes/render.php';
require_once PLUGIN_DIR . 'includes/assets.php';
require_once PLUGIN_DIR . 'includes/editor.php';
require_once PLUGIN_DIR . 'includes/updater.php';

/**
 * Whether Etch is active.
 *
 * Etch-specific block support, documentation and editor integration are all conditional on this, so
 * the plugin is equally usable on a Gutenberg-only site.
 */
function etch_is_active(): bool {
	$active = class_exists( '\\Etch\\Plugin' )
		|| defined( 'ETCH_VERSION' )
		|| in_array( 'etch/etch.php', (array) get_option( 'active_plugins', array() ), true );

	/**
	 * Filters whether the plugin treats Etch as active.
	 *
	 * Deliberately not memoised: the result must stay filterable at any point in the request.
	 * Both checks are cheap — `get_option()` is served from the alloptions cache.
	 *
	 * @param bool $active Detection result.
	 */
	return (bool) apply_filters( 'nm_ai_badger_etch_is_active', $active );
}

/**
 * Load translations. Runs on `init` to avoid the just-in-time loading notice (WP 6.7+).
 */
function load_textdomain(): void {
	load_plugin_textdomain( 'nm-wp-ai-badger', false, dirname( plugin_basename( PLUGIN_FILE ) ) . '/languages' );
}
add_action( 'init', __NAMESPACE__ . '\\load_textdomain' );

Settings\bootstrap();
Media\bootstrap();
Render\bootstrap();
Assets\bootstrap();
Editor\bootstrap();
Updater\bootstrap();
