<?php

defined( 'ABSPATH' ) || exit;

/**
 * WPFEC_POST
 */
class WPFEC_POST {
    public static function init() {
        self::add_post_events();
    }

    public static function add_post_events() {
        $post_events = array(
            'export_form_entries'
        );

        foreach ( $post_events as $post_event ) {
            add_action( 'admin_post_wpfec_' . $post_event, array( __CLASS__, $post_event ) );
        }
    }

    public static function export_form_entries() {
        if ( 
            ! isset( $_POST['wpfec_export_form_entries_field'] ) 
            || ! wp_verify_nonce( $_POST['wpfec_export_form_entries_field'], 'wpfec_export_form_entries' ) 
        ) {
            print 'Sorry, your nonce did not verify.';
            die();
            
        } else {
            if ( ! class_exists( 'SimpleXLSXGen' ) ) {
                require_once WPFEC_PLUGIN_PATH . 'includes/lib/simplexlsxgen/src/SimpleXLSXGen.php';
            }

            $form_id = isset( $_POST['form_id'] ) ? intval( sanitize_text_field( $_POST['form_id'] ) ) : '';

            $data = array();

            global $wpdb;

            // Get entries for this form
            $wpfec_entries_table_name = $wpdb->prefix . 'wpfec_entries';
            $entries = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $wpfec_entries_table_name WHERE form_id=%d",
                    array(
                        $form_id
                    )
                )
            );

            // Define the columns
            $column_names = array();

            $column_names[] = 'Date Submitted'; // Add this default column

            $wpfec_entry_meta_table_name = $wpdb->prefix . 'wpfec_entry_meta';
            foreach ( $entries as $entry ) {
                $entry_meta = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT * FROM $wpfec_entry_meta_table_name WHERE entry_id=%d",
                        array(
                            $entry->id
                        )
                    )
                );

                if ( count( $entry_meta ) > 0 ) {
                    foreach ( $entry_meta as $entry_meta_result ) {
                        if ( ! in_array( $entry_meta_result->meta_key, $column_names ) ) {
                            $column_names[] = $entry_meta_result->meta_key;
                        }
                    }
                }
            }

            $data[] = $column_names;



            // Add data and map it by column
            $wpfec_entry_meta_table_name = $wpdb->prefix . 'wpfec_entry_meta';
            foreach ( $entries as $entry ) {
                $entry_data = array();

                $entry_meta = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT * FROM $wpfec_entry_meta_table_name WHERE entry_id=%d",
                        array(
                            $entry->id
                        )
                    )
                );

                foreach ( $entry_meta as $entry_meta_result ) {
                    $entry_data[$entry_meta_result->meta_key] = $entry_meta_result->meta_value;
                }


                $row_to_add = array();

                foreach ( $column_names as $column_name ) {
                    if ( $column_name === 'Date Submitted' ) {
                        // Add column for date
                        $entry_date_created = DateTime::createFromFormat( 'Y-m-d H:i:s', $entry->date_created )->format( 'm/d/y g:ia' );
                        $row_to_add[] = esc_html( $entry_date_created );
                    }
                    else {
                        $row_to_add[] = esc_html( $entry_data[$column_name] );
                    }
                }

                $data[] = $row_to_add;
            }



            // Get form name from form ID
            $args = array(
                'post_type' => 'wpforms',
                'posts_per_page' => -1
            );
            $query = new WP_Query( $args );
            $posts = $query->posts;

            $form_name = '';
            foreach ( $posts as $post ) {
                if ( $post->ID == $form_id ) {
                    $form_name = $post->post_title;
                }
            }

            // Format the form name to be used as in the export file name.
            $form_name = strtolower( $form_name );
            $form_name = str_replace(' ', '-', $form_name );
            $form_name = str_replace('_', '-', $form_name );

            SimpleXLSXGen::fromArray( $data )
                ->downloadAs('wpfec_form_export_' . $form_name . '.xlsx' );
            die;
        }
    }
}

WPFEC_POST::init();


