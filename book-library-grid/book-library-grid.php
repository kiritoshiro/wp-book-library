<?php
/*
Plugin Name: Book Library Grid (Advanced)
Description: Books library with cover selection, multiple audio tracks, multiple PDFs, and responsive popup grid.
Version: 2.0.1
Requires at least: 5.8
Requires PHP: 7.4
Update URI: https://github.com/kiritoshiro/wp-book-library
Author: Custom
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/updater.php';

/**
 * 1. Register Custom Post Type "Books"
 */
function blg_register_book_post_type() {
    $labels = array(
        'name'               => 'Books',
        'singular_name'      => 'Book',
        'menu_name'          => 'Books',
        'add_new'            => 'Add New Book',
        'add_new_item'       => 'Add New Book',
        'edit_item'          => 'Edit Book',
    );

    $args = array(
        'labels'              => $labels,
        'public'              => false,
        'show_ui'             => true,
        'show_in_menu'        => true,
        'menu_icon'           => 'dashicons-book',
        'supports'            => array( 'title', 'editor', 'thumbnail' ), // 'thumbnail' is the standard Featured Image
        'has_archive'         => false,
    );

    register_post_type( 'blg_book', $args );
}
add_action( 'init', 'blg_register_book_post_type' );

/**
 * 2. Enqueue Admin Scripts (Media Uploader)
 */
function blg_admin_scripts( $hook ) {
    global $post;
    if ( $hook == 'post-new.php' || $hook == 'post.php' ) {
        if ( 'blg_book' === $post->post_type ) {
            wp_enqueue_media();
            // Enqueue local script for repeaters
            add_action('admin_footer', 'blg_print_admin_js');
        }
    }
}
add_action( 'admin_enqueue_scripts', 'blg_admin_scripts' );

/**
 * 3. Add Custom Meta Boxes
 */
function blg_add_meta_boxes() {
    add_meta_box( 'blg_book_details', 'Book Options & Attachments', 'blg_render_meta_box', 'blg_book', 'normal', 'high' );
}
add_action( 'add_meta_boxes', 'blg_add_meta_boxes' );

