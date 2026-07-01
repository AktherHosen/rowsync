<?php
/**
 * Plugin Name: Rowsync
 * Plugin URI: https://wordpress.org/plugins/rowsync/
 * Description: Export WooCommerce orders directly to Google Sheets with one click. Automatically creates daily sheets and captures courier tracking IDs. No monthly fees or third-party services required.
 * Version: 1.0.4
 * Requires at least: 5.8
 * Tested up to: 7.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: Akther Hosen
 * Author URI: https://github.com/AktherHosen
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: rowsync
 * Domain Path: /languages
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

define('ROWSYNC_VERSION', '1.0.3');
define('ROWSYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ROWSYNC_PLUGIN_URL', plugin_dir_url(__FILE__));

class Rowsync_Plugin
{
    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->init_hooks();
    }

    private function init_hooks()
    {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_post_rowsync_export_order', array($this, 'handle_export'));
        add_action('admin_notices', array($this, 'admin_notices'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        add_filter('manage_edit-shop_order_columns', array($this, 'add_export_column'));
        add_action('manage_shop_order_posts_custom_column', array($this, 'render_export_column'), 10, 2);

        add_filter('manage_woocommerce_page_wc-orders_columns', array($this, 'add_export_column'));
        add_action('manage_woocommerce_page_wc-orders_custom_column', array($this, 'render_export_column_hpos'), 10, 2);

        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));
    }

    public function declare_hpos_compatibility()
    {
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    }

    public function activate()
    {
        if (!class_exists('WooCommerce')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(esc_html__('Rowsync requires WooCommerce to be installed and active.', 'rowsync'), esc_html__('Plugin Activation Error', 'rowsync'), array('back_link' => true));
        }
        if (!function_exists('openssl_sign')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(esc_html__('Rowsync requires the PHP OpenSSL extension.', 'rowsync'), esc_html__('Plugin Activation Error', 'rowsync'), array('back_link' => true));
        }

        if (!get_option('rowsync_settings')) {
            add_option('rowsync_settings', array(
                'sheet_id' => '',
                'debug_mode' => 0,
                'courier_meta_keys' => 'ptc_consignment_id, steadfast_consignment_id, _steadfast_consignment_id',
            ));
        }
    }

    public function deactivate()
    {
        delete_transient('rowsync_google_access_token');
        delete_transient('rowsync_export_success');
        delete_transient('rowsync_export_error');
    }

    public function add_settings_page()
    {
        add_options_page(
            esc_html__('Rowsync Settings', 'rowsync'),
            esc_html__('Rowsync', 'rowsync'),
            'manage_woocommerce',
            'rowsync-settings',
            array($this, 'render_settings_page')
        );
    }

    public function register_settings()
    {
        register_setting('rowsync_settings_group', 'rowsync_settings', array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_settings'),
        ));
    }

    public function sanitize_settings($input)
    {
        $sanitized = array();

        if (isset($input['sheet_id'])) {
            $sanitized['sheet_id'] = sanitize_text_field($input['sheet_id']);
        }

        if (isset($input['debug_mode'])) {
            $sanitized['debug_mode'] = absint($input['debug_mode']);
        }

        if (isset($input['courier_meta_keys'])) {
            $sanitized['courier_meta_keys'] = sanitize_text_field($input['courier_meta_keys']);
        }

        if (!empty($input['service_account_json'])) {
            $json_raw = trim(wp_unslash($input['service_account_json']));
            $decoded = json_decode($json_raw, true);

            if (is_array($decoded) && isset($decoded['private_key'], $decoded['client_email'])) {
                $sanitized['service_account_json'] = $json_raw;
                delete_transient('rowsync_google_access_token');
            } else {
                add_settings_error(
                    'rowsync_settings',
                    'invalid_json',
                    esc_html__('Invalid service account JSON. Please check and try again.', 'rowsync'),
                    'error'
                );
                $old_settings = get_option('rowsync_settings', array());
                if (isset($old_settings['service_account_json'])) {
                    $sanitized['service_account_json'] = $old_settings['service_account_json'];
                }
            }
        }

        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_key($_POST['_wpnonce']), 'rowsync_settings_group-options')) {
            return $sanitized;
        }

        if (!empty($_FILES['rowsync_json_file']['tmp_name'])) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $tmp_name = wp_unslash($_FILES['rowsync_json_file']['tmp_name']); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            if (!is_uploaded_file($tmp_name)) {
                add_settings_error('rowsync_settings', 'upload_error', esc_html__('File upload verification failed.', 'rowsync'), 'error');
            } else {
                $file_content = file_get_contents($tmp_name);
                $decoded = json_decode($file_content, true);

                if (is_array($decoded) && isset($decoded['private_key'], $decoded['client_email'])) {
                    $sanitized['service_account_json'] = $file_content;
                    delete_transient('rowsync_google_access_token');
                    add_settings_error(
                        'rowsync_settings',
                        'file_upload_success',
                        esc_html__('Service account JSON uploaded successfully via file!', 'rowsync'),
                        'updated'
                    );
                } else {
                    add_settings_error(
                        'rowsync_settings',
                        'invalid_file',
                        esc_html__('The uploaded file is not a valid service account JSON.', 'rowsync'),
                        'error'
                    );
                }
            }
        }

        return $sanitized;
    }

    public function render_settings_page()
    {
        if (!current_user_can('manage_woocommerce'))
            return;

        $settings = get_option('rowsync_settings', array());
        $sheet_id = isset($settings['sheet_id']) ? $settings['sheet_id'] : '';
        $debug_mode = isset($settings['debug_mode']) ? $settings['debug_mode'] : 0;
        $courier_keys = isset($settings['courier_meta_keys']) ? $settings['courier_meta_keys'] : '';
        $has_key = !empty($settings['service_account_json']);
        $client_email = '';

        if ($has_key) {
            $decoded = json_decode($settings['service_account_json'], true);
            $client_email = isset($decoded['client_email']) ? $decoded['client_email'] : '';
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <form method="post" action="options.php" enctype="multipart/form-data">
                <?php settings_fields('rowsync_settings_group'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="rowsync_sheet_id"><?php esc_html_e('Google Sheet ID', 'rowsync'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="rowsync_sheet_id" name="rowsync_settings[sheet_id]"
                                value="<?php echo esc_attr($sheet_id); ?>" class="regular-text" required>
                            <p class="description">
                                <?php /* translators: %s: Google Sheets URL example showing where the Sheet ID appears */ ?>
                                <?php printf(esc_html__('From your sheet URL: %s', 'rowsync'), '<code>docs.google.com/spreadsheets/d/<strong>THIS_PART</strong>/edit</code>'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label
                                for="rowsync_courier_meta_keys"><?php esc_html_e('Courier Meta Keys', 'rowsync'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="rowsync_courier_meta_keys" name="rowsync_settings[courier_meta_keys]"
                                value="<?php echo esc_attr($courier_keys); ?>" class="regular-text"
                                placeholder="ptc_consignment_id, steadfast_consignment_id">
                            <p class="description">
                                <?php esc_html_e('Comma-separated list of meta keys for courier tracking IDs.', 'rowsync'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label><?php esc_html_e('Service Account JSON', 'rowsync'); ?></label></th>
                        <td>
                            <h4><?php esc_html_e('Option 1: Upload JSON File (Recommended)', 'rowsync'); ?></h4>
                            <p class="description">
                                <?php esc_html_e('This bypasses any security plugins that might break pasted text.', 'rowsync'); ?>
                            </p>
                            <input type="file" name="rowsync_json_file" accept=".json" style="margin-bottom: 15px;">

                            <h4><?php esc_html_e('Option 2: Paste JSON Manually', 'rowsync'); ?></h4>
                            <textarea id="rowsync_service_account_json" name="rowsync_settings[service_account_json]"
                                class="large-text code" rows="8"
                                placeholder="<?php echo $has_key ? esc_attr__('Key already saved. Paste new JSON to replace.', 'rowsync') : esc_attr__('Paste your service account JSON here', 'rowsync'); ?>"><?php
                                         if ($has_key) {
                                             echo esc_textarea($settings['service_account_json']);
                                         }
                                         ?></textarea>
                            <p class="description">
                                <?php if ($has_key): ?>
                                    <span style="color: #46b450;">✓
                                        <?php /* translators: %s: Service account email address */ ?>
                                        <?php printf(esc_html__('Saved for: %s', 'rowsync'), '<code>' . esc_html($client_email) . '</code>'); ?></span>
                                <?php endif; ?>
                                <br><?php /* translators: %s: Service account email address */ ?>
                                <?php printf(esc_html__('Share your Google Sheet with %s as Editor.', 'rowsync'), '<code>' . ($has_key ? esc_html($client_email) : 'client_email') . '</code>'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rowsync_debug_mode"><?php esc_html_e('Debug Mode', 'rowsync'); ?></label>
                        </th>
                        <td>
                            <label><input type="checkbox" id="rowsync_debug_mode" name="rowsync_settings[debug_mode]" value="1"
                                    <?php checked($debug_mode, 1); ?>>
                                <?php esc_html_e('Enable debug output', 'rowsync'); ?></label>
                            <p class="description">
                                <?php esc_html_e('Show detailed API errors and payloads when exporting.', 'rowsync'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr>
            <h2><?php esc_html_e('Required Sheet Headers', 'rowsync'); ?></h2>
            <p><?php esc_html_e('Your Google Sheet must have these headers in Row 1 (exact order):', 'rowsync'); ?></p>
            <div
                style="background: #f0f0f1; padding: 15px; font-family: monospace; font-size: 13px; border-left: 4px solid #2271b1;">
                Order ID | Date | Customer Name | Phone | Address | Items &amp; Quantity | Order Notes | Amount (BDT) | Delivery
                Charge | Courier ID
            </div>
        </div>
        <?php
    }

    public function add_export_column($columns)
    {
        $new_columns = array();
        foreach ($columns as $key => $name) {
            $new_columns[$key] = $name;
            if ('order_status' === $key) {
                $new_columns['rowsync_export'] = esc_html__('Export', 'rowsync');
            }
        }
        return $new_columns;
    }

    public function render_export_column($column, $post_id)
    {
        if ('rowsync_export' === $column)
            $this->render_export_button($post_id);
    }

    public function render_export_column_hpos($column, $order)
    {
        if ('rowsync_export' === $column)
            $this->render_export_button($order->get_id());
    }

    private function render_export_button($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order)
            return;

        $is_exported = $order->get_meta('_rowsync_exported');

        if ($is_exported) {
            echo '<span class="button" style="background: #46b450; border-color: #46b450; color: #fff; cursor: default;">' . esc_html__('Exported ✓', 'rowsync') . '</span>';
        } else {
            $nonce = wp_create_nonce('rowsync_export_' . $order_id);
            $url = admin_url('admin-post.php?action=rowsync_export_order&order_id=' . $order_id . '&_wpnonce=' . $nonce);
            echo '<a href="' . esc_url($url) . '" class="button button-primary rowsync-export-btn">' . esc_html__('Export', 'rowsync') . '</a>';
        }
    }

    public function handle_export()
    {
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        if (!$order_id || !isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_key($_GET['_wpnonce']), 'rowsync_export_' . $order_id)) {
            wp_die(esc_html__('Security check failed.', 'rowsync'));
        }
        if (!current_user_can('edit_shop_orders')) {
            wp_die(esc_html__('You do not have permission to export orders.', 'rowsync'));
        }

        $order = wc_get_order($order_id);
        if (!$order)
            wp_die(esc_html__('Order not found.', 'rowsync'));

        if ($order->get_meta('_rowsync_exported')) {
            set_transient('rowsync_export_error', esc_html__('This order has already been exported.', 'rowsync'), 30);
            wp_safe_redirect(wp_get_referer());
            exit;
        }

        $settings = get_option('rowsync_settings', array());
        $is_debug = isset($settings['debug_mode']) ? (bool) $settings['debug_mode'] : false;

        $data = $this->prepare_order_data($order);
        $result = $this->export_to_google_sheets($data, $order);

        if ($is_debug) {
            $this->render_debug_output($result, $data);
            exit;
        }

        if (is_wp_error($result)) {
            set_transient('rowsync_export_error', $result->get_error_message(), 30);
        } else {
            $order->update_meta_data('_rowsync_exported', current_time('mysql'));
            $order->save();
            set_transient('rowsync_export_success', 1, 30);
        }

        wp_safe_redirect(wp_get_referer());
        exit;
    }

    private function prepare_order_data($order)
    {
        $settings = get_option('rowsync_settings', array());
        $courier_keys = isset($settings['courier_meta_keys']) ? $settings['courier_meta_keys'] : '';
        $keys_array = array_filter(array_map('trim', explode(',', $courier_keys)));

        $courier_id = '';
        foreach ($keys_array as $meta_key) {
            $value = $order->get_meta($meta_key);
            if (empty($value))
                $value = get_post_meta($order->get_id(), $meta_key, true);
            if ($value === '-')
                $value = '';
            if (!empty($value)) {
                $courier_id = $value;
                break;
            }
        }

        $items_string = '';
        foreach ($order->get_items() as $item) {
            $items_string .= $item->get_name() . ' x' . $item->get_quantity() . '; ';
        }

        $notes_string = '';


        $customer_note = $order->get_customer_note();
        if (!empty($customer_note)) {
            $notes_string = wp_strip_all_tags($customer_note);
        }

        if (empty($notes_string)) {
            $notes = wc_get_order_notes(array('order_id' => $order->get_id()));
            foreach ($notes as $note) {
                if (isset($note->type) && $note->type !== 'system') {
                    $notes_string .= wp_strip_all_tags($note->content) . ' | ';
                }
            }
            $notes_string = trim($notes_string, ' |');
        }

        return array(
            'Order ID' => (string) $order->get_id(),
            'Date' => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : '',
            'Customer Name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'Phone' => $order->get_billing_phone(),
            'Address' => trim($order->get_billing_address_1() . ' ' . $order->get_billing_address_2() . ', ' . $order->get_billing_city()),
            'Items & Quantity' => trim($items_string, '; '),
            'Order Notes' => trim($notes_string, ' |'),
            'Amount (BDT)' => (string) $order->get_total(),
            'Delivery Charge' => (string) $order->get_shipping_total(),
            'Courier ID' => (string) $courier_id,
        );
    }

    private function export_to_google_sheets($data, $order)
    {
        $settings = get_option('rowsync_settings', array());
        $sheet_id = isset($settings['sheet_id']) ? $settings['sheet_id'] : '';

        if (empty($sheet_id))
            return new WP_Error('no_sheet_id', esc_html__('Google Sheet ID not configured.', 'rowsync'));

        $token = $this->get_google_access_token();
        if (is_wp_error($token))
            return $token;

        $order_date = $order->get_date_created() ? $order->get_date_created()->date('Y-m-d') : current_time('Y-m-d');
        $sheet_name = $order_date;

        $ensure_result = $this->ensure_sheet_with_headers($sheet_id, $token, $sheet_name);
        if (is_wp_error($ensure_result))
            return $ensure_result;

        $range = rawurlencode("'" . $sheet_name . "'!A:J");
        $url = "https://sheets.googleapis.com/v4/spreadsheets/{$sheet_id}/values/{$range}:append";
        $url .= '?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS';

        $body = wp_json_encode(array('values' => array(array_values($data))));

        $response = wp_remote_post($url, array(
            'method' => 'POST',
            'headers' => array('Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $token),
            'body' => $body,
            'timeout' => 15,
        ));

        if (is_wp_error($response))
            return $response;

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            /* translators: 1: HTTP status code, 2: Error message body */
            return new WP_Error('api_error', sprintf(esc_html__('Google Sheets API error: HTTP %1$d - %2$s', 'rowsync'), $code, wp_remote_retrieve_body($response)));
        }

        return true;
    }

    private function ensure_sheet_with_headers($sheet_id, $token, $sheet_name)
    {
        $url = "https://sheets.googleapis.com/v4/spreadsheets/{$sheet_id}?fields=sheets.properties.title";
        $response = wp_remote_get($url, array('headers' => array('Authorization' => 'Bearer ' . $token), 'timeout' => 15));

        if (is_wp_error($response))
            return $response;

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $sheet_exists = false;

        if (isset($data['sheets'])) {
            foreach ($data['sheets'] as $sheet) {
                if (isset($sheet['properties']['title']) && $sheet['properties']['title'] === $sheet_name) {
                    $sheet_exists = true;
                    break;
                }
            }
        }

        if (!$sheet_exists) {
            $create_url = "https://sheets.googleapis.com/v4/spreadsheets/{$sheet_id}:batchUpdate";
            $create_body = wp_json_encode(array('requests' => array(array('addSheet' => array('properties' => array('title' => $sheet_name))))));

            $create_response = wp_remote_post($create_url, array(
                'method' => 'POST',
                'headers' => array('Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $token),
                'body' => $create_body,
                'timeout' => 15,
            ));

            if (is_wp_error($create_response))
                return $create_response;
            $create_code = wp_remote_retrieve_response_code($create_response);
            if ($create_code < 200 || $create_code >= 300)
                return new WP_Error('create_sheet_error', esc_html__('Failed to create sheet.', 'rowsync'));
        }

        $headers = array(array('Order ID', 'Date', 'Customer Name', 'Phone', 'Address', 'Items & Quantity', 'Order Notes', 'Amount (BDT)', 'Delivery Charge', 'Courier ID'));

        $check_url = "https://sheets.googleapis.com/v4/spreadsheets/{$sheet_id}/values/" . rawurlencode("'" . $sheet_name . "'!A1");
        $check_response = wp_remote_get($check_url, array('headers' => array('Authorization' => 'Bearer ' . $token), 'timeout' => 15));

        $needs_headers = true;
        if (!is_wp_error($check_response)) {
            $check_data = json_decode(wp_remote_retrieve_body($check_response), true);
            if (isset($check_data['values'][0][0]) && !empty($check_data['values'][0][0]))
                $needs_headers = false;
        }

        if ($needs_headers) {
            $header_url = "https://sheets.googleapis.com/v4/spreadsheets/{$sheet_id}/values/" . rawurlencode("'" . $sheet_name . "'!A1") . '?valueInputOption=USER_ENTERED';
            $header_response = wp_remote_request($header_url, array(
                'method' => 'PUT',
                'headers' => array('Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $token),
                'body' => wp_json_encode(array('values' => $headers)),
                'timeout' => 15,
            ));

            if (is_wp_error($header_response))
                return $header_response;
            $header_code = wp_remote_retrieve_response_code($header_response);
            if ($header_code < 200 || $header_code >= 300)
                return new WP_Error('header_error', esc_html__('Failed to add headers.', 'rowsync'));
        }

        return true;
    }

    private function get_google_access_token()
    {
        $cached = get_transient('rowsync_google_access_token');
        if ($cached)
            return $cached;

        $settings = get_option('rowsync_settings', array());
        $json_raw = isset($settings['service_account_json']) ? $settings['service_account_json'] : '';

        if (empty($json_raw))
            return new WP_Error('no_json_key', esc_html__('Service account JSON not configured.', 'rowsync'));

        $creds = json_decode($json_raw, true);

        // Fallback: If json_decode fails, maybe the newlines were converted to real newlines in the DB?
        if (!is_array($creds)) {
            $fixed_json = preg_replace('/\r?\n/', '\\n', $json_raw);
            $creds = json_decode($fixed_json, true);
        }

        if (!is_array($creds) || empty($creds['private_key']) || empty($creds['client_email']) || empty($creds['token_uri'])) {
            return new WP_Error('invalid_json', esc_html__('Invalid service account JSON. Please re-upload your JSON file.', 'rowsync'));
        }

        $now = time();
        $header = array('alg' => 'RS256', 'typ' => 'JWT');
        $claims = array(
            'iss' => $creds['client_email'],
            'scope' => 'https://www.googleapis.com/auth/spreadsheets',
            'aud' => $creds['token_uri'],
            'exp' => $now + 3600,
            'iat' => $now,
        );

        $segments = array(
            $this->base64url_encode(wp_json_encode($header)),
            $this->base64url_encode(wp_json_encode($claims)),
        );
        $signing_input = implode('.', $segments);

        $private_key_str = $creds['private_key'];

        // ==========================================
        // BULLETPROOF KEY CLEANUP
        // ==========================================
        // 1. Remove any carriage returns (Windows line endings)
        $private_key_str = str_replace("\r", "", $private_key_str);
        // 2. Convert literal \n to actual newlines
        $private_key_str = str_replace('\\n', "\n", $private_key_str);
        // 3. Ensure it has the correct PEM headers
        if (strpos($private_key_str, '-----BEGIN PRIVATE KEY-----') === false) {
            $private_key_str = "-----BEGIN PRIVATE KEY-----\n" . $private_key_str;
        }
        if (strpos($private_key_str, '-----END PRIVATE KEY-----') === false) {
            $private_key_str = $private_key_str . "\n-----END PRIVATE KEY-----\n";
        }

        $pkey = openssl_pkey_get_private($private_key_str);

        if (!$pkey) {
            return new WP_Error('invalid_key', esc_html__('Could not parse private key. Please re-upload your JSON file in settings.', 'rowsync'));
        }

        $signature = '';
        if (!openssl_sign($signing_input, $signature, $pkey, OPENSSL_ALGO_SHA256)) {
            return new WP_Error('sign_error', esc_html__('Failed to sign JWT.', 'rowsync'));
        }

        $jwt = $signing_input . '.' . $this->base64url_encode($signature);

        $response = wp_remote_post($creds['token_uri'], array(
            'method' => 'POST',
            'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
            'body' => array('grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt),
            'timeout' => 15,
        ));

        if (is_wp_error($response))
            return $response;

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300 || empty($body['access_token'])) {
            $error_msg = isset($body['error_description']) ? $body['error_description'] : wp_remote_retrieve_body($response);
            /* translators: %s: Error description from Google API */
            return new WP_Error('token_error', sprintf(esc_html__('Google API error: %s', 'rowsync'), $error_msg));
        }

        $access_token = $body['access_token'];
        $expires_in = isset($body['expires_in']) ? (int) $body['expires_in'] : 3600;
        set_transient('rowsync_google_access_token', $access_token, max(60, $expires_in - 300));

        return $access_token;
    }

    private function base64url_encode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function render_debug_output($result, $data)
    {
        ?>
        <div
            style="font-family: monospace; padding: 20px; background: #fff; border: 1px solid #ccc; margin: 20px; max-width: 1000px;">
            <h2><?php esc_html_e('Rowsync Debug Output', 'rowsync'); ?></h2>
            <h3><?php esc_html_e('Result:', 'rowsync'); ?></h3>
            <pre><?php echo esc_html(is_wp_error($result) ? $result->get_error_message() : 'SUCCESS'); ?></pre>

            <h3><?php esc_html_e('Data Sent:', 'rowsync'); ?></h3>
            <pre><?php echo esc_html(wp_json_encode($data, JSON_PRETTY_PRINT)); ?></pre>

            <a href="<?php echo esc_url(admin_url('edit.php?post_type=shop_order')); ?>" class="button"
                style="display: inline-block; padding: 10px; background: #2271b1; color: #fff; text-decoration: none;">
                <?php esc_html_e('Back to Orders', 'rowsync'); ?>
            </a>
        </div>
        <?php
    }

    public function admin_notices()
    {
        $success = get_transient('rowsync_export_success');
        if ($success) {
            delete_transient('rowsync_export_success');
            ?>
            <div class="notice notice-success is-dismissible">
                <p><strong><?php esc_html_e('Success!', 'rowsync'); ?></strong>
                    <?php esc_html_e('Order exported to Google Sheets.', 'rowsync'); ?></p>
            </div>
            <?php
        }

        $error = get_transient('rowsync_export_error');
        if ($error) {
            delete_transient('rowsync_export_error');
            ?>
            <div class="notice notice-error is-dismissible">
                <p><strong><?php esc_html_e('Export Failed:', 'rowsync'); ?></strong> <?php echo esc_html($error); ?></p>
            </div>
            <?php
        }
    }

    /**
     * Enqueue admin CSS and JS properly
     */
    public function enqueue_admin_assets()
    {
        // 1. Enqueue Inline CSS
        wp_register_style('rowsync-admin-styles', false);
        wp_enqueue_style('rowsync-admin-styles');
        $custom_css = ".rowsync-export-btn.rowsync-loading { background: #a7aaad !important; border-color: #a7aaad !important; color: #fff; pointer-events: none; }";
        wp_add_inline_style('rowsync-admin-styles', $custom_css);

        // 2. Enqueue Inline JS
        wp_register_script('rowsync-admin-scripts', false, array('jquery'));
        wp_enqueue_script('rowsync-admin-scripts');
        $custom_js = "jQuery(document).ready(function($) {
            $('.rowsync-export-btn').on('click', function(e) {
                $(this).addClass('rowsync-loading');
                $(this).text('" . esc_js(__('Exporting...', 'rowsync')) . "');
            });
        });";
        wp_add_inline_script('rowsync-admin-scripts', $custom_js);
    }
}

function rowsync_init()
{
    return Rowsync_Plugin::get_instance();
}
rowsync_init();