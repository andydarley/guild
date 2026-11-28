<?php
/**
 * Plugin Name: Guild
 * Description: Private, WordPress-based catalogue with real frontend Pages and a simple mobile entry form.
 * Version: 0.1.5
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Author: Guild of Bears
 * Text Domain: guild
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }


require_once __DIR__ . '/includes/post-types.php';
require_once __DIR__ . '/includes/taxonomies.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/frontend.php';
require_once __DIR__ . '/includes/forms.php';
require_once __DIR__ . '/includes/import-export.php';

final class Guild_Census {
    use Guild_Post_Types, Guild_Taxonomies, Guild_Admin, Guild_Frontend, Guild_Forms, Guild_Import_Export;
    const VERSION = '0.1.5';
    const CPT = 'gob_item';
    const OPT_PAGES = 'gob_census_pages';
    const OPT_COUNTER = 'gob_census_counter';
    const META_ID = '_gob_census_id';
    public static function boot() {
        add_action( 'init', array( __CLASS__, 'register_content' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
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
        foreach ( array( 'upload', 'batch', 'cancel', 'report', 'export' ) as $operation ) {
            add_action( 'admin_post_guild_io_' . $operation, array( __CLASS__, 'io_' . $operation ) );
        }
    }
    public static function activate() {
        self::register_content();
        add_option( self::OPT_COUNTER, '0', '', false );
        flush_rewrite_rules();
    }
    public static function enqueue_assets() {
        if ( is_admin() || ! is_singular( 'page' ) ) { return; }
        $pages = get_option( self::OPT_PAGES, array() );
        if ( ! in_array( get_queried_object_id(), array_map( 'absint', array_values( (array) $pages ) ), true ) ) { return; }
        wp_enqueue_style( 'guild', plugins_url( 'assets/css/guild.css', __FILE__ ), array(), self::VERSION );
        if ( get_queried_object_id() === (int) ( $pages['form'] ?? 0 ) ) {
            wp_enqueue_script( 'guild-form', plugins_url( 'assets/js/guild-form.js', __FILE__ ), array(), self::VERSION, true );
        }
    }
}
Guild_Census::boot();
register_activation_hook( __FILE__, array( 'Guild_Census', 'activate' ) );
