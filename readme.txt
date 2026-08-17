=== NM AI Badger ===
Contributors: netzmaedchen
Tags: ai, media, images, labelling, compliance
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.4.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Mark media library images as AI-generated or AI-assisted and render a badge on the front end, server-side, for Gutenberg and Etch.

== Description ==

Images in the media library get an "AI labelling" field with three choices: none, AI-generated, or AI-assisted. Wherever a labelled image is used in a supported block, a badge is rendered into the page.

The badge is injected server-side through the `render_block` filter. No JavaScript, no shortcode, nothing for editors to remember beyond setting the field once per image.

= Supported blocks =

* `core/image` — including images nested in galleries and columns
* `core/cover` — the cover's background image; images placed inside a cover keep their own badge
* `core/post-featured-image` — including inside a query loop, where each entry resolves to its own post's image
* `etch/dynamic-image` — including images inside Etch loops, where the media ID is a dynamic expression

The list is filterable, see `nm_ai_badger_supported_blocks` below.

= Not covered =

* Images used as CSS `background-image` (no attachment ID in the markup)
* Featured images rendered by a theme template's `the_post_thumbnail()` rather than by the featured image block
* Other page builders (Beaver Builder, Bricks)

= Hiding the badge on a single image =

In the block editor, tick "Hide the badge here" in the image block's sidebar. The checkbox appears once the image carries a labelling.

It writes the CSS class `nm-hide-ai-badge` onto the block, which is the same thing as entering that class by hand under "Advanced" — one mechanism, so a class set either way is reflected by the checkbox. Etch image elements have no such field, so there the class goes into the element's regular `class` attribute.

Either way the badge is not written into the page at all; it is not hidden with CSS. The check runs before the labelling is looked up, so a hidden badge costs slightly less to render than a visible one.

= Styling =

Three stable classes, all overridable from the Custom CSS field on the settings page:

* `.nm-ai-badge-wrap` — the wrapper around the image, carries `position: relative`
* `.nm-ai-badge` — the badge itself
* `.nm-ai-badge--generated` / `.nm-ai-badge--assisted` — per label type

The default style uses single-class selectors and no `!important`, so overriding needs no specificity tricks.

= Background images =

Some images are backgrounds rather than content: they are positioned absolutely against their container and stretched to fill it. Wrapping such an image would collapse it, so there the badge goes in as a plain sibling of the image instead. This applies to the cover block's background image and to Etch's `is-bg` utility; the class list is filterable via `nm_ai_badger_background_image_classes`.

Etch's `is-bg` background utility styles the image with a direct-child selector and positions it absolutely inside its figure. For those images the plugin leaves the wrapper out and renders the badge as a sibling of the image instead, so the background keeps working and the badge still appears over it.

= Block editor =

Image and cover blocks carry an "AI labelling" field and a "Hide the badge here" checkbox in the block sidebar, so an image uploaded straight into a post can be labelled without opening the media library. A cover with a video background offers neither, since it renders no image to badge.

The labelling belongs to the image, not to the block: it is saved immediately rather than with the post, and it applies to every page where that image appears. The sidebar states this as a warning, since the rest of the block settings are scoped to the block. The "Hide the badge here" checkbox is the local counterpart — it only affects the block it sits in.

The featured image block gets the checkbox only. It is a template: placed once, rendered for many posts, so a labelling field there would edit whichever post the editor happened to resolve. Deciding that a listing shows no badges is a property of the block and belongs there; labelling the images themselves belongs in the media library, or in the blocks that actually hold an image.

Labelled images also show a badge preview on the editor canvas, drawn as a CSS pseudo-element over the image. It is a preview: it uses the plugin's default badge styling, so custom CSS from the settings does not change how it looks there. Etch's own canvas renders client-side and is not covered — there the badge appears on the front end only.

= Known limitation =

Everywhere else the plugin wraps the `<img>` in a `<span>` to create a positioning context. A stylesheet that targets images with a direct-child selector such as `.card > img` will no longer match. Descendant selectors (`.card img`) are unaffected. Add `nm-ai-badge-nowrap` to such an image to keep the badge but drop the wrapper.

== Filters ==

