<?php
/*
 * Standalone checks with small WordPress stubs: the [book_library] shortcode keeps
 * the links of password-protected books out of the page, and the GitHub updater
 * only offers and installs a release ZIP that matches its SHA-256 digest.
 *
 * Run: php tests/run.php
 */
define( 'ABSPATH', __DIR__ );

/* ---- WordPress stubs */
$GLOBALS['hooks']   = array();
$GLOBALS['books']   = array();
$GLOBALS['routes']  = array();
$GLOBALS['http']    = array();
$GLOBALS['current'] = null;
$GLOBALS['temp_files'] = array();
$GLOBALS['json_routes'] = array();

class WP_Error {
    public $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function add_action( $hook, $callback ) { $GLOBALS['hooks'][ $hook ][] = $callback; }
function add_filter( $hook, $callback ) { $GLOBALS['hooks'][ $hook ][] = $callback; }
function add_shortcode( $tag, $callback ) { $GLOBALS['hooks'][ 'shortcode_' . $tag ][] = $callback; }
function apply_filters( $hook, $value ) { return $value; }
function __( $text, $domain ) { return $text; }
function absint( $v ) { return abs( (int) $v ); }
function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $v ) { return preg_match( '#^https?://#i', (string) $v ) ? str_replace( '&', '&#038;', htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) ) : ''; }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function wp_parse_url( $url ) { return parse_url( $url ); }
function plugin_basename( $file ) { return 'book-library-grid/' . basename( $file ); }
function plugin_dir_url( $file ) { return 'https://example.test/wp-content/plugins/book-library-grid/'; }
function wp_kses_post( $v ) { return $v; }
function do_shortcode( $v ) { return $v; }
function wpautop( $v ) { return '<p>' . $v . '</p>'; }
function wp_reset_postdata() { $GLOBALS['current'] = null; }
function wp_tempnam( $name ) { return $GLOBALS['temp_files'][] = tempnam( sys_get_temp_dir(), 'blg' ); }
function wp_delete_file( $file ) { if ( file_exists( $file ) ) { unlink( $file ); } }

/* Books: id => title, content, password lock and meta. */
class WP_Query {
    private $ids;
    public function __construct( $args ) { $this->ids = array_keys( $GLOBALS['books'] ); }
    public function have_posts() { return ! empty( $this->ids ); }
    public function the_post() { $GLOBALS['current'] = array_shift( $this->ids ); }
}
function get_the_ID() { return $GLOBALS['current']; }
function get_the_title() { return $GLOBALS['books'][ $GLOBALS['current'] ]['title']; }
function post_password_required( $post = null ) {
    $id = is_object( $post ) ? $post->ID : ( $post ? $post : $GLOBALS['current'] );
    return ! empty( $GLOBALS['books'][ $id ]['locked'] );
}
function get_the_password_form( $post = 0 ) {
    return '<form action="https://example.test/wp-login.php?action=postpass" class="post-password-form" method="post"><input name="post_password" type="password" /></form>';
}
function get_the_content() {
    // Like core: a protected post's content is replaced by the password form.
    $id = $GLOBALS['current'];
    return post_password_required( $id ) ? get_the_password_form( $id ) : $GLOBALS['books'][ $id ]['content'];
}
function get_post_meta( $id, $key, $single ) { return $GLOBALS['books'][ $id ]['meta'][ $key ] ?? ''; }
function get_the_post_thumbnail_url( $id, $size ) { return ''; }

/*
 * HTTP: $GLOBALS['routes'][ url ] = array( status, body, headers ). Every request
 * is recorded with its arguments; streamed responses are written to the file.
 */
function wp_remote_get( $url, $args = array() ) {
    $GLOBALS['http'][] = array( 'url' => $url, 'args' => $args );
    // The asset API URL answers JSON metadata or a redirect to the file, by Accept header.
    $json  = 'application/vnd.github+json' === ( $args['headers']['Accept'] ?? '' );
    $route = $json && isset( $GLOBALS['json_routes'][ $url ] ) ? $GLOBALS['json_routes'][ $url ] : ( $GLOBALS['routes'][ $url ] ?? null );
    if ( null === $route ) {
        return new WP_Error( 'http_request_failed' );
    }
    list( $status, $body, $headers ) = $route + array( 200, '', array() );
    if ( ! empty( $args['stream'] ) ) {
        file_put_contents( $args['filename'], $body );
    }
    return array( 'response' => array( 'code' => $status ), 'body' => $body, 'headers' => $headers );
}
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? $r['response']['code'] : ''; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? $r['body'] : ''; }
function wp_remote_retrieve_header( $r, $name ) { return is_array( $r ) && isset( $r['headers'][ $name ] ) ? $r['headers'][ $name ] : ''; }
/* download_url follows redirects through the routes (used by older updater code). */
function download_url( $url, $timeout = 300 ) {
    for ( $i = 0; $i < 5; $i++ ) {
        $response = wp_remote_get( $url );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        if ( 200 === $response['response']['code'] ) {
            $file = $GLOBALS['temp_files'][] = tempnam( sys_get_temp_dir(), 'blg' );
            file_put_contents( $file, $response['body'] );
            return $file;
        }
        $url = wp_remote_retrieve_header( $response, 'location' );
    }
    return new WP_Error( 'http_request_failed' );
}

