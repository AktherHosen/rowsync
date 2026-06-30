<?php
/**
 * Plugin Name: Rowsync
 * Plugin URI:  https://github.com/yourusername/rowsync
 * Description: Adds an export button to each WooCommerce order row that appends order data directly to a Google Sheet via the Google Sheets API — no third-party connector service required.
 * Version:     1.0.0
 * Author:      Your Name
 * Author URI:  https://github.com/yourusername
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: rowsync
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * Rowsync is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * any later version.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'ROWSYNC_VERSION', '1.0.0' );

// ==========================================
// 1. SETTINGS PAGE
// ==========================================
add_action( 'admin_menu', 'rowsync_register_settings_page' );
function rowsync_register_settings_page() {
    add_options_page(
        __( 'Rowsync Settings', 'rowsync' ),
        __( 'Rowsync', 'rowsync' ),
        'manage_woocommerce',
        'rowsync-settings',
        'rowsync_settings_page_html'
    );
}

function rowsync_settings_page_html() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) return;

    if ( isset( $_POST['rowsync_save_settings'] ) && check_admin_referer( 'rowsync_save_nonce' ) ) {
        update_option( 'rowsync_sheet_id', sanitize_text_field( wp_unslash( $_POST['rowsync_sheet_id'] ?? '' ) ) );
        update_option( 'rowsync_sheet_tab', sanitize_text_field( wp_unslash( $_POST['rowsync_sheet_tab'] ?? '' ) ) );
        update_option( 'rowsync_debug_mode', isset( $_POST['rowsync_debug_mode'] ) ? 1 : 0 );

        // Configurable courier meta keys -- this is what makes the plugin work for ANY courier setup
        update_option( 'rowsync_courier_meta_keys', sanitize_text_field( wp_unslash( $_POST['rowsync_courier_meta_keys'] ?? '' ) ) );

        // Only overwrite the stored key if something new was pasted in (textarea shown blank on reload for security)
        if ( ! empty( $_POST['rowsync_service_account_json'] ) ) {
            $json_raw = wp_unslash( $_POST['rowsync_service_account_json'] );
            $decoded  = json_decode( $json_raw, true );
            if ( is_array( $decoded ) && isset( $decoded['private_key'], $decoded['client_email'] ) ) {
                update_option( 'rowsync_service_account_json', $json_raw, false ); // false = don't autoload, it's sensitive
                delete_transient( 'rowsync_google_access_token' ); // force fresh token next export
                echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved! New service account key stored.', 'rowsync' ) . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>' . esc_html__( "That didn't look like a valid service account JSON file (missing private_key or client_email). Key was NOT saved — please re-paste it.", 'rowsync' ) . '</p></div>';
            }
        } else {
            echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved!', 'rowsync' ) . '</p></div>';
        }
    }

    $sheet_id     = get_option( 'rowsync_sheet_id', '' );
    $sheet_tab    = get_option( 'rowsync_sheet_tab', 'Sheet1' );
    $debug_mode   = get_option( 'rowsync_debug_mode', 0 );
    $courier_keys = get_option( 'rowsync_courier_meta_keys', '' );
    $has_key      = ! empty( get_option( 'rowsync_service_account_json', '' ) );
    $client_email = '';
    if ( $has_key ) {
        $decoded      = json_decode( get_option( 'rowsync_service_account_json' ), true );
        $client_email = $decoded['client_email'] ?? '';
    }
    ?>
<div class="wrap">
    <h1><?php esc_html_e( 'Rowsync Settings', 'rowsync' ); ?></h1>
    <form method="POST" action="">
        <?php wp_nonce_field( 'rowsync_save_nonce' ); ?>
        <table class="form-table">
            <tr>
                <th scope="row"><label
                        for="rowsync_sheet_id"><?php esc_html_e( 'Google Sheet ID', 'rowsync' ); ?></label></th>
                <td>
                    <input type="text" id="rowsync_sheet_id" name="rowsync_sheet_id"
                        value="<?php echo esc_attr( $sheet_id ); ?>" class="regular-text" required>
                    <p class="description">
                        <?php esc_html_e( 'From your sheet URL: docs.google.com/spreadsheets/d/THIS_PART/edit', 'rowsync' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label
                        for="rowsync_sheet_tab"><?php esc_html_e( 'Sheet/Tab Name', 'rowsync' ); ?></label></th>
                <td>
                    <input type="text" id="rowsync_sheet_tab" name="rowsync_sheet_tab"
                        value="<?php echo esc_attr( $sheet_tab ); ?>" class="regular-text" required>
                    <p class="description">
                        <?php esc_html_e( 'The tab name at the bottom of your sheet, e.g. Sheet1', 'rowsync' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label
                        for="rowsync_courier_meta_keys"><?php esc_html_e( 'Courier Consignment ID Meta Key(s)', 'rowsync' ); ?></label>
                </th>
                <td>
                    <input type="text" id="rowsync_courier_meta_keys" name="rowsync_courier_meta_keys"
                        value="<?php echo esc_attr( $courier_keys ); ?>" class="regular-text"
                        placeholder="e.g. ptc_consignment_id, _steadfast_consignment_id">
                    <p class="description">
                        <?php esc_html_e( 'Comma-separated list of order meta keys your courier plugin(s) use to store the consignment/tracking ID. Rowsync checks each one in order and uses the first non-empty value found.', 'rowsync' ); ?>
                        <br><?php esc_html_e( 'Common examples: ptc_consignment_id (Pathao Courier), _steadfast_consignment_id (Steadfast). Check your courier plugin\'s documentation or database if unsure.', 'rowsync' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label
                        for="rowsync_service_account_json"><?php esc_html_e( 'Service Account JSON Key', 'rowsync' ); ?></label>
                </th>
                <td>
                    <textarea id="rowsync_service_account_json" name="rowsync_service_account_json"
                        class="large-text code" rows="8"
                        placeholder="<?php echo $has_key ? esc_attr__( 'A key is already saved. Paste a new one only to replace it.', 'rowsync' ) : esc_attr__( 'Paste the full contents of your downloaded service account JSON file here', 'rowsync' ); ?>"></textarea>
                    <p class="description">
                        <?php if ( $has_key ) : ?>
                        <span style="color:#2271b1;">&#10003; <?php
                                    /* translators: %s: service account email address */
                                    printf( esc_html__( 'A service account key is currently saved (%s).', 'rowsync' ), '<code>' . esc_html( $client_email ) . '</code>' );
                                ?></span>
                        <?php esc_html_e( 'Leave this blank to keep it, or paste a new JSON file to replace it.', 'rowsync' ); ?>
                        <?php else : ?>
                        <?php esc_html_e( 'No key saved yet. Paste the full contents of your downloaded .json file.', 'rowsync' ); ?>
                        <?php endif; ?>
                        <br><?php
                                $email_display = $has_key ? esc_html( $client_email ) : esc_html__( 'the client_email from your JSON key', 'rowsync' );
                                /* translators: %s: service account email or placeholder text */
                                printf( esc_html__( "Don't forget: share your Google Sheet with %s as Editor.", 'rowsync' ), '<code>' . $email_display . '</code>' );
                            ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label
                        for="rowsync_debug_mode"><?php esc_html_e( 'Enable Debug Mode', 'rowsync' ); ?></label></th>
                <td>
                    <label><input type="checkbox" id="rowsync_debug_mode" name="rowsync_debug_mode" value="1"
                            <?php checked( 1, $debug_mode ); ?>>
                        <?php esc_html_e( 'Show exact API errors and JSON payload on screen', 'rowsync' ); ?></label>
                    <p class="description">
                        <?php esc_html_e( 'Turn this on if the sheet is still empty. It will show you exactly what the API is rejecting.', 'rowsync' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <p class="submit"><input type="submit" name="rowsync_save_settings" class="button-primary"
                value="<?php esc_attr_e( 'Save Settings', 'rowsync' ); ?>"></p>
    </form>

    <hr>
    <h2><?php esc_html_e( 'Your Google Sheet Row 1 MUST match these exact headers, in this order:', 'rowsync' ); ?></h2>
    <p style="background:#f0f0f1; padding:10px; font-family:monospace;">
        Order ID | Date | Customer Name | Phone | Address | Items &amp; Quantity | Order Notes | Amount | Delivery
        Charge | Courier ID
    </p>
    <p><?php esc_html_e( 'This plugin appends rows by column position (A:J), so the header text itself does not need to match exactly — but the column order does.', 'rowsync' ); ?>
    </p>
</div>
<?php
}

