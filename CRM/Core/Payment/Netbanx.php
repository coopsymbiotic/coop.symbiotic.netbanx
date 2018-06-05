<?php

/*
 +--------------------------------------------------------------------+
 | Netbanx Payment Gateway Processor (post/autonomous)                |
 +--------------------------------------------------------------------+
 | Copyright Mathieu Lutfy 2010-2015                                  |
 | https://www.symbiotic.coop                                         |
 |                                                                    |
 | This file is part of the coop.symbiotic.netbanx extension.         |
 | https://github.com/coopsymbiotic/coop.symbiotic.netbanx            |
 |                                                                    |
 | See README.md for more information (support, license, etc).        |
 +--------------------------------------------------------------------+
*/

/**
 * @file
 *
 * Credit Card Payment Processor class for Netbanx (post/autonomous mode).
 */

require_once 'CRM/Core/Payment.php';

class CRM_Core_Payment_Netbanx extends CRM_Core_Payment {
  // Netbanx SOAP services paths
  const CIVICRM_NETBANX_SERVICE_CREDIT_CARD = 'creditcardWS/CreditCardService';

  // Netbanx SOAP responses
  const CIVICRM_NETBANX_PAYMENT_ACCEPTED = 'ACCEPTED';
  const CIVICRM_NETBANX_PAYMENT_DECLINED = 'DECLINED';
  const CIVICRM_NETBANX_PAYMENT_ERROR    = 'ERROR';

  /**
   * Live auth endpoint.
   *
   * @var string
   */
  protected $live_auth_endpoint = 'https://api.netbanx.com/cardpayments/v1/accounts';

  /**
   * Test auth endpoint.
   *
   * @var string
   */
  protected $test_auth_endpoint = 'https://api.test.netbanx.com/cardpayments/v1/accounts';

  /**
   * Live Customer Vault endpoint.
   *
   * @var string
   */
  protected $live_customervault_endpoint = 'https://api.netbanx.com/customervault/v1/profiles';

  /**
   * Test Customer Vault endpoint.
   *
   * @var string
   */
  protected $test_customervault_endpoint = 'https://api.test.netbanx.com/customervault/v1/profiles';

  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable.
   *
   * @var object
   * @static
   */
  static private $_singleton = NULL;

  /**
   * Mode of operation: live or test.
   *
   * @var string
   */
  protected $_mode = NULL;

  // IP of the visitor
  private $ip = 0;

  // CiviCRM invoice ID
  private $invoice_id = NULL;

  /**
   * Constructor
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return void
   */
  function __construct($mode, &$paymentProcessor) {
    $this->_mode = $mode;
    $this->_paymentProcessor = $paymentProcessor;
    $this->_processorName = ts('Netbanx', array('domain' => 'coop.symbiotic.netbanx'));
  }

  /**
   * Singleton function used to manage this object.
   *
   * @param string $mode the mode of operation: live or test.
   *
   * @return object
   * @static
   *
   */
  static function &singleton($mode, &$paymentProcessor, &$paymentForm = NULL, $force = false) {
    $processorName = $paymentProcessor['name'];
    if (self::$_singleton[$processorName] === NULL) {
      self::$_singleton[$processorName] = new self($mode, $paymentProcessor);
    }
    return self::$_singleton[$processorName];
  }

