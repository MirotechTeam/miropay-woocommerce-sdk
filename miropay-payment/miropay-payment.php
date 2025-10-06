<?php
/**
 * Plugin Name: Miropay payment
 * Description: A minimal WooCommerce payment gateway integration.
 * Author: Your Company
 * Version: 1.0.0
 */

if (!defined('ABSPATH'))
  exit;

// Load gateway class
add_action('plugins_loaded', 'miropay_payment_init', 11);

function miropay_payment_init()
{
  if (!class_exists('WC_Payment_Gateway'))
    return;

  require_once plugin_dir_path(__FILE__) . 'includes/class-wc-gateway-miropaypayment.php';

  add_filter('woocommerce_payment_gateways', function ($methods): mixed {
    $methods[] = 'WC_Gateway_Miropaypayment';
    return $methods;
  });
}