// ==========================================
// 2. ADD THE EXPORT BUTTON (HPOS & LEGACY)
// ==========================================
add_filter( 'manage_edit-shop_order_columns', 'rowsync_add_export_column' );
add_action( 'manage_shop_order_posts_custom_column', 'rowsync_render_export_column', 10, 2 );
add_filter( 'manage_woocommerce_page_wc-orders_columns', 'rowsync_add_export_column' );
add_action( 'manage_woocommerce_page_wc-orders_custom_column', 'rowsync_render_export_column_hpos', 10, 2 );

function rowsync_add_export_column( $columns ) {
    $columns['rowsync_export'] = __( 'Export to Sheet', 'rowsync' );
    return $columns;
}

function rowsync_render_export_column( $column, $post_id ) {
    if ( $column === 'rowsync_export' ) rowsync_output_button( $post_id );
}

function rowsync_render_export_column_hpos( $column, $order ) {
    if ( $column === 'rowsync_export' ) rowsync_output_button( $order->get_id() );
}

function rowsync_output_button( $order_id ) {
    if ( ! current_user_can( 'edit_shop_orders' ) ) return;
    $nonce = wp_create_nonce( 'rowsync_export_nonce_' . $order_id );
    $url   = admin_url( 'admin-post.php?action=rowsync_send_to_sheet&order_id=' . absint( $order_id ) . '&_wpnonce=' . $nonce );
    echo '<a href="' . esc_url( $url ) . '" class="button button-primary rowsync-export-btn">' . esc_html__( 'Export', 'rowsync' ) . '</a>';
}