  /**
   * Submit a payment using the Netbanx API.
   *
   * @param array $params assoc array of input parameters for this transaction
   *
   * @return array the result in a nice formatted array (or an error object)
   * @public
   */
  function doDirectPayment(&$params) {
    if (! class_exists('SoapClient')) {
      return self::error('The Netbanx API service requires php-soap.  Please talk to your system administrator to get this configured (Debian/Ubuntu: apt-get install php-soap).');
    }

    $this->ip = $params['ip_address'];
    $this->invoice_id = $params['invoiceID'];

/*
    // Fraud-protection: Validate the postal code
    if (! self::isValidPostalCode($params)) {
      watchdog('civicrmnetbanx', 'Invalid postcode for Canada: ' . print_r($params, 1));
      return self::netbanxFailMessage('NBX002', 'request invalid postcode', $params);
    }
*/

/* less necessary now that we have CVV2
    // Fraud-protection: Limit the number of transactions: 1 per hours
    if ($this->isTooManyTransactions($params)) {
      watchdog('civicrmnetbanx', 'Too many transactions from: ' . $params['ip_address']);
      return self::netbanxFailMessage('NBX003', 'request flood by ip', $params);
    }
*/

    $this->log($params, 'civicrm params');

    $data = array(
      'merchantAccount' => $this->netbanxMerchantAccount(),
      'merchantRefNum' => $this->invoice_id,  // string max 255 chars
      'amount' => self::netbanxGetAmount($params),
      'card' => self::netbanxGetCard($params),
      'customerIP' => $params['ip_address'],
      'billingDetails' => self::netbanxGetBillingDetails($params),
    );

    $data = array(
      'merchantRefNum' => $this->invoice_id,  // string max 255 chars
      'settleWithAuth' => TRUE,
      'amount' => self::netbanxGetAmount($params),
      'customerIp' => $params['ip_address'],
    );

    if (CRM_Utils_Array::value('is_recur', $params)) {
      // TODO: This is not yet fully handled. It will create the
      // customer, address and card in Netbanx's Customer Vault,
      // but the resulting token is not saved in CiviCRM for regular
      // processing.
      $frequency = $params['frequency_unit'];
      $installments = $params['installments'];

      $vault = $this->netbanxCustomerVaultCreate([
        // NB: we would have to manage locally which customers always exist,
        // and we don't really care about duplicates, so just create a new
        // profile for each recurrent transaction (there shouldn't be that many).
        'merchantCustomerId' => $params['invoiceID'],
        'locale' => $this->netbanxGetLocale($params),
        'firstName' => $params['first_name'],
        'lastName' => $params['last_name'],
        'email' => $params['email-5'],
        // FIXME not ideal, assumes billing-phone in profile:
        'phone' => CRM_Utils_Array::value('phone-5-1', $params),
        'ip' => $params['ip_address'],
        'addresses' => [
          $this->netbanxGetBillingDetails($params, TRUE),
        ],
        'cards' => [
          $this->netbanxGetCard($params, TRUE),
        ],
      ]);

      $data['card'] = [
        'paymentToken' => $vault['paymentToken'],
      ];
    }
    else {
      $data['billingDetails'] = $this->netbanxGetBillingDetails($params);
      $data['profile'] = $this->netbanxGetProfileDetails($params);
      $data['card'] = $this->netbanxGetCard($params);
    }

    try {
      $response = $this->netbanxAuthorize($data);

      if ($response == NULL) {
        return self::netbanxFailMessage('NBX010', 'netbanx response null', $params);
      }
    }
    catch (Exception $e) {
      $this->log('Netbanx error: ' . $e->getMessage(), 'netbanx purchase fail', TRUE);
      return self::error(ts('There was a communication problem with the payment processor. Please try again, or contact us for more information.', array('domain' => 'coop.symbiotic.netbanx')));
    }

    if ($response['status'] != 'COMPLETED') {
      $receipt = $this->generateReceipt($params, $response, FALSE);
      return $this->netbanxFailMessage($receipt, 'netbanx authorization declined', $params, $response);
    }

    // Success
    $params['trxn_id'] = $response['id'];
    # what? $params['gross_amount']  = $data['amount'];

    // Assigning the receipt to the $params doesn't really do anything
    // In previous versions, we would patch the core in order to show the receipt.
    // It would be nice to have something in CiviCRM core in order to handle this.
    $params['receipt_netbanx'] = self::generateReceipt($params, $response);
    $params['trxn_result_code'] = $response['id'];

    CRM_Core_DAO::executeQuery("INSERT INTO civicrm_netbanx_receipt (trx_id, receipt, first_name, last_name, card_type, card_number, timestamp, ip)
              VALUES (%1, %2, %3, %4, %5, %6, %7, %8)",
              array(
                1 => array($params['trxn_id'], 'String'),
                2 => array($params['receipt_netbanx'], 'String'),
                3 => array($params['first_name'], 'String'),
                4 => array($params['last_name'], 'String'),
                5 => array($params['credit_card_type'], 'String'),
                6 => array(self::netbanxGetCardForReceipt($params['credit_card_number']), 'String'),
                7 => array(time(), 'String'),
                8 => array($this->ip, 'String'),
             ));

    // Invoke hook_civicrmdesjardins_success($params, $purchase).
    module_invoke_all('civicrmdesjardins_success', $params, $response);

    return $params;
  }

