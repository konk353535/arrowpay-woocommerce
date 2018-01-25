<?php
 
/**
 * Plugin Name: ArrowPay Woocommerce
 * Plugin URI: https://arrowpay.io
 * Description: This plugin allows you to accept RaiBlocks as payment on your Woocommerce site.
 * Version: 0.0.1
 * Author: Jason Romaior
 * License: MIT
 */

defined('ABSPATH') or exit;

// Check if woocommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
  return;
}

add_action('plugins_loaded', 'arrowpay_gateway_init', 11);

function arrowpay_gateway_init() {

  // Add ArrowPay to woocommerce list of payment gateways
  function add_to_gateway($gateways) {
    array_push($gateways, 'WC_ArrowPay_Gateway');
    return $gateways;
  }

  // Add link to settings from the plugin area
  function add_checkout_nav_link($links) {
    $href = admin_url('admin.php?page=wc-settings&tab=checkout&section=arrowpay_gateway');
    $settings_link = '<a href="' . $href . '">Settings</a>';
    array_push($links, $settings_link);
    return $links;
  }

  add_filter('woocommerce_payment_gateways', 'add_to_gateway');
  add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'add_checkout_nav_link');

  class WC_ArrowPay_Gateway extends WC_Payment_Gateway {
    /**
     * Constructor for the gateway.
     */
    public function __construct() {
    
      $this->id = 'arrowpay_gateway';
      $this->icon = apply_filters('woocommerce_offline_icon', '');
      $this->has_fields = false;
      $this->method_title = __('ArrowPay', 'arrowpay_gateway');
      $this->method_description = __('ArrowPay allows customers to checkout with RaiBlocks without leaving your website.', 'arrowpay_gateway');
      
      // Load the settings.
      $this->init_form_fields();
      $this->init_settings();
            
      // Define user set variables
      $this->title = $this->get_option('title');
      $this->description  = $this->get_option('description');
      $this->public_key  = $this->get_option('public_key');

      add_filter('woocommerce_order_button_html', array($this, 'display_html'), 1);

      add_action(
        'woocommerce_update_options_payment_gateways_' . $this->id,
        array($this, 'process_admin_options')
      );
    }

    public function display_html($value) {

      // Convert payment amount to cents
      $total = (int)($this->get_order_total() * 100);
      $currency = strtolower(get_woocommerce_currency());
      $public_key = $this->settings['public_key'];
      $modal_title = $this->settings['modal_title'];
      $modal_sub_title = $this->settings['modal_sub_title'];
      $button_text = $this->settings['button_text'];

      ?>

      <link href="http://arrowpay.io/checkout.css" rel="stylesheet">
      <script src="https://arrowpay.io/checkout.js"></script>
      <style>
        #arrowpay-checkout-button, #arrowpay-others-button {
          display: none;
        }
      </style>

      <div id="arrowpay-others-button"><?= $value ?></div>

      <div id="arrowpay-checkout-button">
      </div>

      <script>

          function toggleVisibility() {
            var showArrowPayCheckout = jQuery('#payment_method_arrowpay_gateway').is(':checked');
            if (showArrowPayCheckout) {
              jQuery('#arrowpay-others-button').hide();
              jQuery('#arrowpay-checkout-button').show();
            } else {
              jQuery('#arrowpay-others-button').show();
              jQuery('#arrowpay-checkout-button').hide();
            }
          }

          ArrowPayCheckout({
            public_key: '<?= $public_key ?>',

            // Button text
            text: '<?= $button_text ?>',

            // Optional title text
            title: '<?= $modal_title ?>',

            sub_title: '<?= $modal_sub_title ?>',

            onClick: function() {
              var checkout_form = jQuery('form.checkout');

              checkout_form.one('checkout_place_order', function(event) {
                return false;
              });

              checkout_form.submit();

              checkout_form.one('checkout_place_order', function(event) {
                return false;
              });

              return new Promise(function(resolve) {
                setTimeout(resolve, 200);
              }).then(function() {
                if (document.querySelector('.woocommerce-invalid')) {
                  checkout_form.submit();
                  return false;
                }

                return true;
              });
            },

            payment: {
              currency: '<?= strtoupper($currency) ?>', // Specified currency, choose from USD or XRB
              amount: parseFloat('<?= $total ?>') // Amount in USD cents
            },

            // Callback that fires when payment is confirmed
            onPaymentConfirmed: function(data) {
              var checkout_form = jQuery('form.checkout');
              checkout_form.append(
                '<input type="hidden" name="arrowpay_token" value="' + data.token + '">'
              );
              checkout_form.submit();
            }
          }, '#arrowpay-checkout-button');

          toggleVisibility();

          jQuery('body').on('click', function () {
            toggleVisibility();
          });
      </script>

      <?php
    }

    public function init_form_fields() {
    
      $this->form_fields = apply_filters('arrowpay_form_fields', array(
      
        'enabled' => array(
          'title'   => __('Enable/Disable', 'arrowpay_gateway'),
          'type'    => 'checkbox',
          'label'   => __('Enable RaiBlocks Payments with ArrowPay', 'arrowpay_gateway'),
          'default' => 'yes'
        ),
        
        'title' => array(
          'title'       => __('Title', 'arrowpay_gateway'),
          'type'        => 'text',
          'description' => __('Determines the title of this payment method when a user checks out.', 'arrowpay_gateway' ),
          'default'     => __('RaiBlocks Payment', 'arrowpay_gateway'),
          'desc_tip'    => true,
        ),
        
        'description' => array(
          'title'       => __('Description', 'arrowpay_gateway'),
          'type'        => 'textarea',
          'description' => __('Description of the payment method that users will see upon checkout'),
          'default'     => __('Pay with RaiBlocks', 'arrowpay_gateway'),
          'desc_tip'    => true,
        ),
                
        'public_key' => array(
          'title'       => __('Public key', 'arrowpay_gateway'),
          'type'        => 'text',
          'description' => __('Public_key used to link payments made on your site to your ArrowPay account.', 'arrowpay_gateway'),
          'default'     => __('', 'arrowpay_gateway'),
          'desc_tip'    => true,
        ),

        'modal_title' => array(
          'title'       => __('Modal title', 'arrowpay_gateway'),
          'type'        => 'text',
          'description' => __('Optional title to display when the ArrowPay modal is open'),
          'default'     => __('', 'arrowpay_gateway'),
          'desc_tip'    => true,
        ),

        'modal_sub_title' => array(
          'title'       => __('Modal subtitle', 'arrowpay_gateway'),
          'type'        => 'text',
          'description' => __('Optional sub title to display when the ArrowPay modal is open', 'arrowpay_gateway'),
          'default'     => __('', 'arrowpay_gateway'),
          'desc_tip'    => true,
        ),

        'button_text' => array(
          'title'       => __('Button text', 'arrowpay_gateway'),
          'type'        => 'text',
          'description' => __('Text displayed on the button that opens the ArrowPay modal.'),
          'default'     => __('Pay With RaiBlocks', 'arrowpay_gateway'),
          'desc_tip'    => true,
        ),
      ));
    }

    public function process_payment($order_id) {
      $order = wc_get_order($order_id);

      if (!$_POST['arrowpay_token']) {
        wc_clear_notices('Awaiting payment from ArrowPay', 'notice'); 
        return array(
          'result' => 'failure'
        );
      }

      $token = $_POST['arrowpay_token'];

      // Convert amount to cents.
      $amount = (int)($order->get_total() * 100);
      // Fetch currency
      $currency = strtoupper(get_woocommerce_currency());

      $amount_data = array("amount" => $amount, "currency" => $currency);
      $data = array("payment" => $amount_data, "token" => $token);
      $data_string = json_encode($data);
      
      // Confirm the token given is valid for the correct amount
      $ch = curl_init('https://arrowpay.io/api/payment/handle');
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
      curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_HTTPHEADER, array(
          'Content-Type: application/json',
          'Content-Length: ' . strlen($data_string))
      );
      
      $output = curl_exec($ch);
      $info = curl_getinfo($ch);

      if ($output === false || $info['http_code'] != 200) {
        $order->update_status('error', 'error receiving payment');
        wc_add_notice('Error confirming payment from ArrowPay', 'error'); 
        return array(
          'result' => 'failure'
        );
      }
                                                                                                                                 
      // Mark as completed
      $order->update_status('completed');
      
      // Reduce stock levels
      $order->reduce_order_stock();
      
      // Remove cart
      WC()->cart->empty_cart();
      
      // Return thankyou redirect
      return array(
        'result'  => 'success',
        'redirect'  => $this->get_return_url($order)
      );
    }
  }
}

?>
