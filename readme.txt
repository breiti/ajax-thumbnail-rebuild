=== AJAX Thumbnail Rebuild ===
Contributors: junkcoder, RistoNiinemets
Donate link: http://breiti.cc/wordpress/ajax-thumbnail-rebuild/#donate
Tags: ajax, thumbnail, rebuild, regenerate, admin, image, photo
Requires at least: 5.6
Requires PHP: 7.4
Tested up to: 7.0
Stable tag: 2.5.0

[AJAX Thumbnail Rebuild](https://wordpress.org/plugins/ajax-thumbnail-rebuild/) allows you to rebuild all thumbnails at once without script timeouts on your server.

== Description ==

AJAX Thumbnail Rebuild allows you to rebuild all thumbnails on your site. There are already some plugins available for this, but they have one thing in common: All thumbnails are rebuilt in a single step. This works fine when you don’t have that many photos on your site. When you have a lot of full-size photos, the script on the server side takes a long time to run. Unfortunately the time a script is allowed to run is limited, which sets an upper limit to the number of thumbnails you can regenerate. This number depends on the server configuration and the computing power your server has available. When you get over this limit, you won’t be able to rebuild your thumbnails.

Why would you want to rebuild your thumbnails? Wordpress allows you to change the size of thumbnails. This way, you can make the size of thumbnails fit the design of your website. When you change the size to fit for a new theme, all future photos you are going to upload will have this new size. Your old thumbnails won’t be resized. That’s where this plugin comes into action. After changing the image sizes, you can rebuild all thumbnails. But instead of telling the server to recreate all thumbnails at once, they are rebuilt one after another. Rebuilding thumbnails for one photo won’t take all too long, so you won’t run into any script timeouts. Note that you still have to wait until all thumbnails have been rebuilt. If you close the page before the task is completed, you have to start all over again.

You can also select the thumbnail sizes you want to rebuild, so that you don't need to recreate all images if you've just changed one thumbnail-size. You can also choose to only rebuild post thumbnails (featured images).

This plugin requires JavaScript to be enabled.


Contributions are welcome at [Github](https://github.com/breiti/ajax-thumbnail-rebuild)

== Installation ==

Upload the plugin to your blog, activate it, done. You can then rebuild all thumbnails in the tools section (Tools -> Rebuild Thumbnails).

== Changelog ==

= 2.5.0 =

* NEW: Single sizes can be marked as on demand. A marked size is never written on upload and is skipped by a rebuild; it is cut the first time a page asks for it. Useful for the big sizes only a handful of pages use, and it works whether or not on demand is on for everything.
* NEW: "Only rebuild featured images" also covers the images in a WooCommerce product gallery. A product's main image is its featured image, so that was already included.

= 2.4.0 =

* NEW: Rebuild straight from the media library: a "Rebuild thumbnails" row action for one image, and a bulk action that hands the selection to the plugin's screen, where it runs one image at a time with a progress bar.
* FIX: WebP copies and the image proxy are independent of each other; either can be on without the other.

= 2.3.0 =

* NEW: Optional on demand sizes. An upload keeps only its own file, and each registered size is cut the first time something asks for it. Off by default; rebuilding still generates whatever you select on the Rebuild tab.

= 2.2.0 =

* NEW: Optional WebP copies. Every image WordPress generates gets a WebP copy written next to it, and the front end serves it through a picture element with the original as the fallback - which works behind a page cache. Off by default; a copy that would be larger than the original is discarded, and the copies are deleted with the attachment.

= 2.1.0 =

* NEW: Optional image proxy. Front end image URLs can be served through wsrv.nl, which resizes and re-encodes on the fly (WebP by default) and serves the result from a CDN. Off by default; your own files are never touched.
* NEW: A "Settings" tab, built with the WordPress Settings API.

= 2.0.0 =

* NEW: The screen has been rebuilt out of the components WordPress itself uses - tabs, list tables, postboxes and notices - with a progress bar, a running preview and a Stop button.
* NEW: A "Registered sizes" tab listing every image size on the site with its dimensions and crop setting.
* NEW: Rebuild only the images whose file name matches a pattern, e.g. `banner-*.jpg`.
* NEW: The browser now talks to the REST API instead of admin-ajax.
* FIX: Rebuilding no longer throws away the rest of the attachment metadata. `original_image` and `filesize` survive, so WordPress can still find and delete the untouched upload (props @toolshedlabs-hash).
* FIX: Sub sizes are cut from the original image rather than from the "-scaled" copy, which used to leave a duplicate set of files behind.
* FIX: Selecting a subset of sizes rebuilt every size anyway.
* FIX: Sizes registered through the `intermediate_image_sizes_advanced` filter are picked up; the filter now receives the metadata and attachment id core passes it.
* FIX: "Toggle all" checks or clears every size instead of inverting each one.
* FIX: The button on a single attachment works when several attachments are on screen; it no longer prints a copy of the whole script per image.
* FIX: An image whose file is missing or unreadable is skipped and listed, instead of stalling the run.
* PHP 8.4 compatible; undefined variables, unchecked `getimagesize()` results and unescaped output cleaned up throughout.

= 1.14 =
* Fix security issues (props @patchstack)
* Fix Github link (props @Julix91 @garretthyder)
* Fix crop-settings for additional sizes (props @karlkowald)

= 1.2.2 =

* Compatibility with PHP 7.2 (props @thomas-gordon)
* Implemented throttling and retries for image regeneration (props @da2x)

= 1.2.1 =

* NEW: Allow custom crop areas, [read more](https://developer.wordpress.org/reference/functions/add_image_size/#parameters)

= 1.2 =

* Compatibility with PHP7

= 1.12 =

* FIX: An issue where rebuilding thumbnails in the media gallery
       would not work

= 1.11 =

* FIX: An issue where the plugin would sometimes break the media gallery.

= 1.10 =

* NEW: Rebuild thumbnails of single images on the media attachment page.

= 1.09 =

* NEW: Checkboxes can be activated by clicking on text.

= 1.08 =

* NEW: Slovak translation, provided by Branco Radenovich.

= 1.07 =

* FIX: Don't create metadata with empty size when original image is smaller
       than the target size.

= 1.06 =

* FIX: Don't forget metadata for sizes that aren't rebuilt.
* FIX: Option to only rebuild featured images should now work correctly.
* FIX: Don't fail if there are no attachments.
* NEW: It's now possible to toggle all selected sizes.
* NEW: Added translation: German.

= 1.05 =

* Add option to only rebuild post thumbnails (featured images)

= 1.04 =

* Tested with Wordpress 3.2

= 1.03 =

* Fixed: Show correct height value for thumbnails.

= 1.02 =

* You can now select which thumbnail sizes you want to rebuild. Thanks to Nicolas Juen!

= 1.01 =

* Tested with Wordpress 3.0

= 1.0 =

* Initial release

== Screenshots ==

1. Plugin in action