  /**
   * Returns a correctly formatted array with the merchant account info.
   */
  function netbanxMerchantAccount() {
    return array(
      'accountNum' => $this->_paymentProcessor['subject'],
      'storeID' => $this->_paymentProcessor['user_name'], // aka 'Merchant ID'
      'storePwd' => $this->_paymentProcessor['password'],
    );
  }

  /**
   * Extracts the transaction amount
   * Returns in the format: 20.50
   *
   * NB: new REST API uses format 2500 for 25$
   */
  function netbanxGetAmount($params) {
    $amount = 0;

    if (! empty($params['amount'])){
      $amount = $params['amount'];
    }
    else{
      $amount = $params['amount_other'];
    }

    // format: 10.00
    // FIXME: remove? (was for SOAP backend)
    $amount = number_format($amount, 2, '.', '');

    // REST API expects an integer amount.
    $amount = $amount * 100;

    return $amount;
  }

  /**
   * Extracts the credit card info.
   * Returns an array.
   */
  function netbanxGetCard($params, $is_vault = FALSE) {
    $card = array(
      'cardNum' => $params['credit_card_number'],
      'cardExpiry' => array(
        'month' => $params['month'],
        'year' => $params['year'],
      ),
    );

    // Add security code.
    if (! empty($params['cvv2'])) {
      $card['cvv'] = $params['cvv2'];
    }

    if ($is_vault) {
      $card['nickName'] = $params['credit_card_type'];
      $card['holderName'] = $params['first_name'] . ' ' . $params['last_name'];
    }

    return $card;
  }

  /**
   * Extracts the billing details info.
   * Returns an array.
   */
  function netbanxGetBillingDetails($params, $is_vault = FALSE) {
    $billing = array(
      'street' => $params['street_address'],
      'city' => $params['city'],
      'country' => $params['country'],
      'zip' => $params['postal_code'],
    );

    // Add state or region based on country
    if (in_array($params['country'], array('US', 'CA'))) {
      $billing['state'] = $params['state_province'];
    }

    if ($is_vault) {
      $billing['nickName'] = 'Billing address';
    }

    return $billing;
  }

  function netbanxGetProfileDetails($params) {
    $profile = array(
      'firstName' => $params['first_name'],
      'lastName' => $params['last_name'],
      'email' => (isset($params['email-Primary']) ? $params['email-Primary'] : $params['email-5']),
    );

    return $profile;
  }

  /**
   * Convert the locale/language to an accepted format.
   *
   * Depending on your configuration, it will return:
   *  - the value of the field "preferred_language" if you have included it in your form,
   *  - the value of $tsLocale, which is the language of the user interface,
   *  - the value of $config->lcMessages, which is the default CiviCRM language,
   *  - if all else fails, defaults to en_US.
   */
  function netbanxGetLocale($params) {
    $lang = 'en_US';

    // Netbanx only supports fr_CA, en_US and en_UK.
    $map = array(
      'fr_CA' => 'fr_CA',
      'fr_FR' => 'fr_CA',
      'en_US' => 'en_US',
      'en_GB' => 'en_GB',
    );

    // This should always exist, i.e. default CiviCRM language.
    $config = CRM_Core_Config::singleton();
    $lang = $config->lcMessages;

    // This usually exists as well, and is the current UI language.
    global $tsLocale;

    if (isset($tsLocale) && isset($map[$tsLocale])) {
      $lang = $map[$tsLocale];
    }

    if ($locale = CRM_Utils_Array::value('preferred_language', $params)) {
      if (isset($map[$locale])) {
        $lang = $map[$locale];
      }
    }

    return $lang;
  }

