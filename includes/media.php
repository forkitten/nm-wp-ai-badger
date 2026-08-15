<?php
/**
 * Attachment meta registration and media library UI.
 *
 * @package NM_AI_Badger
 */

declare( strict_types = 1 );

namespace NM\AIBadger\Media;

use const NM\AIBadger\LABELS;
use const NM\AIBadger\META_KEY;

defined( 'ABSPATH' ) || exit;

/**
 * Register hooks.
 */
function bootstrap(): void {
	add_action( 'init', __NAMESPACE__ . '\\register_meta' );

	// One hook covers both the "Edit Media" screen and the attachment details panel in the media modal.
	add_filter( 'attachment_fields_to_edit', __NAMESPACE__ . '\\add_field', 10, 2 );
	add_filter( 'attachment_fields_to_save', __NAMESPACE__ . '\\save_field', 10, 2 );

	// Media library list view: column, filter, bulk action.
	add_filter( 'manage_media_columns', __NAMESPACE__ . '\\add_column' );
	add_action( 'manage_media_custom_column', __NAMESPACE__ . '\\render_column', 10, 2 );
	add_action( 'restrict_manage_posts', __NAMESPACE__ . '\\render_filter' );
	add_action( 'pre_get_posts', __NAMESPACE__ . '\\apply_filter' );
	add_filter( 'bulk_actions-upload', __NAMESPACE__ . '\\add_bulk_actions' );
	add_filter( 'handle_bulk_actions-upload', __NAMESPACE__ . '\\handle_bulk_actions', 10, 3 );
	add_action( 'admin_notices', __NAMESPACE__ . '\\bulk_action_notice' );
}

/**
 * The choices offered in the admin UI, keyed by stored value.
 *
 * @return array<string, string>
 */
function choices(): array {
	return array(
		''          => __( '— None —', 'nm-wp-ai-badger' ),
		'generated' => __( 'AI-generated', 'nm-wp-ai-badger' ),
		'assisted'  => __( 'AI-assisted', 'nm-wp-ai-badger' ),
	);
}

/**
 * Normalise an arbitrary value to a valid label.
 *
 * @param mixed $value Raw value.
 */
function sanitize_label( $value ): string {
	$value = is_string( $value ) ? trim( $value ) : '';

	return in_array( $value, LABELS, true ) ? $value : '';
}

/**
 * Read the label stored on an attachment.
 *
 * @param int $attachment_id Attachment ID.
 */
function get_label( int $attachment_id ): string {
	return sanitize_label( get_post_meta( $attachment_id, META_KEY, true ) );
}

/**
 * Register the attachment meta.
 */
function register_meta(): void {
	\register_post_meta(
		'attachment',
		META_KEY,
		array(
			'type'              => 'string',
			'description'       => 'AI labelling for this image.',
			'single'            => true,
			'default'           => '',
			'sanitize_callback' => __NAMESPACE__ . '\\sanitize_label',
			'show_in_rest'      => true,
			'auth_callback'     => static fn( $allowed, $meta_key, $post_id ) => current_user_can( 'edit_post', $post_id ),
		)
	);
}

/**
 * Add the select field to the attachment edit UI.
 *
 * @param array<string, mixed> $fields Existing fields.
 * @param \WP_Post             $post   Attachment post.
 * @return array<string, mixed>
 */
function add_field( array $fields, \WP_Post $post ): array {
	if ( ! wp_attachment_is_image( $post ) ) {
		return $fields;
	}

	$current = get_label( $post->ID );
	$html    = '<select name="attachments[' . esc_attr( (string) $post->ID ) . '][nm_ai_badge]" id="attachments-' . esc_attr( (string) $post->ID ) . '-nm_ai_badge">';

	foreach ( choices() as $value => $text ) {
		$html .= sprintf(
			'<option value="%s"%s>%s</option>',
			esc_attr( $value ),
			selected( $current, $value, false ),
			esc_html( $text )
		);
	}

	$html .= '</select>';

	$fields['nm_ai_badge'] = array(
		'label' => __( 'AI labelling', 'nm-wp-ai-badger' ),
		'input' => 'html',
		'html'  => $html,
		'helps' => __( 'Shows a badge on the front end when this image is used in a supported block.', 'nm-wp-ai-badger' ),
	);

	return $fields;
}

/**
 * Persist the field.
 *
 * @param array<string, mixed> $post       Attachment post data.
 * @param array<string, mixed> $attachment Submitted attachment fields.
 * @return array<string, mixed>
 */
function save_field( array $post, array $attachment ): array {
	if ( ! array_key_exists( 'nm_ai_badge', $attachment ) ) {
		return $post;
	}

	$post_id = isset( $post['ID'] ) ? (int) $post['ID'] : 0;

	if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
		return $post;
	}

	$label = sanitize_label( $attachment['nm_ai_badge'] );

	if ( '' === $label ) {
		delete_post_meta( $post_id, META_KEY );
	} else {
		update_post_meta( $post_id, META_KEY, $label );
	}

	return $post;
}

