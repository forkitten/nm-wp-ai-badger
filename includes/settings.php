<?php
/**
 * Settings page and option access.
 *
 * @package NM_AI_Badger
 */

declare( strict_types = 1 );

namespace NM\AIBadger\Settings;

use const NM\AIBadger\EXCLUDE_CLASS;
use const NM\AIBadger\LABELS;
use const NM\AIBadger\NOWRAP_CLASS;
use const NM\AIBadger\OPTION_KEY;

defined( 'ABSPATH' ) || exit;

/**
 * Register hooks.
 */
function bootstrap(): void {
	add_action( 'admin_init', __NAMESPACE__ . '\\register' );
	add_action( 'admin_menu', __NAMESPACE__ . '\\add_page' );
	add_filter( 'plugin_action_links_' . plugin_basename( \NM\AIBadger\PLUGIN_FILE ), __NAMESPACE__ . '\\action_link' );
}

/**
 * Default settings.
 *
 * @return array<string, string>
 */
function defaults(): array {
	return array(
		'label_generated' => 'AI-generated',
		'label_assisted'  => 'AI-assisted',
		'custom_css'      => '',
	);
}

/**
 * Read the full settings array, merged over the defaults.
 *
 * @return array<string, string>
 */
function get_all(): array {
	$stored = get_option( OPTION_KEY, array() );

	return wp_parse_args( is_array( $stored ) ? $stored : array(), defaults() );
}

/**
 * Read a single setting.
 *
 * @param string $key Setting key.
 */
function get( string $key ): string {
	$all = get_all();

	return isset( $all[ $key ] ) ? (string) $all[ $key ] : '';
}

/**
 * The front-end badge text for a label value, or an empty string if there is none.
 *
 * @param string $label One of LABELS.
 */
function badge_text( string $label ): string {
	if ( ! in_array( $label, LABELS, true ) ) {
		return '';
	}

	return trim( get( 'label_' . $label ) );
}

/**
 * Register the setting and its sections/fields.
 */
function register(): void {
	register_setting(
		'nm_ai_badger',
		OPTION_KEY,
		array(
			'type'              => 'array',
			'sanitize_callback' => __NAMESPACE__ . '\\sanitize',
			'default'           => defaults(),
		)
	);

	add_settings_section( 'nm_ai_badger_texts', __( 'Badge texts', 'nm-wp-ai-badger' ), __NAMESPACE__ . '\\section_texts', 'nm-wp-ai-badger' );
	add_settings_field( 'label_generated', __( 'AI-generated', 'nm-wp-ai-badger' ), __NAMESPACE__ . '\\field_text', 'nm-wp-ai-badger', 'nm_ai_badger_texts', array( 'key' => 'label_generated' ) );
	add_settings_field( 'label_assisted', __( 'AI-assisted', 'nm-wp-ai-badger' ), __NAMESPACE__ . '\\field_text', 'nm-wp-ai-badger', 'nm_ai_badger_texts', array( 'key' => 'label_assisted' ) );

	add_settings_section( 'nm_ai_badger_hide', __( 'Hiding the badge on a single image', 'nm-wp-ai-badger' ), __NAMESPACE__ . '\\section_hide', 'nm-wp-ai-badger' );

	add_settings_section( 'nm_ai_badger_styling', __( 'Styling', 'nm-wp-ai-badger' ), __NAMESPACE__ . '\\section_styling', 'nm-wp-ai-badger' );
	add_settings_field( 'custom_css', __( 'Custom CSS', 'nm-wp-ai-badger' ), __NAMESPACE__ . '\\field_custom_css', 'nm-wp-ai-badger', 'nm_ai_badger_styling' );
}

/**
 * Sanitize the whole settings array.
 *
 * @param mixed $input Raw input.
 * @return array<string, string>
 */
function sanitize( $input ): array {
	$input  = is_array( $input ) ? $input : array();
	$output = defaults();

	foreach ( array( 'label_generated', 'label_assisted' ) as $key ) {
		if ( isset( $input[ $key ] ) ) {
			$output[ $key ] = sanitize_text_field( (string) $input[ $key ] );
		}
	}

	if ( isset( $input['custom_css'] ) ) {
		$output['custom_css'] = sanitize_css( (string) $input['custom_css'] );
	}

	return $output;
}