  /**
   * Sends an authorization request to the REST API of Netbanx.
   */
  function netbanxAuthorize($data) {
    $webservice_url = $this->getAuthorizationEndpoint();
    return $this->netbanxSendRequest($webservice_url, $data, 'auth');
  }

  /**
   * Stores the customer profile in the Netbanx Custom Vault API.
   */
  function netbanxCustomerVaultCreate($data) {
    $webservice_url = $this->getCustomerVaultEndpoint();
    $profile = $this->netbanxSendRequest($webservice_url, $data, 'customervault');

    if ($profile['id']) {
      $address = $this->netbanxSendRequest($webservice_url . '/' . $profile['id'] . '/addresses', $data['addresses'][0]);
      $card = $this->netbanxSendRequest($webservice_url . '/'  . $profile['id'] . '/cards', $data['cards'][0] + array('billingAddressId' => $address['id']));

      return $card;
    }

    return NULL;
  }

  function netbanxSendRequest($webservice_url, $data, $description) {
    require 'vendor/autoload.php';
    $client = new GuzzleHttp\Client();

    $json = NULL;

    self::log($data, 'netbanx request ' . $description);

    try {
      $api_username = $this->_paymentProcessor['user_name'];
      $api_key = $this->_paymentProcessor['password'];

      $headers = [
        'Content-Type' => 'application/json',
        'Authorization' => 'Basic ' . base64_encode($api_username . ':' . $api_key),
      ];

      $response = $client->post($webservice_url, [
        'body' => json_encode($data),
        'headers' => $headers,
      ]);

      $json = $response->getBody()->getContents();
      $json = json_decode($json, 1);

      $this->log($json, 'netbanx response ' . $description);
    }
    catch (RequestException $e) {
      $this->log($e->getMessage(), 'netbanx request exception');
      return NULL;
    }
    catch (Exception $e) {
      $this->log($e->getMessage(), 'netbanx generic exception');
      return NULL;
    }

    return $json;
  }

  /**
   * Returns the appropriate endpoint URL to process Authorization requests.
   */
  function getAuthorizationEndpoint() {
    $url = ($this->_mode == 'test' ? $this->test_auth_endpoint : $this->live_auth_endpoint);
    $merchant_number = $this->_paymentProcessor['subject'];

    return $url . '/' . $merchant_number . '/auths';
  }

  /**
   * Returns the appropriate endpoint URL to process Customer Vault requests.
   */
  function getCustomerVaultEndpoint() {
    $url = ($this->_mode == 'test' ? $this->test_customervault_endpoint : $this->live_customervault_endpoint);
    $merchant_number = $this->_paymentProcessor['subject'];

    return $url;
  }

  /**
   * Input: 4511111111111111
   * Returns: **** **** **** 1111 (Visa/MC/Amex requirement)
   */
  function netbanxGetCardForReceipt($card_number) {
    $a = substr($card_number, 0, 2);
    $b = substr($card_number, -4, 4);
    $str = '**** **** **** ' . $b;
    return $str;
  }

