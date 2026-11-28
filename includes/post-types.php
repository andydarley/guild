<?php
defined( 'ABSPATH' ) || exit;

trait Guild_Post_Types {
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
}
