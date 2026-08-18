<?php
/**
 * Server-side badge injection.
 *
 * @package NM_AI_Badger
 */

declare( strict_types = 1 );

namespace NM\AIBadger\Render;

use NM\AIBadger\Media;
use NM\AIBadger\Settings;
use const NM\AIBadger\EXCLUDE_CLASS;
use const NM\AIBadger\HOST_CLASS;
use const NM\AIBadger\NOWRAP_CLASS;

defined( 'ABSPATH' ) || exit;

/**
 * Register the render_block filters for every supported block.
 */
function bootstrap(): void {
	add_action( 'init', __NAMESPACE__ . '\\add_filters', 20 );
}

/**
 * Attach the filters. Runs late on `init` so third parties can hook the block list first.
 */
function add_filters(): void {
	foreach ( supported_blocks() as $block_name ) {
		add_filter( 'render_block_' . $block_name, __NAMESPACE__ . '\\maybe_inject_badge', 10, 3 );
	}

	// Gallery items are flex children whose sizing the wrapper would break; see unwrap_in_flow().
	add_filter( 'render_block_core/gallery', __NAMESPACE__ . '\\unwrap_gallery_badges', 10, 1 );

	if ( \NM\AIBadger\etch_is_active() ) {
		add_filter( 'render_block_etch/element', __NAMESPACE__ . '\\maybe_unwrap_background', 10, 2 );
	}
}

/**
 * Class names on a containing element that mark its image as a background image.
 *
 * @return array<int, string>
 */
function background_classes(): array {
	/**
	 * Filters the class names that identify a background-image container.
	 *
	 * `is-bg` is Etch's own background utility.
	 *
	 * @param array<int, string> $classes Class names.
	 */
	$classes = apply_filters( 'nm_ai_badger_background_classes', array( 'is-bg' ) );

	return array_values( array_filter( array_map( 'strval', (array) $classes ) ) );
}

/**
 * Remove the badge wrapper inside a background-image container.
 *
 * Etch's `is-bg` utility styles the image with a direct-child selector (`.is-bg > img`) and relies
 * on the image being absolutely positioned inside the figure. A wrapper element between the two
 * breaks both. Here the wrapper is taken back out again, leaving the badge as a sibling of the
 * image: the selector matches once more, and the badge positions itself against the figure, which
 * `is-bg` already makes a containing block.
 *
 * The parent cannot be inspected from the image block itself — Etch renders child blocks through
 * its own `render_block()` calls, so WordPress passes no parent block along.
 *
 * @param string               $block_content Rendered block HTML.
 * @param array<string, mixed> $block         Parsed block.
 */
function maybe_unwrap_background( string $block_content, array $block ): string {
	if ( ! str_contains( $block_content, 'nm-ai-badge-wrap' ) ) {
		return $block_content;
	}

	$class_attr = $block['attrs']['attributes']['class'] ?? '';

	if ( ! is_string( $class_attr ) || '' === $class_attr ) {
		return $block_content;
	}

	$classes = preg_split( '/\s+/', trim( $class_attr ) ) ?: array();

	if ( ! array_intersect( $classes, background_classes() ) ) {
		return $block_content;
	}

	return unwrap_badge( $block_content );
}

/**
 * Strip the badge wrapper, keeping image and badge as siblings.
 *
 * @param string $html Rendered HTML containing a wrapped badge.
 */
function unwrap_badge( string $html ): string {
	$pattern = '#<span class="nm-ai-badge-wrap">(.*?)(<span class="nm-ai-badge )#s';

	$unwrapped = preg_replace_callback(
		$pattern,
		static function ( array $m ): string {
			return $m[1] . $m[2];
		},
		$html
	);

	if ( ! is_string( $unwrapped ) || $unwrapped === $html ) {
		return $html;
	}

	// Drop the now-superfluous closing tag of each wrapper we opened.
	$unwrapped = preg_replace( '#(<span class="nm-ai-badge nm-ai-badge--\w+">.*?</span>)</span>#s', '$1', $unwrapped );

	return is_string( $unwrapped ) ? $unwrapped : $html;
}

/**
 * The block types the badge is injected into.
 *
 * @return array<int, string>
 */