// ==========================================
// 3. GOOGLE AUTH HELPERS (pure PHP, no Composer)
// ==========================================

/**
 * Base64url encode (Google/JWT use URL-safe base64, no padding)
 */
function rowsync_base64url_encode( $data ) {
    return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
}

/**
 * Build and sign a JWT for Google's OAuth2 service-account flow,
 * then exchange it for a short-lived access token.
 * Returns the access token string, or a WP_Error on failure.
 */
function rowsync_get_google_access_token() {
    // Reuse cached token if still valid (Google tokens last 1hr; we cache 55 min to be safe)
    $cached = get_transient( 'rowsync_google_access_token' );
    if ( $cached ) return $cached;

    $json_raw = get_option( 'rowsync_service_account_json', '' );
    if ( empty( $json_raw ) ) {
        return new WP_Error( 'no_key', __( 'No service account JSON key saved in plugin settings.', 'rowsync' ) );
    }

    $creds = json_decode( $json_raw, true );
    if ( ! is_array( $creds ) || empty( $creds['private_key'] ) || empty( $creds['client_email'] ) || empty( $creds['token_uri'] ) ) {
        return new WP_Error( 'bad_key', __( 'Service account JSON is missing required fields (private_key, client_email, token_uri).', 'rowsync' ) );
    }

    $now    = time();
    $header = [ 'alg' => 'RS256', 'typ' => 'JWT' ];
    $claims = [
        'iss'   => $creds['client_email'],
        'scope' => 'https://www.googleapis.com/auth/spreadsheets',
        'aud'   => $creds['token_uri'],
        'exp'   => $now + 3600,
        'iat'   => $now,
    ];

    $segments = [
        rowsync_base64url_encode( wp_json_encode( $header ) ),
        rowsync_base64url_encode( wp_json_encode( $claims ) ),
    ];
    $signing_input = implode( '.', $segments );

    $private_key = openssl_pkey_get_private( $creds['private_key'] );
    if ( ! $private_key ) {
        return new WP_Error( 'bad_private_key', __( 'Could not parse the private_key from the service account JSON.', 'rowsync' ) );
    }

    $signature = '';
    $signed_ok = openssl_sign( $signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256 );

    if ( ! $signed_ok ) {
        return new WP_Error( 'sign_fail', __( 'Failed to sign JWT with the provided private key.', 'rowsync' ) );
    }

    $jwt = $signing_input . '.' . rowsync_base64url_encode( $signature );

    // Exchange JWT for access token
    $response = wp_remote_post( $creds['token_uri'], [
        'method'  => 'POST',
        'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
        'body'    => [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ],
        'timeout' => 15,
    ] );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code < 200 || $code >= 300 || empty( $body['access_token'] ) ) {
        $error_detail = isset( $body['error_description'] ) ? $body['error_description'] : wp_remote_retrieve_body( $response );
        /* translators: 1: HTTP status code, 2: error detail from Google */
        return new WP_Error( 'token_fail', sprintf( __( 'Google rejected the token request (HTTP %1$s): %2$s', 'rowsync' ), $code, $error_detail ) );
    }

    $access_token = $body['access_token'];
    $expires_in   = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3600;

    // Cache for slightly less than the actual expiry
    set_transient( 'rowsync_google_access_token', $access_token, max( 60, $expires_in - 300 ) );

    return $access_token;
}

