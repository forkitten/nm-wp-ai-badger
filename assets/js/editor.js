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
		'core/cover': function ( attributes ) {
			// A cover with a video background keeps an attachment id too, but renders no <img> —
			// the front end shows no badge there, so the editor must not offer one either.
			return 'video' === attributes.backgroundType ? undefined : attributes.id;
		},
		'core/media-text': function ( attributes ) {
			// A media-text block can hold a video instead; then there is no image to badge.
			return 'video' === attributes.mediaType ? undefined : attributes.mediaId;
		},
		'core/group': function ( attributes ) {
			// Group, row and stack keep their background image in the style attribute.
			var background =
				attributes.style &&
				attributes.style.background &&
				attributes.style.background.backgroundImage;

			return background ? background.id : undefined;
		},
		'etch/dynamic-image': function ( attributes ) {
			var nested = attributes.attributes || {};
			var id = parseInt( nested.mediaId, 10 );

			// A dynamic expression such as {item.image.id} only resolves on the front end.
			return isNaN( id ) ? undefined : id;
		},
		// The featured image block holds no media reference of its own; see resolveAttachmentId().
		'core/post-featured-image': null,
	};

	/**
	 * Blocks whose image is chosen in the block itself, so editing its labelling here is meaningful.
	 *
	 * The featured image block is deliberately absent: it is a template. Inside a query loop it is
	 * placed once and rendered for many posts, so a labelling field would edit whichever post the
	 * editor happens to resolve — arbitrary from the author's point of view. Only the checkbox,
	 * which is a property of the block, makes sense there.
	 */
	var LABEL_FIELD_BLOCKS = [
		'core/image',
		'core/cover',
		'core/media-text',
		'core/group',
		'etch/dynamic-image',
	];

	function offersLabelField( name ) {
		return LABEL_FIELD_BLOCKS.indexOf( name ) !== -1;
	}

	/**
	 * Whether the plugin handles this block type. Cannot be a truthiness check on the map: the
	 * featured image block is registered with a null resolver.
	 */
	function isSupported( name ) {
		return Object.prototype.hasOwnProperty.call( SUPPORTED, name );
	}

	/**
	 * The attachment a block shows, resolved inside a useSelect callback.
	 *
	 * Most blocks state it in their own attributes. The featured image block does not — it belongs
	 * to the post being rendered, which inside a query loop is not the post being edited.
	 */
	function resolveAttachmentId( select, name, attributes, context ) {
		if ( 'core/post-featured-image' === name ) {
			if ( context.postId && context.postType ) {
				// Edited, not saved: a featured image just picked but not yet published must show
				// its labelling straight away.
				var record = select( 'core' ).getEditedEntityRecord( 'postType', context.postType, context.postId );

				return record ? record.featured_media : undefined;
			}

			var editor = select( 'core/editor' );

			return editor ? editor.getEditedPostAttribute( 'featured_media' ) : undefined;
		}

		var getId = SUPPORTED[ name ];

		return getId ? getId( attributes ) : undefined;
	}

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
		'core/cover': {
			read: function ( attributes ) {
				return attributes.className || '';
			},
			write: function ( value ) {
				return { className: value || undefined };
			},
		},
		'core/post-featured-image': {
			read: function ( attributes ) {
				return attributes.className || '';
			},
			write: function ( value ) {
				return { className: value || undefined };
			},
		},
		'core/media-text': {
			read: function ( attributes ) {
				return attributes.className || '';
			},
			write: function ( value ) {
				return { className: value || undefined };
			},
		},
		'core/group': {
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
			var state = wp.element.useState( '' );
			var status = state[ 0 ];
			var setStatus = state[ 1 ];

			var data = wp.data.useSelect(
				function ( select ) {
					// Only the labelling field needs the attachment. Blocks that just get the
					// checkbox skip the lookup — a query loop would otherwise resolve and fetch a
					// post plus its media for every entry, to no purpose.
					if ( ! offersLabelField( props.name ) ) {
						return null;
					}

					var attachmentId = resolveAttachmentId(
						select,
						props.name,
						props.attributes || {},
						props.context || {}
					);

					if ( ! attachmentId ) {
						return null;
					}

					var core = select( 'core' );
					var media = core.getMedia( attachmentId );

					return {
						attachmentId: attachmentId,
						label: ( media && media.meta && media.meta[ metaKey ] ) || '',
						loaded: !! media,
						canEdit: core.canUser( 'update', 'media', attachmentId ),
					};
				},
				[ props.name, props.attributes, props.context ]
			);

			var edit = wp.element.createElement( BlockEdit, props );

			if ( ! isSupported( props.name ) ) {
				return edit;
			}

			var classField = CLASS_FIELD[ props.name ];
			var showLabel = offersLabelField( props.name );

			// The labelling field needs a readable, editable attachment; the checkbox does not.
			var labelReady = !! data && data.loaded && data.canEdit !== false;

			function onChange( value ) {
				var meta = {};
				meta[ metaKey ] = value;

				setStatus( ui.saving || '' );

				wp.data
					.dispatch( 'core' )
					.saveEntityRecord( 'root', 'media', { id: data.attachmentId, meta: meta } )
					.then( function () {
						setStatus( ui.saved || '' );
					} )
					.catch( function () {
						setStatus( ui.error || '' );
					} );
			}

			function onToggleHidden( checked ) {
				if ( ! classField ) {
					return;
				}

				props.setAttributes(
					classField.write(
						toggleClass( classField.read( props.attributes || {} ), excludeClass, checked ),
						props.attributes || {}
					)
				);
			}

			var fields = [];

			if ( showLabel && labelReady ) {
				fields.push(
					wp.element.createElement( wp.components.SelectControl, {
						__nextHasNoMarginBottom: true,
						key: 'label',
						label: ui.panelTitle,
						value: data.label,
						options: choices,
						onChange: onChange,
						help: status || undefined,
					} )
				);

				// The labelling lives on the attachment, so it is not scoped to this block the way
				// the rest of the sidebar is. Worth stating plainly rather than hiding in help text.
				if ( ui.warning ) {
					fields.push(
						wp.element.createElement(
							wp.components.Notice,
							{
								key: 'warning',
								status: 'warning',
								isDismissible: false,
								politeness: 'polite',
							},
							ui.warning
						)
					);
				}
			}

			// Where the image is picked in the block, hiding is only offered once there is a badge
			// to hide. Where it is dynamic, the choice has to be available up front.
			var offerCheckbox = classField && ( showLabel ? labelReady && data.label : true );

			if ( offerCheckbox ) {
				fields.push(
					wp.element.createElement( wp.components.CheckboxControl, {
						__nextHasNoMarginBottom: true,
						key: 'hide',
						label: ui.hideLabel,
						checked: isExcluded( props.attributes || {} ),
						onChange: onToggleHidden,
						help: showLabel ? ui.hideHelp : ui.hideHelpDynamic,
					} )
				);
			}

			if ( ! fields.length ) {
				return edit;
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
			var attributes = props.attributes || {};

			var label = wp.data.useSelect(
				function ( select ) {
					if ( ! isSupported( props.name ) ) {
						return '';
					}

					var attachmentId = resolveAttachmentId(
						select,
						props.name,
						attributes,
						props.context || {}
					);

					if ( ! attachmentId ) {
						return '';
					}

					var media = select( 'core' ).getMedia( attachmentId );

					return ( media && media.meta && media.meta[ metaKey ] ) || '';
				},
				[ props.name, attributes, props.context ]
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
