<?php
/**
 * Plugin Name: WooCommerce PayPal Proxy Client
 * Plugin URI: https://www.upwork.com/freelancers/~01a6e65817b86d4589
 * Description: Connects to Website B for secure PayPal processing
 * Version: 1.0.0
 * Author: Masum Billah
 * Author URI: https://www.upwork.com/freelancers/~01a6e65817b86d4589
 * Text Domain: woo-paypal-proxy-client
 * Domain Path: /languages
 * WC requires at least: 5.0.0
 * WC tested up to: 8.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WPPPC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPPPC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPPPC_VERSION', '1.0.0');

/**
 * Check if WooCommerce is active
 */
function wpppc_check_woocommerce_active() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'wpppc_woocommerce_missing_notice');
        return false;
    }
    return true;
}

/**
 * Display WooCommerce missing notice
 */
function wpppc_woocommerce_missing_notice() {
    ?>
    <div class="error">
        <p><?php _e('WooCommerce PayPal Proxy Client requires WooCommerce to be installed and active.', 'woo-paypal-proxy-client'); ?></p>
    </div>
    <?php
}

/**
 * Initialize the plugin
 */
function wpppc_init() {
    if (!wpppc_check_woocommerce_active()) {
        return;
    }
    
    // Load required files
    require_once WPPPC_PLUGIN_DIR . 'includes/class-woo-paypal-gateway.php';
    require_once WPPPC_PLUGIN_DIR . 'includes/class-api-handler.php';
    require_once WPPPC_PLUGIN_DIR . 'includes/class-admin.php';
    require_once WPPPC_PLUGIN_DIR . 'includes/class-product-mapping.php';
    
    // Initialize classes
    $api_handler = new WPPPC_API_Handler();
    $admin = new WPPPC_Admin();
    $product_mapping = new WPPPC_Product_Mapping();
    
    // Add payment gateway to WooCommerce
    add_filter('woocommerce_payment_gateways', 'wpppc_add_gateway');
    
    // Add scripts and styles
    add_action('wp_enqueue_scripts', 'wpppc_enqueue_scripts');
}
add_action('plugins_loaded', 'wpppc_init');

/**
 * Add PayPal Proxy Gateway to WooCommerce
 */
function wpppc_add_gateway($gateways) {
    $gateways[] = 'WPPPC_PayPal_Gateway';
    return $gateways;
}

/**
 * Enqueue scripts and styles
 */
function wpppc_enqueue_scripts() {
    if (is_checkout()) {
        wp_enqueue_style('wpppc-checkout-style', WPPPC_PLUGIN_URL . 'assets/css/checkout.css', array(), WPPPC_VERSION);
        wp_enqueue_script('wpppc-checkout-script', WPPPC_PLUGIN_URL . 'assets/js/checkout.js', array('jquery'), WPPPC_VERSION, true);
        
        // Add localized data for the script
        wp_localize_script('wpppc-checkout-script', 'wpppc_params', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wpppc-nonce'),
        ));
    }
}

/**
 * AJAX handler for validating checkout fields
 */
/**
 * AJAX handler for validating checkout fields
 */
function wpppc_validate_checkout_fields() {
    check_ajax_referer('wpppc-nonce', 'nonce');
    
    $errors = array();
    
    // Get checkout fields
    $fields = WC()->checkout()->get_checkout_fields();
    
    // Check if shipping to different address
    $ship_to_different_address = !empty($_POST['ship_to_different_address']);
    
    // Check if creating account
    $create_account = !empty($_POST['createaccount']);
    
    // Loop through field groups and validate conditionally
    foreach ($fields as $fieldset_key => $fieldset) {
        // Skip shipping fields if not shipping to different address
        if ($fieldset_key === 'shipping' && !$ship_to_different_address) {
            continue;
        }
        
        // Skip account fields if not creating account
        if ($fieldset_key === 'account' && !$create_account) {
            continue;
        }
        
        foreach ($fieldset as $key => $field) {
            // Only validate required fields that are empty
            if (!empty($field['required']) && empty($_POST[$key])) {
                $errors[$key] = sprintf(__('%s is a required field.', 'woocommerce'), $field['label']);
            }
        }
    }
    
    if (empty($errors)) {
        wp_send_json_success(array('valid' => true));
    } else {
        wp_send_json_error(array('valid' => false, 'errors' => $errors));
    }
    
    wp_die();
}
add_action('wp_ajax_wpppc_validate_checkout', 'wpppc_validate_checkout_fields');
add_action('wp_ajax_nopriv_wpppc_validate_checkout', 'wpppc_validate_checkout_fields');

