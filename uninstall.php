<?php
/**
 * Uninstall routine.
 *
 * Removes the plugin's settings. The per-image labels in postmeta are deliberately kept: they are
 * editorial data, an accidental deactivation must not throw them away, and they cost nothing if the
 * plugin is never reinstalled.
 *
 * @package NM_AI_Badger
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'nm_ai_badger_settings' );