$failures = 0;
$checks   = 0;
function check( $condition, $message ) {
    global $failures, $checks;
    ++$checks;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: $message\n" );
        ++$failures;
    }
}

require __DIR__ . '/../book-library-grid/book-library-grid.php';

/* ---- shortcode: password-protected books */
$GLOBALS['books'] = array(
    11 => array(
        'title'   => 'Open book',
        'content' => 'Open description',
        'meta'    => array(
            '_blg_main_link' => 'https://example.test/open-main.pdf',
            '_blg_pdfs'      => array( array( 'url' => 'https://example.test/open.pdf', 'post_url' => '', 'image' => '' ) ),
            '_blg_audios'    => array( array( 'url' => 'https://example.test/open.mp3', 'post_url' => '', 'label' => 'Part 1' ) ),
        ),
    ),
    12 => array(
        'title'   => 'Locked book',
        'content' => 'Secret description',
        'locked'  => true,
        'meta'    => array(
            '_blg_main_link'    => 'https://example.test/secret-main.pdf',
            '_blg_custom_cover' => 'https://example.test/locked-cover.jpg',
            '_blg_pdfs'         => array(
                array( 'url' => 'https://example.test/secret.pdf', 'post_url' => '', 'image' => 'https://example.test/secret-icon.png' ),
                array( 'url' => '', 'post_url' => 'https://example.test/secret-pdf-post/', 'image' => '' ),
            ),
            '_blg_audios'       => array(
                array( 'url' => 'https://example.test/secret.mp3', 'post_url' => '', 'label' => 'Secret part' ),
                array( 'url' => '', 'post_url' => 'https://example.test/secret-audio-post/', 'label' => '' ),
            ),
        ),
    ),
);
$html = blg_shortcode_display( array() );
foreach ( array( 'open-main.pdf', 'open.pdf', 'open.mp3', 'Open description' ) as $needle ) {
    check( false !== strpos( $html, $needle ), "open book shows $needle" );
}
foreach ( array( 'secret-main.pdf', 'secret.pdf', 'secret-icon.png', 'secret-pdf-post', 'secret.mp3', 'secret-audio-post', 'Secret part', 'Secret description' ) as $needle ) {
    check( false === strpos( $html, $needle ), "locked book hides $needle" );
}
check( false !== strpos( $html, 'Locked book' ) && false !== strpos( $html, 'locked-cover.jpg' ), 'locked book keeps its title and cover in the grid' );
check( 1 === substr_count( $html, 'name="post_password"' ), 'locked book popup shows the password form' );
$GLOBALS['books'][12]['locked'] = false;
$html = blg_shortcode_display( array() );
check( false !== strpos( $html, 'secret-main.pdf' ) && false !== strpos( $html, 'secret.mp3' ) && false !== strpos( $html, 'secret-pdf-post' ), 'unlocked book (password entered) shows its links' );
check( false === strpos( $html, 'name="post_password"' ), 'unlocked book shows no password form' );

