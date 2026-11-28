<?php
defined( 'ABSPATH' ) || exit;

/** Import/export is only invoked by authorised administrators, never on ordinary page requests. */
trait Guild_Import_Export {
    private static function io_url( $args = array() ) {
        return add_query_arg( $args, admin_url( 'admin.php?page=guild&tab=transfer' ) );
    }
    private static function io_key( $token ) {
        return 'guild_io_' . get_current_user_id() . '_' . $token;
    }
    private static function io_token() {
        return isset( $_REQUEST['guild_token'] ) && is_string( $_REQUEST['guild_token'] ) ? sanitize_key( wp_unslash( $_REQUEST['guild_token'] ) ) : '';
    }
    private static function io_guard() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Only administrators may import or export Guild records.', '', array( 'response' => 403 ) ); }
    }
    private static function io_error_redirect( $error ) {
        set_transient( 'guild_io_error_' . get_current_user_id(), $error, MINUTE_IN_SECONDS * 3 );
        wp_safe_redirect( self::io_url(), 303 ); exit;
    }
    private static function io_csv_rows( $filepath ) {
        $file = fopen( $filepath, 'rb' );
        if ( ! $file ) { return new WP_Error( 'csv_open', 'Could not read the CSV file.' ); }
        $rows = array(); $line = 0;
        while ( ( $columns = fgetcsv( $file, 0, ',', '"', '' ) ) !== false ) {
            ++$line;
            if ( 1 === $line && isset( $columns[0] ) ) { $columns[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $columns[0] ); }
            $rows[] = array( 'line' => $line, 'values' => $columns );
            if ( count( $rows ) > 501 ) { fclose( $file ); return new WP_Error( 'too_many', 'A maximum of 500 data rows can be imported at once.' ); }
        }
        fclose( $file ); return $rows;
    }
    private static function io_xlsx_rows( $filepath ) {
        if ( ! class_exists( 'ZipArchive' ) || ! function_exists( 'simplexml_load_string' ) ) {
            return new WP_Error( 'xlsx_unavailable', 'XLSX reading requires the PHP ZIP and SimpleXML extensions. Use CSV instead.' );
        }
        $zip = new ZipArchive();
        if ( true !== $zip->open( $filepath, ZipArchive::RDONLY ) ) { return new WP_Error( 'xlsx_zip', 'Invalid Excel workbook.' ); }
        $total = 0;
        for ( $i = 0; $i < $zip->numFiles; ++$i ) {
            $stat = $zip->statIndex( $i );
            if ( ! $stat || $stat['size'] > 8 * 1024 * 1024 ) { $zip->close(); return new WP_Error( 'xlsx_large', 'Workbook contains an oversized component.' ); }
            $total += $stat['size'];
            if ( $total > 16 * 1024 * 1024 ) { $zip->close(); return new WP_Error( 'xlsx_large', 'Workbook expands beyond the safe size limit.' ); }
        }
        $strings = array();
        $shared_xml = $zip->getFromName( 'xl/sharedStrings.xml' );
        if ( false !== $shared_xml ) {
            $xml = self::io_xml( $shared_xml );
            if ( is_wp_error( $xml ) ) { $zip->close(); return $xml; }
            $xml->registerXPathNamespace( 's', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );
            foreach ( $xml->xpath( '//s:si' ) ?: array() as $si ) {
                $text = '';
                 $si->registerXPathNamespace( 's', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );
                foreach ( $si->xpath( './/s:t' ) ?: array() as $t ) { $text .= (string) $t; }
                $strings[] = $text;
            }
        }
        // Find the first worksheet through workbook relationships, rather than assuming sheet1.xml.
        $workbook = $zip->getFromName( 'xl/workbook.xml' );
        $rels = $zip->getFromName( 'xl/_rels/workbook.xml.rels' );
        if ( false === $workbook || false === $rels ) { $zip->close(); return new WP_Error( 'xlsx_structure', 'Workbook structure is incomplete.' ); }
        $book = self::io_xml( $workbook ); $relxml = self::io_xml( $rels );
        if ( is_wp_error( $book ) || is_wp_error( $relxml ) ) { $zip->close(); return new WP_Error( 'xlsx_xml', 'Workbook XML is invalid.' ); }
        $book->registerXPathNamespace( 's', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );
        $sheets = $book->xpath( '//s:sheets/s:sheet' );
        if ( ! $sheets ) { $zip->close(); return new WP_Error( 'xlsx_sheet', 'Workbook has no worksheet.' ); }
        $attrs = $sheets[0]->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' );
        $rid = (string) ( $attrs['id'] ?? '' ); $target = '';
        foreach ( $relxml->children( 'http://schemas.openxmlformats.org/package/2006/relationships' )->Relationship as $rel ) {
            if ( (string) $rel['Id'] === $rid && str_contains( (string) $rel['Type'], '/worksheet' ) ) { $target = (string) $rel['Target']; break; }
        }
        if ( ! $target || str_contains( $target, '..' ) || preg_match( '~^[a-z]+:~i', $target ) ) { $zip->close(); return new WP_Error( 'xlsx_sheet', 'Could not locate the first worksheet.' ); }
        $path = str_starts_with( $target, '/' ) ? ltrim( $target, '/' ) : 'xl/' . ltrim( $target, '/' );
        $sheetxml = $zip->getFromName( $path ); $zip->close();
        if ( false === $sheetxml ) { return new WP_Error( 'xlsx_sheet', 'First worksheet is missing.' ); }
        $sheet = self::io_xml( $sheetxml );
        if ( is_wp_error( $sheet ) ) { return $sheet; }
        $sheet->registerXPathNamespace( 's', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );
        $rows = array();
        foreach ( $sheet->xpath( '//s:sheetData/s:row' ) ?: array() as $row ) {
            $number = (int) $row['r']; $values = array();
            $row->registerXPathNamespace( 's', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );
            foreach ( $row->xpath( './s:c' ) ?: array() as $cell ) {
                $reference = (string) $cell['r'];
                if ( ! preg_match( '/^([A-Z]{1,3})\d+$/', $reference, $match ) ) { continue; }
                $col = 0;
                foreach ( str_split( $match[1] ) as $letter ) { $col = $col * 26 + ord( $letter ) - 64; }
                if ( $col > 20 ) { continue; }
                $type = (string) $cell['t']; $value = '';
                $cell->registerXPathNamespace( 's', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );
                if ( 'inlineStr' === $type ) {
                    foreach ( $cell->xpath( './/s:is//s:t' ) ?: array() as $t ) { $value .= (string) $t; }
                } else {
                    $v = $cell->xpath( './s:v' );
                    $value = $v ? (string) $v[0] : '';
                    if ( 's' === $type ) { $value = $strings[(int) $value] ?? ''; }
                }
                $values[$col - 1] = $value;
            }
            if ( ! $values ) { continue; }
            $rows[] = array( 'line' => $number ?: count( $rows ) + 1, 'values' => $values );
            if ( count( $rows ) > 501 ) { return new WP_Error( 'too_many', 'A maximum of 500 data rows can be imported at once.' ); }
        }
        return $rows;
    }
    private static function io_xml( $source ) {
        if ( preg_match( '/<!DOCTYPE|<!ENTITY/i', $source ) ) { return new WP_Error( 'xlsx_xml', 'XML entities are not allowed.' ); }
        $old = libxml_use_internal_errors( true );
        $xml = simplexml_load_string( $source, 'SimpleXMLElement', LIBXML_NONET );
        libxml_clear_errors(); libxml_use_internal_errors( $old );
        return false === $xml ? new WP_Error( 'xlsx_xml', 'Excel XML could not be parsed.' ) : $xml;
    }
    private static function io_term_options( $taxonomy ) {
        $terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
        $out = array();
        if ( is_wp_error( $terms ) ) { return $out; }
        foreach ( $terms as $term ) { $out[$term->term_id] = self::term_path( $term, $taxonomy ); }
        return $out;
    }
    private static function io_resolve_term( $value, $options ) {
        $lower = function( $text ) { return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text ); };
        $matches = array(); $needle = $lower( trim( $value ) );
        foreach ( $options as $id => $path ) {
            $leaf = trim( substr( $path, strrpos( $path, '→' ) === false ? 0 : strrpos( $path, '→' ) + strlen( '→' ) ) );
            if ( $lower( trim( $path ) ) === $needle ) { return (int) $id; }
            if ( $lower( $leaf ) === $needle ) { $matches[] = (int) $id; }
        }
        return 1 === count( $matches ) ? $matches[0] : ( count( $matches ) ? -2 : -1 );
    }
    private static function io_clean_field( $value ) {
        // Guild's CSV exports prefix spreadsheet formulas with a single apostrophe.
        $value = trim( (string) $value );
        if ( strlen( $value ) > 1 && "'" === $value[0] && preg_match( '/^[=+\-@\t\r]/', substr( $value, 1 ) ) ) { $value = substr( $value, 1 ); }
        return $value;
    }
    private static function io_existing_ids( $ids ) {
        global $wpdb;
        $result = array();
        foreach ( array_chunk( $ids, 100 ) as $group ) {
            if ( ! $group ) { continue; }
            $marks = implode( ',', array_fill( 0, count( $group ), '%s' ) );
            $sql = $wpdb->prepare( "SELECT p.ID, m.meta_value FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id=p.ID WHERE p.post_type=%s AND m.meta_key=%s AND m.meta_value IN ($marks) AND p.post_status NOT IN ('trash','auto-draft')", array_merge( array( self::CPT, self::META_ID ), $group ) );
            foreach ( $wpdb->get_results( $sql ) as $item ) { $result[$item->meta_value] = (int) $item->ID; }
        }
        return $result;
    }
    public static function io_upload() {
        self::io_guard(); check_admin_referer( 'guild_io_upload' );
        $file = $_FILES['guild_file'] ?? array();
        if ( ! is_array( $file ) || (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK || ! is_uploaded_file( $file['tmp_name'] ?? '' ) ) { self::io_error_redirect( 'Upload failed. Please select a CSV or XLSX file.' ); }
        if ( (int) $file['size'] > 2 * 1024 * 1024 ) { self::io_error_redirect( 'Maximum import file size is 2 MB.' ); }
        $ext = strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, array( 'csv', 'xlsx' ), true ) ) { self::io_error_redirect( 'Only .csv and .xlsx files are supported.' ); }
        $rows = 'csv' === $ext ? self::io_csv_rows( $file['tmp_name'] ) : self::io_xlsx_rows( $file['tmp_name'] );
        if ( is_wp_error( $rows ) ) { self::io_error_redirect( $rows->get_error_message() ); }
        if ( count( $rows ) < 2 ) { self::io_error_redirect( 'No data rows were found.' ); }
        $header = array();
        foreach ( $rows[0]['values'] as $column => $label ) {
            $label = strtolower( trim( preg_replace( '/[^a-z0-9]+/i', '_', (string) $label ), '_' ) );
            $aliases = array( 'census_id' => 'id', 'item_type' => 'type', 'storage_location' => 'location', 'primary_photo' => 'primary_image', 'primary_image_url' => 'primary_image' );
            $label = $aliases[$label] ?? $label;
            if ( in_array( $label, array( 'id', 'name', 'type', 'sets', 'location', 'notes', 'primary_image' ), true ) ) {
                if ( isset( $header[$label] ) ) { self::io_error_redirect( 'A column header is duplicated: ' . $label ); }
                $header[$label] = $column;
            }
        }
        if ( ! isset( $header['id'] ) || ! isset( $header['name'] ) ) { self::io_error_redirect( 'The first row must contain ID and Name column headings.' ); }
        $options = array( 'type' => self::io_term_options( 'gob_type' ), 'sets' => self::io_term_options( 'gob_set' ), 'location' => self::io_term_options( 'gob_location' ) );
        $prepared = array(); $ids = array(); $seen = array();
        foreach ( array_slice( $rows, 1 ) as $row ) {
            $cells = array(); $any = false;
            foreach ( $header as $key => $col ) { $cells[$key] = self::io_clean_field( $row['values'][$col] ?? '' ); if ( '' !== $cells[$key] ) { $any = true; } }
            if ( ! $any ) { continue; }
            $id = strtoupper( $cells['id'] ); $errors = array();
            if ( $id && ! preg_match( '/^GOB-[0-9]{6,}$/', $id ) ) { $errors[] = 'Invalid census ID'; }
            if ( $id && isset( $seen[$id] ) ) { $errors[] = 'Duplicate ID in this file'; }
            if ( $id ) { $ids[] = $id; $seen[$id] = true; }
            if ( ! $id && '' === $cells['name'] ) { $errors[] = 'A new record needs a name'; }
            $term_ids = array();
            foreach ( array( 'type', 'location' ) as $field ) {
                if ( isset( $header[$field] ) && '' !== $cells[$field] ) {
                    $term_ids[$field] = self::io_resolve_term( $cells[$field], $options[$field] );
                    if ( $term_ids[$field] < 0 ) { $errors[] = 'Unknown or ambiguous ' . $field . ': ' . $cells[$field]; }
                }
            }
            if ( isset( $header['sets'] ) && '' !== $cells['sets'] ) {
                $term_ids['sets'] = array();
                foreach ( explode( '|', $cells['sets'] ) as $set_name ) {
                    if ( '' === trim( $set_name ) ) { continue; }
                    $match = self::io_resolve_term( trim( $set_name ), $options['sets'] );
                    if ( $match < 0 ) { $errors[] = 'Unknown or ambiguous set: ' . trim( $set_name ); }
                    else { $term_ids['sets'][] = $match; }
                }
                $term_ids['sets'] = array_values( array_unique( $term_ids['sets'] ) );
            }
            $prepared[] = array( 'line' => $row['line'], 'id' => $id, 'cells' => $cells, 'terms' => $term_ids, 'errors' => $errors );
        }
        if ( ! $prepared ) { self::io_error_redirect( 'No non-empty data rows were found.' ); }
        $lookup = self::io_existing_ids( array_values( array_unique( $ids ) ) );
        $counts = array( 'create' => 0, 'update' => 0, 'error' => 0 );
        foreach ( $prepared as &$item ) {
            if ( $item['id'] && ! isset( $lookup[$item['id']] ) ) { $item['errors'][] = 'ID does not exist (not created automatically)'; }
            $item['post_id'] = $item['id'] ? ( $lookup[$item['id']] ?? 0 ) : 0;
            if ( $item['post_id'] && ! current_user_can( 'edit_post', $item['post_id'] ) ) { $item['errors'][] = 'No permission to edit this record'; }
            $item['operation'] = $item['id'] ? 'update' : 'create';
            ++$counts[$item['errors'] ? 'error' : $item['operation']];
        } unset( $item );
        $token = wp_generate_password( 24, false, false );
        set_transient( self::io_key( $token ), array( 'state' => 'preview', 'rows' => $prepared, 'counts' => $counts, 'position' => 0, 'report' => array(), 'new' => 0, 'updated' => 0, 'failed' => 0 ), HOUR_IN_SECONDS );
        wp_safe_redirect( self::io_url( array( 'guild_token' => $token ) ), 303 ); exit;
    }
    public static function io_batch() {
        self::io_guard(); $token = self::io_token();
        if ( ! preg_match( '/^[a-zA-Z0-9]{20,40}$/', $token ) ) { self::io_error_redirect( 'Import session is invalid.' ); }
        check_admin_referer( 'guild_io_batch_' . $token );
        $key = self::io_key( $token ); $data = get_transient( $key );
        if ( ! is_array( $data ) ) { self::io_error_redirect( 'Import session has expired. Upload the spreadsheet again.' ); }
        if ( 'complete' === $data['state'] ) { wp_safe_redirect( self::io_url( array( 'guild_token' => $token ) ), 303 ); exit; }
        $lock = 'guild_io_lock_' . get_current_user_id() . '_' . $token;
        if ( ! add_option( $lock, time(), '', false ) ) {
            $started = (int) get_option( $lock, 0 );
            if ( $started && time() - $started > 180 ) { delete_option( $lock ); }
            if ( ! add_option( $lock, time(), '', false ) ) { self::io_error_redirect( 'This batch is already processing. Return to the import page.' ); }
        }
        $data['state'] = 'running';
        $limit = min( count( $data['rows'] ), $data['position'] + 25 );
        for ( $i = $data['position']; $i < $limit; ++$i ) {
            $row = $data['rows'][$i]; $reason = ''; $record_id = $row['id']; $operation = $row['operation'];
            if ( $row['errors'] ) { $reason = implode( '; ', $row['errors'] ); }
            else {
                $post_id = (int) $row['post_id'];
                if ( $post_id && ( self::CPT !== get_post_type( $post_id ) || get_post_meta( $post_id, self::META_ID, true ) !== $record_id ) ) { $reason = 'Record changed after preview'; }
                elseif ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) { $reason = 'Permission denied'; }
                else {
                    $data_to_save = array( 'post_type' => self::CPT, 'post_status' => 'publish' );
                    if ( $post_id ) { $data_to_save['ID'] = $post_id; }
                    foreach ( array( 'name' => 'post_title', 'notes' => 'post_content' ) as $field => $property ) {
                        if ( isset( $row['cells'][$field] ) && '' !== $row['cells'][$field] ) { $data_to_save[$property] = $field === 'notes' ? wp_kses_post( $row['cells'][$field] ) : sanitize_text_field( $row['cells'][$field] ); }
                    }
                    if ( ! $post_id && empty( $data_to_save['post_title'] ) ) { $reason = 'Name missing'; }
                    else {
                        if ( $post_id ) {
                            // Do not change draft/private visibility when only updating data.
                            unset( $data_to_save['post_status'] );
                            $result = wp_update_post( wp_slash( $data_to_save ), true );
                        } else { $result = wp_insert_post( wp_slash( $data_to_save ), true ); }
                        if ( is_wp_error( $result ) || ! $result ) { $reason = 'WordPress could not save the record'; }
                        else {
                            $post_id = (int) $result;
                            $record_id = get_post_meta( $post_id, self::META_ID, true );
                            if ( ! $record_id ) { $reason = 'Permanent ID was not allocated'; }
                            foreach ( array( 'type' => 'gob_type', 'location' => 'gob_location', 'sets' => 'gob_set' ) as $field => $taxonomy ) {
                                if ( isset( $row['terms'][$field] ) ) {
                                    $terms = is_array( $row['terms'][$field] ) ? $row['terms'][$field] : array( $row['terms'][$field] );
                                    $assigned = wp_set_object_terms( $post_id, array_map( 'intval', $terms ), $taxonomy );
                                    if ( is_wp_error( $assigned ) ) { $reason = 'Record saved but taxonomy assignment failed'; }
                                }
                            }
                        }
                    }
                }
            }
            if ( $reason ) { ++$data['failed']; }
            elseif ( 'create' === $operation ) { ++$data['new']; }
            else { ++$data['updated']; }
            $data['report'][] = array( $row['line'], $operation, $record_id, $row['cells']['name'], $reason ? 'Error' : 'Saved', $reason );
            $data['position'] = $i + 1;
        }
        if ( $data['position'] >= count( $data['rows'] ) ) { $data['state'] = 'complete'; unset( $data['rows'] ); }
        set_transient( $key, $data, HOUR_IN_SECONDS );
        delete_option( $lock );
        wp_safe_redirect( self::io_url( array( 'guild_token' => $token ) ), 303 ); exit;
    }
    private static function io_safe_csv( $value ) {
        $value = (string) $value;
        // Prevent spreadsheet formula execution. The importer recognises this escape.
        return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
    }
    private static function io_stream_header( $filename ) {
        while ( ob_get_level() ) { ob_end_clean(); }
        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        echo "\xEF\xBB\xBF";
    }
    public static function io_export() {
        self::io_guard(); check_admin_referer( 'guild_io_export' );
        self::io_stream_header( 'guild-census-' . gmdate( 'Y-m-d' ) . '.csv' );
        $out = fopen( 'php://output', 'wb' );
        fputcsv( $out, array( 'ID', 'Name', 'Type', 'Sets', 'Location', 'Notes', 'Primary image' ), ',', '"', '' );
        $page = 1;
        do {
            $ids = get_posts( array( 'post_type' => self::CPT, 'post_status' => array( 'publish', 'private', 'draft' ), 'numberposts' => 100, 'paged' => $page, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => true ) );
            if ( $ids ) { update_meta_cache( 'post', $ids ); update_object_term_cache( $ids, self::CPT ); }
            foreach ( $ids as $post_id ) {
                $post = get_post( $post_id ); $id = get_post_meta( $post_id, self::META_ID, true );
                $cells = array( $id, $post->post_title );
                foreach ( array( 'gob_type', 'gob_set', 'gob_location' ) as $tax ) {
                    $terms = wp_get_post_terms( $post_id, $tax ); $names = array();
                    if ( ! is_wp_error( $terms ) ) { foreach ( $terms as $term ) { $names[] = self::term_path( $term, $tax ); } }
                    $cells[] = implode( ' | ', $names );
                }
                $cells[] = $post->post_content;
                $cells[] = get_post_thumbnail_id( $post_id ) ? wp_get_attachment_url( get_post_thumbnail_id( $post_id ) ) : '';
                fputcsv( $out, array_map( array( __CLASS__, 'io_safe_csv' ), $cells ), ',', '"', '' );
            }
            ++$page;
        } while ( count( $ids ) === 100 && $page <= 1000 );
        fclose( $out ); exit;
    }
    public static function io_report() {
        self::io_guard(); $token = self::io_token();
        if ( ! preg_match( '/^[a-zA-Z0-9]{20,40}$/', $token ) ) { self::io_error_redirect( 'Invalid report token.' ); }
        check_admin_referer( 'guild_io_report_' . $token );
        $data = get_transient( self::io_key( $token ) );
        if ( ! is_array( $data ) || 'complete' !== $data['state'] ) { self::io_error_redirect( 'Report is not available.' ); }
        self::io_stream_header( 'guild-import-results-' . gmdate( 'Y-m-d' ) . '.csv' );
        $out = fopen( 'php://output', 'wb' );
        fputcsv( $out, array( 'Spreadsheet row', 'Operation', 'Census ID', 'Name', 'Result', 'Details' ), ',', '"', '' );
        foreach ( $data['report'] as $line ) { fputcsv( $out, array_map( array( __CLASS__, 'io_safe_csv' ), $line ), ',', '"', '' ); }
        fclose( $out ); exit;
    }
    public static function io_cancel() {
        self::io_guard(); $token = self::io_token(); check_admin_referer( 'guild_io_cancel_' . $token );
        if ( preg_match( '/^[a-zA-Z0-9]{20,40}$/', $token ) ) { delete_transient( self::io_key( $token ) ); }
        wp_safe_redirect( self::io_url(), 303 ); exit;
    }
    private static function io_form( $action, $token, $label, $primary = false ) {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="guild_token" value="' . esc_attr( $token ) . '">';
        wp_nonce_field( 'guild_io_' . str_replace( 'guild_io_', '', $action ) . '_' . $token );
        submit_button( $label, $primary ? 'primary' : 'secondary', 'submit', false ); echo '</form>';
    }
    public static function io_tab() {
        self::io_guard();
        echo '<h2>Import / Export</h2>';
        $error = get_transient( 'guild_io_error_' . get_current_user_id() );
        if ( $error ) { echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>'; delete_transient( 'guild_io_error_' . get_current_user_id() ); }
        $token = isset( $_GET['guild_token'] ) ? sanitize_key( wp_unslash( $_GET['guild_token'] ) ) : '';
        $data = $token ? get_transient( self::io_key( $token ) ) : false;
        if ( is_array( $data ) ) {
            echo '<h3>Import ' . ( 'complete' === $data['state'] ? 'complete' : 'preview / progress' ) . '</h3>';
            if ( 'preview' === $data['state'] ) {
                echo '<p><strong>' . (int) $data['counts']['create'] . '</strong> new, <strong>' . (int) $data['counts']['update'] . '</strong> updates, <strong>' . (int) $data['counts']['error'] . '</strong> errors. Errors will be skipped. No records have been changed yet.</p>';
                echo '<table class="widefat striped"><thead><tr><th>Row</th><th>ID</th><th>Name</th><th>Action</th><th>Validation</th></tr></thead><tbody>';
                foreach ( $data['rows'] as $row ) {
                    echo '<tr><td>' . (int) $row['line'] . '</td><td>' . esc_html( $row['id'] ?: '(new)' ) . '</td><td>' . esc_html( $row['cells']['name'] ) . '</td><td>' . esc_html( $row['operation'] ) . '</td><td>' . esc_html( $row['errors'] ? implode( '; ', $row['errors'] ) : 'Ready' ) . '</td></tr>';
                }
                echo '</tbody></table>';
                if ( $data['counts']['create'] + $data['counts']['update'] ) { self::io_form( 'guild_io_batch', $token, 'Confirm and process first batch', true ); }
            } elseif ( 'complete' === $data['state'] ) {
                echo '<p><strong>Finished:</strong> ' . (int) $data['new'] . ' created, ' . (int) $data['updated'] . ' updated, ' . (int) $data['failed'] . ' skipped or failed.</p>';
                self::io_form( 'guild_io_report', $token, 'Download results CSV' );
            } else {
                $total = count( $data['rows'] );
                echo '<p>Processed <strong>' . (int) $data['position'] . ' of ' . (int) $total . '</strong> rows. Each batch handles at most 25 rows.</p>';
                self::io_form( 'guild_io_batch', $token, 'Process next batch', true );
            }
            self::io_form( 'guild_io_cancel', $token, 'Close this import' );
            echo '<hr>';
        }
        echo '<h3>Upload spreadsheet</h3><p>Accepted: Excel .xlsx (first worksheet) or UTF-8 .csv, maximum 2 MB and 500 rows. Required headings: <code>ID</code> and <code>Name</code>. Optional headings: <code>Type</code>, <code>Sets</code>, <code>Location</code>, <code>Notes</code>. A <code>Primary image</code> export column is ignored on import.</p>';
        echo '<p>Existing IDs are updated; blank IDs create new records; unknown IDs and invalid taxonomy values are reported as errors. Empty cells do not erase existing values. Multiple sets use <code>|</code>. Term names must already exist; use a full hierarchy such as <code>Study → Bookcase</code> to distinguish duplicate names.</p>';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="guild_io_upload">';
        wp_nonce_field( 'guild_io_upload' );
        echo '<p><input type="file" name="guild_file" accept=".csv,.xlsx" required></p>';
        submit_button( 'Preview import' ); echo '</form>';
        echo '<h3>Export census</h3><p>Download a UTF-8 CSV suitable for opening in Excel and importing back into Guild. Export includes record ID, name, classifications, location, notes and primary-image URL (reference only; image files are not copied).</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="guild_io_export">';
        wp_nonce_field( 'guild_io_export' ); submit_button( 'Download census CSV', 'secondary' ); echo '</form>';
        echo '<p><em>Importing does not remove records, photographs or existing taxonomy terms. Back up WordPress before a large import.</em></p>';
    }
}