/**
 * Append one row to the configured Google Sheet.
 * $row_values must be a plain array in column A->... order.
 * Returns true on success, or a WP_Error on failure.
 */
function rowsync_append_row_to_sheet( $row_values ) {
    $sheet_id  = get_option( 'rowsync_sheet_id', '' );
    $sheet_tab = get_option( 'rowsync_sheet_tab', 'Sheet1' );

    if ( empty( $sheet_id ) ) {
        return new WP_Error( 'no_sheet_id', __( 'No Google Sheet ID configured in plugin settings.', 'rowsync' ) );
    }

    $token = rowsync_get_google_access_token();
    if ( is_wp_error( $token ) ) {
        return $token;
    }

    $range = rawurlencode( $sheet_tab . '!A:J' );
    $url   = "https://sheets.googleapis.com/v4/spreadsheets/{$sheet_id}/values/{$range}:append"
           . '?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS';

    $body = wp_json_encode( [
        'values' => [ array_values( $row_values ) ],
    ] );

    $response = wp_remote_post( $url, [
        'method'  => 'POST',
        'headers' => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ],
        'body'    => $body,
        'timeout' => 15,
    ] );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code      = wp_remote_retrieve_response_code( $response );
    $resp_body = wp_remote_retrieve_body( $response );

    if ( $code < 200 || $code >= 300 ) {
        /* translators: 1: HTTP status code, 2: response body from Google */
        return new WP_Error( 'append_fail', sprintf( __( 'Google Sheets API returned HTTP %1$s: %2$s', 'rowsync' ), $code, $resp_body ) );
    }

    return true;
}

