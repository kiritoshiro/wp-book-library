# Book Library Grid (Advanced)

WordPress plugin for a responsive book grid with book covers, popup descriptions, multiple audio tracks, and PDF attachments. Use the shortcode [book_library] to display the library.

## Install

Upload the book-library-grid.zip release asset in Plugins → Add New → Upload Plugin, then activate Book Library Grid (Advanced). Add [book_library] to a page or post.

## Enable updates from this private repository

The updater reads the latest published GitHub Release. Because this repository is private, each WordPress site needs a fine-grained GitHub token limited to this repository with Contents: Read-only permission.

Add this to wp-config.php above the “That's all, stop editing” line:

    define( 'BOOK_LIBRARY_GRID_GITHUB_TOKEN', 'github_pat_REPLACE_WITH_READ_ONLY_TOKEN' );

Keep the real token on the WordPress server. Do not commit it to this repository, add it to plugin source, or save it in a WordPress option.

Version 2.0 did not include the updater. Install version 2.0.1 once manually; later published releases will appear under Dashboard → Updates after the token is configured.

## Publish a release

Keep the plugin header version, BLG_PLUGIN_VERSION in updater.php, and Stable tag in readme.txt in sync. Push a matching tag such as v2.0.2, or run the Build plugin release workflow manually with that tag. The workflow builds book-library-grid.zip and publishes it to GitHub Releases.