/**
 * Sanitize the custom CSS.
 *
 * `wp_strip_all_tags()` would destroy valid CSS (child selectors), so instead every `<` is removed.
 * CSS has no legitimate use for it, and it makes closing the `<style>` element impossible.
 *
 * @param string $css Raw CSS.
 */
function sanitize_css( string $css ): string {
	return trim( str_replace( '<', '', wp_unslash( $css ) ) );
}

/**
 * Add the settings page under Settings.
 */
function add_page(): void {
	add_options_page(
		__( 'AI Image Badge', 'nm-wp-ai-badger' ),
		__( 'AI Image Badge', 'nm-wp-ai-badger' ),
		'manage_options',
		'nm-wp-ai-badger',
		__NAMESPACE__ . '\\render_page'
	);
}

/**
 * Add a Settings link on the plugins screen.
 *
 * @param array<int, string> $links Existing links.
 * @return array<int, string>
 */
function action_link( array $links ): array {
	$url = admin_url( 'options-general.php?page=nm-wp-ai-badger' );

	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'nm-wp-ai-badger' ) . '</a>' );

	return $links;
}

/**
 * Render the settings page.
 */
function render_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

		<p>
			<?php
			printf(
				/* translators: %s: link to the media library */
				esc_html__( 'Set the label per image in the %s, in the "AI labelling" field of the attachment details.', 'nm-wp-ai-badger' ),
				'<a href="' . esc_url( admin_url( 'upload.php' ) ) . '">' . esc_html__( 'media library', 'nm-wp-ai-badger' ) . '</a>'
			);
			?>
			<?php esc_html_e( 'The media library list view has an "AI labelling" column, a filter and a bulk action for labelling many images at once.', 'nm-wp-ai-badger' ); ?>
		</p>

		<p>
			<?php
			$blocks = implode( ', ', array_map( fn( $b ) => '<code>' . esc_html( $b ) . '</code>', \NM\AIBadger\Render\supported_blocks() ) );
			printf(
				/* translators: %s: comma separated list of block names */
				esc_html__( 'The badge is only rendered for images embedded through these block types: %s.', 'nm-wp-ai-badger' ),
				wp_kses( $blocks, array( 'code' => array() ) )
			);
			?>
			<?php esc_html_e( 'Images used as CSS background images, or output by other page builders, are not covered.', 'nm-wp-ai-badger' ); ?>
		</p>

		<form action="options.php" method="post">
			<?php
			settings_fields( 'nm_ai_badger' );
			do_settings_sections( 'nm-wp-ai-badger' );
			submit_button();
			?>
		</form>
	</div>
	<?php
}

/**
 * Intro for the badge texts section.
 */
function section_texts(): void {
	echo '<p>' . esc_html__( 'These texts are shown in the badge on the front end. Changing them takes effect immediately and does not touch the labels stored on your images.', 'nm-wp-ai-badger' ) . '</p>';
}

/**
 * A plain text field.
 *
 * @param array{key: string} $args Field args.
 */
function field_text( array $args ): void {
	$key = $args['key'];
	printf(
		'<input type="text" class="regular-text" name="%s[%s]" id="%s" value="%s" />',
		esc_attr( OPTION_KEY ),
		esc_attr( $key ),
		esc_attr( $key ),
		esc_attr( get( $key ) )
	);
	echo '<p class="description">' . esc_html__( 'Leave empty to render no badge for this label.', 'nm-wp-ai-badger' ) . '</p>';
}

/**
 * Documentation for hiding the badge on individual images.
 */
