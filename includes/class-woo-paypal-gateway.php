<?php
/**
 * PayPal Proxy Gateway for WooCommerce
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce PayPal Proxy Gateway Class
 */
class WPPPC_PayPal_Gateway extends WC_Payment_Gateway {
    
    /**
     * API Handler instance
     */
    private $api_handler;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->id                 = 'paypal_proxy';
        $this->icon               = apply_filters('woocommerce_paypal_proxy_icon', WPPPC_PLUGIN_URL . 'assets/images/paypal.svg');
        $this->has_fields         = true;
        $this->method_title       = __('PayPal via Proxy', 'woo-paypal-proxy-client');
        $this->method_description = __('Accept PayPal payments securely through Website B proxy.', 'woo-paypal-proxy-client');
        
        // Load settings
        $this->init_form_fields();
        $this->init_settings();
        
        // Define properties
        $this->title        = $this->get_option('title');
        $this->description  = $this->get_option('description');
        $this->enabled      = $this->get_option('enabled');
        $this->proxy_url    = $this->get_option('proxy_url');
        //$this->api_key      = $this->get_option('wpppc_api_key');
        $this->api_key = get_option('wpppc_api_key');

        
        // Initialize API handler
        $this->api_handler = new WPPPC_API_Handler(
            $this->proxy_url,
            $this->api_key,
            get_option('wpppc_api_secret')
        );
        
        // Hooks
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
        add_action('woocommerce_api_wpppc_callback', array($this, 'process_callback'));
        
        // AJAX handlers for order processing
        add_action('wp_ajax_wpppc_create_order', array($this, 'ajax_create_order', 5));
        add_action('wp_ajax_nopriv_wpppc_create_order', array($this, 'ajax_create_order', 5));
        add_action('wp_ajax_wpppc_complete_order', array($this, 'ajax_complete_order'));
        add_action('wp_ajax_nopriv_wpppc_complete_order', array($this, 'ajax_complete_order'));
    }
    
    /**
     * Initialize form fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'       => __('Enable/Disable', 'woo-paypal-proxy-client'),
                'type'        => 'checkbox',
                'label'       => __('Enable PayPal via Proxy', 'woo-paypal-proxy-client'),
                'default'     => 'yes'
            ),
            'title' => array(
                'title'       => __('Title', 'woo-paypal-proxy-client'),
                'type'        => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'woo-paypal-proxy-client'),
                'default'     => __('PayPal', 'woo-paypal-proxy-client'),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __('Description', 'woo-paypal-proxy-client'),
                'type'        => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'woo-paypal-proxy-client'),
                'default'     => __('Pay securely via PayPal.', 'woo-paypal-proxy-client'),
                'desc_tip'    => true,
            ),
            'proxy_url' => array(
                'title'       => __('Proxy Website URL', 'woo-paypal-proxy-client'),
                'type'        => 'text',
                'description' => __('Enter the URL of Website B (PayPal proxy).', 'woo-paypal-proxy-client'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'api_key' => array(
                'title'       => __('API Key', 'woo-paypal-proxy-client'),
                'type'        => 'text',
                'description' => __('Enter the API key provided by Website B.', 'woo-paypal-proxy-client'),
                'default'     => '',
                'desc_tip'    => true,
            ),
        );
    }
    
    /**
     * Payment fields displayed on checkout
     */
    public function payment_fields() {
        // Display description if set
        if ($this->description) {
            echo wpautop(wptexturize($this->description));
        }
        
        // Load PayPal buttons iframe
        $iframe_url = $this->generate_iframe_url();
        include WPPPC_PLUGIN_DIR . 'templates/iframe-container.php';
    }
    
    /**
     * Generate iframe URL with necessary parameters
     */
    private function generate_iframe_url() {
        // Get cart total and currency
        $total = WC()->cart->get_total('');
        $currency = get_woocommerce_currency();
        
        // Get callback URL
        $callback_url = WC()->api_request_url('wpppc_callback');
        
        // Generate a hash for security
        $timestamp = time();
        $hash_data = $timestamp . $total . $currency . $this->api_key;
        $hash = hash_hmac('sha256', $hash_data, get_option('wpppc_api_secret'));
        
        // Build the iframe URL
        $params = array(
            'rest_route'    => '/wppps/v1/paypal-buttons',
            'amount'        => $total,
            'currency'      => $currency,
            'api_key'       => $this->api_key,
            'timestamp'     => $timestamp,
            'hash'          => $hash,
            'callback_url'  => base64_encode($callback_url),
            'site_url'      => base64_encode(get_site_url()),
        );
        
        


        $iframe_url = $this->proxy_url . '?' . http_build_query($params);
        
        


        
        return $iframe_url;
    }
    
    /**
     * Process payment
     */
    public function process_payment($order_id) {
        // This method will be called when the order is created
        // The actual payment processing happens via AJAX
        
        $order = wc_get_order($order_id);
        
        // Return success response
        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url($order)
        );
    }
    
    