  /**
   * Make CiviCRM return a fail message and cancel the transaction.
   * FIXME: this is not very clean..
   */
  function netbanxFailMessage($code, $errtype, $request = NULL, $response = NULL) {
    self::log($response, $errtype, TRUE);

    // FIXME: format: self::error(9003, 'Message here'); ?
    if (is_numeric($code)) {
      return self::error(ts("Error %1: The transaction could not be processed, please contact us for more information.", array(1 => $code, 'domain' => 'coop.symbiotic.netbanx')));
    }

    return self::error('<div class="civicrm-netbanx-retrytx">' . ts("The transaction was not approved. Please verify your credit card number and expiration date.", array('domain' => 'coop.symbiotic.netbanx')) . '</div>'
           . '<div><strong>̈́' . $this->getErrorMessageTranslation($response) . '</strong></div>'
           . '<br/><pre class="civicrm-netbanx-receiptfail">' . $code . '</pre>');
  }

  /**
   * Returns a translated error message for the more common errors.
   * Otherwise it returns the original English message.
   *
   * https://developer.optimalpayments.com/en/documentation/card-payments-api/simulating-response-codes/
   */
  function getErrorMessageTranslation($response) {
    // Legacy soap call, but also to avoid weird errors (will cause fatal errors if it's not an object)
    if (! is_array($response)) {
      return '';
    }

    if (! isset($response['error']) || ! isset($response['error']['message'])) {
      return ts('Unknown error.', array('domain' => 'coop.symbiotic.netbanx'));
    }

    // FIXME: This is an intentional mis-use of ts(), see strings below.
    return ts($response['error']['message'], array('domain' => 'coop.symbiotic.netbanx')) . ' (' . $response['error']['code'] . ')';

    // This is intentionally here so that the gettext string extractor will pickup the strings for the .pot files.
    ts("The bank has requested that you process the transaction manually by calling the card holder's credit card company.", array('domain' => 'coop.symbiotic.netbanx'));
    ts('Your request has been declined by the issuing bank.', array('domain' => 'coop.symbiotic.netbanx'));
    ts('The card has been declined due to insufficient funds.', array('domain' => 'coop.symbiotic.netbanx'));
    ts('Your request has been declined because the issuing bank does not permit the transaction for this card.', array('domain' => 'coop.symbiotic.netbanx'));
    ts('An internal error occurred.', array('domain' => 'coop.symbiotic.netbanx'));
    ts('The external processing gateway has rejected the transaction.', array('domain' => 'coop.symbiotic.netbanx'));
  }

  /**
   * Validate the postal code.
   * Returns TRUE if the postal code is valid.
   */
  function isValidPostalCode($params) {
    if ($params['country'] != 'CA') {
      return TRUE;
    }

    $province     = $params['state_province'];
    $postal_code  = $params['postal_code'];
    $postal_first = strtoupper(substr($postal_code, 0, 1));

    $provinces_codes = array(
      'AB' => array('T'),
      'BC' => array('V'),
      'MB' => array('R'),
      'NB' => array('E'),
      'NL' => array('A'),
      'NT' => array('X'),
      'NS' => array('B'),
      'NU' => array('X'),
      'ON' => array('K', 'L', 'M', 'N', 'P'),
      'PE' => array('C'),
      'QC' => array('H', 'J', 'G'),
      'SK' => array('S'),
      'YT' => array('Y'),
    );

    if (in_array($postal_first, $provinces_codes[$province])) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Check whether the person (by IP address) has been doing too many transactions lately (2 tx in the past 6 hours)
   * Returns TRUE if there have been too many transactions
   */
  function isTooManyTransactions($params) {
    $ip = $params['ip_address'];

    $nb_tx_lately = CRM_Core_DAO::singleValueQuery('SELECT count(*) from civicrm_netbanx_receipt
       WHERE ip = %1 and timestamp > UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 1 HOUR))',
       array(1 => array($ip, 'String')));

    if ($nb_tx_lately >= 400) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * error : either an object that implements getResponseCode() and getErrorMessage, or a string.
   * errnum : if the error is a string, this should have the error number.
   */
  function &error($error = null, $errnum = 9002) {
      $e =& CRM_Core_Error::singleton();
      if (is_object($error)) {
          $e->push( $error->getResponseCode(),
                    0, null,
                    $error->getErrorMessage());
      } elseif (is_string($error)) {
          $e->push( $errnum,
                    0, null,
                    $error);
      } else {
          $e->push(9001, 0, null, "Unknown System Error.");
      }
      return $e;
  }

