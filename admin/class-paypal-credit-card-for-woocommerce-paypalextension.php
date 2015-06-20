<?php

/**
 * @class       PayPal_Credit_Card_For_WooCommerce_PayPalExtension
 * @version	1.0.0
 * @package	paypal-credit-card-for-woocommerce
 * @category	Class
 * @author      johnny manziel <phpwebcreators@gmail.com>
 */
class PayPal_Credit_Card_For_WooCommerce_PayPalExtension extends WC_Payment_Gateway {

    protected $PAYPAL_NVP_SIG_SANDBOX = "https://api-3t.sandbox.paypal.com/nvp";
    protected $PAYPAL_NVP_SIG_LIVE = "https://api-3t.paypal.com/nvp";
    protected $PAYPAL_NVP_PAYMENTACTION = "Sale";
    protected $PAYPAL_NVP_METHOD = "DoDirectPayment";
    protected $PAYPAL_NVP_API_VERSION = "84.0";
    protected $instructions = '';
    protected $order = null;
    protected $acceptableCards = null;
    protected $transactionId = null;
    protected $transactionErrorMessage = null;
    protected $usesandboxapi = true;
    protected $apiusername = '';
    protected $apipassword = '';
    protected $apisigniture = '';

    public function __construct() {
        $this->id = 'PayPalPro';
        $this->method_title = 'PayPal Credit Card';
        $this->has_fields = true;
        $this->icon                 = apply_filters('woocommerce_paypal_credit_card_icon', plugins_url( '/assets/images/cards.png', plugin_basename( dirname( __FILE__ ) ) ) );
        $this->acceptableCards = array(
            "Visa",
            "MasterCard",
            "Discover",
            "Amex"
        );

        $this->init_form_fields();
        $this->init_settings();

        $this->description = '';
        $this->usesandboxapi = strcmp($this->settings['debug'], 'yes') == 0;
        $this->instructions = $this->settings['instructions'];
        $this->title = $this->settings['title'];
        $this->apiusername = $this->settings['paypalapiusername'];
        $this->apipassword = $this->settings['paypalapipassword'];
        $this->apisigniture = $this->settings['paypalapisigniture'];

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('admin_notices', array($this, 'perform_ssl_check'));
        add_action('woocommerce_thankyou', array($this, 'thankyou_page'));

        add_filter('http_request_version', array($this, 'use_http_1_1'));
    }