/**
 * AJAX handler for creating a WooCommerce order
 */
public function ajax_create_order() {
    // Log the raw POST data for debugging
    error_log('PayPal Proxy - Create Order Raw POST data: ' . print_r($_POST, true));
    
    try {
        check_ajax_referer('wpppc-nonce', 'nonce');
        
        // Get test data if available
        $test_data = !empty($_POST['paypal_test_data']) ? 
            sanitize_text_field($_POST['paypal_test_data']) : 'Default Test Data';
        
        error_log('PayPal Proxy - Test data from form: ' . $test_data);
        
        // Log the checkout fields
        $checkout_fields = WC()->checkout()->get_checkout_fields();
        error_log('PayPal Proxy - Checkout fields structure: ' . print_r($checkout_fields, true));
        
        // Create order with error handling
        try {
            // For testing, create a simple order directly
            $order = wc_create_order();
            
            // Add billing information
            $billing_address = array(
                'first_name' => !empty($_POST['billing_first_name']) ? sanitize_text_field($_POST['billing_first_name']) : 'Test',
                'last_name'  => !empty($_POST['billing_last_name']) ? sanitize_text_field($_POST['billing_last_name']) : 'User',
                'email'      => !empty($_POST['billing_email']) ? sanitize_email($_POST['billing_email']) : 'test@example.com',
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
            
            // Add shipping method from cart
            $chosen_shipping_methods = WC()->session->get('chosen_shipping_methods');
            if (!empty($chosen_shipping_methods)) {
                // Get active shipping methods
                $shipping_methods = WC()->shipping->get_shipping_methods();
                
                // Add shipping line items
                foreach (WC()->shipping->get_packages() as $package_key => $package) {
                    if (isset($chosen_shipping_methods[$package_key], $package['rates'][$chosen_shipping_methods[$package_key]])) {
                        $shipping_rate = $package['rates'][$chosen_shipping_methods[$package_key]];
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
                    }
                }
            }
            
            // Add a product if cart is empty (for testing)
            if (WC()->cart->is_empty()) {
                // Get any product
                $products = wc_get_products(array('limit' => 1));
                if (!empty($products)) {
                    $product = $products[0];
                    $order->add_product($product, 1);
                }
            } else {
                // Add real cart items
                foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
                    $product = $cart_item['data'];
                    $order->add_product($product, $cart_item['quantity']);
                }
            }
            
            // Calculate totals
            $order->calculate_totals();
            
            // Set payment method
            $order->set_payment_method('paypal_proxy');
            
            // Set status
            $order->update_status('pending', __('Awaiting PayPal payment', 'woo-paypal-proxy-client'));
            
            // Save the test data to order meta
            update_post_meta($order->get_id(), '_paypal_test_data', $test_data);
            
            $order_id = $order->get_id();
            error_log('PayPal Proxy - Successfully created order #' . $order_id);
            
            // Get the proxy URL and API key
            $proxy_url = get_option('wpppc_proxy_url', '');
            $api_key = get_option('wpppc_api_key', '');
            
            // Send the test data to the storage endpoint
            if (!empty($proxy_url) && !empty($api_key)) {
                error_log('PayPal Proxy - Sending test data to: ' . $proxy_url . '/wp-json/wppps/v1/store-test-data');
                
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
                            'test_data' => $test_data
                        ))
                    )
                );
                
                if (is_wp_error($response)) {
                    error_log('PayPal Proxy - Error sending test data: ' . $response->get_error_message());
                } else {
                    $body = json_decode(wp_remote_retrieve_body($response), true);
                    error_log('PayPal Proxy - Test data response: ' . print_r($body, true));
                }
            } else {
                error_log('PayPal Proxy - Missing proxy URL or API key, cannot send test data');
            }
            
            // Return success
            wp_send_json_success(array(
                'order_id'   => $order_id,
                'order_key'  => $order->get_order_key(),
                'proxy_data' => array('message' => 'Order created successfully'),
            ));
            
        } catch (Exception $e) {
            error_log('PayPal Proxy - Exception during order creation: ' . $e->getMessage());
            error_log('PayPal Proxy - Exception trace: ' . $e->getTraceAsString());
            wp_send_json_error(array(
                'message' => 'Order creation error: ' . $e->getMessage()
            ));
        }
    } catch (Exception $e) {
        error_log('PayPal Proxy - Exception in AJAX handler: ' . $e->getMessage());
        error_log('PayPal Proxy - Exception trace: ' . $e->getTraceAsString());
        wp_send_json_error(array(
            'message' => 'AJAX handler error: ' . $e->getMessage()
        ));
    }
    
    wp_die();
}
    
    /**
     * AJAX handler for completing an order after payment
     */
    public function ajax_complete_order() {
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
    
    // IMPORTANT: Check if shipping is missing but should be there
    if ($order->get_shipping_total() <= 0 && WC()->session && WC()->session->get('chosen_shipping_methods')) {
        // Retrieve the shipping methods from session
        $chosen_shipping_methods = WC()->session->get('chosen_shipping_methods');
        
        // Re-add shipping methods to the order
        foreach (WC()->shipping->get_packages() as $package_key => $package) {
            if (isset($chosen_shipping_methods[$package_key], $package['rates'][$chosen_shipping_methods[$package_key]])) {
                $shipping_rate = $package['rates'][$chosen_shipping_methods[$package_key]];
                
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
            }
        }
        
        // Recalculate totals with shipping
        $order->calculate_totals();
    }
    
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
        
        // Complete the order
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
        
        // Empty cart
        WC()->cart->empty_cart();
        
        // Log the success
        error_log('PayPal Proxy - Order successfully completed: ' . $order_id);
        
        // Return success with redirect URL
        $redirect_url = $order->get_checkout_order_received_url();
        wp_send_json_success(array(
            'redirect' => $redirect_url
        ));
    } catch (Exception $e) {
        error_log('PayPal Proxy - Exception during order completion: ' . $e->getMessage());
        wp_send_json_error(array(
            'message' => 'Error completing order: ' . $e->getMessage()
        ));
    }
    
    wp_die();
}
    
    /**
     * Process callback from Website B
     */
    public function process_callback() {
        $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $hash = isset($_GET['hash']) ? sanitize_text_field($_GET['hash']) : '';
        
        // Verify hash
        $check_hash = hash_hmac('sha256', $order_id . $status . $this->api_key, get_option('wpppc_api_secret'));
        
        if ($hash !== $check_hash) {
            wp_die(__('Invalid security hash', 'woo-paypal-proxy-client'), '', array('response' => 403));
        }
        
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_die(__('Order not found', 'woo-paypal-proxy-client'), '', array('response' => 404));
        }
        
        if ($status === 'completed') {
            // Payment was successful
            $order->payment_complete();
            $order->add_order_note(__('Payment completed via PayPal proxy callback', 'woo-paypal-proxy-client'));
            
            // Redirect to thank you page
            wp_redirect($this->get_return_url($order));
            exit;
        } elseif ($status === 'cancelled') {
            // Payment was cancelled
            $order->update_status('cancelled', __('Payment cancelled by customer', 'woo-paypal-proxy-client'));
            
            // Redirect to cart page
            wp_redirect(wc_get_cart_url());
            exit;
        } else {
            // Payment failed
            $order->update_status('failed', __('Payment failed', 'woo-paypal-proxy-client'));
            
            // Redirect to checkout page
            wp_redirect(wc_get_checkout_url());
            exit;
        }
    }
    
   /**
 * Validate checkout fields
 */
private function validate_checkout_fields() {
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
        return array('valid' => true);
    } else {
        return array('valid' => false, 'errors' => $errors);
    }
    }
    
    
    /**
 * Add mapped product IDs to the order items
 */
private function add_product_mappings_to_items($line_items) {
    // Check if product mapping class is available
    if (!class_exists('WPPPC_Product_Mapping')) {
        return $line_items;
    }
    
    // Get product mapping instance
    $product_mapping = new WPPPC_Product_Mapping();
    
    // Get all product mappings
    $mappings = $product_mapping->get_product_mappings_array();
    
    // No mappings available
    if (empty($mappings)) {
        return $line_items;
    }
    
    // Add mapping info to line items
    foreach ($line_items as &$item) {
        if (!empty($item['product_id']) && isset($mappings[$item['product_id']])) {
            $item['mapped_product_id'] = $mappings[$item['product_id']];
        }
    }
    
    return $line_items;
}
}