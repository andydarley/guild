<?php
/**
 * Plugin Name: Guild
 * Description: Private, WordPress-based census foundation with real frontend Pages and a simple mobile entry form.
 * Version: 0.1.2
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Author: Guild of Bears
 * Text Domain: guild
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Guild_Census {
    const VERSION = '0.1.2';
    const CPT = 'gob_item';
    const OPT_PAGES = 'gob_census_pages';
    const OPT_COUNTER = 'gob_census_counter';
    const META_ID = '_gob_census_id';

    public static function boot() {
        add_action( 'init', array( __CLASS__, 'register_content' ) );
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'settings' ) );
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
        add_action( 'save_post_' . self::CPT, array( __CLASS__, 'ensure_id' ), 10, 3 );
        add_filter( 'manage_' . self::CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
        add_action( 'manage_' . self::CPT . '_posts_custom_column', array( __CLASS__, 'column_value' ), 10, 2 );
        add_shortcode( 'gob_census', array( __CLASS__, 'shortcode_census' ) );
        add_shortcode( 'gob_entry', array( __CLASS__, 'shortcode_entry' ) );
        add_shortcode( 'gob_form', array( __CLASS__, 'shortcode_form' ) );
        add_action( 'admin_post_gob_save_item', array( __CLASS__, 'save_frontend_item' ) );
        add_action( 'admin_post_guild_term', array( __CLASS__, 'manage_term' ) );
    }
    public static function activate() {
        self::register_content();
        add_option( self::OPT_COUNTER, '0', '', false );
        flush_rewrite_rules();
    }
    public static function register_content() {
        register_post_type( self::CPT, array(
            'labels' => array( 'name' => 'Census records', 'singular_name' => 'Census record', 'add_new_item' => 'Add census record', 'edit_item' => 'Edit census record', 'menu_name' => 'Census records' ),
            'public' => false, 'publicly_queryable' => false, 'exclude_from_search' => true,
            'show_ui' => true, 'show_in_menu' => false, 'show_in_rest' => false,
            'has_archive' => false, 'rewrite' => false, 'query_var' => false,
            'supports' => array( 'title', 'editor', 'thumbnail' ),
            'capability_type' => 'post', 'map_meta_cap' => true,
        ) );
        $taxes = array(
            'gob_type' => array( 'Item types', true ),
            'gob_set' => array( 'Sets', false ),
            'gob_location' => array( 'Storage locations', true ),
        );
        foreach ( $taxes as $slug => $data ) {
            register_taxonomy( $slug, self::CPT, array(
                'labels' => array( 'name' => $data[0], 'singular_name' => rtrim( $data[0], 's' ) ),
                'public' => false, 'publicly_queryable' => false, 'rewrite' => false,
                'hierarchical' => $data[1], 'show_ui' => true, 'show_admin_column' => true, 'show_in_menu' => false,
                'show_in_rest' => false, 'show_in_nav_menus' => false,
            ) );
        }
    }
    public static function admin_menu() {
        add_menu_page( 'Guild', 'Guild', 'edit_posts', 'guild', array( __CLASS__, 'dashboard' ), 'dashicons-archive', 26 );
    }
    private static function tab_url( $tab ) {
        return admin_url( 'admin.php?page=guild&tab=' . rawurlencode( $tab ) );
    }
    private static function tabs() {
        return array( 'overview' => 'Overview', 'records' => 'Records', 'types' => 'Item types', 'sets' => 'Sets', 'locations' => 'Storage locations', 'pages' => 'Pages' );
    }
    private static function taxonomy_for_tab( $tab ) {
        $map = array( 'types' => 'gob_type', 'sets' => 'gob_set', 'locations' => 'gob_location' );
        return isset( $map[$tab] ) ? $map[$tab] : '';
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
    private static function configured_url( $key ) {
        $pages = get_option( self::OPT_PAGES, array() );
        $id = isset( $pages[$key] ) ? absint( $pages[$key] ) : 0;
        return $id && get_post_status( $id ) === 'publish' ? get_permalink( $id ) : '';
    }
    private static function record_url( $record_id ) {
        $url = self::configured_url( 'entry' );
        return $url ? add_query_arg( 'record', $record_id, $url ) : '';
    }
    public static function dashboard() {
        if ( ! current_user_can( 'edit_posts' ) ) { wp_die( 'Access denied.' ); }
        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
        if ( ! isset( self::tabs()[$tab] ) ) { $tab = 'overview'; }
        echo '<div class="wrap"><h1>Guild</h1><nav class="nav-tab-wrapper" aria-label="Guild administration">';
        foreach ( self::tabs() as $key => $label ) {
            if ( 'pages' === $key && ! current_user_can( 'manage_options' ) ) { continue; }
            echo '<a class="nav-tab ' . ( $key === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( self::tab_url( $key ) ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</nav>';
        if ( 'pages' === $tab ) { self::settings_page( true ); }
        elseif ( self::taxonomy_for_tab( $tab ) ) { self::terms_tab( $tab ); }
        elseif ( 'records' === $tab ) {
            echo '<h2>Census records</h2><p>Manage census entries using the standard WordPress editor, or use the entry form on your site.</p>';
            echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'post-new.php?post_type=' . self::CPT ) ) . '">Add record</a> ';
            echo '<a class="button" href="' . esc_url( admin_url( 'edit.php?post_type=' . self::CPT ) ) . '">View and edit records</a></p>';
            $form = self::configured_url( 'form' );
            if ( $form ) { echo '<p><a href="' . esc_url( $form ) . '">Open mobile-friendly entry form</a></p>'; }
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
            echo '<p><strong>Planned:</strong> Excel imports, CSV exports and photographs associated with multiple records.</p>';
        }
        echo '</div>';
    }
    public static function manage_term() {
        if ( ! current_user_can( 'manage_categories' ) ) { wp_die( 'Access denied.', '', array( 'response' => 403 ) ); }
        check_admin_referer( 'guild_term', 'guild_term_nonce' );
        $tab = isset( $_POST['guild_tab'] ) ? sanitize_key( wp_unslash( $_POST['guild_tab'] ) ) : '';
        $tax = self::taxonomy_for_tab( $tab );
        if ( ! $tax ) { wp_die( 'Invalid classification.', '', array( 'response' => 400 ) ); }
        $action = isset( $_POST['guild_operation'] ) ? sanitize_key( wp_unslash( $_POST['guild_operation'] ) ) : '';
        $term_id = isset( $_POST['guild_term_id'] ) ? absint( $_POST['guild_term_id'] ) : 0;
        $result = null;
        if ( 'delete' === $action && $term_id ) {
            $result = wp_delete_term( $term_id, $tax );
        } elseif ( 'save' === $action ) {
            $name = isset( $_POST['guild_name'] ) ? sanitize_text_field( wp_unslash( $_POST['guild_name'] ) ) : '';
            $parent = isset( $_POST['guild_parent'] ) ? absint( $_POST['guild_parent'] ) : 0;
            if ( ! is_taxonomy_hierarchical( $tax ) ) { $parent = 0; }
            if ( $parent && ! term_exists( $parent, $tax ) ) { $parent = 0; }
            if ( ! $name ) { $result = new WP_Error( 'empty_name', 'Enter a name.' ); }
            elseif ( $term_id ) {
                if ( $parent === $term_id ) { $result = new WP_Error( 'invalid_parent', 'A location cannot be its own parent.' ); }
                else { $result = wp_update_term( $term_id, $tax, array( 'name' => $name, 'parent' => $parent ) ); }
            } else { $result = wp_insert_term( $name, $tax, array( 'parent' => $parent ) ); }
        } else { wp_die( 'Invalid operation.', '', array( 'response' => 400 ) ); }
        $url = self::tab_url( $tab );
        if ( is_wp_error( $result ) ) { $url = add_query_arg( 'guild_error', $result->get_error_code(), $url ); }
        else { $url = add_query_arg( 'guild_saved', 1, $url ); }
        wp_safe_redirect( $url ); exit;
    }
    private static function term_path( $term, $tax ) {
        $ancestors = array_reverse( get_ancestors( $term->term_id, $tax, 'taxonomy' ) );
        $names = array();
        foreach ( $ancestors as $ancestor ) { $p = get_term( $ancestor, $tax ); if ( $p && ! is_wp_error( $p ) ) { $names[] = $p->name; } }
        $names[] = $term->name;
        return implode( ' → ', $names );
    }
    private static function terms_tab( $tab ) {
        $tax = self::taxonomy_for_tab( $tab );
        if ( ! current_user_can( 'manage_categories' ) ) { echo '<p>You cannot manage classifications.</p>'; return; }
        $labels = array( 'types' => 'Item types', 'sets' => 'Sets', 'locations' => 'Storage locations' );
        $terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) );
        if ( is_wp_error( $terms ) ) { echo '<p>Could not load terms.</p>'; return; }
        usort( $terms, function( $a, $b ) use ( $tax ) { return strcasecmp( self::term_path( $a, $tax ), self::term_path( $b, $tax ) ); } );
        $edit = isset( $_GET['edit_term'] ) ? absint( $_GET['edit_term'] ) : 0;
        $current = $edit ? get_term( $edit, $tax ) : null;
        if ( is_wp_error( $current ) ) { $current = null; }
        echo '<h2>' . esc_html( $labels[$tab] ) . '</h2>';
        if ( isset( $_GET['guild_saved'] ) ) { echo '<div class="notice notice-success"><p>Changes saved.</p></div>'; }
        if ( isset( $_GET['guild_error'] ) ) { echo '<div class="notice notice-error"><p>Could not save the change. Check for duplicate names or invalid hierarchy.</p></div>'; }
        echo '<h3>' . ( $current ? 'Edit' : 'Add' ) . ' ' . esc_html( strtolower( $labels[$tab] ) ) . '</h3>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="guild_term"><input type="hidden" name="guild_operation" value="save"><input type="hidden" name="guild_tab" value="' . esc_attr( $tab ) . '"><input type="hidden" name="guild_term_id" value="' . esc_attr( $current ? $current->term_id : 0 ) . '">';
        wp_nonce_field( 'guild_term', 'guild_term_nonce' );
        echo '<p><label for="guild-name">Name</label><br><input type="text" required id="guild-name" name="guild_name" value="' . esc_attr( $current ? $current->name : '' ) . '" class="regular-text"></p>';
        if ( is_taxonomy_hierarchical( $tax ) ) {
            echo '<p><label for="guild-parent">Parent (optional)</label><br><select id="guild-parent" name="guild_parent"><option value="0">— None —</option>';
            foreach ( $terms as $term ) {
                if ( $current && ( $current->term_id === $term->term_id || term_is_ancestor_of( $current->term_id, $term->term_id, $tax ) ) ) { continue; }
                echo '<option value="' . esc_attr( $term->term_id ) . '" ' . selected( $current ? $current->parent : 0, $term->term_id, false ) . '>' . esc_html( self::term_path( $term, $tax ) ) . '</option>';
            }
            echo '</select></p>';
        }
        submit_button( $current ? 'Update' : 'Add' ); echo '</form>';
        if ( $current ) { echo '<p><a href="' . esc_url( self::tab_url( $tab ) ) . '">Cancel editing</a></p>'; }
        echo '<h3>Existing ' . esc_html( strtolower( $labels[$tab] ) ) . '</h3>';
        if ( ! $terms ) { echo '<p>No entries yet.</p>'; return; }
        echo '<table class="widefat striped"><thead><tr><th>Name</th><th>Records</th><th>Actions</th></tr></thead><tbody>';
        foreach ( $terms as $term ) {
            echo '<tr><td>' . esc_html( self::term_path( $term, $tax ) ) . '</td><td>' . esc_html( $term->count ) . '</td><td><a href="' . esc_url( add_query_arg( 'edit_term', $term->term_id, self::tab_url( $tab ) ) ) . '">Edit</a> ';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
            echo '<input type="hidden" name="action" value="guild_term"><input type="hidden" name="guild_operation" value="delete"><input type="hidden" name="guild_tab" value="' . esc_attr( $tab ) . '"><input type="hidden" name="guild_term_id" value="' . esc_attr( $term->term_id ) . '">';
            wp_nonce_field( 'guild_term', 'guild_term_nonce' );
            echo '<button type="submit" class="button-link-delete" onclick="return confirm(&quot;Delete this classification? Records will remain.&quot;)">Delete</button></form></td></tr>';
        }
        echo '</tbody></table>';
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
    public static function add_meta_boxes() {
        add_meta_box( 'gob_identity', 'Guild Census identity', array( __CLASS__, 'identity_box' ), self::CPT, 'side', 'high' );
    }
    public static function identity_box( $post ) {
        $id = get_post_meta( $post->ID, self::META_ID, true );
        echo '<p><strong>Permanent census ID</strong></p><p>' . ( $id ? esc_html( $id ) : 'Assigned on first save' ) . '</p><p>ID cannot be edited. Use the Featured image panel for the primary photograph.</p>';
    }
    // Atomic database increment protects identifiers from two simultaneous registrations.
    private static function next_id() {
        global $wpdb;
        add_option( self::OPT_COUNTER, '0', '', false );
        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID(CAST(option_value AS UNSIGNED) + 1) WHERE option_name = %s",
            self::OPT_COUNTER
        ) );
        if ( 1 !== $updated ) { return ''; }
        $n = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' ); // Same database connection.
        wp_cache_delete( self::OPT_COUNTER, 'options' );
        wp_cache_delete( 'alloptions', 'options' );
        return $n > 0 ? sprintf( 'GOB-%06d', $n ) : '';
    }
    public static function ensure_id( $post_id, $post, $update ) {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || $post->post_status === 'auto-draft' ) { return; }
        if ( get_post_meta( $post_id, self::META_ID, true ) ) { return; }
        $id = self::next_id();
        if ( $id ) { add_post_meta( $post_id, self::META_ID, $id, true ); }
    }
    public static function columns( $columns ) {
        $out = array();
        foreach ( $columns as $key => $value ) {
            $out[$key] = $value;
            if ( 'title' === $key ) { $out['gob_id'] = 'Census ID'; }
        }
        return $out;
    }
    public static function column_value( $column, $post_id ) {
        if ( 'gob_id' === $column ) { echo esc_html( get_post_meta( $post_id, self::META_ID, true ) ); }
    }
    private static function access() {
        if ( ! is_user_logged_in() ) {
            return '<div class="gob-census"><p>This census requires a login. <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">Log in</a></p></div>';
        }
        if ( ! current_user_can( 'edit_posts' ) ) { return '<p>Access denied.</p>'; }
        return '';
    }
    private static function item_by_id( $external_id ) {
        if ( ! preg_match( '/^GOB-[0-9]{6,}$/', $external_id ) ) { return null; }
        $items = get_posts( array( 'post_type' => self::CPT, 'post_status' => array( 'publish', 'private', 'draft' ), 'meta_key' => self::META_ID, 'meta_value' => $external_id, 'posts_per_page' => 1 ) );
        return $items ? $items[0] : null;
    }
    public static function shortcode_census() {
        if ( $denied = self::access() ) { return $denied; }
        $search = isset( $_GET['gob_q'] ) && is_string( $_GET['gob_q'] ) ? sanitize_text_field( wp_unslash( $_GET['gob_q'] ) ) : '';
        $page = isset( $_GET['gob_page'] ) ? max( 1, absint( $_GET['gob_page'] ) ) : 1;
        $args = array( 'post_type' => self::CPT, 'post_status' => array( 'publish', 'private', 'draft' ), 'posts_per_page' => 24, 'paged' => $page, 'orderby' => 'title', 'order' => 'ASC' );
        if ( $search !== '' ) {
            if ( preg_match( '/^GOB-[0-9]{6,}$/i', $search ) ) {
                $args['meta_query'] = array( array( 'key' => self::META_ID, 'value' => strtoupper( $search ), 'compare' => '=' ) );
            } else { $args['s'] = $search; }
        }
        $q = new WP_Query( $args );
        ob_start();
        echo '<section class="gob-census"><h2>Census catalogue</h2><form method="get" class="gob-search" action="' . esc_url( get_permalink() ) . '"><label for="gob-q">Search by name or ID</label><input type="search" id="gob-q" name="gob_q" value="' . esc_attr( $search ) . '"><button type="submit">Search</button></form>';
        $form_url = self::configured_url( 'form' );
        if ( current_user_can( 'edit_posts' ) && $form_url ) { echo '<p><a href="' . esc_url( $form_url ) . '">Add census entry</a></p>'; }
        if ( ! $q->have_posts() ) { echo '<p>No matching records.</p>'; }
        else {
            echo '<ul class="gob-record-list">';
            while ( $q->have_posts() ) { $q->the_post();
                $id = get_post_meta( get_the_ID(), self::META_ID, true );
                $link = self::record_url( $id );
                echo '<li class="gob-record">';
                if ( has_post_thumbnail() ) { echo $link ? '<a href="' . esc_url( $link ) . '">' : ''; echo get_the_post_thumbnail( get_the_ID(), 'medium', array( 'loading' => 'lazy' ) ); echo $link ? '</a>' : ''; }
                else { echo '<p>No photograph</p>'; }
                echo '<h3>' . ( $link ? '<a href="' . esc_url( $link ) . '">' : '' ) . esc_html( get_the_title() ) . ( $link ? '</a>' : '' ) . '</h3><p>' . esc_html( $id ) . '</p></li>';
            }
            echo '</ul>';
            if ( $q->max_num_pages > 1 ) {
                echo '<nav class="gob-pages" aria-label="Census pages">';
                for ( $i = 1; $i <= min( 100, $q->max_num_pages ); $i++ ) {
                    $url = add_query_arg( array( 'gob_page' => $i, 'gob_q' => $search ), get_permalink() );
                    echo $i === $page ? '<strong>' . $i . '</strong> ' : '<a href="' . esc_url( $url ) . '">' . $i . '</a> ';
                }
                echo '</nav>';
            }
        }
        wp_reset_postdata(); echo '</section>';
        return ob_get_clean();
    }
    public static function shortcode_entry() {
        if ( $denied = self::access() ) { return $denied; }
        $id = isset( $_GET['record'] ) && is_string( $_GET['record'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['record'] ) ) ) : '';
        $post = self::item_by_id( $id );
        if ( ! $post ) { return '<div class="gob-census"><p>No census record selected or found.</p></div>'; }
        $form = self::configured_url( 'form' ); $catalogue = self::configured_url( 'catalogue' );
        ob_start(); echo '<article class="gob-census gob-detail">';
        if ( $catalogue ) { echo '<p><a href="' . esc_url( $catalogue ) . '">← Catalogue</a></p>'; }
        echo '<h1>' . esc_html( get_the_title( $post ) ) . '</h1><p class="gob-identifier">' . esc_html( $id ) . '</p>';
        if ( has_post_thumbnail( $post->ID ) ) { echo get_the_post_thumbnail( $post->ID, 'large' ); }
        if ( trim( $post->post_content ) !== '' ) { echo '<div class="gob-notes">' . wp_kses_post( apply_filters( 'the_content', $post->post_content ) ) . '</div>'; }
        foreach ( array( 'gob_type' => 'Type', 'gob_set' => 'Sets', 'gob_location' => 'Storage location' ) as $tax => $label ) {
            $names = wp_get_post_terms( $post->ID, $tax, array( 'fields' => 'names' ) );
            if ( ! is_wp_error( $names ) && $names ) { echo '<p><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( implode( ', ', $names ) ) . '</p>'; }
        }
        if ( current_user_can( 'edit_post', $post->ID ) && $form ) { echo '<p><a href="' . esc_url( add_query_arg( 'record', $id, $form ) ) . '">Edit record</a></p>'; }
        echo '</article>'; return ob_get_clean();
    }
    public static function shortcode_form() {
        if ( $denied = self::access() ) { return $denied; }
        if ( ! current_user_can( 'edit_posts' ) ) { return '<p>You cannot create census records.</p>'; }
        $id = isset( $_GET['record'] ) && is_string( $_GET['record'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['record'] ) ) ) : '';
        $post = $id ? self::item_by_id( $id ) : null;
        if ( $id && ! $post ) { return '<p>Record not found.</p>'; }
        if ( $post && ! current_user_can( 'edit_post', $post->ID ) ) { return '<p>Access denied.</p>'; }
        $locations = get_terms( array( 'taxonomy' => 'gob_location', 'hide_empty' => false ) );
        if ( ! is_wp_error( $locations ) ) { usort( $locations, function( $a, $b ) { return strcasecmp( self::term_path( $a, 'gob_location' ), self::term_path( $b, 'gob_location' ) ); } ); }
        $selected = $post ? wp_get_post_terms( $post->ID, 'gob_location', array( 'fields' => 'ids' ) ) : array();
        $loc_id = ! is_wp_error( $selected ) && $selected ? (int) $selected[0] : 0;
        ob_start(); echo '<section class="gob-census gob-form"><h2>' . ( $post ? 'Edit census entry' : 'New census entry' ) . '</h2>';
        if ( isset( $_GET['gob_saved'] ) ) { echo '<p role="status">Record saved.</p>'; }
        if ( $post ) { echo '<p>Permanent ID: <strong>' . esc_html( $id ) . '</strong></p>'; }
        echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post" enctype="multipart/form-data">';
        echo '<input type="hidden" name="action" value="gob_save_item"><input type="hidden" name="gob_post_id" value="' . esc_attr( $post ? $post->ID : 0 ) . '">';
        wp_nonce_field( 'gob_save_item', 'gob_nonce' );
        echo '<p><label for="gob-name">Name (required)</label><br><input required id="gob-name" name="gob_name" maxlength="200" value="' . esc_attr( $post ? $post->post_title : '' ) . '"></p>';
        echo '<p><label for="gob-photo">' . ( $post && has_post_thumbnail( $post->ID ) ? 'Replace primary photo' : 'Primary photograph' ) . '</label>';
        if ( $post && has_post_thumbnail( $post->ID ) ) { echo get_the_post_thumbnail( $post->ID, 'thumbnail' ); }
        echo '<br><input id="gob-photo" name="gob_photo" type="file" accept="image/jpeg,image/png,image/webp,image/heic,image/heif,image/*" capture="environment"></p>';
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
        $location = isset( $_POST['gob_location'] ) ? absint( $_POST['gob_location'] ) : 0;
        if ( $location && term_exists( $location, 'gob_location' ) ) { wp_set_object_terms( $post_id, array( $location ), 'gob_location' ); }
        else { wp_set_object_terms( $post_id, array(), 'gob_location' ); }
        if ( ! empty( $_FILES['gob_photo']['name'] ) ) {
            if ( ! current_user_can( 'upload_files' ) ) { wp_die( 'Photo upload permission required.', '', array( 'response' => 403 ) ); }
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            $attachment_id = media_handle_upload( 'gob_photo', $post_id, array(), array( 'test_form' => false, 'mimes' => array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'heic' => 'image/heic', 'heif' => 'image/heif' ) ) );
            if ( is_wp_error( $attachment_id ) ) { wp_die( 'Record saved but photograph upload failed: ' . esc_html( $attachment_id->get_error_message() ), '', array( 'response' => 400 ) ); }
            set_post_thumbnail( $post_id, $attachment_id );
        }
        $id = get_post_meta( $post_id, self::META_ID, true );
        $url = self::record_url( $id );
        if ( ! $url ) { $url = self::configured_url( 'form' ); $url = $url ? add_query_arg( array( 'record' => $id, 'gob_saved' => 1 ), $url ) : admin_url( 'post.php?post=' . $post_id . '&action=edit' ); }
        wp_safe_redirect( $url ); exit;
    }
}
Guild_Census::boot();
register_activation_hook( __FILE__, array( 'Guild_Census', 'activate' ) );
