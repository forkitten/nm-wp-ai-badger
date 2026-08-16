/**
 * Tags labelled image blocks on the editor canvas with a data attribute. The badge itself is drawn
 * by CSS (see includes/editor.php) as a pseudo-element, so no node is added to the block markup.
 *
 * Written against the wp.* globals on purpose: the plugin ships no build step, and this is small
 * enough that a bundler would cost more than it saves.
 */
( function ( wp ) {
	'use strict';

	if (
		! wp ||
		! wp.hooks ||
		! wp.element ||
		! wp.compose ||
		! wp.data ||
		! wp.blockEditor ||
		! wp.components
	) {
		return;
	}

	var config = window.nmAiBadger || {};
	var texts = config.texts || {};
	var attribute = config.attribute;
	var metaKey = config.metaKey;
	var excludeClass = config.excludeClass;
	var choices = config.choices || [];
	var ui = config.ui || {};

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

	/**
	 * Where a block keeps its CSS classes. The checkbox toggles the exclusion class in place rather
	 * than introducing a second opt-out mechanism, so the manual class and the checkbox stay one and
	 * the same setting — and the server keeps its existing, single check.
	 */
	var CLASS_FIELD = {
		'core/image': {
			read: function ( attributes ) {
				return attributes.className || '';
			},
			write: function ( value ) {
				return { className: value || undefined };
			},
		},
		'etch/dynamic-image': {
			read: function ( attributes ) {
				return ( attributes.attributes || {} ).class || '';
			},
			write: function ( value, attributes ) {
				var nested = Object.assign( {}, attributes.attributes || {} );

				if ( value ) {
					nested.class = value;
				} else {
					delete nested.class;
				}

				return { attributes: nested };
			},
		},
	};

	/**
	 * Add or remove a single class name, leaving every other class untouched.
	 */
	function toggleClass( existing, className, add ) {
		var list = existing.split( /\s+/ ).filter( function ( value ) {
			return value !== '' && value !== className;
		} );

		if ( add ) {
			list.push( className );
		}

		return list.join( ' ' );
	}

	/**
	 * Adds the labelling control to the block sidebar, so an image uploaded straight into a post can
	 * be labelled without a detour through the media library.
	 *
	 * The value lives on the attachment rather than on the block, so it is saved immediately instead
	 * of riding along with the post: saving the post would not persist it, and leaving it pending
	 * would suggest otherwise.
	 */
	var withInspector = wp.compose.createHigherOrderComponent( function ( BlockEdit ) {
		return function ( props ) {
			var getId = SUPPORTED[ props.name ];
			var attachmentId = getId ? getId( props.attributes || {} ) : undefined;
			var state = wp.element.useState( '' );
			var status = state[ 0 ];
			var setStatus = state[ 1 ];

			var data = wp.data.useSelect(
				function ( select ) {
					if ( ! attachmentId ) {
						return null;
					}

					var core = select( 'core' );
					var media = core.getMedia( attachmentId );

					return {
						label: ( media && media.meta && media.meta[ metaKey ] ) || '',
						loaded: !! media,
						canEdit: core.canUser( 'update', 'media', attachmentId ),
					};
				},
				[ attachmentId ]
			);

			var edit = wp.element.createElement( BlockEdit, props );

			// No image chosen yet, still loading, or read-only for this user.
			if ( ! attachmentId || ! data || ! data.loaded || data.canEdit === false ) {
				return edit;
			}

			function onChange( value ) {
				var meta = {};
				meta[ metaKey ] = value;

				setStatus( ui.saving || '' );

				wp.data
					.dispatch( 'core' )
					.saveEntityRecord( 'root', 'media', { id: attachmentId, meta: meta } )
					.then( function () {
						setStatus( ui.saved || '' );
					} )
					.catch( function () {
						setStatus( ui.error || '' );
					} );
			}

			// Rendered into the settings group without a PanelBody title, so it reads as another
			// field in the Settings tab rather than a separate collapsible section. WordPress always
			// appends extension controls after the block's own panels, so this sits below the alt
			// text rather than directly beneath it — there is no API to insert into a core panel.
			var classField = CLASS_FIELD[ props.name ];
			var hidden = isExcluded( props.attributes || {} );

			function onToggleHidden( checked ) {
				if ( ! classField ) {
					return;
				}

				var current = classField.read( props.attributes || {} );

				props.setAttributes(
					classField.write(
						toggleClass( current, excludeClass, checked ),
						props.attributes || {}
					)
				);
			}

			var fields = [
				wp.element.createElement( wp.components.SelectControl, {
					__nextHasNoMarginBottom: true,
					key: 'label',
					label: ui.panelTitle,
					value: data.label,
					options: choices,
					onChange: onChange,
					help: status || ui.help,
				} ),
			];

			// Only offered where the badge would actually show: an unlabelled image has none to hide.
			if ( classField && data.label ) {
				fields.push(
					wp.element.createElement( wp.components.CheckboxControl, {
						__nextHasNoMarginBottom: true,
						key: 'hide',
						label: ui.hideLabel,
						checked: hidden,
						onChange: onToggleHidden,
						help: ui.hideHelp,
					} )
				);
			}

			var control = wp.element.createElement(
				wp.blockEditor.InspectorControls,
				{ group: 'settings' },
				wp.element.createElement(
					wp.components.PanelBody,
					{ title: null },
					fields
				)
			);

			return wp.element.createElement( wp.element.Fragment, null, edit, control );
		};
	}, 'nmAiBadgerWithInspector' );

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

	wp.hooks.addFilter( 'editor.BlockEdit', 'nm-ai-badger/with-inspector', withInspector );
	wp.hooks.addFilter( 'editor.BlockListBlock', 'nm-ai-badger/with-badge', withBadge );
} )( window.wp );