  /**
   * This function checks to see if we have the right config values
   *
   * @return string the error message if any
   * @public
   */
  function checkConfig() {
    $error = array();

    if (empty($this->_paymentProcessor['user_name'])) {
      $error[] = ts('Merchant ID is not set in the Administer CiviCRM &raquo; Payment Processor.', array('domain' => 'coop.symbiotic.netbanx'));
    }

    if (empty($this->_paymentProcessor['password'])) {
      $error[] = ts('Password is not set in the Administer CiviCRM &raquo; Payment Processor.', array('domain' => 'coop.symbiotic.netbanx'));
    }

    if (! empty($error)) {
      return implode('<p>', $error);
    } else {
      return null;
    }
  }

  /**
   * Logs exchanges with Netbanx
   */
  function log($message, $type, $fail = 0) {
    $time = time();

    // If the message is a params, data or response, cleanse it before print_r
    // credit card numbers/cvv2 must not be stored in the database
    if (is_array($message)) {
      if (isset($message['card'])) {
        $message['card']['cardNum'] = self::netbanxGetCardForReceipt($message['card']['cardNum']);
        $message['card']['cvd'] = 'XYZ';
      }

      if (isset($message['credit_card_number'])) {
        $message['credit_card_number'] = self::netbanxGetCardForReceipt($message['credit_card_number']);
        $message['cvv2'] = 'XYZ';
      }

      $message = print_r($message, 1);
    }
    elseif (is_object($message)) {
      $message = print_r($message, 1);
    }

    // sometimes the field is empty, not 0
    if (! $fail) {
      $fail = 0;
    }

    // If the 'reporterror' extension is enabled, send an email for fatal errors.
    if ($fail && function_exists('reporterror_civicrm_handler')) {
      $variables = array(
        'message' => $type,
        'body' => $message,
      );

      reporterror_civicrm_handler($variables);
    }

    CRM_Core_DAO::executeQuery("INSERT INTO civicrm_netbanx_log (trx_id, timestamp, type, message, fail, ip) VALUES (%1, %2, %3, %4, %4, %5)",
      array(
        1 => array($this->invoice_id, 'String'),
        2 => array($time, 'String'),
        3 => array(isset($type) ? $type : '', 'String'),
        4 => array(isset($message) ? $message : '', 'String'),
        5 => array($fail, 'String'),
        6 => array($this->ip, 'String'),
      )
    );
  }

