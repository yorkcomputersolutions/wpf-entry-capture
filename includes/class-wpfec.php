<?php

// Exit if accessed directly
if( ! defined( 'ABSPATH' ) ) {
    die;
}

class WPFEC {
    public static function init() {
        $class = __CLASS__;
        new $class;
    }

    public function __construct() {
        $this->set_includes();
        $this->maybe_install();
        $this->add_hooks();
    }

    public function set_includes() {
        require_once WPFEC_PLUGIN_PATH . 'includes/class-wpfec-post.php';
    }

    public function maybe_install() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Create the wpfec_entries table
        $table_name = $wpdb->prefix . 'wpfec_entries';

        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id BIGINT(20) NOT NULL,
            status VARCHAR(255) NOT NULL,
            referer TEXT NOT NULL,
            date_created DATETIME NOT NULL,
            UNIQUE KEY id (id)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );


        // Create the wpfec_entry_meta table
        $table_name = $wpdb->prefix . 'wpfec_entry_meta';

        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            entry_id BIGINT(20) NOT NULL,
            meta_key VARCHAR(255) NOT NULL,
            meta_value LONGTEXT NOT NULL,
            UNIQUE KEY id (id)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }

    public function add_hooks() {
        add_action( 'wpforms_process_complete', array( $this, 'process_entry' ), 5, 4 );

        add_filter( 'wpforms_admin_dashboardwidget', '__return_false' );

        add_action( 'admin_menu', array( $this, 'add_form_entries_menu_page' ) );

        add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
    }

    public function process_entry( $form_fields, $entry, $form_data, $entry_id ) {
        global $wpdb;
        $form_id = $form_data['id'];
        $entry_data = array(
            'form_id'         => $form_id,
            'status'          => 'publish',
            'referer'         => $_SERVER['HTTP_REFERER'],
            'date_created'    => current_time( 'mysql' )
        );
    
        // Insert into wpfec_entries custom table.
        $success = $wpdb->insert( $wpdb->prefix . 'wpfec_entries', $entry_data );
        $entry_id = $wpdb->insert_id;
    
        // Create meta data.
        if ( $entry_id ) {
            foreach ( $form_fields as $field ) {
                $field = apply_filters( 'wpfec_process_entry_field', $field, $form_data, $entry_id );
                if ( isset( $field['value'] ) && '' !== $field['value'] ) {
                    $field_value    = is_array( $field['value'] ) ? serialize( $field['value'] ) : $field['value'];
                    $entry_metadata = array(
                        'entry_id'   => $entry_id,
                        'meta_key'   => $field['name'],
                        'meta_value' => $field_value,
                    );
                    // Insert entry meta.
                    $wpdb->insert( $wpdb->prefix . 'wpfec_entry_meta', $entry_metadata );
                }
            }
        }
    }

    public function add_form_entries_menu_page() {
        add_menu_page(
            __( 'Form Entries', 'wpfec' ),
            'Form Entries',
            'manage_options',
            'wpfec-form-entries',
            array( $this, 'render_form_entries_menu_page' ),
            'dashicons-feedback'
        );
    }

    public function render_form_entries_menu_page() {
        $view = isset( $_GET['view'] ) ? sanitize_text_field( $_GET['view'] ) : '';
        ?>
        <div class="wrap">
            <h2>Form Entries</h2>

            <?php
            switch ( $view ) {
                case 'view-entries': {
                    $form_id = isset( $_GET['form'] ) ? intval( sanitize_text_field( $_GET['form'] ) ) : '';


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


                    $back_url = add_query_arg( array(
                        'page' => 'wpfec-form-entries'
                    ), admin_url( 'admin.php' ) );
                    ?>
                    <a class="wpfec-back-button button button-secondary" href="<?php echo esc_url( $back_url ); ?>">&lt; Back to Forms</a>

                    <p>You are viewing form entries for form: <i><?php echo esc_html( $form_name ); ?></i>.</p>

                    <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
                        <input name="action" type="hidden" value="wpfec_export_form_entries" />
                        <?php wp_nonce_field( 'wpfec_export_form_entries', 'wpfec_export_form_entries_field' ); ?>

                        <input name="form_id" type="hidden" value="<?php echo esc_attr( $form_id ); ?>" />

                        <button class="button button-primary" type="submit">Export Spreadsheet</button>
                    </form>

                    <br>
                    <?php

                    global $wpdb;
                    $wpfec_entries_table_name = $wpdb->prefix . 'wpfec_entries';
                    $entries = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT * FROM $wpfec_entries_table_name WHERE form_id=%d",
                            array(
                                $form_id
                            )
                        )
                    );

                    if ( count( $entries ) > 0 ) {
                        $column_names = array();

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
                        ?>
                        <table class="wp-list-table widefat fixed striped table-view-list">
                            <thead>
                                <tr>
                                    <td>Date Submitted</td>
                                    <?php
                                    foreach ( $column_names as $column_name ) {
                                        ?>
                                        <td><?php echo esc_html( $column_name ); ?></td>
                                        <?php
                                    }
                                    ?>
                                </tr>
                            </thead>

                            <tbody>
                                <?php
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

                                    ?>
                                    <tr>
                                        <td>
                                            <?php
                                            $view_entry_url = add_query_arg( array(
                                                'page' => 'wpfec-form-entries',
                                                'view' => 'view-entry',
                                                'form' => urlencode( $form_id ),
                                                'entry' => urlencode( $entry->id )
                                            ), admin_url( 'admin.php' ) );
                                            ?>
                                            <a class="button button-secondary" href="<?php echo esc_url( $view_entry_url ); ?>">View</a>
                                        </td>

                                        <td>
                                            <?php
                                            $entry_date_created = DateTime::createFromFormat( 'Y-m-d H:i:s', $entry->date_created )->format( 'm/d/y g:ia' );

                                            echo esc_html( $entry_date_created );
                                            ?>
                                        </td>

                                        <?php
                                        foreach ( $column_names as $column_name ) {
                                            ?>
                                            <td><?php echo esc_html( $entry_data[$column_name] ); ?></td>
                                            <?php
                                        }
                                        ?>
                                    </tr>
                                    <?php
                                }
                                ?>
                            </tbody>
                        </table>
                        <?php
                    }
                    else {
                        ?>
                        <p>No entries have been submitted yet for this form.</p>
                        <?php
                    }

                    break;
                }

                case 'view-entry': {
                    $form_id = isset( $_GET['form'] ) ? intval( sanitize_text_field( $_GET['form'] ) ) : '';
                    $entry_id = isset( $_GET['entry'] ) ? intval( sanitize_text_field( $_GET['entry'] ) ) : ''; 

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


                    // Get the entry specified
                    global $wpdb;
                    $wpfec_entries_table_name = $wpdb->prefix . 'wpfec_entries';
                    $entry = $wpdb->get_row(
                        $wpdb->prepare(
                            "SELECT * FROM $wpfec_entries_table_name WHERE id=%d",
                            array(
                                $entry_id
                            )
                        )
                    );

                    if ( is_null( $entry ) ) {
                        ?>
                        <p>Failed to load entry: the entry specified doesn't exist.</p>
                        <?php
                        wp_die();
                    }


                    // Get column names
                    $column_names = array();

                    $wpfec_entry_meta_table_name = $wpdb->prefix . 'wpfec_entry_meta';

                    $entry_meta = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT * FROM $wpfec_entry_meta_table_name WHERE entry_id=%d",
                            array(
                                $entry_id
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

                    // Get entry values
                    $wpfec_entry_meta_table_name = $wpdb->prefix . 'wpfec_entry_meta';
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


                    $back_url = add_query_arg( array(
                        'page' => 'wpfec-form-entries',
                        'view' => 'view-entries',
                        'form' => urlencode( $form_id )
                    ), admin_url( 'admin.php' ) );
                    ?>
                    <a class="wpfec-back-button button button-secondary" href="<?php echo esc_url( $back_url ); ?>">&lt; Back to All Entries</a>

                    <h3>View Form Entry</h3>
                    <p>Form entry for form: <i><?php echo esc_html( $form_name ); ?></i></p>
                    <input id="wpfec-btn-print" class="wpfec-print-button button button-secondary" type="button" value="Print Entry" onclick="window.print();" />

                    <table class="wpfec-entry-table">
                        <tbody>
                            <?php
                            $entry_date_created = DateTime::createFromFormat( 'Y-m-d H:i:s', $entry->date_created )->format( 'm/d/y g:ia' );
                            ?>
                            <tr>
                                <td><b>Date Submitted</b></td>
                                <td><?php echo esc_html( $entry_date_created ); ?></td>
                            </tr>

                            <?php
                            foreach ( $column_names as $column_name ) {
                                ?>
                                <tr>
                                    <td><b><?php echo esc_html( $column_name ); ?></b></td>
                                    <td><?php echo esc_html( $entry_data[$column_name] ); ?></td>
                                </td>
                                <?php
                            }
                            ?>
                        </tbody>
                    </table>
                    <?php

                    break;
                }

                default: {
                    ?>
                    <p>Please select a form to view submissions for it.</p>
                    <?php
                    $args = array(
                        'post_type' => 'wpforms',
                        'posts_per_page' => -1
                    );
                    $query = new WP_Query( $args );
                    $posts = $query->posts;

                    if ( count( $posts ) > 0 ) {
                        ?>
                        <table class="wp-list-table widefat fixed striped table-view-list">
                            <tbody>
                                <?php
                                foreach ( $posts as $post ) {
                                    $view_form_entries_url = add_query_arg( array(
                                        'page' => 'wpfec-form-entries',
                                        'view' => 'view-entries',
                                        'form' => urlencode( $post->ID )
                                    ), admin_url( 'admin.php' ) );
                                    ?>
                                    <tr>
                                        <td><a href="<?php echo esc_url( $view_form_entries_url ); ?>"><?php echo esc_html( $post->post_title ); ?></a></td>
                                    </tr>
                                    <?php
                                }
                                ?>
                            </tbody>
                        </table>
                        <?php
                    }
                    else {
                        ?>
                        <p>No forms have been added yet.</p>
                        <?php
                    }

                    break;
                }
            }
            ?>
        </div>
        <?php
    }

    public function admin_enqueue_scripts() {
        // Only enqueue the print style sheet on the WPFEC form entries page
        $current_screen = get_current_screen();

        if ( isset( $current_screen->base ) && $current_screen->base === 'toplevel_page_wpfec-form-entries' ) {
            wp_enqueue_style(
                'wpfec-print',
                WPFEC_PLUGIN_URL . 'includes/admin/assets/css/wpfec-admin.css',
            );
        }
    }
}
