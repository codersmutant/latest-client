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
        
        // *** SHIPPING HANDLING - IMPORTANT FIX ***
        // Get chosen shipping methods from the session
        $chosen_shipping_methods = WC()->session ? WC()->session->get('chosen_shipping_methods') : array();
        
        // Log shipping methods for debugging
        error_log('PayPal Proxy - Chosen shipping methods: ' . print_r($chosen_shipping_methods, true));
        
        // Store shipping methods in order meta for later recovery if needed
        if (!empty($chosen_shipping_methods)) {
            $order->update_meta_data('_wpppc_shipping_methods', $chosen_shipping_methods);
            
            // Get all available shipping packages
            $packages = WC()->shipping->get_packages();
            error_log('PayPal Proxy - Available shipping packages: ' . print_r($packages, true));
            
            // Add shipping line items to the order
            $shipping_added = false;
            
            foreach ($packages as $package_key => $package) {
                if (isset($chosen_shipping_methods[$package_key], $package['rates'][$chosen_shipping_methods[$package_key]])) {
                    $shipping_rate = $package['rates'][$chosen_shipping_methods[$package_key]];
                    error_log('PayPal Proxy - Adding shipping method: ' . $shipping_rate->get_label() . ' with cost: ' . $shipping_rate->get_cost());
                    
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
                    
                    // Store shipping rate details in meta for insurance
                    $order->update_meta_data('_wpppc_shipping_package_'.$package_key, array(
                        'method_title' => $shipping_rate->get_label(),
                        'method_id' => $shipping_rate->get_id(),
                        'cost' => wc_format_decimal($shipping_rate->get_cost()),
                        'taxes' => $shipping_rate->get_taxes(),
                        'meta_data' => $shipping_rate->get_meta_data(),
                        'instance_id' => $shipping_rate->get_instance_id(),
                    ));
                }
            }
            
            // Fallback for flat rate shipping if no shipping was added but flat rate was selected
            if (!$shipping_added && !empty($chosen_shipping_methods[0]) && strpos($chosen_shipping_methods[0], 'flat_rate') !== false) {
                error_log('PayPal Proxy - Using fallback flat rate shipping');
                
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
                    error_log('PayPal Proxy - Added fallback flat rate shipping: ' . $shipping_total);
                } else {
                    error_log('PayPal Proxy - Could not determine flat rate cost, creating shipping line without cost');
                    $item = new WC_Order_Item_Shipping();
                    $item->set_props(array(
                        'method_title' => 'Flat rate shipping',
                        'method_id'    => 'flat_rate',
                    ));
                    $order->add_item($item);
                }
            }
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
                    $cart_item['quantity'],
                    array(
                        'subtotal' => $cart_item['line_subtotal'],
                        'total' => $cart_item['line_total'],
                        'subtotal_tax' => $cart_item['line_subtotal_tax'],
                        'total_tax' => $cart_item['line_tax'],
                        'taxes' => $cart_item['line_tax_data']
                    )
                );
            }
        }
        
        // Set payment method
        $order->set_payment_method('paypal_proxy');
        
        // Calculate totals with shipping
        $order->calculate_shipping();
        $order->calculate_totals();
        
        // Log the order totals for debugging
        error_log('PayPal Proxy - Order totals after calculation: ' . print_r(array(
            'shipping_total' => $order->get_shipping_total(),
            'shipping_tax' => $order->get_shipping_tax(),
            'cart_tax' => $order->get_cart_tax(),
            'total' => $order->get_total()
        ), true));
        
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
        
        // Send the test data and address data to the storage endpoint
        if (!empty($proxy_url) && !empty($api_key)) {
            error_log('PayPal Proxy Client - Sending data to: ' . $proxy_url . '/wp-json/wppps/v1/store-test-data');
            
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
                error_log('PayPal Proxy Client - Error sending data: ' . $response->get_error_message());
            } else {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                error_log('PayPal Proxy Client - Data response: ' . print_r($body, true));
            }
        } else {
            error_log('PayPal Proxy Client - Missing proxy URL or API key, cannot send data');
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
 * Plugin deactivation hook
 */
function wpppc_deactivate() {
    // Cleanup if needed
}
register_deactivation_hook(__FILE__, 'wpppc_deactivate');