function supported_blocks(): array {
	$blocks = array( 'core/image', 'core/cover', 'core/post-featured-image', 'core/media-text', 'core/group' );

	if ( \NM\AIBadger\etch_is_active() ) {
		$blocks[] = 'etch/dynamic-image';
	}

	$blocks = apply_filters( 'nm_ai_badger_supported_blocks', $blocks );

	return array_values( array_filter( array_map( 'strval', (array) $blocks ) ) );
}

/**
 * Inject the badge into a rendered block, if the image carries a label.
 *
 * @param string               $block_content Rendered block HTML.
 * @param array<string, mixed> $block         Parsed block.
 * @param \WP_Block|null       $instance      Block instance, carries the rendering context.
 */
function maybe_inject_badge( string $block_content, array $block, ?\WP_Block $instance = null ): string {
	if ( '' === trim( $block_content ) || is_admin() || is_feed() ) {
		return $block_content;
	}

	// Checked first so an excluded image costs nothing beyond the class lookup.
	if ( has_exclude_class( $block_content, $block ) ) {
		return $block_content;
	}

	$attachment_id = attachment_id_for_block( $block_content, $block, $instance );

	if ( ! $attachment_id ) {
		return $block_content;
	}

	$label = Media\get_label( $attachment_id );

	if ( '' === $label ) {
		return $block_content;
	}

	$text = Settings\badge_text( $label );

	if ( '' === $text ) {
		return $block_content;
	}

	$badge = badge_html( $label, $text );

	// A block whose image is a CSS background has no <img> to attach to: the badge becomes the
	// element's own last child instead.
	if ( '' !== background_image_url( $block ) ) {
		return append_badge_to_element( $block_content, $badge );
	}

	// An image that fills or backs its container is sized and positioned against that container by
	// core's CSS. A wrapper would take its place as flex item or positioning context and collapse
	// it, so there the badge goes in as a plain sibling.
	$host = bare_image_host( $block_content, $block );

	if ( null !== $host ) {
		return append_badge_after_image( $block_content, $host['image_class'], $badge, $host['host_class'] );
	}

	$wrapped = wrap_image( $block_content, $badge );

	// Manual escape hatch for layouts the wrapper would break — see NOWRAP_CLASS.
	if ( has_class( $block_content, NOWRAP_CLASS ) ) {
		return unwrap_badge( $wrapped );
	}

	return $wrapped;
}

/**
 * The background image URL a block declares through the style attribute, or an empty string.
 *
 * Group, row and stack blocks put their background image here; there is no image element in the
 * markup at all. Only images from the media library carry an id and can be labelled.
 *
 * @param array<string, mixed> $block Parsed block.
 */
function background_image_url( array $block ): string {
	$image = $block['attrs']['style']['background']['backgroundImage'] ?? null;

	if ( ! is_array( $image ) || ! numeric_id( $image['id'] ?? null ) ) {
		return '';
	}

	return is_string( $image['url'] ?? null ) ? $image['url'] : '';
}

/**
 * Where a block wants its badge placed next to, rather than around, its image.
 *
 * @param string               $block_content Rendered block HTML.
 * @param array<string, mixed> $block         Parsed block.
 * @return array{image_class: string, host_class: string}|null
 */
function bare_image_host( string $block_content, array $block ) {
	foreach ( background_image_classes() as $class_name ) {
		if ( has_class( $block_content, $class_name ) ) {
			// core/cover already makes .wp-block-cover a positioning context.
			return array(
				'image_class' => $class_name,
				'host_class'  => '',
			);
		}
	}

	if ( 'core/media-text' === ( $block['blockName'] ?? '' ) ) {
		// The media column is a grid item, and with the fill option the image is absolutely
		// positioned against it. Either way the wrapper would get in the way.
		return array(
			'image_class' => '',
			'host_class'  => 'wp-block-media-text__media',
		);
	}

	return null;
}

/**
 * Append the badge as the last child of the block's own outermost element.
 *
 * @param string $block_content Rendered block HTML.
 * @param string $badge         Badge markup.
 */
