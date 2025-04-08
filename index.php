<?php
/*
 * Plugin Name: WooCommerce Custom Payment Gateway
 * Plugin URI: https://dev.kaizen-design.ru/payments-today/
 * Description: Take credit card payments on your store.
 * Author: Denis Bondarchuk
 * Author URI: https://kaizen-design.ru
 * Version: 1.0.1
 */

/*
 * This action hook registers our PHP class as a WooCommerce payment gateway
 */
add_filter('woocommerce_payment_gateways', 'pt_add_gateway_class');
function pt_add_gateway_class($gateways)
{
  $gateways[] = 'WC_PT_Gateway'; // your class name is here
  return $gateways;
}

/*
 * The class itself, please note that it is inside plugins_loaded action hook
 */
add_action('plugins_loaded', 'pt_init_gateway_class');

function pt_init_gateway_class()
{
  #[AllowDynamicProperties]

  class WC_PT_Gateway extends WC_Payment_Gateway
  {


    /**
     * Class constructor
     */
    public function __construct()
    {
      $this->id = 'payments_today'; // payment gateway plugin ID
      $this->has_fields = true; // in case you need a custom credit card form
      $this->method_title = 'Payments Today Gateway';
      $this->method_description = 'Payments Today payment gateway'; // will be displayed on the options page

      // gateways can support subscriptions, refunds, saved payment methods
      $this->supports = array(
        'products'
      );

      // Method with all the options fields
      $this->init_form_fields();

      // Load the settings.
      $this->init_settings();
      $this->icon = apply_filters('woocommerce_noob_icon', plugins_url('/assets/images/card-icons.png', __FILE__)); // URL of the icon that will be displayed on checkout page near your gateway name
      $this->title = $this->get_option('title');
      $this->description = $this->get_option('description');
      $this->enabled = $this->get_option('enabled');
      $this->reseller_id = $this->get_option('reseller_id');
      $this->secret_key = $this->get_option('secret_key');
      $this->webhook_success_url = $this->get_option('webhook_success_url');
      $this->webhook_fail_url = $this->get_option('webhook_fail_url');
      $this->currency_converter = $this->get_option('currency_converter');
      $this->currency_converter_rate = $this->get_option('currency_converter_rate');
      $this->endpoint = 'https://atlaspayx.com/api/v1/' . $this->reseller_id . '/' . $this->secret_key . '/';
      $this->client_id = null;

      // This action hook saves the settings
      add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

      // We need custom JavaScript to obtain a token
      add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));

      // You can also register a webhook here
      add_action('woocommerce_api_pmtd-success', array($this, 'success_webhook'));
      add_action('woocommerce_api_pmtd-fail', array($this, 'fail_webhook'));
    }

    /**
     * Plugin options
     */
    public function init_form_fields()
    {
      $this->form_fields = array(
        'enabled' => array(
          'title'       => 'Enable/Disable',
          'label'       => 'Enable Payments Today Gateway',
          'type'        => 'checkbox',
          'description' => '',
          'default'     => 'no'
        ),
        'title' => array(
          'title'       => 'Title',
          'type'        => 'text',
          'description' => 'This controls the title which the user sees during checkout.',
          'default'     => 'Visa & MasterCard',
          'desc_tip'    => true,
        ),
        'description' => array(
          'title'       => 'Description',
          'type'        => 'textarea',
          'description' => 'This controls the description which the user sees during checkout.',
          'default'     => 'Pay with your credit card via our super-cool payment gateway.',
        ),
        'reseller_id' => array(
          'title'       => 'Reseller ID',
          'type'        => 'text',
          'custom_attributes' => array(
            'required'  => true
          )
        ),
        'secret_key' => array(
          'title'       => 'Secret Key',
          'type'        => 'password',
          'custom_attributes' => array(
            'required'  => true
          )
        ),
        'webhook_success_url' => array(
          'title'       => 'Webhook Success URL',
          'type'        => 'text',
          'description' => 'Send this to your administrator.',
          'default'     => get_site_url() . '/wc-api/pmtd-success/',
          'custom_attributes' => array(
            'readonly'  => true
          )
        ),
        'webhook_fail_url' => array(
          'title'       => 'Webhook Fail URL',
          'type'        => 'text',
          'description' => 'Send this to your administrator.',
          'default'     => get_site_url() . '/wc-api/pmtd-fail/',
          'custom_attributes' => array(
            'readonly'  => true
          )
        ),
        'currency_converter' => array(
          'title'       => 'Enable/Disable Currency Converter',
          'label'       => 'Enable Currency Converter',
          'type'        => 'checkbox',
          'description' => '',
          'default'     => 'no'
        ),
        'currency_converter_rate' => array(
          'title'       => 'Currency Converter Rate',
          'type'        => 'number',
          'description' => 'Set currency converter rate relative to €.',
        ),
      );
    }

    public function get_payment_systems()
    {

      $response = wp_remote_get($this->endpoint . 'payment-systems');

      $body = json_decode(wp_remote_retrieve_body($response), true);

      if ($body['data'] && $body['data']['payment_systems']) {
        $is_hidden = count($body['data']['payment_systems']) === 1 ? 'display: none;' : '';
        echo '<label style="margin: 0; ' . $is_hidden . '">Payment system</label>';
        echo '<ul class="pt_payment-systems-list form-row" style="list-style: none; padding: 0; margin: 0; ' . $is_hidden . '">';
        foreach ($body['data']['payment_systems'] as $key => $payment_system) {
          $is_checked = $key === 0 ? 'checked' : '';
          echo '<li>
                  <label for="pt_payment_system_' . $payment_system['id_payment_system'] . '">
                    <input 
                      type="radio" 
                      name="pt_payment_system" id="pt_payment_system_' . $payment_system['id_payment_system'] . '"
                      value="' . $payment_system['id_payment_system'] . '"
                      ' . $is_checked . '
                    />
                    ' . $payment_system['name'] . '
                  </label>
                </li>';
        }
        echo '</ul>';
      } else {
        wc_add_notice('Please try again. ' . $body['error']['message'], 'error');
        return;
      }		
    }

    /**
     * You will need it if you want your custom credit card form
     */
    public function payment_fields()
    {
      // ok, let's display some description before the payment form
      if ($this->description) {
        // display the description with <p> tags etc.
        echo wpautop(wp_kses_post($this->description));
      }
      // Add this action hook if you want your custom payment gateway to support it
      do_action('woocommerce_credit_card_form_start', $this->id);

      //$this->get_payment_systems();
      echo '<input type="hidden" name="pt_payment_system" value="" />';
      echo '<input type="hidden" name="pt_period" value="1" />';
      /* echo '<div class="form-row" style="padding: 0; margin: 0; display: none;">
              <label style="margin: 0;">Period (in months)</label>
              <input id="pt_period" name="pt_period" class="input-text" type="number" autocomplete="off" value="1" min="1" style="border: none; max-width: 200px;">
            </div>'; */

      do_action('woocommerce_credit_card_form_end', $this->id);
    }

    /*
		 * Custom CSS and JS, in most cases required only when you decided to go with a custom credit card form
		 */
    public function payment_scripts() {
      // we need JavaScript to process a token only on cart/checkout pages, right?
      if( ! is_cart() && ! is_checkout() && ! isset( $_GET[ 'pay_for_order' ] ) ) {
        return;
      }

      // if our payment gateway is disabled, we do not have to enqueue JS too
      if( 'no' === $this->enabled ) {
        return;
      }

      // no reason to enqueue JavaScript if API keys are not set
      if( empty( $this->reseller_id ) || empty( $this->secret_key ) ) {
        return;
      }

      // let's suppose it is our payment processor JavaScript that allows to obtain a token
      wp_enqueue_script( 'sweet_alert_js', 'https://cdn.jsdelivr.net/npm/sweetalert2@11' );

      // and this is our custom JS in your plugin directory that works with token.js
      wp_register_script( 'woocommerce_pmtd', plugins_url( 'pmtd.js', __FILE__ ), array( 'jquery', 'sweet_alert_js' ) );

      // in most payment processors you have to use PUBLIC KEY to obtain a token
      wp_localize_script( 'woocommerce_pmtd', 'pmtd_params', array(
        'endpoint' => $this->endpoint
      ) );

      wp_enqueue_script( 'woocommerce_pmtd' );
    }

    public function get_customer_by_email($email)
    {
      $response = wp_remote_get($this->endpoint . 'clients/find/email', [
        'body' => [
          'email' => $email
        ]
      ]);

      $body = json_decode(wp_remote_retrieve_body($response), true);

      if ($body['data']) {
        $this->client_id = $body['data']['id_client'];
      } else {
        return $this->create_customer();
      }
    }

    public function get_countries()
    {
      if (!$this->reseller_id && !$this->secret_key) return;
      $response = wp_remote_get($this->endpoint . 'countries');

      $body = json_decode(wp_remote_retrieve_body($response), true);

      if (isset($body['data'])) {
        return $body['data'];
      } else {
        wc_add_notice('Couldn\'t fetch countries from API – ' . $body['error']['message'], 'error');
        return;
      }
    }

    public function get_billing_country_id($country_code)
    {
      $id = null;
      foreach ($this->countries as $key => $country) {
        if ($country['iso_3166_1'] === $country_code) {
          $id = $country['id'];
          break;
        }
      }
      return $id ? $id : wc_add_notice($country_code . ' – country is not supported.', 'error');
    }

    public function create_customer()
    {
      $args = [
        'name' => $_POST['billing_first_name'] . ' ' . $_POST['billing_last_name'],
        'id_country' => $this->get_billing_country_id($_POST['billing_country']),
        'e_mail' => $_POST['billing_email'],
        'city' => $_POST['billing_city'],
        'postal_code' => $_POST['billing_postcode'],
        'address' => $_POST['billing_address_1'],
        'phone' => $_POST['billing_phone'],
        'description' => '',
      ];

      $response = wp_remote_post($this->endpoint . 'clients/create', [
        'body' => $args
      ]);

      $body = json_decode(wp_remote_retrieve_body($response), true);

      if ($body['data']) {
        $this->client_id = $body['data']['id_client'];
      } else {
        wc_add_notice('Failed to create a new customer – ' . $body['error']['message'], 'error');
        return;
      }
    }

    /*
 		 * Fields validation
		 */
    public function validate_fields() {}

    /*
		 * We're processing the payments here
		 */
    public function process_payment($order_id)
    {
      $this->countries = $this->get_countries();

      // we need it to get any order detailes
      $order = wc_get_order($order_id);

      $this->get_customer_by_email($order->get_billing_email());

      /*
      * Array with parameters for API interaction
      */
      $args = array(
        'id_client' => $this->client_id,
        'price' => $order->get_total(),
        'period' => $_POST['pt_period'],
        'id_payment_system' => $_POST['pt_payment_system'],
        'description' => $order->get_id()
      );

      /*
      * Your API integration can be built with wp_remote_post()
      */
      if ($_POST['pt_payment_system'] === '') {
        wc_add_notice('Please select a payment system.', 'notice');
        return;
      }
      $response = wp_remote_post($this->endpoint . 'payments/create', [
        'body' => $args
      ]);

      $body = json_decode(wp_remote_retrieve_body($response), true);

      //if( 201 === wp_remote_retrieve_response_code( $response ) ) {

      // it could be different depending on your payment processor
      if ($body['data']) {



        $uuid = $body['data']['uuid_payment'];

        // some notes to customer (replace true with false to make it private)
        $order->add_order_note('https://atlaspayx.com/pay/' . $uuid, true);

        // Empty cart
        WC()->cart->empty_cart();

        // Redirect to the payment page
        return array(
          'result' => 'success',
          'redirect' => 'https://atlaspayx.com/pay/' . $uuid,
        );
      } else {
        wc_add_notice('Please try again. ' . $body['error']['message'], 'error');
        return;
      }
      //}
    }

    /*
		 * In case you need a webhook, like PayPal IPN etc
		 */
    public function success_webhook()
    {
      header('HTTP/1.1 200 OK');
      $posted = json_decode(@file_get_contents("php://input"));

      $order = wc_get_order($posted->description);

      if ($order) {
        $order->payment_complete();
        $order->reduce_order_stock();
      }

      update_option('webhook_debug', $posted);
      die();
    }

    public function fail_webhook() {}
  }
}
