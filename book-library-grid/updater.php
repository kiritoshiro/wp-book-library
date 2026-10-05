<?php
/**
 * GitHub Releases updater for Book Library Grid.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'BLG_PLUGIN_VERSION' ) ) {
    define( 'BLG_PLUGIN_VERSION', '2.0.1' );
}

function blg_github_token() {
    $token = defined( 'BOOK_LIBRARY_GRID_GITHUB_TOKEN' ) ? BOOK_LIBRARY_GRID_GITHUB_TOKEN : '';
    $token = apply_filters( 'blg_github_token', $token );
    return is_string( $token ) ? trim( $token ) : '';
}

/**
 * GitHub API headers. The repository is public, so the token is optional: it
 * only raises the API rate limit (or restores access if the repository goes
 * private) and is sent to api.github.com only.
 */
function blg_github_headers( $token, $accept ) {
    $headers = array(
        'Accept'               => $accept,
        'X-GitHub-Api-Version' => '2022-11-28',
        'User-Agent'           => 'book-library-grid/' . BLG_PLUGIN_VERSION,
    );
    if ( '' !== $token ) {
        $headers['Authorization'] = 'Bearer ' . $token;
    }
    return $headers;
}

function blg_latest_github_release( $token ) {
    $response = wp_remote_get(
        'https://api.github.com/repos/kiritoshiro/wp-book-library/releases/latest',
        array(
            'timeout' => 15,
            'headers' => blg_github_headers( $token, 'application/vnd.github+json' ),
        )
    );

    if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
        return false;
    }

    $release = json_decode( wp_remote_retrieve_body( $response ), true );
    return is_array( $release ) ? $release : false;
}

function blg_check_for_plugin_update( $transient ) {
    if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
        return $transient;
    }

    $release = blg_latest_github_release( blg_github_token() );
    if ( ! $release || empty( $release['tag_name'] ) || empty( $release['assets'] ) || ! is_array( $release['assets'] ) ) {
        return $transient;
    }

    $version = preg_replace( '/^v/i', '', sanitize_text_field( $release['tag_name'] ) );
    if ( ! preg_match( '/^\d+(?:\.\d+){1,3}(?:-[0-9A-Za-z.-]+)?$/', $version ) || version_compare( $version, BLG_PLUGIN_VERSION, '<=' ) ) {
        return $transient;
    }

    $asset_id = 0;
    foreach ( $release['assets'] as $asset ) {
        if ( isset( $asset['name'], $asset['id'] ) && 'book-library-grid.zip' === $asset['name'] ) {
            $asset_id = absint( $asset['id'] );
            break;
        }
    }

    if ( ! $asset_id ) {
        return $transient;
    }

    $plugin_file = plugin_basename( dirname( __FILE__ ) . '/book-library-grid.php' );
    $transient->response[ $plugin_file ] = (object) array(
        'slug'        => 'book-library-grid',
        'plugin'      => $plugin_file,
        'new_version' => $version,
        'url'         => 'https://github.com/kiritoshiro/wp-book-library',
        'package'     => 'https://api.github.com/repos/kiritoshiro/wp-book-library/releases/assets/' . $asset_id,
    );

    return $transient;
}
add_filter( 'pre_set_site_transient_update_plugins', 'blg_check_for_plugin_update' );

function blg_is_github_release_asset_url( $url ) {
    $parts = wp_parse_url( $url );
    if ( empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) || empty( $parts['host'] ) ) {
        return false;
    }

    $host = strtolower( $parts['host'] );
    return 'githubusercontent.com' === $host || (bool) preg_match( '/\.githubusercontent\.com$/', $host );
}

function blg_download_private_release_asset( $reply, $package, $upgrader, $hook_extra ) {
    if ( false !== $reply || ! is_string( $package ) ) {
        return $reply;
    }

    $parts = wp_parse_url( $package );
    if (
        empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) ||
        empty( $parts['host'] ) || 'api.github.com' !== strtolower( $parts['host'] ) ||
        empty( $parts['path'] ) ||
        ! preg_match( '#^/repos/kiritoshiro/wp-book-library/releases/assets/[1-9][0-9]*$#', $parts['path'] )
    ) {
        return $reply;
    }

    $token = blg_github_token();

    $response = wp_remote_get(
        $package,
        array(
            'timeout'     => 30,
            'redirection' => 0,
            'headers'     => blg_github_headers( $token, 'application/octet-stream' ),
        )
    );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $status = wp_remote_retrieve_response_code( $response );
    if ( in_array( $status, array( 301, 302, 303, 307, 308 ), true ) ) {
        $download_url = wp_remote_retrieve_header( $response, 'location' );
        if ( ! $download_url || ! blg_is_github_release_asset_url( $download_url ) ) {
            return new WP_Error( 'blg_invalid_download_url', __( 'GitHub returned an unexpected plugin download URL.', 'book-library-grid' ) );
        }

        // The temporary signed asset URL is fetched without the GitHub token.
        return download_url( $download_url, 300 );
    }

    if ( 200 === $status ) {
        $body = wp_remote_retrieve_body( $response );
        if ( '' === $body ) {
            return new WP_Error( 'blg_empty_download', __( 'GitHub returned an empty plugin package.', 'book-library-grid' ) );
        }

        $temp_file = wp_tempnam( 'book-library-grid.zip' );
        if ( ! $temp_file || false === file_put_contents( $temp_file, $body ) ) {
            if ( $temp_file && file_exists( $temp_file ) ) {
                unlink( $temp_file );
            }
            return new WP_Error( 'blg_save_download_failed', __( 'The plugin package could not be saved to a temporary file.', 'book-library-grid' ) );
        }

        return $temp_file;
    }

    return new WP_Error( 'blg_github_download_failed', sprintf( __( 'GitHub could not provide the plugin package (HTTP %d).', 'book-library-grid' ), absint( $status ) ) );
}
add_filter( 'upgrader_pre_download', 'blg_download_private_release_asset', 10, 4 );