  /**
   * Generates a human-readable receipt using the purchase response from Netbanx.
   *
   * @param $params Array of form data.
   * @param $response Array of the Netbanx response.
   */
  function generateReceipt($params, $response) {
    $receipt = '';

    // CiviCRM's invoice ID.
    $trx_id = $this->invoice_id;

    $receipt .= self::getNameAndAddress() . "\n\n";

    $receipt .= ts('CREDIT CARD TRANSACTION RECORD', array('domain' => 'coop.symbiotic.netbanx')) . "\n\n";

    if (isset($response['txnTime'])) {
      $receipt .= ts('Date: %1', array(1 => $response['txnTime'], 'domain' => 'coop.symbiotic.netbanx')) . "\n";
    }
    else {
      $receipt .= ts('Date: %1', array(1 => date('Y-m-d H:i:s'), 'domain' => 'coop.symbiotic.netbanx')) . "\n";
    }

    $receipt .= ts('Transaction: %1', array(1 => $this->invoice_id, 'domain' => 'coop.symbiotic.netbanx')) . "\n";
    $receipt .= ts('ID: %1', array(1 => $response['id'], 'domain' => 'coop.symbiotic.netbanx')) . "\n";
    $receipt .= ts('Type: purchase', array('domain' => 'coop.symbiotic.netbanx')) . "\n"; // could be preauthorization, preauth completion, refund.
    $receipt .= ts('Authorization: %1', array(1 => $response['authCode'], 'domain' => 'coop.symbiotic.netbanx')) . "\n";

    $receipt .= ts('Credit card type: %1', array(1 => $params['credit_card_type'], 'domain' => 'coop.symbiotic.netbanx')) . "\n";
    $receipt .= ts('Credit card holder name: %1', array(1 => $params['first_name'] . ' ' . $params['last_name'], 'domain' => 'coop.symbiotic.netbanx')) . "\n";
    $receipt .= ts('Credit card number: %1', array(1 => self::netbanxGetCardForReceipt($params['credit_card_number']), 'domain' => 'coop.symbiotic.netbanx')) . "\n\n";

    $receipt .= ts('Transaction amount: %1', array(1 => CRM_Utils_Money::format($params['amount']), 'domain' => 'coop.symbiotic.netbanx')) . "\n\n";

    switch ($response['status']) {
      case 'COMPLETED':
        $receipt .= ts('TRANSACTION APPROVED - THANK YOU', array('domain' => 'coop.symbiotic.netbanx')) . "\n\n";
        break;

      case 'FAILED':
      default:
        $receipt .= ts('TRANSACTION FAILED', array('domain' => 'coop.symbiotic.netbanx')) . "\n\n";

        if (isset($response['error'])) {
          $receipt .= wordwrap($this->getErrorMessageTranslation($response)) . "\n\n";
        }
    }

    if (function_exists('variable_get')) {
      $tos_url  = variable_get('civicrmdesjardins_tos_url', FALSE);
      $tos_text = variable_get('civicrmdesjardins_tos_text', FALSE);

      if ($tos_url) {
        $receipt .= ts("Terms and conditions:") . "\n";
        $receipt .= $tos_url . "\n\n";
      }

      if ($tos_text) {
        $receipt .= wordwrap($tos_text);
      }
    }

    // Add obligatory notes:
    $receipt .= "\n";
    $receipt .= ts('Prices are in canadian dollars ($ CAD).', array('domain' => 'coop.symbiotic.netbanx')) . "\n";
    $receipt .= ts("This transaction is non-taxable.", array('domain' => 'coop.symbiotic.netbanx'));

    return $receipt;
  }

