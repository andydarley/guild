<?php
defined( 'ABSPATH' ) || exit;

trait Guild_Admin {
    public static function admin_menu() {
        add_menu_page( 'Guild', 'Guild', 'edit_posts', 'guild', array( __CLASS__, 'dashboard' ), 'dashicons-pets', 26 );
    }
    private static function tab_url( $tab ) {
        return admin_url( 'admin.php?page=guild&tab=' . rawurlencode( $tab ) );
    }
    private static function tabs() {
        return array( 'overview' => 'Overview', 'records' => 'Records', 'types' => 'Item types', 'sets' => 'Sets', 'locations' => 'Storage locations', 'transfer' => 'Import / Export', 'pages' => 'Pages' );
    }
    public static function dashboard() {
        if ( ! current_user_can( 'edit_posts' ) ) { wp_die( 'Access denied.' ); }
        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
        if ( ! isset( self::tabs()[$tab] ) ) { $tab = 'overview'; }
        echo '<div class="wrap"><h1>Guild</h1><nav class="nav-tab-wrapper" aria-label="Guild administration">';
        foreach ( self::tabs() as $key => $label ) {
            if ( in_array( $key, array( 'pages', 'transfer' ), true ) && ! current_user_can( 'manage_options' ) ) { continue; }
            echo '<a class="nav-tab ' . ( $key === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( 'records' === $key ? admin_url( 'edit.php?post_type=' . self::CPT ) : self::tab_url( $key ) ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</nav>';
        if ( 'pages' === $tab ) { self::settings_page( true ); }
        elseif ( 'transfer' === $tab ) { self::io_tab(); }
        elseif ( self::taxonomy_for_tab( $tab ) ) { self::terms_tab( $tab ); }
        elseif ( 'records' === $tab ) {
            echo '<p><a href="' . esc_url( admin_url( 'edit.php?post_type=' . self::CPT ) ) . '">View and edit all records</a></p>';
        } else {
            $counts = wp_count_posts( self::CPT );
            $total = (int) $counts->publish + (int) $counts->draft + (int) $counts->private;
            echo '<h2>Census overview</h2><p><strong>' . esc_html( $total ) . '</strong> records registered.</p>';
            echo '<p>Use the tabs above to manage records, classifications, locations and pages.</p>';
            echo '<h2>Frontend pages</h2><ul>';
            foreach ( array( 'catalogue' => 'Catalogue', 'entry' => 'Individual entry', 'form' => 'Entry form' ) as $key => $label ) {
                $url = self::configured_url( $key );
                echo '<li>' . esc_html( $label ) . ': ' . ( $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a>' : '<em>Not configured — visit the Pages tab</em>' ) . '</li>';
            }
            echo '</ul><p><strong>Privacy:</strong> Protect this installation and its Media Library files using cPanel Directory Privacy before uploading real photographs.</p>';
            echo '<p><strong>Available:</strong> Excel import and CSV export are on the Import / Export tab.</p><p><strong>Planned:</strong> Photographs associated with multiple records.</p>';
        }
        echo '</div>';
    }
    public static function settings() {
        register_setting( 'gob_page_settings', self::OPT_PAGES, array( 'type' => 'array', 'sanitize_callback' => array( __CLASS__, 'sanitize_page_settings' ), 'default' => array() ) );
    }
    public static function sanitize_page_settings( $input ) {
        $out = array();
        foreach ( array( 'catalogue', 'entry', 'form' ) as $key ) {
            $id = isset( $input[$key] ) ? absint( $input[$key] ) : 0;
            $out[$key] = $id && get_post_type( $id ) === 'page' ? $id : 0;
        }
        return $out;
    }
    public static function settings_page( $embedded = false ) {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Access denied.' ); }
        $saved = get_option( self::OPT_PAGES, array() );
        $rows = array(
            'catalogue' => array( '1. Census catalogue / search', 'Census', 'census', '[gob_census]', 'Shows the searchable list of census records.' ),
            'entry' => array( '2. Individual record view', 'Census entry', 'census-entry', '[gob_entry]', 'Displays one record, selected by the record ID in its URL.' ),
            'form' => array( '3. Add / edit record', 'Add / edit census entry', 'census-edit', '[gob_form]', 'Displays the form for creating or editing a record.' ),
        );
        if ( ! $embedded ) { echo '<div class="wrap">'; }
        echo '<h2>Frontend page settings</h2>';
        echo '<p><strong>First create and publish three ordinary WordPress Pages.</strong> Go to <a href="' . esc_url( admin_url( 'edit.php?post_type=page' ) ) . '">Pages</a>, add one <strong>Shortcode block</strong> to each Page, and paste the exact shortcode shown below. Then return here and choose the corresponding Page in each dropdown.</p>';
        echo '<p>The names and URL slugs are suggestions, not requirements. The <strong>shortcode and matching dropdown selection</strong> are what connect the pages. There is no need to create a WordPress Page for each bear.</p>';
        echo '<form method="post" action="options.php">'; settings_fields( 'gob_page_settings' );
        echo '<table class="widefat striped"><thead><tr><th>Plugin function</th><th>Suggested Page to create</th><th>Exact shortcode to insert</th><th>Choose the Page you created</th><th>Check</th></tr></thead><tbody>';
        foreach ( $rows as $key => $row ) {
            $selected_id = isset( $saved[$key] ) ? absint( $saved[$key] ) : 0;
            echo '<tr><th scope="row">' . esc_html( $row[0] ) . '<p class="description">' . esc_html( $row[4] ) . '</p></th>';
            echo '<td><strong>' . esc_html( $row[1] ) . '</strong><p class="description">Suggested slug: <code>' . esc_html( $row[2] ) . '</code></p></td>';
            echo '<td><code>' . esc_html( $row[3] ) . '</code></td><td>';
            wp_dropdown_pages( array( 'name' => self::OPT_PAGES . '[' . $key . ']', 'selected' => $selected_id, 'show_option_none' => '— Select the matching Page —', 'option_none_value' => '0' ) );
            echo '</td><td>';
            if ( ! $selected_id ) {
                echo '<span>Not assigned</span>';
            } else {
                $page = get_post( $selected_id );
                $body = $page ? $page->post_content : '';
                // Page shortcode blocks are stored as shortcode text in post_content.
                if ( $page && 'publish' !== $page->post_status ) {
                    echo '<strong>Page is not published</strong>';
                } elseif ( ! has_shortcode( $body, trim( $row[3], '[]' ) ) ) {
                    echo '<strong>Shortcode not detected — check the Page</strong>';
                } else {
                    echo '<strong>Ready</strong> — <a href="' . esc_url( get_permalink( $selected_id ) ) . '" target="_blank" rel="noopener">View Page</a>';
                }
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>'; submit_button( 'Save page settings' );
        echo '</form><p><strong>Important:</strong> the Check column reflects saved selections. After changing dropdowns, click Save page settings to update the checks. For friendly URLs, select <strong>Post name</strong> under <a href="' . esc_url( admin_url( 'options-permalink.php' ) ) . '">Settings → Permalinks</a>.</p>';
        if ( ! $embedded ) { echo '</div>'; }
    }
}
