<?php
defined( 'ABSPATH' ) || exit;

trait Guild_Forms {
    private static function php_size_bytes( $value ) {
        $value = trim( (string) $value );
        if ( '' === $value ) { return 0; }
        $number = (float) $value;
        $unit = strtolower( substr( $value, -1 ) );
        if ( 'g' === $unit ) { $number *= 1024 * 1024 * 1024; }
        elseif ( 'm' === $unit ) { $number *= 1024 * 1024; }
        elseif ( 'k' === $unit ) { $number *= 1024; }
        return (int) $number;
    }
    private static function photo_max_bytes() {
        $limits = array_filter( array(
            self::php_size_bytes( ini_get( 'upload_max_filesize' ) ),
            self::php_size_bytes( ini_get( 'post_max_size' ) ) - 1024 * 128,
            (int) wp_max_upload_size(),
        ), function( $n ) { return $n > 0; } );
        return $limits ? min( $limits ) : 0;
    }
    private static function photo_upload_message( $code ) {
        $messages = array(
            'size' => 'The photograph exceeds the server upload limit. The record was saved, but the photograph was not.',
            'partial' => 'The photograph was only partially uploaded. The record was saved.',
            'missing' => 'No photograph was received. The record was saved.',
            'permission' => 'Your account cannot upload photographs. The record was saved.',
            'failed' => 'The photograph could not be uploaded. The record was saved.',
        );
        return isset( $messages[$code] ) ? $messages[$code] : $messages['failed'];
    }
    private static function redirect_to_saved_form( $post_id, $error = '' ) {
        $id = get_post_meta( $post_id, self::META_ID, true );
        $url = self::configured_url( 'form' );
        if ( $url && $id ) {
            $args = array( 'record' => $id );
            if ( $error ) { $args['gob_photo_error'] = $error; }
            else { $args['gob_saved'] = 1; }
            wp_safe_redirect( add_query_arg( $args, $url ), 303 );
        } else {
            wp_safe_redirect( admin_url( 'post.php?post=' . $post_id . '&action=edit' ), 303 );
        }
        exit;
    }
    public static function shortcode_form() {
        if ( $denied = self::access() ) { return $denied; }
        if ( ! current_user_can( 'edit_posts' ) ) { return '<p>You cannot create census records.</p>'; }
        $id = isset( $_GET['record'] ) && is_string( $_GET['record'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['record'] ) ) ) : '';
        $post = $id ? self::item_by_id( $id ) : null;
        if ( $id && ! $post ) { return '<p>Record not found.</p>'; }
        if ( $post && ! current_user_can( 'edit_post', $post->ID ) ) { return '<p>Access denied.</p>'; }
        $types = get_terms( array( 'taxonomy' => 'gob_type', 'hide_empty' => false ) );
        $sets = get_terms( array( 'taxonomy' => 'gob_set', 'hide_empty' => false ) );
        if ( ! is_wp_error( $types ) ) { usort( $types, function( $a, $b ) { return strcasecmp( self::term_path( $a, 'gob_type' ), self::term_path( $b, 'gob_type' ) ); } ); }
        if ( ! is_wp_error( $sets ) ) { usort( $sets, function( $a, $b ) { return strcasecmp( $a->name, $b->name ); } ); }
        $selected_types = $post ? wp_get_post_terms( $post->ID, 'gob_type', array( 'fields' => 'ids' ) ) : array();
        $type_id = ! is_wp_error( $selected_types ) && $selected_types ? (int) $selected_types[0] : 0;
        $selected_sets = $post ? wp_get_post_terms( $post->ID, 'gob_set', array( 'fields' => 'ids' ) ) : array();
        if ( is_wp_error( $selected_sets ) ) { $selected_sets = array(); }
        $locations = get_terms( array( 'taxonomy' => 'gob_location', 'hide_empty' => false ) );
        if ( ! is_wp_error( $locations ) ) { usort( $locations, function( $a, $b ) { return strcasecmp( self::term_path( $a, 'gob_location' ), self::term_path( $b, 'gob_location' ) ); } ); }
        $selected = $post ? wp_get_post_terms( $post->ID, 'gob_location', array( 'fields' => 'ids' ) ) : array();
        $loc_id = ! is_wp_error( $selected ) && $selected ? (int) $selected[0] : 0;
        ob_start(); echo '<section class="gob-census gob-form"><h2>' . ( $post ? 'Edit census entry' : 'New census entry' ) . '</h2>';
        if ( isset( $_GET['gob_saved'] ) ) { echo '<p role="status">Record saved.</p>'; }
        if ( isset( $_GET['gob_photo_error'] ) ) {
            $code = sanitize_key( wp_unslash( $_GET['gob_photo_error'] ) );
            echo '<p role="alert"><strong>' . esc_html( self::photo_upload_message( $code ) ) . '</strong> You can choose a smaller photograph below and save again. Your existing name, notes and location are retained.</p>';
        }
        if ( $post ) { echo '<p>Permanent ID: <strong>' . esc_html( $id ) . '</strong></p>'; }
        echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post" enctype="multipart/form-data">';
        echo '<input type="hidden" name="action" value="gob_save_item"><input type="hidden" name="gob_post_id" value="' . esc_attr( $post ? $post->ID : 0 ) . '">';
        wp_nonce_field( 'gob_save_item', 'gob_nonce' );
        echo '<p><label for="gob-name">Name (required)</label><br><input required id="gob-name" name="gob_name" maxlength="200" value="' . esc_attr( $post ? $post->post_title : '' ) . '"></p>';
        echo '<p><label for="gob-photo">' . ( $post && has_post_thumbnail( $post->ID ) ? 'Replace primary photo' : 'Primary photograph' ) . '</label>';
        if ( $post && has_post_thumbnail( $post->ID ) ) { echo get_the_post_thumbnail( $post->ID, 'thumbnail' ); }
        $max_bytes = self::photo_max_bytes();
        echo '<br><input id="gob-photo" name="gob_photo" type="file" accept="image/*" data-max-bytes="' . esc_attr( $max_bytes ) . '"></p>';
        echo '<p>To avoid losing a camera photograph if an upload fails, take it in the Camera app first, then select it from Photos. Do not rely on a photograph taken only within this form.</p>';
        if ( $max_bytes ) { echo '<p>Maximum photograph upload: ' . esc_html( size_format( $max_bytes, 1 ) ) . ' (server limit). Choose a smaller image if necessary.</p>'; }
        echo '<p id="gob-photo-warning" role="alert" hidden></p>';
        // Upload limit check is handled by assets/js/guild-form.js.
        echo '<p><label for="gob-type">Item type</label><br><select id="gob-type" name="gob_type"><option value="0">— Not recorded —</option>';
        if ( ! is_wp_error( $types ) ) { foreach ( $types as $type ) {
            echo '<option value="' . esc_attr( $type->term_id ) . '" ' . selected( $type_id, $type->term_id, false ) . '>' . esc_html( self::term_path( $type, 'gob_type' ) ) . '</option>';
        } }
        echo '</select></p>';
        echo '<fieldset class="guild-sets"><legend>Sets (select any that apply)</legend>';
        if ( ! is_wp_error( $sets ) && $sets ) { foreach ( $sets as $set ) {
            echo '<label><input type="checkbox" name="gob_sets[]" value="' . esc_attr( $set->term_id ) . '" ' . checked( in_array( (int) $set->term_id, array_map( 'intval', $selected_sets ), true ), true, false ) . '> ' . esc_html( $set->name ) . '</label>';
        } } else { echo '<p>No sets defined yet. Create them in Guild → Sets.</p>'; }
        echo '</fieldset>';
        echo '<p><label for="gob-location">Storage location</label><br><select id="gob-location" name="gob_location"><option value="0">— Not recorded —</option>';
        if ( ! is_wp_error( $locations ) ) { foreach ( $locations as $loc ) {
            echo '<option value="' . esc_attr( $loc->term_id ) . '" ' . selected( $loc_id, $loc->term_id, false ) . '>' . esc_html( self::term_path( $loc, 'gob_location' ) ) . '</option>';
        } }
        echo '</select></p><p><label for="gob-notes">Notes</label><br><textarea id="gob-notes" name="gob_notes" rows="5">' . esc_textarea( $post ? $post->post_content : '' ) . '</textarea></p>';
        echo '<p><button type="submit">Save record</button></p></form></section>'; return ob_get_clean();
    }
    public static function save_frontend_item() {
        if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) { wp_die( 'Access denied.', '', array( 'response' => 403 ) ); }
        check_admin_referer( 'gob_save_item', 'gob_nonce' );
        $post_id = isset( $_POST['gob_post_id'] ) ? absint( $_POST['gob_post_id'] ) : 0;
        if ( $post_id && ( get_post_type( $post_id ) !== self::CPT || ! current_user_can( 'edit_post', $post_id ) ) ) { wp_die( 'Record not available.', '', array( 'response' => 403 ) ); }
        $name = isset( $_POST['gob_name'] ) && is_string( $_POST['gob_name'] ) ? sanitize_text_field( wp_unslash( $_POST['gob_name'] ) ) : '';
        if ( '' === $name ) { wp_die( 'A name is required.', '', array( 'response' => 400 ) ); }
        $notes = isset( $_POST['gob_notes'] ) && is_string( $_POST['gob_notes'] ) ? wp_kses_post( wp_unslash( $_POST['gob_notes'] ) ) : '';
        $data = array( 'post_type' => self::CPT, 'post_title' => $name, 'post_content' => $notes, 'post_status' => 'publish' );
        if ( $post_id ) { $data['ID'] = $post_id; $result = wp_update_post( wp_slash( $data ), true ); }
        else { $result = wp_insert_post( wp_slash( $data ), true ); }
        if ( is_wp_error( $result ) || ! $result ) { wp_die( 'Could not save record.', '', array( 'response' => 500 ) ); }
        $post_id = (int) $result;
        $type = isset( $_POST['gob_type'] ) ? absint( $_POST['gob_type'] ) : 0;
        wp_set_object_terms( $post_id, $type && term_exists( $type, 'gob_type' ) ? array( $type ) : array(), 'gob_type' );
        $set_ids = isset( $_POST['gob_sets'] ) && is_array( $_POST['gob_sets'] ) ? array_map( 'absint', wp_unslash( $_POST['gob_sets'] ) ) : array();
        $valid_sets = array();
        foreach ( array_unique( $set_ids ) as $set_id ) {
            if ( $set_id && term_exists( $set_id, 'gob_set' ) ) { $valid_sets[] = $set_id; }
        }
        wp_set_object_terms( $post_id, $valid_sets, 'gob_set' );
        $location = isset( $_POST['gob_location'] ) ? absint( $_POST['gob_location'] ) : 0;
        if ( $location && term_exists( $location, 'gob_location' ) ) { wp_set_object_terms( $post_id, array( $location ), 'gob_location' ); }
        else { wp_set_object_terms( $post_id, array(), 'gob_location' ); }
        $photo = isset( $_FILES['gob_photo'] ) && is_array( $_FILES['gob_photo'] ) ? $_FILES['gob_photo'] : array();
        $upload_error = isset( $photo['error'] ) ? (int) $photo['error'] : UPLOAD_ERR_NO_FILE;
        if ( UPLOAD_ERR_NO_FILE !== $upload_error ) {
            if ( ! current_user_can( 'upload_files' ) ) { self::redirect_to_saved_form( $post_id, 'permission' ); }
            if ( UPLOAD_ERR_INI_SIZE === $upload_error || UPLOAD_ERR_FORM_SIZE === $upload_error ) { self::redirect_to_saved_form( $post_id, 'size' ); }
            if ( UPLOAD_ERR_PARTIAL === $upload_error ) { self::redirect_to_saved_form( $post_id, 'partial' ); }
            if ( UPLOAD_ERR_OK !== $upload_error ) { self::redirect_to_saved_form( $post_id, 'failed' ); }
            if ( ! empty( $photo['size'] ) && self::photo_max_bytes() && (int) $photo['size'] > self::photo_max_bytes() ) { self::redirect_to_saved_form( $post_id, 'size' ); }
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            $attachment_id = media_handle_upload( 'gob_photo', $post_id, array(), array( 'test_form' => false, 'mimes' => array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'heic' => 'image/heic', 'heif' => 'image/heif' ) ) );
            if ( is_wp_error( $attachment_id ) ) { self::redirect_to_saved_form( $post_id, 'failed' ); }
            set_post_thumbnail( $post_id, $attachment_id );
        }
        $id = get_post_meta( $post_id, self::META_ID, true );
        $url = self::record_url( $id );
        if ( ! $url ) { self::redirect_to_saved_form( $post_id ); }
        wp_safe_redirect( $url, 303 ); exit;
    }
}
