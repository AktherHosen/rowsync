=== Rowsync - WooCommerce to Google Sheets Export ===
Contributors: akther1650
Tags: woocommerce, google sheets, export, orders, excel, crm
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds an export button to each WooCommerce order row that appends order data directly to a Google Sheet via the Google Sheets API.

== Description ==

Rowsync allows you to seamlessly export WooCommerce order data directly to a Google Sheet with a single click. No third-party connector services (like Zapier or SheetDB) are required. It connects directly to your Google Sheet using a secure Service Account.

**Features:**
* **Direct Google Sheets API:** Connects natively without middleman services.
* **One-Click Export:** Adds an "Export" button to every order in the WooCommerce orders list.
* **Customizable Courier Tracking:** Configure your own meta keys to capture tracking IDs from any courier plugin (Pathao, Steadfast, etc.).
* **HPOS Compatible:** Fully compatible with WooCommerce High-Performance Order Storage.
* **Secure:** Uses OAuth2 Service Account authentication. Your credentials stay on your server.

**Exported Fields:**
Order ID, Date, Customer Name, Phone, Address, Items & Quantity, Order Notes, Amount, Delivery Charge, and Courier ID.

== Installation ==

1. Upload the `rowsync` folder to the `/wp-content/plugins/` directory or install via the Plugins menu.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to **Settings > Rowsync** to configure your Google Sheet ID and Service Account JSON.

**How to get your Google Service Account JSON:**
1. Go to the [Google Cloud Console](https://console.cloud.google.com/).
2. Create a new Project (or select an existing one).
3. Enable the **Google Sheets API**.
4. Go to **IAM & Admin > Service Accounts**, create a new service account, and generate a JSON key.
5. Copy the `client_email` from that JSON file.
6. Open your target Google Sheet, click **Share**, and paste the `client_email` giving it **Editor** access.
7. Paste the contents of the JSON file into the Rowsync settings page.

== Frequently Asked Questions ==

= Do I need to pay for Zapier or SheetDB? =
No! Rowsync connects directly to the Google Sheets API for free using your own Google Cloud Service Account.

= How do I find my Courier Tracking ID meta key? =
If you use a courier plugin, the tracking ID is stored in the order's custom fields. You can find the exact "meta key" by checking your courier plugin's documentation, or by viewing the "Custom Fields" section on the WooCommerce Edit Order page. Enter that key in the Rowsync settings.

= Is my data secure? =
Yes. The connection is made directly from your server to Google's servers using secure OAuth2. Rowsync does not send your data to any third-party servers.

== Changelog ==

= 1.0.0 =
* Initial release.