  /**
   * Generates a human-readable receipt using the purchase response from Desjardins.
   * trx_id : CiviCRM transaction ID
   * amount : numeric amount of the transcation
   * success : whether this is a receipt for a successful or failed transaction (not really used)
   */
  function generateReceiptOld($params, $response, $success = TRUE) {
    $receipt = '';

    $trx_id = $this->invoice_id; // CiviCRM's ID

    $receipt .= self::getNameAndAddress() . "\n\n";

    $receipt .= ts('CREDIT CARD TRANSACTION RECORD', array('domain' => 'coop.symbiotic.netbanx')) . "\n\n";

    $receipt .= ts('Date: %1', array(1 => $response->txnTime, 'domain' => 'coop.symbiotic.netbanx')) . "\n";
    $receipt .= ts('Transaction: %1', array(1 => $this->invoice_id, 'domain' => 'coop.symbiotic.netbanx')) . "\n";
    $receipt .= ts('Type: purchase', array('domain' => 'coop.symbiotic.netbanx')) . "\n"; // could be preauthorization, preauth completion, refund.
    $receipt .= ts('Authorization: %1', array(1 => $response->authCode, array('domain' => 'coop.symbiotic.netbanx'))) . "\n";
    $receipt .= ts('Confirmation: %1', array(1 => $response->confirmationNumber, array('domain' => 'coop.symbiotic.netbanx'))) . "\n";

    $receipt .= ts('Credit card type: %1', array(1 => $params['credit_card_type'], 'domain' => 'coop.symbiotic.netbanx')) . "\n";
    $receipt .= ts('Credit card holder name: %1', array(1 => $params['first_name'] . ' ' . $params['last_name'], 'domain' => 'coop.symbiotic.netbanx')) . "\n";
    $receipt .= ts('Credit card number: %1', array(1 => self::netbanxGetCardForReceipt($params['credit_card_number']), 'domain' => 'coop.symbiotic.netbanx')) . "\n\n";

    $receipt .= ts('Transaction amount: %1', array(1 => CRM_Utils_Money::format($params['amount']), 'domain' => 'coop.symbiotic.netbanx')) . "\n\n";

    if ($response->decision == self::CIVICRM_NETBANX_PAYMENT_ACCEPTED) {
      $receipt .= ts('TRANSACTION APPROVED - THANK YOU', array('domain' => 'coop.symbiotic.netbanx')) . "\n\n";
    }
    elseif ($response->decision == self::CIVICRM_NETBANX_PAYMENT_ERROR) {
      $receipt .= wordwrap(ts('TRANSACTION CANCELLED - %1', array(1 => $response->description, 'domain' => 'coop.symbiotic.netbanx'))) . "\n\n";
    }
    elseif ($response->decision == self::CIVICRM_NETBANX_PAYMENT_DECLINED) {
      $description = $response->description;

      // Silly.. but we try to translate as many messages as possible.
      if ($description == 'Your request has been declined by the issuing bank.') {
        $description = ts('Your request has been declined by the issuing bank.', array('domain' => 'coop.symbiotic.netbanx'));
      }

      $receipt .= ts('TRANSACTION DECLINED - %1', array(1 => $description, 'domain' => 'coop.symbiotic.netbanx')) . "\n\n";
    }
    else {
      $receipt .= $response->decision . ' - ' . $response->description . "\n\n";
    }

    if (function_exists('variable_get')) {
      $tos_url  = variable_get('civicrmdesjardins_tos_url', FALSE);
      $tos_text = variable_get('civicrmdesjardins_tos_text', FALSE);

      if ($tos_url) {
        $receipt .= ts("Terms and conditions:", array('domain' => 'coop.symbiotic.netbanx')) . "\n";
        $receipt .= $tos_url . "\n\n";
      }

      if ($tos_text) {
        $receipt .= wordwrap($tos_text);
      }
    }

    // Add obligatory notes:
    $receipt .= "\n";
    $receipt .= ts('Prices are in canadian dollars ($ CAD).', array('domain' => 'coop.symbiotic.netbanx')) . "\n";
    $receipt .= ts("This transaction is non-taxable.", array('domain' => 'coop.symbiotic.netbanx'));

    return $receipt;
  }

  /**
   * Returns the org's name and address
   */
  function getNameAndAddress() {
    $receipt = '';

    // Fetch the domain name, but allow to override it (Desjardins requires that it
    // be the exact business name of the org, and sometimes we use shorter names.
    $domain = civicrm_api('Domain', 'get', array('version' => 3));

    $org_name = NULL;
    if (function_exists('variable_get')) {    
      $org_name = variable_get('civicrmdesjardins_orgname', NULL);
    }

    if (! $org_name) {
      $org_name = $domain['values'][1]['name'];
    }

    // get province abbrev
    $province = CRM_Core_DAO::singleValueQuery('SELECT abbreviation FROM civicrm_state_province WHERE id = %1', array(1 => array($domain['values'][1]['domain_address']['state_province_id'], 'Integer')));
    // $country = db_query('SELECT name FROM {civicrm_country} WHERE id = :id', array(':id' => $domain['values'][1]['domain_address']['country_id']))->fetchField();

    $receipt .= $org_name . "\n";
    $receipt .= $domain['values'][1]['domain_address']['street_address'] . "\n";
    $receipt .= $domain['values'][1]['domain_address']['city'] . ', ' . $province;

    return $receipt;
  }
}

