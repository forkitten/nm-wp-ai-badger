/**
 * Tags labelled image blocks on the editor canvas with a data attribute. The badge itself is drawn
 * by CSS (see includes/editor.php) as a pseudo-element, so no node is added to the block markup.
 *
 * Written against the wp.* globals on purpose: the plugin ships no build step, and this is small
 * enough that a bundler would cost more than it saves.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.hooks || ! wp.element || ! wp.compose || ! wp.data ) {
		return;
	}

	var config = window.nmAiBadger || {};
	var texts = config.texts || {};
	var attribute = config.attribute;
	var metaKey = config.metaKey;
	var excludeClass = config.excludeClass;

	if ( ! attribute || ! metaKey ) {
		return;
	}

	/** Block types that carry a badge, mirroring nm_ai_badger_supported_blocks on the PHP side. */
	var SUPPORTED = {
		'core/image': function ( attributes ) {
			return attributes.id;
		},
		'etch/dynamic-image': function ( attributes ) {
			var nested = attributes.attributes || {};
			var id = parseInt( nested.mediaId, 10 );

			// A dynamic expression such as {item.image.id} only resolves on the front end.
			return isNaN( id ) ? undefined : id;
		},
	};

	/**
	 * Whether the block opts out via the exclusion class.
	 */
	function isExcluded( attributes ) {
		var nested = attributes.attributes || {};

		return [ attributes.className, nested.class ].some( function ( value ) {
			return (
				typeof value === 'string' &&
				value.split( /\s+/ ).indexOf( excludeClass ) !== -1
			);
		} );
	}

	var withBadge = wp.compose.createHigherOrderComponent( function ( BlockListBlock ) {
		return function ( props ) {
			var getId = SUPPORTED[ props.name ];
			var attributes = props.attributes || {};
			var attachmentId = getId ? getId( attributes ) : undefined;

			var label = wp.data.useSelect(
				function ( select ) {
					if ( ! attachmentId ) {
						return '';
					}

					var media = select( 'core' ).getMedia( attachmentId );

					return ( media && media.meta && media.meta[ metaKey ] ) || '';
				},
				[ attachmentId ]
			);

			var text = label ? texts[ label ] : '';

			if ( ! text || isExcluded( attributes ) ) {
				return wp.element.createElement( BlockListBlock, props );
			}

			var wrapperProps = Object.assign( {}, props.wrapperProps );
			wrapperProps[ attribute ] = text;

			return wp.element.createElement(
				BlockListBlock,
				Object.assign( {}, props, { wrapperProps: wrapperProps } )
			);
		};
	}, 'nmAiBadgerWithBadge' );

	wp.hooks.addFilter( 'editor.BlockListBlock', 'nm-ai-badger/with-badge', withBadge );
} )( window.wp );