`nm_ai_badger_supported_blocks` — array of block names the badge is injected into.

`nm_ai_badger_attachment_id` — the resolved attachment ID for a block. Use this to support blocks that store their media reference somewhere unusual.

`nm_ai_badger_default_css` — the plugin's default stylesheet. Return an empty string to ship no default style.

`nm_ai_badger_background_classes` — class names that mark a container as holding a background image. Defaults to `is-bg`.

`nm_ai_badger_etch_is_active` — override the Etch detection. Etch-specific block support, documentation and settings are all conditional on it.

== Data ==

Labels are stored in the attachment meta key `_nm_ai_badge` with the stable values `generated` and `assisted`. The badge texts shown on the front end come from the settings, so changing the wording never requires a data migration.

Uninstalling removes the plugin's settings. The per-image labels are deliberately kept.

== Updates ==

Updates come from the GitHub repository via the Plugin Update Checker library, so installed sites see them in the normal WordPress update UI.

To release a version:

1. Bump the `Version:` header in `nm-wp-ai-badger.php` and the `VERSION` constant right below it. Both must match.
2. Bump `Stable tag` in this file and add a `== Changelog ==` entry — that entry is what sites see behind "View version details".
3. Commit, then create a GitHub release whose tag is the version number (`1.2.3` or `v1.2.3`).

The release may carry a built ZIP as an asset; if it does not, GitHub's generated source archive is used instead. Pre-releases are ignored.

Sites check roughly twice a day. Unauthenticated GitHub API calls are limited to 60 per hour and IP, which matters only if many sites share one host address; a failed check is silently retried later.

= Updating the update checker itself =

The `plugin-update-checker/` directory is a bundled third-party library (currently 5.7) and is required at runtime — it must stay in the repository and in the ZIP. It does not update itself.

To update it: download the latest release from https://github.com/YahnisElsts/plugin-update-checker, replace the whole directory, test, then ship it with the next plugin release. `plugin-update-checker.php` is a stable entry point, so no plugin code changes.

There is little urgency. The library namespaces its classes by version (`v5p7`), because several plugins on one site may each bundle their own copy. On load, every copy registers itself and the highest compatible version wins for all of them. That is why the code calls `v5\PucFactory` rather than the pinned `v5p7\PucFactory` — pinning would opt out of that arbitration. In practice a slightly stale copy is harmless as long as some plugin on the site ships a current one.

If the directory is missing entirely, update checks are skipped silently and the rest of the plugin keeps working.

= Private repository =

For a public repository no credentials are needed. If the repository is private, define a read-only token in each site's `wp-config.php`:

`define( 'NM_AI_BADGER_GITHUB_TOKEN', '…' );`

It is read from there on purpose, so the token never travels inside the plugin ZIP. Filters: `nm_ai_badger_repository_url`, `nm_ai_badger_github_token`. Define `NM_AI_BADGER_DISABLE_UPDATER` as true to switch update checks off entirely.

== Changelog ==

= 0.4.0 =
* Added support for the featured image block. Inside a query loop each entry resolves to its own post's image, not the post being edited.
* The featured image block's sidebar offers only the "Hide the badge here" checkbox. The block is a template — placed once, rendered for many posts — so labelling one image from there would be meaningless. Use it to keep a listing free of badges.

= 0.3.0 =
* Added support for the cover block. The badge sits on the cover's background image; an image block placed inside a cover keeps its own badge, so a cover with both shows two.
* The cover block's sidebar now offers the same "AI labelling" field and "Hide the badge here" checkbox as the image block.
* The sidebar warns that the labelling belongs to the image and applies wherever that image is used, to set it apart from the block-scoped settings around it.

= 0.2.0 =
* Added an "AI labelling" field to the image block sidebar (in the settings tab), so images uploaded straight into a post can be labelled without opening the media library. The value is saved on the image immediately and applies everywhere that image is used.
* Added a "Hide the badge here" checkbox in the same place, for leaving the badge off a single image without having to type a CSS class. It sets the existing `nm-hide-ai-badge` class, so both ways of hiding remain one and the same setting.
* Fixed: editor assets were cached by the browser across plugin updates. The asset version now includes the file's modification time.

= 0.1.0 =
* Initial release.