/* ---- updater: release check */
$api_release = 'https://api.github.com/repos/kiritoshiro/wp-book-library/releases/latest';
$api_asset   = 'https://api.github.com/repos/kiritoshiro/wp-book-library/releases/assets/42';
$cdn_url     = 'https://release-assets.githubusercontent.com/github-production-release-asset/1/42?sig=x';
$zip         = "PK\x03\x04 book-library-grid release package";
$asset       = array(
    'id'     => 42,
    'name'   => 'book-library-grid.zip',
    'state'  => 'uploaded',
    'size'   => strlen( $zip ),
    'digest' => 'sha256:' . hash( 'sha256', $zip ),
    'url'    => $api_asset,
);
function blg_test_check( $asset ) {
    global $api_release;
    $GLOBALS['routes'] = array( $api_release => array( 200, json_encode( array( 'tag_name' => 'v99.0.0', 'assets' => array( $asset ) ) ) ) );
    $transient = (object) array( 'checked' => array( 'book-library-grid/book-library-grid.php' => BLG_PLUGIN_VERSION ), 'response' => array() );
    $result    = blg_check_for_plugin_update( $transient );
    return $result->response['book-library-grid/book-library-grid.php'] ?? null;
}
$offer = blg_test_check( $asset );
check( $offer && '99.0.0' === $offer->new_version && $api_asset === $offer->package, 'release with a SHA-256 digest is offered' );
$no_digest = $asset;
unset( $no_digest['digest'] );
check( null === blg_test_check( $no_digest ), 'release ZIP without a digest is not offered' );
$bad_digest = $asset;
$bad_digest['digest'] = 'sha1:' . sha1( $zip );
check( null === blg_test_check( $bad_digest ), 'release ZIP with a non-SHA-256 digest is not offered' );
$bad_digest['digest'] = 'sha256:' . strtoupper( hash( 'sha256', $zip ) ) . 'x';
check( null === blg_test_check( $bad_digest ), 'release ZIP with a malformed digest is not offered' );

/* ---- updater: download */
function blg_test_download( $asset_meta, $cdn_status, $cdn_body, $location = null, $package = null ) {
    global $api_asset, $cdn_url;
    $GLOBALS['http']        = array();
    $GLOBALS['json_routes'] = array( $api_asset => null === $asset_meta ? array( 404, '{}' ) : array( 200, json_encode( $asset_meta ) ) );
    $GLOBALS['routes']      = array(
        $api_asset => array( 302, '', array( 'location' => null === $location ? $cdn_url : $location ) ),
        $cdn_url   => array( $cdn_status, $cdn_body ),
        'https://raw.githubusercontent.com/attacker/repo/main/book-library-grid.zip' => array( 200, $cdn_body ),
        'https://evil.test/book-library-grid.zip' => array( 200, $cdn_body ),
    );
    return blg_download_private_release_asset( false, null === $package ? $api_asset : $package, null, array() );
}

$file = blg_test_download( $asset, 200, $zip );
check( is_string( $file ) && is_file( $file ) && $zip === file_get_contents( $file ), 'package matching its digest is installed' );
$cdn_requests = array_values( array_filter( $GLOBALS['http'], function ( $r ) use ( $cdn_url ) { return $cdn_url === $r['url']; } ) );
check( $cdn_requests && empty( $cdn_requests[0]['args']['headers']['Authorization'] ), 'signed CDN download is fetched without the GitHub token' );
if ( is_string( $file ) && is_file( $file ) ) {
    unlink( $file );
}

$GLOBALS['temp_files'] = array();
$result = blg_test_download( $asset, 200, $zip . ' tampered' );
check( $result instanceof WP_Error, 'package that does not match its digest is refused' );
check( $GLOBALS['temp_files'] && ! array_filter( $GLOBALS['temp_files'], 'file_exists' ), 'refused package leaves no temporary file' );

$same_size = substr( $zip, 0, -1 ) . 'X';
check( blg_test_download( $asset, 200, $same_size ) instanceof WP_Error, 'same-size tampered package is refused' );

$no_digest = $asset;
unset( $no_digest['digest'] );
check( blg_test_download( $no_digest, 200, $zip ) instanceof WP_Error, 'download is refused when GitHub gives no digest' );
check( blg_test_download( null, 200, $zip ) instanceof WP_Error, 'download is refused when the asset metadata is unavailable' );

check( blg_test_download( $asset, 200, $zip, 'https://raw.githubusercontent.com/attacker/repo/main/book-library-grid.zip' ) instanceof WP_Error, 'redirect to raw.githubusercontent.com is refused' );
check( blg_test_download( $asset, 200, $zip, 'http://release-assets.githubusercontent.com/x' ) instanceof WP_Error, 'redirect over plain HTTP is refused' );
check( blg_test_download( $asset, 200, $zip, 'https://evil.test/book-library-grid.zip' ) instanceof WP_Error, 'redirect to a foreign host is refused' );
check( false === blg_test_download( $asset, 200, $zip, null, 'https://example.test/book-library-grid.zip' ), 'packages of other plugins are left to WordPress' );

foreach ( $GLOBALS['temp_files'] as $temp ) {
    if ( file_exists( $temp ) ) {
        unlink( $temp );
    }
}

if ( $failures ) {
    fwrite( STDERR, "$failures of $checks check(s) failed\n" );
    exit( 1 );
}
echo "All $checks checks passed.\n";