// ==========================================
// 4. HANDLE EXPORT BUTTON CLICK
// ==========================================
add_action( 'admin_post_rowsync_send_to_sheet', 'rowsync_process_export' );
function rowsync_process_export() {
    if ( ! isset( $_GET['order_id'] ) || ! isset( $_GET['_wpnonce'] ) ) {
        wp_die( esc_html__( 'Invalid request.', 'rowsync' ) );
    }

    $order_id = absint( $_GET['order_id'] );
    $nonce    = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );

    if ( ! wp_verify_nonce( $nonce, 'rowsync_export_nonce_' . $order_id ) ) {
        wp_die( esc_html__( 'Security check failed.', 'rowsync' ) );
    }
    if ( ! current_user_can( 'edit_shop_orders' ) ) {
        wp_die( esc_html__( 'You do not have permission to do this.', 'rowsync' ) );
    }

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        wp_die( esc_html__( 'Order not found.', 'rowsync' ) );
    }

    $is_debug = get_option( 'rowsync_debug_mode', 0 );

    // 1. Gather order line items
    $items_string = '';
    foreach ( $order->get_items() as $item ) {
        $items_string .= $item->get_name() . ' x' . $item->get_quantity() . '; ';
    }

    // 2. Gather order notes
    $notes        = wc_get_order_notes( [ 'order_id' => $order_id ] );
    $notes_string = '';
    foreach ( $notes as $note ) {
        $notes_string .= wp_strip_all_tags( $note->content ) . ' | ';
    }

    // 3. Resolve courier consignment ID from user-configured meta keys
    $courier_id        = '';
    $courier_keys_raw  = get_option( 'rowsync_courier_meta_keys', '' );
    $courier_keys      = array_filter( array_map( 'trim', explode( ',', $courier_keys_raw ) ) );
    foreach ( $courier_keys as $meta_key ) {
        $value = $order->get_meta( $meta_key );
        if ( ! empty( $value ) ) {
            $courier_id = $value;
            break;
        }
    }

    // Order matters here -- must match your sheet's column order A->J
    $data = [
        'Order ID'         => (string) $order_id,
        'Date'             => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : '',
        'Customer Name'    => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
        'Phone'            => $order->get_billing_phone(),
        'Address'          => trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() . ', ' . $order->get_billing_city() ),
        'Items & Quantity' => trim( $items_string, '; ' ),
        'Order Notes'      => trim( $notes_string, ' |' ),
        'Amount'           => (string) $order->get_total(),
        'Delivery Charge'  => (string) $order->get_shipping_total(),
        'Courier ID'       => (string) $courier_id,
    ];

    // 4. Send directly to Google Sheets
    $result = rowsync_append_row_to_sheet( $data );

    // If Debug Mode is ON, show exactly what happened
    if ( $is_debug ) {
        echo '<div style="font-family:monospace; padding:20px; background:#fff; border:1px solid #ccc; margin:20px;">';
        echo '<h2>' . esc_html__( 'Google Sheets API Debug Output', 'rowsync' ) . '</h2>';
        echo '<h3>' . esc_html__( '1. Result:', 'rowsync' ) . '</h3><pre>' . ( is_wp_error( $result ) ? esc_html( $result->get_error_message() ) : 'SUCCESS' ) . '</pre>';
        echo '<h3>' . esc_html__( '2. Row Sent:', 'rowsync' ) . '</h3><pre>' . esc_html( wp_json_encode( array_values( $data ), JSON_PRETTY_PRINT ) ) . '</pre>';
        echo '<a href="' . esc_url( admin_url( 'edit.php?post_type=shop_order' ) ) . '" style="display:inline-block; padding:10px; background:#2271b1; color:#fff; text-decoration:none;">' . esc_html__( 'Back to Orders', 'rowsync' ) . '</a>';
        echo '</div>';
        exit;
    }

    // Normal flow (Debug OFF)
    $referer = wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=shop_order' );
    if ( is_wp_error( $result ) ) {
        wp_safe_redirect( add_query_arg( 'rowsync_error', rawurlencode( $result->get_error_message() ), $referer ) );
    } else {
        wp_safe_redirect( add_query_arg( 'rowsync_success', '1', $referer ) );
    }
    exit;
}

// ==========================================
// 5. SHOW NOTICES
// ==========================================
add_action( 'admin_notices', 'rowsync_export_notices' );
function rowsync_export_notices() {
    if ( isset( $_GET['rowsync_success'] ) && $_GET['rowsync_success'] == '1' ) {
        echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html__( 'Success!', 'rowsync' ) . '</strong> ' . esc_html__( 'Order data appended to your Google Sheet.', 'rowsync' ) . '</p></div>';
    }
    if ( isset( $_GET['rowsync_error'] ) ) {
        $err = sanitize_text_field( wp_unslash( $_GET['rowsync_error'] ) );
        echo '<div class="notice notice-error is-dismissible"><p><strong>' . esc_html__( 'Export Failed:', 'rowsync' ) . '</strong> ' . esc_html( $err ) . ' &mdash; ';
        printf(
            /* translators: %s: link to plugin settings page */
            esc_html__( 'Turn on Debug Mode in %s for more detail.', 'rowsync' ),
            '<a href="' . esc_url( admin_url( 'options-general.php?page=rowsync-settings' ) ) . '">' . esc_html__( 'plugin settings', 'rowsync' ) . '</a>'
        );
        echo '</p></div>';
    }
}

// =A=========================================
// 6. ACTIVATION CHECK
// ==========================================
register_activation_hook( __FILE__, 'rowsync_activation_check' );
function rowsync_activation_check() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die( esc_html__( 'Rowsync requires WooCommerce to be installed and active.', 'rowsync' ) );
    }
    if ( ! function_exists( 'openssl_sign' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die( esc_html__( 'Rowsync requires the PHP OpenSSL extension, which is not enabled on this server.', 'rowsync' ) );
    }
}


// ==========================================
// 7. HPOS COMPATIBILITY DECLARATION (Required by WooCommerce)
// ==========================================
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );