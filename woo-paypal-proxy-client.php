<?php
/**
 * Plugin Name: WooCommerce PayPal Proxy Client
 * Plugin URI: https://yourwebsite.com
 * Description: Connects to Website B for secure PayPal processing
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://yourwebsite.com
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
    
    // Initialize classes
    $api_handler = new WPPPC_API_Handler();
    $admin = new WPPPC_Admin();
    
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
}
register_activation_hook(__FILE__, 'wpppc_activate');


/**
 * AJAX handler for creating a WooCommerce order
 * Add detailed error logging and fix the order creation process
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
        
        // Get test data if available
        $test_data = !empty($_POST['paypal_test_data']) ? 
            sanitize_text_field($_POST['paypal_test_data']) : 'Default Test Data';
        
        error_log('PayPal Proxy Client - Test data from form: ' . $test_data);
        
        // Create a simple order for testing
        $order = wc_create_order();
        
         // Add customer data from POST
        $address = array(
            'first_name' => isset($_POST['billing_first_name']) ? sanitize_text_field($_POST['billing_first_name']) : 'Test',
            'last_name'  => isset($_POST['billing_last_name']) ? sanitize_text_field($_POST['billing_last_name']) : 'Customer',
            'email'      => isset($_POST['billing_email']) ? sanitize_email($_POST['billing_email']) : 'test@example.com',
            'phone'      => isset($_POST['billing_phone']) ? sanitize_text_field($_POST['billing_phone']) : '555-555-5555',
            'address_1'  => isset($_POST['billing_address_1']) ? sanitize_text_field($_POST['billing_address_1']) : 'Test Address',
            'city'       => isset($_POST['billing_city']) ? sanitize_text_field($_POST['billing_city']) : 'Test City',
            'state'      => isset($_POST['billing_state']) ? sanitize_text_field($_POST['billing_state']) : 'CA',
            'postcode'   => isset($_POST['billing_postcode']) ? sanitize_text_field($_POST['billing_postcode']) : '12345',
            'country'    => isset($_POST['billing_country']) ? sanitize_text_field($_POST['billing_country']) : 'US',
        );
        
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

// Store shipping methods in order meta for later recovery
if (!empty($chosen_shipping_methods)) {
    $order->update_meta_data('_wpppc_shipping_methods', $chosen_shipping_methods);
    
    // Store each shipping package and rate details
    foreach (WC()->shipping->get_packages() as $package_key => $package) {
        if (isset($chosen_shipping_methods[$package_key], $package['rates'][$chosen_shipping_methods[$package_key]])) {
            $shipping_rate = $package['rates'][$chosen_shipping_methods[$package_key]];
            $order->update_meta_data('_wpppc_shipping_package_'.$package_key, array(
                'method_title' => $shipping_rate->get_label(),
                'method_id' => $shipping_rate->get_id(),
                'cost' => wc_format_decimal($shipping_rate->get_cost()),
                'taxes' => $shipping_rate->get_taxes(),
                'meta_data' => $shipping_rate->get_meta_data()
            ));
            
            error_log('PayPal Proxy - Stored shipping data in order meta: ' . $shipping_rate->get_label() . ' (' . $shipping_rate->get_cost() . ')');
        }
    }
    
    $order->save();
}
        
        // Add cart items
        if (WC()->cart->is_empty()) {
            // For testing, add a dummy product if cart is empty
            $product = new WC_Product_Simple();
            $product->set_name('Test Product');
            $product->set_price(10.00);
            $order->add_product($product, 1);
        } else {
            // Add real cart items
            foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
                $product = $cart_item['data'];
                $order->add_product(
                    $product,
                    $cart_item['quantity']
                );
            }
        }
        
        // Set payment method
        $order->set_payment_method('paypal_proxy');
        
        // Calculate totals
        $order->calculate_totals();
        
        // Set order status
        $order->update_status('pending', __('Awaiting PayPal payment', 'woo-paypal-proxy-client'));
        
        // Save the test data to order meta
        $order->update_meta_data('_paypal_test_data', $test_data);
        $order->save();
        
        $order_id = $order->get_id();
        error_log('PayPal Proxy Client - Order created successfully: #' . $order_id);
        
        // Get the proxy URL and API key
        $proxy_url = get_option('wpppc_proxy_url', '');
        $api_key = get_option('wpppc_api_key', '');
        
        // Send the test data to the storage endpoint
        if (!empty($proxy_url) && !empty($api_key)) {
            error_log('PayPal Proxy Client - Sending test data to: ' . $proxy_url . '/wp-json/wppps/v1/store-test-data');
            
            $response = wp_remote_post(
                $proxy_url . '/wp-json/wppps/v1/store-test-data',
                array(
                    'method' => 'POST',
                    'timeout' => 30,
                    'headers' => array(
                        'Content-Type' => 'application/json',
                    ),
                    'body' => json_encode(array(
                        'api_key' => $api_key,
                        'order_id' => $order_id,
                        'test_data' => $test_data,
                        'shipping_address' => $complete_shipping,
                        'billing_address' => $complete_billing
                    ))
                )
            );
            
            if (is_wp_error($response)) {
                error_log('PayPal Proxy Client - Error sending test data: ' . $response->get_error_message());
            } else {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                error_log('PayPal Proxy Client - Test data response: ' . print_r($body, true));
            }
        } else {
            error_log('PayPal Proxy Client - Missing proxy URL or API key, cannot send test data');
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


/**
 * AJAX handler for completing orders after PayPal payment
 */