function blg_render_meta_box( $post ) {
    wp_nonce_field( 'blg_save_book_data', 'blg_book_nonce' );

    // Retrieve data
    $main_link = get_post_meta( $post->ID, '_blg_main_link', true );
    $custom_cover = get_post_meta( $post->ID, '_blg_custom_cover', true );

    $audios = get_post_meta( $post->ID, '_blg_audios', true );
    if ( ! is_array( $audios ) ) $audios = array();

    $pdfs = get_post_meta( $post->ID, '_blg_pdfs', true );
    if ( ! is_array( $pdfs ) ) $pdfs = array();
    ?>

    <style>
        .blg-section { margin-bottom: 20px; padding: 15px; border: 1px solid #ccc; background: #fff; }
        .blg-section h3 { margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 10px; }
        .blg-row { margin-bottom: 10px; }
        .blg-input { width: 100%; max-width: 400px; }

        .repeater-item { background: #f1f1f1; padding: 15px; border: 1px solid #ddd; margin-bottom: 10px; border-radius: 4px; position: relative; }
        .repeater-item h4 { margin: 0 0 10px 0; font-size: 14px; text-transform: uppercase; color: #666; }
        .remove-row { position: absolute; top: 10px; right: 10px; color: #a00; text-decoration: none; cursor: pointer; }

        .preview-img { max-width: 60px; max-height: 60px; display: block; margin-top: 5px; border: 1px solid #ddd; }
        .btn-width { width: auto; }
        .field-group { margin-bottom: 8px; }
        .field-group label { display: block; font-weight: 600; font-size: 12px; margin-bottom: 3px;}
    </style>

    <!-- General Settings -->
    <div class="blg-section">
        <h3>General Settings</h3>

        <div class="blg-row">
            <label><strong>Main Book Link (Where the "Read Book" button goes):</strong></label><br>
            <input type="text" name="blg_main_link" class="blg-input file-url-input" value="<?php echo esc_attr( $main_link ); ?>">
            <button type="button" class="button upload-file-btn">Select Link/File</button>
        </div>

        <!-- Optional Custom Cover if they don't want to use Featured Image -->
        <div class="blg-row">
            <label><strong>Custom Cover Image (Optional override):</strong></label><br>
            <input type="text" name="blg_custom_cover" class="blg-input image-url-input" value="<?php echo esc_attr( $custom_cover ); ?>">
            <button type="button" class="button upload-image-btn">Select Image</button>
            <?php if($custom_cover): ?><img src="<?php echo esc_url($custom_cover); ?>" class="preview-img"><?php endif; ?>
        </div>
    </div>

    <!-- Audio Repeater -->
    <div class="blg-section">
        <h3>Audio Tracks</h3>
        <div id="audio-container">
            <?php foreach ( $audios as $index => $audio ) :
                blg_render_audio_row( $index, $audio );
            endforeach; ?>
        </div>
        <button type="button" class="button button-primary" id="add-audio-row">+ Add Audio Track</button>
    </div>

    <!-- PDF Repeater -->
    <div class="blg-section">
        <h3>PDF Books / Attachments</h3>
        <div id="pdf-container">
            <?php foreach ( $pdfs as $index => $pdf ) :
                blg_render_pdf_row( $index, $pdf );
            endforeach; ?>
        </div>
        <button type="button" class="button button-primary" id="add-pdf-row">+ Add PDF</button>
    </div>

    <!-- Templates for JS -->
    <script type="text/template" id="tmpl-audio-row">
        <?php blg_render_audio_row( 'INDEX', array() ); ?>
    </script>
    <script type="text/template" id="tmpl-pdf-row">
        <?php blg_render_pdf_row( 'INDEX', array() ); ?>
    </script>
    <?php
}

/* Helper to render Audio Row */
function blg_render_audio_row( $index, $data ) {
    $url      = isset($data['url']) ? $data['url'] : '';
    $post_url = isset($data['post_url']) ? $data['post_url'] : '';
    $label    = isset($data['label']) ? $data['label'] : '';
    ?>
    <div class="repeater-item">
        <h4>Audio Track</h4>
        <a href="#" class="remove-row">Remove</a>

        <div class="field-group">
            <label>Track Label (e.g., "Part 1"):</label>
            <input type="text" name="blg_audios[<?php echo esc_attr( $index ); ?>][label]" class="widefat" value="<?php echo esc_attr($label); ?>">
        </div>

        <div class="field-group">
            <label>Audio File URL:</label>
            <input type="text" name="blg_audios[<?php echo esc_attr( $index ); ?>][url]" class="widefat file-url-input" value="<?php echo esc_attr($url); ?>" style="width:75%">
            <button type="button" class="button upload-audio-btn">Select Audio</button>
        </div>

        <div class="field-group">
            <label>Link to Post (Redirects user when clicked):</label>
            <input type="text" name="blg_audios[<?php echo esc_attr( $index ); ?>][post_url]" class="widefat" value="<?php echo esc_attr($post_url); ?>">
        </div>
    </div>
    <?php
}

/* Helper to render PDF Row */
function blg_render_pdf_row( $index, $data ) {
    $url      = isset($data['url']) ? $data['url'] : '';
    $image    = isset($data['image']) ? $data['image'] : '';
    $post_url = isset($data['post_url']) ? $data['post_url'] : '';
    ?>
    <div class="repeater-item">
        <h4>PDF Item</h4>
        <a href="#" class="remove-row">Remove</a>

        <div class="field-group">
            <label>PDF File URL (Download Link):</label>
            <input type="text" name="blg_pdfs[<?php echo esc_attr( $index ); ?>][url]" class="widefat file-url-input" value="<?php echo esc_attr($url); ?>" style="width:75%">
            <button type="button" class="button upload-file-btn">Select PDF</button>
        </div>

        <div class="field-group">
            <label>Link to Post (Where clicking the image takes you):</label>
            <input type="text" name="blg_pdfs[<?php echo esc_attr( $index ); ?>][post_url]" class="widefat" value="<?php echo esc_attr($post_url); ?>">
        </div>

        <div class="field-group">
            <label>Custom Image/Icon:</label>
            <input type="text" name="blg_pdfs[<?php echo esc_attr( $index ); ?>][image]" class="widefat image-url-input" value="<?php echo esc_attr($image); ?>" style="width:75%">
            <button type="button" class="button upload-image-btn">Select Image</button>
            <img src="<?php echo esc_url($image); ?>" class="preview-img" style="<?php echo $image ? '' : 'display:none;'; ?>">
        </div>
    </div>
    <?php
}

/**
 * 4. JS for Media Uploader & Repeaters
 */
function blg_print_admin_js() {
    ?>
    <script>
    jQuery(document).ready(function($) {

        // --- Repeater Logic ---
        var audioCount = 1000;
        var pdfCount   = 1000;

        $('#add-audio-row').click(function() {
            var tmpl = $('#tmpl-audio-row').html().replace(/INDEX/g, audioCount++);
            $('#audio-container').append(tmpl);
        });

        $('#add-pdf-row').click(function() {
            var tmpl = $('#tmpl-pdf-row').html().replace(/INDEX/g, pdfCount++);
            $('#pdf-container').append(tmpl);
        });

        $(document).on('click', '.remove-row', function(e) {
            e.preventDefault();
            $(this).closest('.repeater-item').remove();
        });

        // --- Media Uploader Logic ---

        // 1. Generic File/Link Uploader (PDFs, Main Link)
        $(document).on('click', '.upload-file-btn', function(e) {
            e.preventDefault();
            var btn = $(this);
            var input = btn.prev('.file-url-input');

            var frame = wp.media({
                title: 'Select File',
                multiple: false
            });

            frame.on('select', function() {
                var attachment = frame.state().get('selection').first().toJSON();
                input.val(attachment.url);
            });
            frame.open();
        });

        // 2. Audio Uploader
        $(document).on('click', '.upload-audio-btn', function(e) {
            e.preventDefault();
            var btn = $(this);
            var input = btn.prev('.file-url-input');

            var frame = wp.media({
                title: 'Select Audio File',
                library: { type: 'audio' },
                multiple: false
            });

            frame.on('select', function() {
                var attachment = frame.state().get('selection').first().toJSON();
                input.val(attachment.url);
            });
            frame.open();
        });

        // 3. Image Uploader
        $(document).on('click', '.upload-image-btn', function(e) {
            e.preventDefault();
            var btn = $(this);
            var input = btn.prev('.image-url-input');
            var imgPreview = btn.parent().find('.preview-img');

            var frame = wp.media({
                title: 'Select Image',
                library: { type: 'image' },
                multiple: false
            });

            frame.on('select', function() {
                var attachment = frame.state().get('selection').first().toJSON();
                input.val(attachment.url);
                imgPreview.attr('src', attachment.url).show();
            });
            frame.open();
        });

    });
    </script>
    <?php
}


/**
 * 5. Save Data
 */
function blg_save_book_data( $post_id ) {
    if ( ! isset( $_POST['blg_book_nonce'] ) || ! is_string( $_POST['blg_book_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['blg_book_nonce'] ) ), 'blg_save_book_data' ) ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    // Simple fields
    if ( isset( $_POST['blg_main_link'] ) && is_string( $_POST['blg_main_link'] ) ) update_post_meta( $post_id, '_blg_main_link', esc_url_raw( wp_unslash( $_POST['blg_main_link'] ) ) );
    if ( isset( $_POST['blg_custom_cover'] ) && is_string( $_POST['blg_custom_cover'] ) ) update_post_meta( $post_id, '_blg_custom_cover', esc_url_raw( wp_unslash( $_POST['blg_custom_cover'] ) ) );

    // Save Audios
    if ( isset( $_POST['blg_audios'] ) && is_array( $_POST['blg_audios'] ) ) {
        $clean_audios = array();
        // Each row is type-checked and its fields sanitized below.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        foreach ( wp_unslash( $_POST['blg_audios'] ) as $item ) {
            if ( ! is_array( $item ) ) continue;
            if ( ! empty( $item['url'] ) || ! empty( $item['post_url'] ) ) {
                $clean_audios[] = array(
                    'label'    => sanitize_text_field( is_string( $item['label'] ?? null ) ? $item['label'] : '' ),
                    'url'      => esc_url_raw( is_string( $item['url'] ?? null ) ? $item['url'] : '' ),
                    'post_url' => esc_url_raw( is_string( $item['post_url'] ?? null ) ? $item['post_url'] : '' ),
                );
            }
        }
        update_post_meta( $post_id, '_blg_audios', $clean_audios );
    } else {
        delete_post_meta( $post_id, '_blg_audios' );
    }

    // Save PDFs
    if ( isset( $_POST['blg_pdfs'] ) && is_array( $_POST['blg_pdfs'] ) ) {
        $clean_pdfs = array();
        // Each row is type-checked and its fields sanitized below.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        foreach ( wp_unslash( $_POST['blg_pdfs'] ) as $item ) {
            if ( ! is_array( $item ) ) continue;
            if ( ! empty( $item['url'] ) || ! empty( $item['post_url'] ) ) {
                $clean_pdfs[] = array(
                    'url'      => esc_url_raw( is_string( $item['url'] ?? null ) ? $item['url'] : '' ),
                    'post_url' => esc_url_raw( is_string( $item['post_url'] ?? null ) ? $item['post_url'] : '' ),
                    'image'    => esc_url_raw( is_string( $item['image'] ?? null ) ? $item['image'] : '' ),
                );
            }
        }
        update_post_meta( $post_id, '_blg_pdfs', $clean_pdfs );
    } else {
        delete_post_meta( $post_id, '_blg_pdfs' );
    }
}
add_action( 'save_post', 'blg_save_book_data' );

/**
 * 6. Shortcode [book_library]
 */
function blg_shortcode_display( $atts ) {
    ob_start();

    $query = new WP_Query( array(
        'post_type'      => 'blg_book',
        'posts_per_page' => -1,
        'orderby'        => 'menu_order',
        'order'          => 'ASC',
    ));

    if ( $query->have_posts() ) {
        echo '<div class="book-grid">';

        while ( $query->have_posts() ) {
            $query->the_post();
            $post_id = get_the_ID();
            $title = get_the_title();
            $description = get_the_content();

            // Get Meta
            $main_link    = get_post_meta( $post_id, '_blg_main_link', true );
            $custom_cover = get_post_meta( $post_id, '_blg_custom_cover', true );
            $feat_img     = get_the_post_thumbnail_url( $post_id, 'full' );

            // Determine Cover Image: Custom > Featured > Placeholder
            $cover_url = $custom_cover ? $custom_cover : $feat_img;

            $audios = get_post_meta( $post_id, '_blg_audios', true );
            $pdfs   = get_post_meta( $post_id, '_blg_pdfs', true );

            $unique_id = 'book-' . $post_id;
            $popup_id  = 'popup-' . $unique_id;
            ?>

            <!-- Grid Item -->
            <div class="book-item" id="<?php echo esc_attr( $unique_id ); ?>">
                <a class="book-cover" href="#<?php echo esc_attr( $popup_id ); ?>">
                    <?php if ( $cover_url ) : ?>
                        <img src="<?php echo esc_url( $cover_url ); ?>" alt="<?php echo esc_attr( $title ); ?>" />
                    <?php else: ?>
                        <div class="no-cover"><span><?php echo esc_html($title); ?></span></div>
                    <?php endif; ?>
                </a>
            </div>

            <!-- Popup -->
            <div class="popup" id="<?php echo esc_attr( $popup_id ); ?>">
                <a href="#<?php echo esc_attr( $unique_id ); ?>" class="popup-overlay"></a>
                <div class="popup-content">
                    <a class="close" href="#<?php echo esc_attr( $unique_id ); ?>">&times;</a>

                    <!-- Left: Image (Links to Main Link if exists) -->
                    <a class="popup-img" href="<?php echo $main_link ? esc_url($main_link) : '#'; ?>" <?php echo $main_link ? 'target="_blank" rel="noopener"' : ''; ?>>
                        <?php if ( $cover_url ) : ?>
                            <img src="<?php echo esc_url( $cover_url ); ?>" alt="<?php echo esc_attr( $title ); ?>" />
                        <?php endif; ?>
                    </a>

                    <!-- Right: Description & Attachments -->
                    <div class="popup-description">
                        <div class="popup-title"><?php echo esc_html( $title ); ?></div>

                        <div class="popup-text">
                            <?php echo wp_kses_post( wpautop( do_shortcode( $description ) ) ); ?>
                        </div>

                        <div class="popup-attachments">

                            <!-- PDF List -->
                            <?php if ( ! empty( $pdfs ) ) : ?>
                                <div class="attach-group">
                                    <?php foreach ( $pdfs as $pdf ) :
                                        // Priority: Post URL > File URL
                                        $link_target = !empty($pdf['post_url']) ? $pdf['post_url'] : $pdf['url'];
                                        $img_src     = !empty($pdf['image']) ? $pdf['image'] : plugin_dir_url(__FILE__).'default-pdf.png';
                                    ?>
                                        <?php if($link_target): ?>
                                            <a href="<?php echo esc_url( $link_target ); ?>" class="attachment-icon pdf-icon" target="_blank" title="View Book">
                                                <?php if($pdf['image']): ?>
                                                    <img src="<?php echo esc_url($pdf['image']); ?>" alt="PDF">
                                                <?php else: ?>
                                                    <span class="text-icon">PDF</span>
                                                <?php endif; ?>
                                            </a>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Audio List -->
                            <?php if ( ! empty( $audios ) ) : ?>
                                <div class="attach-group">
                                    <?php foreach ( $audios as $audio ) :
                                        // Priority: Post URL > File URL
                                        $a_link = !empty($audio['post_url']) ? $audio['post_url'] : $audio['url'];
                                        $label  = !empty($audio['label']) ? $audio['label'] : 'Audio';
                                    ?>
                                        <?php if($a_link): ?>
                                            <a href="<?php echo esc_url( $a_link ); ?>" class="attachment-icon audio-icon" target="_blank" title="Listen">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon><path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"></path></svg>
                                                <span><?php echo esc_html($label); ?></span>
                                            </a>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ( ! empty( $main_link ) ) : ?>
                                <div style="margin-top:20px;">
                                    <a href="<?php echo esc_url( $main_link ); ?>" target="_blank" class="read-more-btn">Skaityti knygą &rarr;</a>
                                </div>
                            <?php endif; ?>

                        </div>
                    </div>
                </div>
            </div>

            <?php
        }
        echo '</div>'; // End grid
        wp_reset_postdata();
    } else {
        echo '<p>No books found.</p>';
    }

    return ob_get_clean();
}
add_shortcode( 'book_library', 'blg_shortcode_display' );

/**
 * 7. Frontend CSS
 */
function blg_enqueue_assets() {
    ?>
    <style>
        html { scroll-behavior: smooth; }
        .book-grid { display: flex; flex-wrap: wrap; justify-content: flex-start; margin: 0 -1em; }

        .book-item {
            flex: 0 0 calc(25% - 2em); /* 4 per row */
            margin: 1em;
            position: relative; box-sizing: border-box;
            border-radius: 4px; box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            transition: transform 0.2s; background: #fff;
        }
        .book-item:hover { transform: translateY(-5px); }

        @media (max-width: 1024px) { .book-item { flex: 0 0 calc(50% - 2em); } }
        @media (max-width: 600px) { .book-item { flex: 0 0 calc(100% - 2em); } }

        .book-cover { display: flex; justify-content: center; align-items: center; overflow: hidden; cursor: pointer; }
        .book-cover img { width: 100%; height: auto; display: block; }
        .no-cover { width:100%; height:300px; background:#eee; display:flex; align-items:center; justify-content:center; color:#777; font-weight:bold; }

        /* Popup */
        .popup {
            display: none; position: fixed; left: 0; top: 0; width: 100%; height: 100%;
            background-color: rgba(0, 0, 0, 0.8); z-index: 9999;
        }
        .popup:target { display: flex; justify-content: center; align-items: center; }
        .popup-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: 1001; }

        .popup-content {
            display: flex; flex-direction: row; align-items: flex-start;
            background: white; padding: 30px; border-radius: 5px;
            position: relative; max-width: 900px; width: 95%;
            max-height: 90vh; overflow-y: auto; z-index: 1002;
        }

        .popup-img { flex: 0 0 40%; margin-right: 30px; text-decoration: none; border: 0; }
        .popup-img img { width: 100%; height: auto; border-radius: 4px; box-shadow: 0 5px 15px rgba(0,0,0,0.2); }

        .popup-description { flex: 1; }
        .popup-title { font-size: 24px; color: #333; margin-bottom: 15px; border-bottom: 2px solid #eee; padding-bottom: 10px; }
        .popup-text { line-height: 1.6; color: #444; margin-bottom: 20px; }

        .close { position: absolute; top: 10px; right: 20px; text-decoration: none; font-size: 30px; color: #333; z-index: 1005; }

        /* Attachments */
        .attach-group { display: flex; flex-wrap: wrap; gap: 15px; margin-bottom: 15px; }

        .attachment-icon {
            display: flex; flex-direction: column; align-items: center;
            text-decoration: none; color: #333; font-size: 11px; text-align: center;
            width: 80px;
        }
        .attachment-icon:hover { opacity: 0.7; }
        .attachment-icon img { width: 100%; height: auto; margin-bottom: 5px; border-radius: 3px; }
        .text-icon { display:block; padding: 15px; background: #eee; border-radius: 4px; font-weight: bold; width: 100%; box-sizing: border-box; }

        .audio-icon { width: auto; min-width: 60px; }
        .audio-icon svg { color: #555; margin-bottom: 2px; }

        .read-more-btn {
            display: inline-block; background: #333; color: #fff;
            padding: 10px 20px; text-decoration: none; border-radius: 4px;
            font-weight: bold; transition: background 0.3s;
        }
        .read-more-btn:hover { background: #555; }

        @media (max-width: 768px) {
            .popup-content { flex-direction: column; padding: 20px; }
            .popup-img { flex: 0 0 100%; width: 100%; margin: 0 0 20px 0; text-align: center; }
            .popup-img img { max-width: 200px; margin: 0 auto; }
        }
    </style>
    <?php
}
add_action( 'wp_footer', 'blg_enqueue_assets' );
