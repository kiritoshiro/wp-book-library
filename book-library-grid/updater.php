<?php
/**
 * GitHub Releases updater for Book Library Grid.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'BLG_PLUGIN_VERSION' ) ) {
    define( 'BLG_PLUGIN_VERSION', '2.0.3' );
}

// The release ZIP is about 10 KB; anything far larger is not ours.
define( 'BLG_MAX_PACKAGE_SIZE', 10 * 1024 * 1024 );

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
            'timeout'             => 15,
            'redirection'         => 0,
            'limit_response_size' => 1000000,
            'headers'             => blg_github_headers( $token, 'application/vnd.github+json' ),
        )
    );

    if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
        return false;
    }

    $release = json_decode( wp_remote_retrieve_body( $response ), true );
    return is_array( $release ) ? $release : false;
}

/**
 * Returns the ZIP asset's id, size and SHA-256 hex digest, or false. GitHub
 * computes the "sha256:..." digest of every uploaded release asset; an asset
 * without one is never offered or installed.
 */
function blg_verified_release_asset( $asset, $expected_id = 0 ) {
    if ( ! is_array( $asset ) || 'book-library-grid.zip' !== ( $asset['name'] ?? null ) ) {
        return false;
    }

    $id     = $asset['id'] ?? 0;
    $size   = $asset['size'] ?? 0;
    $digest = $asset['digest'] ?? null;
    if (
        ! is_int( $id ) || $id < 1 || ( $expected_id && $expected_id !== $id ) ||
        ( isset( $asset['state'] ) && 'uploaded' !== $asset['state'] ) ||
        ! is_int( $size ) || $size < 1 || $size > BLG_MAX_PACKAGE_SIZE ||
        ! is_string( $digest ) || ! preg_match( '/^sha256:[a-f0-9]{64}$/D', $digest )
    ) {
        return false;
    }

    return array(
        'id'     => $id,
        'size'   => $size,
        'digest' => substr( $digest, 7 ),
    );
}

/** Reads one release asset's metadata (including its digest) from the GitHub API. */
function blg_github_release_asset( $asset_id, $token ) {
    $response = wp_remote_get(
        'https://api.github.com/repos/kiritoshiro/wp-book-library/releases/assets/' . $asset_id,
        array(
            'timeout'             => 15,
            'redirection'         => 0,
            'limit_response_size' => 100000,
            'headers'             => blg_github_headers( $token, 'application/vnd.github+json' ),
        )
    );

    if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
        return false;
    }

    return blg_verified_release_asset( json_decode( wp_remote_retrieve_body( $response ), true ), $asset_id );
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

    $asset = false;
    foreach ( $release['assets'] as $candidate ) {
        if ( is_array( $candidate ) && 'book-library-grid.zip' === ( $candidate['name'] ?? null ) ) {
            $asset = blg_verified_release_asset( $candidate );
            break;
        }
    }

    // No ZIP, or a ZIP without a SHA-256 digest: offer no update.
    if ( ! $asset ) {
        return $transient;
    }
    $asset_id = $asset['id'];

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

/**
 * GitHub redirects the asset API URL to a short-lived signed URL on one of
 * these hosts. Other githubusercontent.com hosts (for example
 * raw.githubusercontent.com) serve user-controlled content and are refused.
 */
function blg_is_github_release_asset_url( $url ) {
    $parts = is_string( $url ) ? wp_parse_url( $url ) : false;
    return is_array( $parts ) &&
        'https' === strtolower( $parts['scheme'] ?? '' ) &&
        in_array( strtolower( $parts['host'] ?? '' ), array( 'release-assets.githubusercontent.com', 'objects.githubusercontent.com' ), true ) &&
        ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) && ! isset( $parts['port'] );
}

function blg_download_private_release_asset( $reply, $package, $upgrader, $hook_extra ) {
    if ( false !== $reply || ! is_string( $package ) ) {
        return $reply;
    }

    $parts = wp_parse_url( $package );
    if (
        empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) ||
        empty( $parts['host'] ) || 'api.github.com' !== strtolower( $parts['host'] ) ||
        empty( $parts['path'] ) || isset( $parts['query'] ) ||
        ! preg_match( '#^/repos/kiritoshiro/wp-book-library/releases/assets/([1-9][0-9]*)$#D', $parts['path'], $matches )
    ) {
        return $reply;
    }

    $token = blg_github_token();

    // The SHA-256 digest is mandatory: without one the update is refused.
    $asset = blg_github_release_asset( (int) $matches[1], $token );
    if ( ! $asset ) {
        return new WP_Error( 'blg_release_unverified', __( 'The plugin package could not be verified: GitHub did not provide its SHA-256 digest.', 'book-library-grid' ) );
    }

    $temp_file = wp_tempnam( 'book-library-grid.zip' );
    if ( ! $temp_file ) {
        return new WP_Error( 'blg_save_download_failed', __( 'The plugin package could not be saved to a temporary file.', 'book-library-grid' ) );
    }

    $options = array(
        'timeout'             => 300,
        'redirection'         => 0,
        'reject_unsafe_urls'  => true,
        'stream'              => true,
        'filename'            => $temp_file,
        'limit_response_size' => $asset['size'] + 1,
        'headers'             => blg_github_headers( $token, 'application/octet-stream' ),
    );
    $response = wp_remote_get( $package, $options );

    if ( ! is_wp_error( $response ) && in_array( wp_remote_retrieve_response_code( $response ), array( 301, 302, 303, 307, 308 ), true ) ) {
        $download_url = wp_remote_retrieve_header( $response, 'location' );
        if ( ! blg_is_github_release_asset_url( $download_url ) ) {
            wp_delete_file( $temp_file );
            return new WP_Error( 'blg_invalid_download_url', __( 'GitHub returned an unexpected plugin download URL.', 'book-library-grid' ) );
        }

        // The temporary signed asset URL is fetched without the GitHub token.
        unset( $options['headers'] );
        $response = wp_remote_get( $download_url, $options );
    }

    if ( is_wp_error( $response ) ) {
        wp_delete_file( $temp_file );
        return $response;
    }

    $status = wp_remote_retrieve_response_code( $response );
    if ( 200 !== $status ) {
        wp_delete_file( $temp_file );
        return new WP_Error( 'blg_github_download_failed', sprintf( /* translators: %d: HTTP status code */ __( 'GitHub could not provide the plugin package (HTTP %d).', 'book-library-grid' ), absint( $status ) ) );
    }

    clearstatcache( true, $temp_file );
    $digest = hash_file( 'sha256', $temp_file );
    if ( filesize( $temp_file ) !== $asset['size'] || ! is_string( $digest ) || ! hash_equals( $asset['digest'], $digest ) ) {
        wp_delete_file( $temp_file );
        return new WP_Error( 'blg_package_digest_mismatch', __( 'The plugin package does not match its SHA-256 digest from GitHub.', 'book-library-grid' ) );
    }

    return $temp_file;
}
add_filter( 'upgrader_pre_download', 'blg_download_private_release_asset', 10, 4 );
