<?php
defined( 'ABSPATH' ) || exit;

trait Guild_Frontend {
    private static function configured_url( $key ) {
        $pages = get_option( self::OPT_PAGES, array() );
        $id = isset( $pages[$key] ) ? absint( $pages[$key] ) : 0;
        return $id && get_post_status( $id ) === 'publish' ? get_permalink( $id ) : '';
    }
    private static function record_url( $record_id ) {
        $url = self::configured_url( 'entry' );
        return $url ? add_query_arg( 'record', $record_id, $url ) : '';
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
}
