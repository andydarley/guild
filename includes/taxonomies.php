<?php
defined( 'ABSPATH' ) || exit;

trait Guild_Taxonomies {
    private static function taxonomy_for_tab( $tab ) {
        $map = array( 'types' => 'gob_type', 'sets' => 'gob_set', 'locations' => 'gob_location' );
        return isset( $map[$tab] ) ? $map[$tab] : '';
    }
    private static function term_path( $term, $tax ) {
        $ancestors = array_reverse( get_ancestors( $term->term_id, $tax, 'taxonomy' ) );
        $names = array();
        foreach ( $ancestors as $ancestor ) { $p = get_term( $ancestor, $tax ); if ( $p && ! is_wp_error( $p ) ) { $names[] = $p->name; } }
        $names[] = $term->name;
        return implode( ' → ', $names );
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
}