function wpppc_complete_order_handler() {
    // Log request for debugging
    error_log('PayPal Proxy Client - Complete Order AJAX request: ' . print_r($_POST, true));
    
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wpppc-nonce')) {
        error_log('PayPal Proxy Client - Invalid nonce in complete order request');
        wp_send_json_error(array(
            'message' => 'Security check failed'
        ));
        wp_die();
    }
    
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $paypal_order_id = isset($_POST['paypal_order_id']) ? sanitize_text_field($_POST['paypal_order_id']) : '';
    $transaction_id = isset($_POST['transaction_id']) ? sanitize_text_field($_POST['transaction_id']) : '';
    
    if (!$order_id || !$paypal_order_id) {
        error_log('PayPal Proxy Client - Invalid order data in completion request');
        wp_send_json_error(array(
            'message' => 'Invalid order data'
        ));
        wp_die();
    }
    
    $order = wc_get_order($order_id);
    
    if (!$order) {
        error_log('PayPal Proxy Client - Order not found: ' . $order_id);
        wp_send_json_error(array(
            'message' => 'Order not found'
        ));
        wp_die();
    }
    
    // Check if shipping is missing but should be there
    if ($order->get_shipping_total() <= 0) {
        error_log('PayPal Proxy - Shipping total is zero, checking for stored shipping data');
        
        // Try to get stored shipping methods from order meta
        $stored_shipping_methods = $order->get_meta('_wpppc_shipping_methods');
        
        if (!empty($stored_shipping_methods)) {
            error_log('PayPal Proxy - Found stored shipping methods, restoring shipping data');
            
            // Process each shipping package
            foreach ($stored_shipping_methods as $package_key => $method_id) {
                $package_data = $order->get_meta('_wpppc_shipping_package_'.$package_key);
                
                if (!empty($package_data)) {
                    // Create shipping line item
                    $item = new WC_Order_Item_Shipping();
                    $item->set_props(array(
                        'method_title' => $package_data['method_title'],
                        'method_id'    => $package_data['method_id'],
                        'total'        => wc_format_decimal($package_data['cost']),
                        'taxes'        => $package_data['taxes'],
                    ));
                    
                    if (!empty($package_data['meta_data'])) {
                        foreach ($package_data['meta_data'] as $key => $value) {
                            $item->add_meta_data($key, $value, true);
                        }
                    }
                    
                    $order->add_item($item);
                    error_log('PayPal Proxy - Added shipping item: ' . $package_data['method_title'] . ' (' . $package_data['cost'] . ')');
                }
            }
            
            // Recalculate totals with shipping
            $order->calculate_totals();
            error_log('PayPal Proxy - Order totals recalculated with shipping from meta data');
        } 
        // Fallback to session data if available
    else if (WC()->session && WC()->session->get('chosen_shipping_methods')) {
    error_log('PayPal Proxy - No stored shipping meta found, using session data');
    $chosen_shipping_methods = WC()->session->get('chosen_shipping_methods');
    error_log('PayPal Proxy - Session shipping methods: ' . print_r($chosen_shipping_methods, true));
    
    // Check if shipping packages exist
    $packages = WC()->shipping->get_packages();
    if (empty($packages)) {
        error_log('PayPal Proxy - No shipping packages found in session');
        
        // Try to recreate shipping packages from cart
        if (!WC()->cart->is_empty()) {
            WC()->shipping()->calculate_shipping(WC()->cart->get_shipping_packages());
            $packages = WC()->shipping->get_packages();
            error_log('PayPal Proxy - Recreated shipping packages: ' . (empty($packages) ? 'Failed' : 'Success'));
        }
    }
    
    // If we have packages, try to add shipping
    if (!empty($packages)) {
        $shipping_added = false;
        
        foreach ($packages as $package_key => $package) {
            error_log('PayPal Proxy - Processing package: ' . $package_key);
            
            if (isset($chosen_shipping_methods[$package_key], $package['rates'][$chosen_shipping_methods[$package_key]])) {
                $shipping_rate = $package['rates'][$chosen_shipping_methods[$package_key]];
                error_log('PayPal Proxy - Found shipping rate: ' . $shipping_rate->get_label() . ' (' . $shipping_rate->get_cost() . ')');
                
                // Create shipping line item
                $item = new WC_Order_Item_Shipping();
                $item->set_props(array(
                    'method_title' => $shipping_rate->get_label(),
                    'method_id'    => $shipping_rate->get_id(),
                    'total'        => wc_format_decimal($shipping_rate->get_cost()),
                    'taxes'        => $shipping_rate->get_taxes(),
                ));
                
                foreach ($shipping_rate->get_meta_data() as $key => $value) {
                    $item->add_meta_data($key, $value, true);
                }
                
                $order->add_item($item);
                $shipping_added = true;
                error_log('PayPal Proxy - Added shipping item to order');
            } else {
                error_log('PayPal Proxy - No matching shipping rate found for package ' . $package_key);
                if (isset($chosen_shipping_methods[$package_key])) {
                    error_log('PayPal Proxy - Method ID: ' . $chosen_shipping_methods[$package_key]);
                    if (!empty($package['rates'])) {
                        error_log('PayPal Proxy - Available rates: ' . implode(', ', array_keys($package['rates'])));
                    } else {
                        error_log('PayPal Proxy - No rates available in package');
                    }
                }
            }
        }
        
        if ($shipping_added) {
            // Force recalculation and save
            $order->calculate_shipping();
            $order->calculate_totals(true);
            $order->save();
            
            error_log('PayPal Proxy - Order totals recalculated with shipping from session. New shipping total: ' . $order->get_shipping_total());
        } else {
            error_log('PayPal Proxy - Failed to add shipping from session data');
            
            // Last resort - add a manual shipping line
            if (!empty($chosen_shipping_methods[0]) && $chosen_shipping_methods[0] === 'flat_rate:1') {
                $item = new WC_Order_Item_Shipping();
                $item->set_props(array(
                    'method_title' => 'Flat rate shipping',
                    'method_id'    => 'flat_rate:1',
                    'total'        => '10.00', // Set your actual flat rate cost here
                    'taxes'        => array(),
                ));
                $order->add_item($item);
                $order->calculate_totals();
                error_log('PayPal Proxy - Added manual flat rate shipping as last resort');
            }
        }
    } else {
        error_log('PayPal Proxy - No shipping packages available, cannot restore shipping');
    }
}
    
    try {
        // Complete the order
        $order->payment_complete($transaction_id);
        
        // Add order note
        $order->add_order_note(
            sprintf('PayPal payment completed. PayPal Order ID: %s, Transaction ID: %s',
                $paypal_order_id,
                $transaction_id
            )
        );
        
        // Update status to processing
        $order->update_status('processing');
        
        // Empty cart
        WC()->cart->empty_cart();
        
        // Log success
        error_log('PayPal Proxy Client - Order successfully completed: ' . $order_id);
        
        // Return success with redirect URL
        wp_send_json_success(array(
            'redirect' => $order->get_checkout_order_received_url()
        ));
    } catch (Exception $e) {
        error_log('PayPal Proxy Client - Exception during order completion: ' . $e->getMessage());
        wp_send_json_error(array(
            'message' => 'Error completing order: ' . $e->getMessage()
        ));
    }
    
    wp_die();
}
}
// Register the AJAX handlers
add_action('wp_ajax_wpppc_complete_order', 'wpppc_complete_order_handler');
add_action('wp_ajax_nopriv_wpppc_complete_order', 'wpppc_complete_order_handler');

/**
 * Plugin deactivation hook
 */
function wpppc_deactivate() {
    // Cleanup if needed
}
register_deactivation_hook(__FILE__, 'wpppc_deactivate');