/**
 * Add the list table column.
 *
 * @param array<string, string> $columns Existing columns.
 * @return array<string, string>
 */
function add_column( array $columns ): array {
	$columns['nm_ai_badge'] = __( 'AI labelling', 'nm-wp-ai-badger' );

	return $columns;
}

/**
 * Render the list table column.
 *
 * @param string $column_name Column key.
 * @param int    $post_id     Attachment ID.
 */
function render_column( string $column_name, int $post_id ): void {
	if ( 'nm_ai_badge' !== $column_name ) {
		return;
	}

	$label = get_label( $post_id );

	if ( '' === $label ) {
		echo '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'Not labelled', 'nm-wp-ai-badger' ) . '</span>';
		return;
	}

	echo esc_html( choices()[ $label ] );
}

/**
 * Render the filter dropdown above the media list table.
 *
 * @param string $post_type Current post type.
 */
function render_filter( string $post_type ): void {
	if ( 'attachment' !== $post_type ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
	$current = isset( $_GET['nm_ai_badge'] ) ? sanitize_text_field( wp_unslash( $_GET['nm_ai_badge'] ) ) : '';

	$options = array(
		''          => __( 'All AI labels', 'nm-wp-ai-badger' ),
		'unlabelled' => __( 'Not labelled', 'nm-wp-ai-badger' ),
		'generated' => __( 'AI-generated', 'nm-wp-ai-badger' ),
		'assisted'  => __( 'AI-assisted', 'nm-wp-ai-badger' ),
	);

	echo '<label class="screen-reader-text" for="nm_ai_badge">' . esc_html__( 'Filter by AI labelling', 'nm-wp-ai-badger' ) . '</label>';
	echo '<select name="nm_ai_badge" id="nm_ai_badge">';

	foreach ( $options as $value => $text ) {
		printf(
			'<option value="%s"%s>%s</option>',
			esc_attr( $value ),
			selected( $current, $value, false ),
			esc_html( $text )
		);
	}

	echo '</select>';
}

/**
 * Apply the filter to the media list query.
 *
 * @param \WP_Query $query Current query.
 */
function apply_filter( \WP_Query $query ): void {
	if ( ! is_admin() || ! $query->is_main_query() ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( ! $screen || 'upload' !== $screen->id ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
	$value = isset( $_GET['nm_ai_badge'] ) ? sanitize_text_field( wp_unslash( $_GET['nm_ai_badge'] ) ) : '';

	if ( '' === $value ) {
		return;
	}

	if ( 'unlabelled' === $value ) {
		$query->set(
			'meta_query',
			array(
				'relation' => 'OR',
				array(
					'key'     => META_KEY,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => META_KEY,
					'value'   => '',
					'compare' => '=',
				),
			)
		);

		return;
	}

	if ( in_array( $value, LABELS, true ) ) {
		$query->set(
			'meta_query',
			array(
				array(
					'key'     => META_KEY,
					'value'   => $value,
					'compare' => '=',
				),
			)
		);
	}
}

/**
 * Add bulk actions to the media list table.
 *
 * @param array<string, string> $actions Existing actions.
 * @return array<string, string>
 */
function add_bulk_actions( array $actions ): array {
	$actions['nm_ai_badge_generated'] = __( 'Mark as AI-generated', 'nm-wp-ai-badger' );
	$actions['nm_ai_badge_assisted']  = __( 'Mark as AI-assisted', 'nm-wp-ai-badger' );
	$actions['nm_ai_badge_none']      = __( 'Remove AI labelling', 'nm-wp-ai-badger' );

	return $actions;
}

/**
 * Handle the bulk actions.
 *
 * @param string           $redirect_to Redirect URL.
 * @param string           $action      Chosen action.
 * @param array<int, int>  $post_ids    Selected attachment IDs.
 */
function handle_bulk_actions( string $redirect_to, string $action, array $post_ids ): string {
	$map = array(
		'nm_ai_badge_generated' => 'generated',
		'nm_ai_badge_assisted'  => 'assisted',
		'nm_ai_badge_none'      => '',
	);

	if ( ! array_key_exists( $action, $map ) ) {
		return $redirect_to;
	}

	$label   = $map[ $action ];
	$changed = 0;

	foreach ( $post_ids as $post_id ) {
		$post_id = (int) $post_id;

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			continue;
		}

		if ( '' === $label ) {
			delete_post_meta( $post_id, META_KEY );
		} else {
			update_post_meta( $post_id, META_KEY, $label );
		}

		++$changed;
	}

	return add_query_arg( 'nm_ai_badge_updated', $changed, $redirect_to );
}

/**
 * Show a notice after a bulk action.
 */
function bulk_action_notice(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice.
	if ( ! isset( $_GET['nm_ai_badge_updated'] ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice.
	$count = (int) $_GET['nm_ai_badge_updated'];

	printf(
		'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: %d: number of images */
				_n( 'AI labelling updated for %d image.', 'AI labelling updated for %d images.', $count, 'nm-wp-ai-badger' ),
				$count
			)
		)
	);
}