/**
 * Add settings link on plugin page
 */
function wpppc_settings_link($links) {
    $settings_link = '<a href="admin.php?page=wc-settings&tab=checkout&section=paypal_proxy">' . __('Settings', 'woo-paypal-proxy-client') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
$plugin = plugin_basename(__FILE__);
add_filter("plugin_action_links_$plugin", 'wpppc_settings_link');

/**
 * Plugin activation hook
 */
function wpppc_activate() {
    // Create necessary database tables or options if needed
    add_option('wpppc_proxy_url', '');
    add_option('wpppc_api_key', '');
    add_option('wpppc_api_secret', md5(uniqid(rand(), true)));
    
    // Create product mapping table
    global $wpdb;
    $table_name = $wpdb->prefix . 'wpppc_product_mappings';
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        product_id bigint(20) NOT NULL,
        server_product_id bigint(20) NOT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY product_id (product_id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

}
register_activation_hook(__FILE__, 'wpppc_activate');


/**
 * AJAX handler for creating a WooCommerce order with detailed line items
 */
function wpppc_create_order_handler() {
    // Log all incoming data for debugging
    error_log('PayPal Proxy Client - Incoming AJAX data: ' . print_r($_POST, true));
    
    try {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wpppc-nonce')) {
            error_log('PayPal Proxy Client - Invalid nonce');
            wp_send_json_error(array(
                'message' => 'Security check failed'
            ));
            wp_die();
        }
        
        // Create a simple order for testing
        $order = wc_create_order();
        
        // Get and set complete billing address with all fields
        $complete_billing = array(
            'first_name' => !empty($_POST['billing_first_name']) ? sanitize_text_field($_POST['billing_first_name']) : 'Test',
            'last_name'  => !empty($_POST['billing_last_name']) ? sanitize_text_field($_POST['billing_last_name']) : 'User',
            'email'      => !empty($_POST['billing_email']) ? sanitize_email($_POST['billing_email']) : 'test@example.com',
            'phone'      => !empty($_POST['billing_phone']) ? sanitize_text_field($_POST['billing_phone']) : '',
            'address_1'  => !empty($_POST['billing_address_1']) ? sanitize_text_field($_POST['billing_address_1']) : '',
            'address_2'  => !empty($_POST['billing_address_2']) ? sanitize_text_field($_POST['billing_address_2']) : '',
            'city'       => !empty($_POST['billing_city']) ? sanitize_text_field($_POST['billing_city']) : '',
            'state'      => !empty($_POST['billing_state']) ? sanitize_text_field($_POST['billing_state']) : '',
            'postcode'   => !empty($_POST['billing_postcode']) ? sanitize_text_field($_POST['billing_postcode']) : '',
            'country'    => !empty($_POST['billing_country']) ? sanitize_text_field($_POST['billing_country']) : '',
        );
        $order->set_address($complete_billing, 'billing');

        // Check if shipping to different address
        $ship_to_different_address = !empty($_POST['ship_to_different_address']);
        if ($ship_to_different_address) {
            // Use shipping address fields
            $complete_shipping = array(
                'first_name' => !empty($_POST['shipping_first_name']) ? sanitize_text_field($_POST['shipping_first_name']) : $complete_billing['first_name'],
                'last_name'  => !empty($_POST['shipping_last_name']) ? sanitize_text_field($_POST['shipping_last_name']) : $complete_billing['last_name'],
                'address_1'  => !empty($_POST['shipping_address_1']) ? sanitize_text_field($_POST['shipping_address_1']) : $complete_billing['address_1'],
                'address_2'  => !empty($_POST['shipping_address_2']) ? sanitize_text_field($_POST['shipping_address_2']) : $complete_billing['address_2'],
                'city'       => !empty($_POST['shipping_city']) ? sanitize_text_field($_POST['shipping_city']) : $complete_billing['city'],
                'state'      => !empty($_POST['shipping_state']) ? sanitize_text_field($_POST['shipping_state']) : $complete_billing['state'],
                'postcode'   => !empty($_POST['shipping_postcode']) ? sanitize_text_field($_POST['shipping_postcode']) : $complete_billing['postcode'],
                'country'    => !empty($_POST['shipping_country']) ? sanitize_text_field($_POST['shipping_country']) : $complete_billing['country'],
            );
        } else {
            // Copy from billing address
            $complete_shipping = $complete_billing;
        }
        $order->set_address($complete_shipping, 'shipping');
        
        // *** SHIPPING HANDLING - IMPORTANT ***
        // Get chosen shipping methods from the session
        $chosen_shipping_methods = WC()->session ? WC()->session->get('chosen_shipping_methods') : array();
        $shipping_total = 0;
        $shipping_tax = 0;
        
        // Store shipping methods in order meta for later recovery if needed
        if (!empty($chosen_shipping_methods)) {
            $order->update_meta_data('_wpppc_shipping_methods', $chosen_shipping_methods);
            
            // Get all available shipping packages
            $packages = WC()->shipping->get_packages();
            
            // Add shipping line items to the order
            $shipping_added = false;
            
            foreach ($packages as $package_key => $package) {
                if (isset($chosen_shipping_methods[$package_key], $package['rates'][$chosen_shipping_methods[$package_key]])) {
                    $shipping_rate = $package['rates'][$chosen_shipping_methods[$package_key]];
                    
                    // Create shipping line item
                    $item = new WC_Order_Item_Shipping();
                    $item->set_props(array(
                        'method_title' => $shipping_rate->get_label(),
                        'method_id'    => $shipping_rate->get_id(),
                        'total'        => wc_format_decimal($shipping_rate->get_cost()),
                        'taxes'        => $shipping_rate->get_taxes(),
                        'instance_id'  => $shipping_rate->get_instance_id(),
                    ));
                    
                    // Add any meta data
                    foreach ($shipping_rate->get_meta_data() as $key => $value) {
                        $item->add_meta_data($key, $value, true);
                    }
                    
                    // Add to order
                    $order->add_item($item);
                    $shipping_added = true;
                    $shipping_total += $shipping_rate->get_cost();
                    $shipping_tax += array_sum($shipping_rate->get_taxes());
                }
            }
            
            // Fallback for flat rate shipping if no shipping was added
            if (!$shipping_added && !empty($chosen_shipping_methods[0]) && strpos($chosen_shipping_methods[0], 'flat_rate') !== false) {
                // Try to get flat rate cost from cart
                $shipping_total = WC()->cart->get_shipping_total();
                $shipping_tax = WC()->cart->get_shipping_tax();
                
                if ($shipping_total > 0) {
                    $item = new WC_Order_Item_Shipping();
                    $item->set_props(array(
                        'method_title' => 'Flat rate shipping',
                        'method_id'    => 'flat_rate',
                        'total'        => wc_format_decimal($shipping_total),
                        'taxes'        => array('total' => array($shipping_tax)),
                    ));
                    $order->add_item($item);
                }
            }
        }
        
        // Prepare line items array to send to PayPal proxy
        $line_items = array();
        $cart_subtotal = 0;
        $tax_total = 0;
        
        // Add cart items
        if (WC()->cart->is_empty()) {
            // For testing, add a dummy product if cart is empty
            $product = new WC_Product_Simple();
            $product->set_name('Test Product');
            $product->set_price(10.00);
            $order->add_product($product, 1);
            
            // Add dummy item to line items
            $line_items[] = array(
                'name' => 'Test Product',
                'quantity' => 1,
                'unit_price' => 10.00,
                'tax_amount' => 0,
                'sku' => 'TEST-1',
                'description' => 'Test product for testing'
            );
            
            $cart_subtotal = 10.00;
        } else {
            // Process real cart items
            foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
                $product = $cart_item['data'];
                
                // Get product details
                $name = $product->get_name();
                $quantity = $cart_item['quantity'];
                $price = $cart_item['line_subtotal'] / $quantity; // Unit price without tax
                $tax = $cart_item['line_tax'] / $quantity; // Unit tax
                
                // Add to order
                $order->add_product(
                    $product,
                    $quantity,
                    array(
                        'subtotal' => $cart_item['line_subtotal'],
                        'total' => $cart_item['line_total'],
                        'subtotal_tax' => $cart_item['line_subtotal_tax'],
                        'total_tax' => $cart_item['line_tax'],
                        'taxes' => $cart_item['line_tax_data']
                    )
                );
                
                // Add to line items array for PayPal
                $line_items[] = array(
                    'name' => $name,
                    'quantity' => $quantity,
                    'unit_price' => wc_format_decimal($price, 2),
                    'tax_amount' => wc_format_decimal($cart_item['line_tax'], 2),
                    'sku' => $product->get_sku() ? $product->get_sku() : 'SKU-' . $product->get_id(),
                    'description' => $product->get_short_description() ? substr(wp_strip_all_tags($product->get_short_description()), 0, 127) : '',
                    'product_id' => $product->get_id()
                );
                $line_items = add_product_mappings_to_items($line_items);
                
                $cart_subtotal += $cart_item['line_subtotal'];
                $tax_total += $cart_item['line_tax'];
            }
        }
        
        // Set payment method
        $order->set_payment_method('paypal_proxy');
        
        // Calculate totals
        $order->calculate_shipping();
        $order->calculate_totals();
        
        // Log the order totals for debugging
        error_log('PayPal Proxy - Order totals: ' . print_r(array(
            'subtotal' => $order->get_subtotal(),
            'shipping_total' => $order->get_shipping_total(),
            'shipping_tax' => $order->get_shipping_tax(),
            'cart_tax' => $order->get_cart_tax(),
            'total' => $order->get_total()
        ), true));
        
        // Set order status
        $order->update_status('pending', __('Awaiting PayPal payment', 'woo-paypal-proxy-client'));
        $order->save();
        
        $order_id = $order->get_id();
        error_log('PayPal Proxy Client - Order created successfully: #' . $order_id);
        
        // Get the proxy URL and API key
        $proxy_url = get_option('wpppc_proxy_url', '');
        $api_key = get_option('wpppc_api_key', '');
        
        // Create order details array with all information needed by PayPal
        $order_details = array(
            'api_key' => $api_key,
            'order_id' => $order_id,
            'test_data' => !empty($_POST['paypal_test_data']) ? 
                sanitize_text_field($_POST['paypal_test_data']) : 'Order #' . $order_id,
            'shipping_address' => $complete_shipping,
            'billing_address' => $complete_billing,
            'line_items' => $line_items,
            'shipping_amount' => $order->get_shipping_total(),
            'shipping_tax' => $order->get_shipping_tax(),
            'tax_total' => $order->get_cart_tax() + $order->get_shipping_tax(),
            'currency' => get_woocommerce_currency(),
            'prices_include_tax' => wc_prices_include_tax(),
            'tax_display_cart' => get_option('woocommerce_tax_display_cart'),
            'tax_display_shop' => get_option('woocommerce_tax_display_shop')
        );
        
        // Send the order details to the storage endpoint
        if (!empty($proxy_url) && !empty($api_key)) {
            error_log('PayPal Proxy Client - Sending order details to: ' . $proxy_url . '/wp-json/wppps/v1/store-test-data');
            
            $response = wp_remote_post(
                $proxy_url . '/wp-json/wppps/v1/store-test-data',
                array(
                    'method' => 'POST',
                    'timeout' => 30,
                    'headers' => array(
                        'Content-Type' => 'application/json',
                    ),
                    'body' => json_encode($order_details)
                )
            );
            
            if (is_wp_error($response)) {
                error_log('PayPal Proxy Client - Error sending order details: ' . $response->get_error_message());
            } else {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                error_log('PayPal Proxy Client - Data response: ' . print_r($body, true));
            }
        } else {
            error_log('PayPal Proxy Client - Missing proxy URL or API key, cannot send order details');
        }
        
        // Return success with order details
        wp_send_json_success(array(
            'order_id'   => $order_id,
            'order_key'  => $order->get_order_key(),
            'proxy_data' => array('message' => 'Order created successfully'),
        ));
        
    } catch (Exception $e) {
        error_log('PayPal Proxy Client - Error creating order: ' . $e->getMessage());
        error_log('PayPal Proxy Client - Error trace: ' . $e->getTraceAsString());
        wp_send_json_error(array(
            'message' => 'Failed to create order: ' . $e->getMessage()
        ));
    }
    
    wp_die();
}