function append_badge_to_element( string $block_content, string $badge ): string {
	$close = strrpos( $block_content, '</' );

	if ( false === $close ) {
		return $block_content;
	}

	return add_host_class( substr( $block_content, 0, $close ) . $badge . substr( $block_content, $close ) );
}

/**
 * Add the positioning class to the outermost tag.
 *
 * @param string $html Rendered HTML.
 */
function add_host_class( string $html ): string {
	$processor = new \WP_HTML_Tag_Processor( $html );

	if ( ! $processor->next_tag() ) {
		return $html;
	}

	$processor->add_class( HOST_CLASS );

	return $processor->get_updated_html();
}

/**
 * Add the positioning class to the first tag carrying a given class.
 *
 * @param string $html       Rendered HTML.
 * @param string $class_name Class identifying the host element.
 */
function add_host_class_to( string $html, string $class_name ): string {
	$processor = new \WP_HTML_Tag_Processor( $html );

	while ( $processor->next_tag() ) {
		if ( true === $processor->has_class( $class_name ) ) {
			$processor->add_class( HOST_CLASS );

			return $processor->get_updated_html();
		}
	}

	return $html;
}

/**
 * Classes that mark an `<img>` as a background image rather than a content image.
 *
 * @return array<int, string>
 */
function background_image_classes(): array {
	/**
	 * Filters the classes that identify an image as a background image.
	 *
	 * @param array<int, string> $classes Class names.
	 */
	$classes = apply_filters( 'nm_ai_badger_background_image_classes', array( 'wp-block-cover__image-background' ) );

	return array_values( array_filter( array_map( 'strval', (array) $classes ) ) );
}

/**
 * Insert the badge directly after an image, leaving the markup around it alone.
 *
 * Targets a specific image where a class is given rather than merely the first one: a cover block
 * can hold further image blocks in its inner container, and those carry their own badge already.
 *
 * @param string $html        Rendered block HTML.
 * @param string $image_class Class identifying the image, empty for the first one.
 * @param string $badge       Badge markup.
 * @param string $host_class  Class of the element the badge is positioned against, empty when that
 *                            element is a positioning context already.
 */
function append_badge_after_image( string $html, string $image_class, string $badge, string $host_class = '' ): string {
	if ( '' === $image_class ) {
		$pattern = '#<img\b[^>]*>#i';
	} else {
		$pattern = '#<img\b[^>]*\bclass="[^"]*(?<![\w-])' . preg_quote( $image_class, '#' ) . '(?![\w-])[^"]*"[^>]*>#i';
	}

	$result = preg_replace( $pattern, '$0' . str_replace( '$', '\\$', $badge ), $html, 1, $count );

	if ( ! is_string( $result ) || 0 === $count ) {
		return $html;
	}

	return '' === $host_class ? $result : add_host_class_to( $result, $host_class );
}

/**
 * Take the wrapper back out of gallery items.
 *
 * A nested gallery lays its items out as flex containers and expects the image itself to be the
 * flex child that fills the column (`flex: 1 0 0%; height: 100%; object-fit: cover`). The wrapper
 * takes that role instead and shrinks to the image's intrinsic size, so cropped galleries stop
 * cropping. Here the wrapper is removed again and the item is made a positioning context, which
 * gallery CSS does not do on its own.
 *
 * @param string $block_content Rendered gallery HTML.
 */
function unwrap_gallery_badges( string $block_content ): string {
	if ( ! str_contains( $block_content, 'nm-ai-badge-wrap' ) ) {
		return $block_content;
	}

	$unwrapped = unwrap_badge( $block_content );

	// Each item that now holds a badge has to become the element the badge is positioned against.
	$result = preg_replace_callback(
		'#<figure\b[^>]*\bclass="[^"]*(?<![\w-])wp-block-image(?![\w-])[^"]*"[^>]*>(.*?)</figure>#is',
		static function ( array $m ): string {
			return str_contains( $m[1], 'nm-ai-badge nm-ai-badge--' ) ? add_host_class( $m[0] ) : $m[0];
		},
		$unwrapped
	);

	return is_string( $result ) ? $result : $unwrapped;
}

/**
 * The badge element.
 *
 * @param string $label Stable label value.
 * @param string $text  Badge text.
 */
