<?php
/**
 * API Handler for communication with Website B
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce PayPal Proxy API Handler Class
 */
class WPPPC_API_Handler {
    
    /**
     * Proxy URL
     */
    private $proxy_url;
    
    /**
     * API Key
     */
    private $api_key;
    
    /**
     * API Secret
     */
    private $api_secret;
    
    /**
     * Constructor
     */
    public function __construct($proxy_url = '', $api_key = '', $api_secret = '') {
        $this->proxy_url  = empty($proxy_url) ? get_option('wpppc_proxy_url') : $proxy_url;
        $this->api_key    = empty($api_key) ? get_option('wpppc_api_key') : $api_key;
        $this->api_secret = empty($api_secret) ? get_option('wpppc_api_secret') : $api_secret;
    }
    
    /**
     * Send order details to Website B
     */
    public function send_order_details($order) {
        if (!$order) {
            return new WP_Error('invalid_order', __('Invalid order object', 'woo-paypal-proxy-client'));
        }
        
        // Prepare order data
        $order_data = array(
            'order_id'       => $order->get_id(),
            'order_key'      => $order->get_order_key(),
            'order_total'    => $order->get_total(),
            'currency'       => $order->get_currency(),
            'customer_email' => $order->get_billing_email(),
            'customer_name'  => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'items'          => $this->get_order_items($order),
            'site_url'       => get_site_url(),
        );
        
        // Generate security hash
        $timestamp = time();
        $hash_data = $timestamp . $order->get_id() . $order->get_total() . $this->api_key;
        $hash = hash_hmac('sha256', $hash_data, $this->api_secret);
        
        // Encode order data
        $encoded_data = base64_encode(json_encode($order_data));
        
        // Prepare request parameters
        $params = array(
            'rest_route'  => '/wppps/v1/register-order',
            'api_key'     => $this->api_key,
            'timestamp'   => $timestamp,
            'hash'        => $hash,
            'order_data'  => $encoded_data,
        );
        
        // Send request to Website B
        $response = $this->make_request($params);
        
        return $response;
    }
    
    /**
     * Verify payment with Website B
     */
    public function verify_payment($paypal_order_id, $order) {
        if (!$paypal_order_id || !$order) {
            return new WP_Error('invalid_data', __('Invalid payment data', 'woo-paypal-proxy-client'));
        }
        
        // Generate security hash
        $timestamp = time();
        $hash_data = $timestamp . $paypal_order_id . $order->get_id() . $this->api_key;
        $hash = hash_hmac('sha256', $hash_data, $this->api_secret);
        
        // Prepare request parameters
        $params = array(
            'rest_route'     => '/wppps/v1/verify-payment',
            'api_key'        => $this->api_key,
            'timestamp'      => $timestamp,
            'hash'           => $hash,
            'paypal_order_id' => $paypal_order_id,
            'order_id'       => $order->get_id(),
            'order_total'    => $order->get_total(),
            'currency'       => $order->get_currency(),
        );
        
        // Send request to Website B
        $response = $this->make_request($params);
        
        return $response;
    }
    
    /**
     * Make API request to Website B
     */
    private function make_request($params) {
        // Build request URL
        $url = $this->proxy_url . '?' . http_build_query($params);
        
        // Make the request
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => array(
                'User-Agent' => 'WooCommerce PayPal Proxy Client/' . WPPPC_VERSION,
            ),
        ));
        
        // Check for errors
        if (is_wp_error($response)) {
            return $response;
        }
        
        // Get response code
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code !== 200) {
            return new WP_Error(
                'api_error',
                sprintf(__('API Error: %s', 'woo-paypal-proxy-client'), wp_remote_retrieve_response_message($response))
            );
        }
        
        // Get response body
        $body = wp_remote_retrieve_body($response);
        
        // Parse JSON response
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', __('Invalid JSON response from API', 'woo-paypal-proxy-client'));
        }
        
        // Check for API error
        if (isset($data['success']) && $data['success'] === false) {
            return new WP_Error(
                'api_response_error',
                isset($data['message']) ? $data['message'] : __('Unknown API error', 'woo-paypal-proxy-client')
            );
        }
        
        return $data;
    }
    
    /**
     * Get order items in a format suitable for API transmission
     */
    private function get_order_items($order) {
        $items = array();
        
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            
            $items[] = array(
                'product_id'   => $product ? $product->get_id() : 0,
                'name'         => $item->get_name(),
                'quantity'     => $item->get_quantity(),
                'price'        => $order->get_item_total($item, false, false),
                'line_total'   => $item->get_total(),
                'sku'          => $product ? $product->get_sku() : '',
            );
        }
        
        return $items;
    }
}