add_action('wp_ajax_wpppc_create_order', 'wpppc_create_order_handler', 10);
add_action('wp_ajax_nopriv_wpppc_create_order', 'wpppc_create_order_handler', 10);


function add_product_mappings_to_items($line_items) {
    // Check if product mapping class is available
    if (!class_exists('WPPPC_Product_Mapping')) {
        return $line_items;
    }
    
    // Get product mapping instance
    $product_mapping = new WPPPC_Product_Mapping();
    
    // Add mapping info to line items
    foreach ($line_items as &$item) {
        if (!empty($item['product_id'])) {
            // Get mapping directly using our enhanced method that checks parent products
            $server_product_id = $product_mapping->get_product_mapping($item['product_id']);
            if ($server_product_id) {
                $item['mapped_product_id'] = $server_product_id;
            }
        }
    }
    
    return $line_items;
}


/**
 * AJAX handler for completing an order after payment
 */
function wpppc_complete_order_handler() {
    // Log all request data for debugging
    error_log('PayPal Proxy - Complete Order Request: ' . print_r($_POST, true));
    
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wpppc-nonce')) {
        error_log('PayPal Proxy - Invalid nonce in complete order request');
        wp_send_json_error(array(
            'message' => 'Security check failed'
        ));
        wp_die();
    }
    
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $paypal_order_id = isset($_POST['paypal_order_id']) ? sanitize_text_field($_POST['paypal_order_id']) : '';
    $transaction_id = isset($_POST['transaction_id']) ? sanitize_text_field($_POST['transaction_id']) : '';
    
    if (!$order_id || !$paypal_order_id) {
        error_log('PayPal Proxy - Invalid order data in completion request');
        wp_send_json_error(array(
            'message' => __('Invalid order data', 'woo-paypal-proxy-client')
        ));
        wp_die();
    }
    
    $order = wc_get_order($order_id);
    
    if (!$order) {
        error_log('PayPal Proxy - Order not found: ' . $order_id);
        wp_send_json_error(array(
            'message' => __('Order not found', 'woo-paypal-proxy-client')
        ));
        wp_die();
    }
    
    try {
        // Log order details
        error_log('PayPal Proxy - Processing order: ' . $order_id . ', Status: ' . $order->get_status());
        
        // Check if payment is already completed to avoid duplicate processing
        if ($order->is_paid()) {
            error_log('PayPal Proxy - Order is already paid, redirecting to thank you page');
            wp_send_json_success(array(
                'redirect' => $order->get_checkout_order_received_url()
            ));
            wp_die();
        }
        
        // Complete the order payment
        $order->payment_complete($transaction_id);
        
        // Add order note
        $order->add_order_note(
            sprintf(__('PayPal payment completed. PayPal Order ID: %s, Transaction ID: %s', 'woo-paypal-proxy-client'),
                $paypal_order_id,
                $transaction_id
            )
        );
        
        // Update status to processing
        $order->update_status('processing');
        
        // Store PayPal order ID in order meta
        $order->update_meta_data('_paypal_order_id', $paypal_order_id);
        $order->update_meta_data('_paypal_transaction_id', $transaction_id);
        $order->save();
        
        // Empty cart
        WC()->cart->empty_cart();
        
        // Log the success
        error_log('PayPal Proxy - Order successfully completed: ' . $order_id);
        
        // Return success with redirect URL
        $redirect_url = $order->get_checkout_order_received_url();
        error_log('PayPal Proxy - Redirecting to: ' . $redirect_url);
        
        wp_send_json_success(array(
            'redirect' => $redirect_url
        ));
    } catch (Exception $e) {
        error_log('PayPal Proxy - Exception during order completion: ' . $e->getMessage());
        error_log('PayPal Proxy - Exception trace: ' . $e->getTraceAsString());
        wp_send_json_error(array(
            'message' => 'Error completing order: ' . $e->getMessage()
        ));
    }
    
    wp_die();
}
// Register the AJAX handlers
add_action('wp_ajax_wpppc_complete_order', 'wpppc_complete_order_handler');
add_action('wp_ajax_nopriv_wpppc_complete_order', 'wpppc_complete_order_handler');


/**
 * Log debug messages
 */
function wpppc_log($message) {
    if (defined('WP_DEBUG') && WP_DEBUG === true) {
        if (is_array($message) || is_object($message)) {
            error_log('WPPPC Debug: ' . print_r($message, true));
        } else {
            error_log('WPPPC Debug: ' . $message);
        }
    }
}

/**
 * Get product mapping status for a product
 */
function wpppc_get_product_mapping_status($product_id) {
    if (!class_exists('WPPPC_Product_Mapping')) {
        return false;
    }
    
    $product_mapping = new WPPPC_Product_Mapping();
    $mapping = $product_mapping->get_product_mapping($product_id);
    
    if ($mapping) {
        return intval($mapping);
    }
    
    return false;
}

/**
 * Plugin deactivation hook
 */
function wpppc_deactivate() {
    // Cleanup if needed
}
register_deactivation_hook(__FILE__, 'wpppc_deactivate');