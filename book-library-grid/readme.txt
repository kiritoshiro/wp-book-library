=== Book Library Grid (Advanced) ===
Contributors: custom
Tags: books, library, grid, audio, pdf
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 2.0.1
License: GPLv2 or later

Books library with cover selection, multiple audio tracks, multiple PDFs, and a responsive popup grid.

== Description ==

Book Library Grid (Advanced) registers a Books post type and displays books in a responsive grid. Each book can have a cover, description, main link, multiple audio tracks, and multiple PDF attachments.

Add the shortcode [book_library] to a page or post.

== Installation ==

1. Upload and activate the plugin.
2. Add [book_library] to a page or post.
3. Add books and their attachments in the Books menu.

== Private GitHub updates ==

The updater reads releases from the private kiritoshiro/wp-book-library repository. Create a fine-grained GitHub personal access token scoped to this repository with Contents: Read-only permission. In wp-config.php, above the “That's all, stop editing” line, define:

    define( 'BOOK_LIBRARY_GRID_GITHUB_TOKEN', 'github_pat_REPLACE_WITH_READ_ONLY_TOKEN' );

Keep the real token on the WordPress server. Do not commit it to the repository or put it in plugin source or a WordPress option.

The original 2.0 release does not include the updater. Install 2.0.1 manually first. Later releases are offered in Dashboard → Updates.

== Changelog ==

= 2.0.1 =
* Add private GitHub Releases updates and token setup instructions.

= 2.0 =
* Initial supplied plugin version.
