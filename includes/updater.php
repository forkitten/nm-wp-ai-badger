<?php
/**
 * Update checks against the GitHub repository.
 *
 * @package NM_AI_Badger
 */

declare( strict_types = 1 );

namespace NM\AIBadger\Updater;

defined( 'ABSPATH' ) || exit;

/**
 * The repository the plugin is released from.
 */
const REPOSITORY_URL = 'https://github.com/forkitten/nm-wp-ai-badger/';

/**
 * Wire up the update checker.
 *
 * Deliberately called at file scope rather than from an `admin_*` hook: WP-CLI, cron and site
 * management tools all check for updates outside the dashboard, and an admin-only checker would be
 * invisible to them.
 */
function bootstrap(): void {
	if ( defined( 'NM_AI_BADGER_DISABLE_UPDATER' ) && NM_AI_BADGER_DISABLE_UPDATER ) {
		return;
	}

	$library = \NM\AIBadger\PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php';

	if ( ! is_readable( $library ) ) {
		return;
	}

	require_once $library;

	if ( ! class_exists( '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory' ) ) {
		return;
	}

	/**
	 * Filters the repository update checks run against.
	 *
	 * Lets a fork point at its own repository without editing plugin files.
	 *
	 * @param string $url Repository URL.
	 */
	$repository = (string) apply_filters( 'nm_ai_badger_repository_url', REPOSITORY_URL );

	if ( '' === $repository ) {
		return;
	}

	$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		$repository,
		\NM\AIBadger\PLUGIN_FILE,
		'nm-wp-ai-badger'
	);

	// Prefer a ZIP attached to the release, so a trimmed build can be shipped later without changing
	// any code. PREFER_RELEASE_ASSETS falls back to GitHub's generated source archive when a release
	// carries no matching asset, so plain releases keep working as they are.
	$checker->getVcsApi()->enableReleaseAssets( '/\.zip($|[?&#])/i' );

	$token = access_token();

	if ( '' !== $token ) {
		$checker->setAuthentication( $token );
	}
}

/**
 * The GitHub access token, if the repository is private.
 *
 * Read from a constant rather than stored in the database or hard-coded, so the token lives in
 * wp-config.php on the sites that need it and never travels inside the plugin ZIP.
 */
function access_token(): string {
	$token = defined( 'NM_AI_BADGER_GITHUB_TOKEN' ) ? (string) NM_AI_BADGER_GITHUB_TOKEN : '';

	/**
	 * Filters the GitHub access token used for update checks.
	 *
	 * @param string $token Access token, empty for a public repository.
	 */
	return trim( (string) apply_filters( 'nm_ai_badger_github_token', $token ) );
}