function badge_html( string $label, string $text ): string {
	return sprintf(
		'<span class="nm-ai-badge nm-ai-badge--%s">%s</span>',
		esc_attr( $label ),
		esc_html( $text )
	);
}

/**
 * Whether the block opts out of the badge.
 *
 * Checks the parsed `className` attribute (where the block editor's "Additional CSS class(es)"
 * field lands) and the class attribute of the outermost rendered tag (where Etch puts it, since
 * `etch/dynamic-image` has no className support).
 *
 * @param string               $block_content Rendered block HTML.
 * @param array<string, mixed> $block         Parsed block.
 */
function has_exclude_class( string $block_content, array $block ): bool {
	$class_name = $block['attrs']['className'] ?? '';

	if ( is_string( $class_name ) && in_array( EXCLUDE_CLASS, preg_split( '/\s+/', trim( $class_name ) ) ?: array(), true ) ) {
		return true;
	}

	return has_class( $block_content, EXCLUDE_CLASS );
}

/**
 * Whether any tag in the markup carries a class.
 *
 * @param string $html       Rendered HTML.
 * @param string $class_name Class to look for.
 */
function has_class( string $html, string $class_name ): bool {
	if ( ! str_contains( $html, $class_name ) ) {
		// Cheap bail-out: no substring means no class, so the parser can be skipped entirely.
		return false;
	}

	$processor = new \WP_HTML_Tag_Processor( $html );

	while ( $processor->next_tag() ) {
		if ( true === $processor->has_class( $class_name ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Resolve the attachment ID for a block.
 *
 * @param string               $block_content Rendered block HTML.
 * @param array<string, mixed> $block         Parsed block.
 * @param \WP_Block|null       $instance      Block instance, carries the rendering context.
 */
function attachment_id_for_block( string $block_content, array $block, ?\WP_Block $instance = null ): int {
	$attrs = $block['attrs'] ?? array();

	// The featured image block stores no media reference of its own — it belongs to the post it is
	// rendered for. Inside a query loop that post comes from the block context, not the main query.
	if ( 'core/post-featured-image' === ( $block['blockName'] ?? '' ) ) {
		$post_id = (int) ( $instance->context['postId'] ?? get_the_ID() );

		if ( $post_id ) {
			return (int) get_post_thumbnail_id( $post_id );
		}
	}

	// Group, row and stack keep their background image in the style attribute.
	$background = $attrs['style']['background']['backgroundImage']['id'] ?? null;

	if ( numeric_id( $background ) ) {
		return numeric_id( $background );
	}

	// core/image, core/cover and anything else that puts a plain ID at the top level.
	$id = numeric_id( $attrs['id'] ?? null );

	// core/media-text names it mediaId, at the top level rather than nested the way Etch does.
	if ( ! $id ) {
		$id = numeric_id( $attrs['mediaId'] ?? null );
	}

	// etch/dynamic-image nests its attributes one level deeper. The value is a string, and in a
	// loop it is a dynamic expression such as `{this.…}` that Etch only resolves at render time —
	// so a non-numeric value falls through to the URL lookup below.
	if ( ! $id && isset( $attrs['attributes'] ) && is_array( $attrs['attributes'] ) ) {
		$id = numeric_id( $attrs['attributes']['mediaId'] ?? null );
	}

	// Only where the attributes cannot state the image. Container blocks must never fall through
	// to this: they would adopt whatever image happens to sit inside them.
	if ( ! $id && in_array( $block['blockName'] ?? '', url_fallback_blocks(), true ) ) {
		$id = attachment_id_from_html( $block_content );
	}

	/**
	 * Filters the attachment ID resolved for a block.
	 *
	 * @param int                  $id            Resolved attachment ID, 0 if none.
	 * @param string               $block_content Rendered block HTML.
	 * @param array<string, mixed> $block         Parsed block.
	 */
	return (int) apply_filters( 'nm_ai_badger_attachment_id', $id, $block_content, $block );
}

/**
 * Blocks whose attachment has to be recovered from the rendered `src`.
 *
 * Etch resolves a dynamic media id only while rendering, so inside a loop the parsed attributes
 * hold an expression rather than a number. Every other supported block names its image outright.
 *
 * @return array<int, string>
 */
function url_fallback_blocks(): array {
	/**
	 * Filters the blocks whose attachment is resolved from the rendered image URL.
	 *
	 * @param array<int, string> $blocks Block names.
	 */
	$blocks = apply_filters( 'nm_ai_badger_url_fallback_blocks', array( 'etch/dynamic-image' ) );

	return array_values( array_filter( array_map( 'strval', (array) $blocks ) ) );
}

/**
 * Cast a block attribute to a positive integer ID, or 0.
 *
 * @param mixed $value Raw attribute value.
 */
function numeric_id( $value ): int {
	if ( is_int( $value ) ) {
		return $value > 0 ? $value : 0;
	}

	if ( is_string( $value ) && ctype_digit( $value ) ) {
		return (int) $value;
	}

	return 0;
}

/**
 * Resolve an attachment ID from the rendered `src`.
 *
 * Fallback for blocks that carry no usable ID attribute — notably Etch images inside a loop.
 *
 * @param string $html Rendered block HTML.
 */
function attachment_id_from_html( string $html ): int {
	$processor = new \WP_HTML_Tag_Processor( $html );

	if ( ! $processor->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
		return 0;
	}

	$src = $processor->get_attribute( 'src' );

	return is_string( $src ) ? attachment_id_from_url( $src ) : 0;
}

/**
 * Look up an attachment ID by URL, tolerating resized file names.
 *
 * @param string $url Image URL.
 */
function attachment_id_from_url( string $url ): int {
	static $cache = array();

	$url = trim( $url );

	if ( '' === $url || str_starts_with( $url, 'data:' ) ) {
		return 0;
	}

	// Optimisation plugins and CDNs often rewrite src to a protocol-relative URL, which
	// attachment_url_to_postid() does not recognise.
	if ( str_starts_with( $url, '//' ) ) {
		$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
	}

	if ( isset( $cache[ $url ] ) ) {
		return $cache[ $url ];
	}

	$cache_key = 'nm_ai_badger_url_' . md5( $url );
	$cached    = wp_cache_get( $cache_key, 'nm-wp-ai-badger' );

	if ( false !== $cached ) {
		$cache[ $url ] = (int) $cached;

		return $cache[ $url ];
	}

	$id = (int) attachment_url_to_postid( $url );

	if ( ! $id ) {
		// `src` may point at an intermediate size (…-1024x683.jpg) that has no post of its own.
		$full = preg_replace( '/-\d+x\d+(\.[a-zA-Z0-9]+)$/', '$1', $url );

		if ( is_string( $full ) && $full !== $url ) {
			$id = (int) attachment_url_to_postid( $full );
		}
	}

	$cache[ $url ] = $id;

	// Only meaningful with a persistent object cache; harmless otherwise. Kept short-lived so a
	// re-uploaded or moved file corrects itself without a manual flush.
	wp_cache_set( $cache_key, $id, 'nm-wp-ai-badger', HOUR_IN_SECONDS );

	return $id;
}

/**
 * Wrap the image in a positioning context and append the badge.
 *
 * The wrapper goes around the `<img>` itself (or its surrounding link) rather than around the whole
 * block. That keeps figure/caption markup and the theme's alignment classes intact, and it anchors
 * the badge to the image instead of to the caption below it.
 *
 * @param string $block_content Rendered block HTML.
 * @param string $badge         Badge markup.
 */
function wrap_image( string $block_content, string $badge ): string {
	// Prefer wrapping an anchor around the image, so the badge does not become part of the link text.
	$patterns = array(
		'#(<a\b[^>]*>\s*<img\b[^>]*>\s*</a>)#i',
		'#(<img\b[^>]*>)#i',
	);

	foreach ( $patterns as $pattern ) {
		$replaced = preg_replace(
			$pattern,
			'<span class="nm-ai-badge-wrap">$1' . str_replace( '$', '\\$', $badge ) . '</span>',
			$block_content,
			1,
			$count
		);

		if ( is_string( $replaced ) && $count > 0 ) {
			return $replaced;
		}
	}

	// No <img> found — leave the markup untouched rather than guessing.
	return $block_content;
}