function section_hide(): void {
	$class = '<code>' . esc_html( EXCLUDE_CLASS ) . '</code>';
	?>
	<p>
		<?php
		printf(
			/* translators: %s: the CSS class name */
			esc_html__( 'Sometimes a single image should not carry a badge even though it is labelled — for example a decorative background or a logo. Add the CSS class %s to that image and the badge is left out completely. Nothing is hidden with CSS; the badge is simply never written into the page.', 'nm-wp-ai-badger' ),
			wp_kses( $class, array( 'code' => array() ) )
		);
		?>
	</p>
	<h3><?php esc_html_e( 'In the block editor (Gutenberg)', 'nm-wp-ai-badger' ); ?></h3>
	<ol>
		<li><?php esc_html_e( 'Select the image block.', 'nm-wp-ai-badger' ); ?></li>
		<li><?php esc_html_e( 'In the sidebar on the right, tick "Hide the badge here" underneath the AI labelling field.', 'nm-wp-ai-badger' ); ?></li>
		<li><?php esc_html_e( 'Update the post.', 'nm-wp-ai-badger' ); ?></li>
	</ol>
	<p class="description">
		<?php
		printf(
			/* translators: %s: the CSS class name */
			esc_html__( 'The checkbox only appears once the image carries a labelling — there is no badge to hide otherwise. Behind the scenes it adds the class %s to the block, so it is the same setting as entering that class by hand under "Advanced".', 'nm-wp-ai-badger' ),
			wp_kses( $class, array( 'code' => array() ) )
		);
		?>
	</p>
	<?php if ( \NM\AIBadger\etch_is_active() ) : ?>
	<h3><?php esc_html_e( 'In Etch', 'nm-wp-ai-badger' ); ?></h3>
	<ol>
		<li><?php esc_html_e( 'Select the image element.', 'nm-wp-ai-badger' ); ?></li>
		<li>
			<?php
			printf(
				/* translators: %s: the CSS class name */
				esc_html__( 'Add %s to the element\'s class attribute. Etch image elements have no separate "Additional CSS class" field, so this goes into the regular class attribute alongside your other classes.', 'nm-wp-ai-badger' ),
				wp_kses( $class, array( 'code' => array() ) )
			);
			?>
		</li>
		<li><?php esc_html_e( 'Save.', 'nm-wp-ai-badger' ); ?></li>
	</ol>

	<h3><?php esc_html_e( 'Background images in Etch', 'nm-wp-ai-badger' ); ?></h3>
	<p>
		<?php
		printf(
			/* translators: 1: the is-bg class, 2: the nm-ai-badge-nowrap class */
			esc_html__( 'Images placed as a background with Etch\'s %1$s utility are handled automatically: the badge is positioned over the background without disturbing the way the image fills its container. If you use a different utility for background images and the layout breaks once a badge appears, add %2$s to the image. The badge is then still rendered, but without the wrapper element around the image.', 'nm-wp-ai-badger' ),
			'<code>is-bg</code>',
			'<code>' . esc_html( NOWRAP_CLASS ) . '</code>'
		);
		?>
	</p>
	<?php endif; ?>
	<?php
}

/**
 * Documentation for the styling section.
 */
function section_styling(): void {
	?>
	<p><?php esc_html_e( 'The plugin ships a small default style. These classes are stable, you can override all of them:', 'nm-wp-ai-badger' ); ?></p>
	<table class="widefat striped" style="max-width:52em">
		<tbody>
			<tr>
				<td><code>.nm-ai-badge-wrap</code></td>
				<td><?php esc_html_e( 'The wrapper the plugin puts around the image. Carries position: relative, which is what the badge is positioned against.', 'nm-wp-ai-badger' ); ?></td>
			</tr>
			<tr>
				<td><code>.nm-ai-badge</code></td>
				<td><?php esc_html_e( 'The badge itself: colour, font, padding, position.', 'nm-wp-ai-badger' ); ?></td>
			</tr>
			<tr>
				<td><code>.nm-ai-badge--generated</code><br><code>.nm-ai-badge--assisted</code></td>
				<td><?php esc_html_e( 'Per label type. Both look identical by default; use these if you want to tell them apart visually.', 'nm-wp-ai-badger' ); ?></td>
			</tr>
		</tbody>
	</table>
	<?php
}

/**
 * The custom CSS textarea.
 */
function field_custom_css(): void {
	printf(
		'<textarea name="%s[custom_css]" id="custom_css" rows="10" class="large-text code" spellcheck="false">%s</textarea>',
		esc_attr( OPTION_KEY ),
		esc_textarea( get( 'custom_css' ) )
	);
	echo '<p class="description">' . esc_html__( 'Printed after the default style, so you do not need !important to override it.', 'nm-wp-ai-badger' ) . '</p>';
}