    function perform_ssl_check() {
        if (!$this->usesandboxapi && get_option('woocommerce_force_ssl_checkout') == 'no' && $this->enabled == 'yes') :
            echo '<div class="error"><p>' . sprintf(__('%s sandbox testing is disabled and can performe live transactions but the <a href="%s">force SSL option</a> is disabled; your checkout is not secure! Please enable SSL and ensure your server has a valid SSL certificate.', 'woocommerce'), $this->method_title, admin_url('admin.php?page=woocommerce_settings&tab=general')) . '</p></div>';
        endif;
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'woocommerces'),
                'type' => 'checkbox',
                'label' => __('Enable Credit Card Payment', 'woocommerces'),
                'default' => 'yes'
            ),
            'debug' => array(
                'title' => __('Paypal Sandbox', 'woocommerces'),
                'type' => 'checkbox',
                'label' => __('Enable Paypal Sandbox', 'woocommerces'),
                'default' => 'no'
            ),
            'title' => array(
                'title' => __('Title', 'woocommerces'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'woocommerces'),
                'default' => __('Credit Card Payment', 'woocommerces')
            ),
            'instructions' => array(
                'title' => __('Customer Message', 'woocommerces'),
                'type' => 'textarea',
                'description' => __('This message is displayed on the buttom of the Order Recieved Page.', 'woocommerces'),
                'default' => ''
            ),
            'paypalapiusername' => array(
                'title' => __('Paypal API Username', 'woocommerces'),
                'type' => 'text',
                'default' => __('', 'woocommerces')
            ),
            'paypalapipassword' => array(
                'title' => __('Paypal API Password', 'woocommerces'),
                'type' => 'text',
                'default' => __('', 'woocommerces')
            ),
            'paypalapisigniture' => array(
                'title' => __('Paypal API signiture', 'woocommerces'),
                'type' => 'textarea',
                'default' => __('', 'woocommerces')
            )
        );
    }

    public function admin_options() {
        parent::admin_options();
    }

    /**
     * @since    1.0.0
     */
    public function payment_fields() {
        if ($this->description) {
            echo '<p>' . $this->description . ( $this->testmode ? ' ' . __('TEST/SANDBOX MODE ENABLED. In test mode, you can use the card number 4007000000027 with any CVC and a valid expiration date.  Note that you will get a faster processing result if you use a card from your developer\'s account.', 'paypal-pro-for-woocommerce') : '' ) . '</p>';
        }

        $this->credit_card_form();
    }

    /**
     * @since    1.0.0
     */
    public function thankyou_page($order_id) {
        if ($this->instructions)
            echo wpautop(wptexturize($this->instructions));
    }

    /**
     * @since    1.0.0
     */
    public function validate_fields() {

        try {

            $card_number = isset($_POST['PayPalPro-card-number']) ? woocommerce_clean($_POST['PayPalPro-card-number']) : '';
            $card_cvc = isset($_POST['PayPalPro-card-cvc']) ? woocommerce_clean($_POST['PayPalPro-card-cvc']) : '';
            $card_expiry = isset($_POST['PayPalPro-card-expiry']) ? woocommerce_clean($_POST['PayPalPro-card-expiry']) : '';

            // Format values
            $card_number = str_replace(array(' ', '-'), '', $card_number);
            $card_expiry = array_map('trim', explode('/', $card_expiry));
            $card_exp_month = str_pad($card_expiry[0], 2, "0", STR_PAD_LEFT);
            $card_exp_year = $card_expiry[1];

            if (strlen($card_exp_year) == 2) {
                $card_exp_year += 2000;
            }

            // Validate values
            if (!ctype_digit($card_cvc)) {
                throw new Exception(__('Card security code is invalid (only digits are allowed)', 'paypal-credit-card-for-woocommerce'));
            }

            if (
                    !ctype_digit($card_exp_month) ||
                    !ctype_digit($card_exp_year) ||
                    $card_exp_month > 12 ||
                    $card_exp_month < 1 ||
                    $card_exp_year < date('y')
            ) {
                throw new Exception(__('Card expiration date is invalid' . $card_exp_month, 'paypal-credit-card-for-woocommerce'));
            }

            if (empty($card_number) || !ctype_digit($card_number)) {
                throw new Exception(__('Card number is invalid ' . $card_number, 'paypal-credit-card-for-woocommerce'));
            }

            return true;
        } catch (Exception $e) {
            wc_add_notice($e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * @since    1.0.0
     */
    public function process_payment($order_id) {
        global $woocommerce;
        $this->order = new WC_Order($order_id);
        $gatewayRequestData = $this->getPaypalRequestData();

        if ($gatewayRequestData AND $this->getPaypalApproval($gatewayRequestData)) {
            $this->completeOrder();

            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($this->order)
            );
        } else {
            $this->markAsFailedPayment();
            wc_add_notice(__('(Transaction Error) something is wrong.', 'woocommerces'), 'error');
        }
    }

    /**
     * @since    1.0.0
     */
    public function use_http_1_1($httpversion) {
        return '1.1';
    }

    /**
     * @since    1.0.0
     */
    protected function markAsFailedPayment() {
        $this->order->add_order_note(sprintf("Paypal Credit Card Payment Failed with message: '%s'", $this->transactionErrorMessage));
    }

    /**
     * @since    1.0.0
     */
    protected function completeOrder() {
        global $woocommerce;

        if ($this->order->status == 'completed')
            return;

        $this->order->payment_complete();
        $woocommerce->cart->empty_cart();

        $this->order->add_order_note(
                sprintf("Paypal Credit Card payment completed with Transaction Id of '%s'", $this->transactionId)
        );
        if(isset($_SESSION) && !empty($_SESSION)) {
            unset($_SESSION['order_awaiting_payment']);
        }
    }

    /**
     * @since    1.0.0
     */
    protected function getPaypalApproval($gatewayRequestData) {
        global $woocommerce;

        $erroMessage = "";
        $api_url = $this->usesandboxapi ? $this->PAYPAL_NVP_SIG_SANDBOX : $this->PAYPAL_NVP_SIG_LIVE;
        $request = array(
            'method' => 'POST',
            'timeout' => 45,
            'blocking' => true,
            'sslverify' => $this->usesandboxapi ? false : true,
            'body' => $gatewayRequestData
        );

        $response = wp_remote_post($api_url, $request);
        if (!is_wp_error($response)) {
            $parsedResponse = $this->parsePaypalResponse($response);

            if (array_key_exists('ACK', $parsedResponse)) {
                switch ($parsedResponse['ACK']) {
                    case 'Success':
                    case 'SuccessWithWarning':
                        $this->transactionId = $parsedResponse['TRANSACTIONID'];
                        return true;
                        break;

                    default:
                        $this->transactionErrorMessage = $erroMessage = $parsedResponse['L_LONGMESSAGE0'];
                        break;
                }
            }
        } else {
            $erroMessage = 'Something went wrong while performing your request. Please contact website administrator to report this problem.';
        }

        wc_add_notice($erroMessage, 'error');
        return false;
    }

    /**
     * @since    1.0.0
     */
    protected function parsePaypalResponse($response) {
        $result = array();
        $enteries = explode('&', $response['body']);

        foreach ($enteries as $nvp) {
            $pair = explode('=', $nvp);
            if (count($pair) > 1)
                $result[urldecode($pair[0])] = urldecode($pair[1]);
        }

        return $result;
    }

    /**
     * @since    1.0.0
     */
    protected function getPaypalRequestData() {
        if ($this->order AND $this->order != null) {

            $card_number = isset($_POST['PayPalPro-card-number']) ? woocommerce_clean($_POST['PayPalPro-card-number']) : '';
            $card_cvc = isset($_POST['PayPalPro-card-cvc']) ? woocommerce_clean($_POST['PayPalPro-card-cvc']) : '';
            $card_expiry = isset($_POST['PayPalPro-card-expiry']) ? woocommerce_clean($_POST['PayPalPro-card-expiry']) : '';

            // Format values
            $card_number = str_replace(array(' ', '-'), '', $card_number);
            $card_expiry = array_map('trim', explode('/', $card_expiry));
            $card_exp_month = str_pad($card_expiry[0], 2, "0", STR_PAD_LEFT);
            $card_exp_year = $card_expiry[1];


            if (strlen($card_exp_year) == 2) {
                $card_exp_year += 2000;
            }

            $card_exp = $card_exp_month . $card_exp_year;

            return apply_filters('woocommerce_paypalpro_paypal_args', array(
                'PAYMENTACTION' => $this->PAYPAL_NVP_PAYMENTACTION,
                'VERSION' => $this->PAYPAL_NVP_API_VERSION,
                'METHOD' => $this->PAYPAL_NVP_METHOD,
                'PWD' => $this->apipassword,
                'USER' => $this->apiusername,
                'SIGNATURE' => $this->apisigniture,
                'AMT' => $this->order->get_total(),
                'FIRSTNAME' => $this->order->billing_first_name,
                'LASTNAME' => $this->order->billing_last_name,
                'CITY' => $this->order->billing_city,
                'STATE' => $this->order->billing_state,
                'ZIP' => $this->order->billing_postcode,
                'COUNTRYCODE' => $this->order->billing_country,
                'IPADDRESS' => $_SERVER['REMOTE_ADDR'],
                'CREDITCARDTYPE' => '',
                'ACCT' => $card_number,
                'CVV2' => $card_cvc,
                'EXPDATE' => $card_exp,
                'STREET' => sprintf('%s, %s', $_POST['billing_address_1'], $_POST['billing_address_2']),
                'CURRENCYCODE' => get_option('woocommerce_currency'),
                'BUTTONSOURCE' => 'mbjtechnolabs_SP'
            ));
        }

        return false;
    }

}

/**
 * @since    1.0.0
 */
function add_paypal_credit_cart($methods) {
    array_push($methods, 'PayPal_Credit_Card_For_WooCommerce_PayPalExtension');
    return $methods;
}

add_filter('woocommerce_payment_gateways', 'add_paypal_credit_cart');