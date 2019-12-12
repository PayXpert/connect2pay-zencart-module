<?php
/**
 * PayXpert Payment Module.
 *
 * PHP dependencies:
 * PHP >= 5.2.0
 *
 * @version 1.0.1 (20191212)
 * @author Heitor Dolinski
 * @copyright 2019 PayXpert
 *
 */ 

class payxpert extends base {
    var $code, $title, $description, $enabled, $checkoutURL, $status, $message;

// class constructor
    function payxpert() {
      global $order;

      $this->signature = 'payxpert|1.0.1|20191212';

      $this->code = 'payxpert';
      $this->title = MODULE_PAYMENT_PAYXPERT_TEXT_TITLE;
      $this->public_title = MODULE_PAYMENT_PAYXPERT_TEXT_PUBLIC_TITLE;
      $this->description = MODULE_PAYMENT_PAYXPERT_TEXT_DESCRIPTION;
      $this->sort_order = MODULE_PAYMENT_PAYXPERT_SORT_ORDER;
      $this->enabled = ((MODULE_PAYMENT_PAYXPERT_STATUS == 'True') ? true : false);

      if ((int)MODULE_PAYMENT_PAYXPERT_PREPARE_ORDER_STATUS_ID > 0) {
        $this->order_status = MODULE_PAYMENT_PAYXPERT_PREPARE_ORDER_STATUS_ID;
      }

      if (is_object($order)) $this->update_status();

      // $this->form_action_url = tep_href_link('ext/modules/payment/payxpert/redirect.php', '', 'SSL');
    }

// class methods
    function update_status() {
      global $order, $db;
      if ($this->enabled && (int)MODULE_PAYMENT_PAYXPERT_ZONE > 0 && isset($order->billing['country']['id'])) {
        $check_flag = false;
        $check = $db->Execute("select zone_id from " . TABLE_ZONES_TO_GEO_ZONES . " where geo_zone_id = '" . MODULE_PAYMENT_PAYXPERT_ZONE . "' and zone_country_id = '" . $order->delivery['country']['id'] . "' order by zone_id");
        while (!$check->EOF) {
          if ($check->fields['zone_id'] < 1) {
            $check_flag = true;
            break;
          } elseif ($check->fields['zone_id'] == $order->delivery['zone_id']) {
            $check_flag = true;
            break;
          }
          $check->MoveNext();
        }
        if ($check_flag == false) {
          $this->enabled = false;
        }
      }
    }

    function javascript_validation() {
      return false;
    }

    function selection() {

      return array('id' => $this->code,
                   'module' => '<img src="images/connect2pay/payxpert_transparent_zencart.png" alt="PayXpert Credit card payments" /> PayXpert Credit card payments',
             );
    }

    function pre_confirmation_check() {
      return false;
    }

    function confirmation() {
      // return array('title' => MODULE_PAYMENT_PAYXPERT_TEXT_DESCRIPTION);
      return false;
    }

    function process_button() {

      return false;
    }

    function before_process() {
      
      return false;
    }

    function after_process() {
      global $db, $order, $insert_id, $customer_id, $messageStack;

      require(dirname(__FILE__) . '/connect2pay/Connect2PayClient.php');
      
      $order->info['payment_method'] = 'PayXpert';
      $order->info['payment_module_code'] = 'payxpert';

      $email = $order->customer['email_address'];
      $customer_id = $this->get_customer_id($email);

      $data = $order->info;
      $customer = $order->customer;
      
      $c2pClient = new PayXpert\Connect2Pay\Connect2PayClient(MODULE_PAYMENT_PAYXPERT_URL, MODULE_PAYMENT_PAYXPERT_ORIGINATOR, MODULE_PAYMENT_PAYXPERT_PASSWORD);
      // Setup parameters
      $c2pClient->setOrderID($insert_id);
      $c2pClient->setCustomerIP($_SERVER["REMOTE_ADDR"]);
      $c2pClient->setPaymentMethod(PayXpert\Connect2Pay\Connect2PayClient::_PAYMENT_TYPE_CREDITCARD);
      $c2pClient->setPaymentMode(PayXpert\Connect2Pay\Connect2PayClient::_PAYMENT_MODE_SINGLE);
      $c2pClient->setShopperID($customer_id);
      $c2pClient->setCtrlCustomData($customer_id);
      $c2pClient->setShippingType(PayXpert\Connect2Pay\Connect2PayClient::_SHIPPING_TYPE_VIRTUAL);
      $c2pClient->setAmount($data['total'] * 100);
      $c2pClient->setOrderDescription($order->products[0]['name']);
      $c2pClient->setCurrency($data['currency']);
      $c2pClient->setShopperFirstName($customer['firstname']);
      $c2pClient->setShopperLastName($customer['lastname']);
      $c2pClient->setShopperAddress($customer['street_address']);
      $c2pClient->setShopperZipcode($customer['postcode']);
      $c2pClient->setShopperCity($customer['city']);
      $c2pClient->setShopperState($customer['state']);
      $c2pClient->setShopperCountryCode($customer['country']['iso_code_2']);
      $c2pClient->setShopperPhone($customer['telephone']);
      $c2pClient->setShopperEmail($customer['email_address']);
      // $c2pClient->setCtrlRedirectURL(tep_href_link('ext/modules/payment/payxpert/returnurl.php', '', 'SSL'));
      // $c2pClient->setCtrlCallbackURL(tep_href_link('ext/modules/payment/payxpert/callback.php', '', 'SSL'));

      $c2pClient->setCtrlRedirectURL(zen_href_link('c2p_callback.php', '', 'SSL', false, false, true));
      $c2pClient->setCtrlCallbackURL(zen_href_link('c2p_callback.php', '', 'SSL', false, false, true));

      // Validate our information
      if ($c2pClient->validate()) {
      
        // Setup the tranaction
        if ($c2pClient->preparePayment()) {
          // We save in session the customer info
          $_SESSION['payxpertMerchantToken'] = $c2pClient->getMerchantToken();
        
          // if setup is correct redirect to the payment page.
          zen_redirect($c2pClient->getCustomerRedirectURL());
        } else {
          // var_dump($c2pClient);
          $payment_error_return = 'payment_error=' . "error";
          $messageStack->add_session('checkout_payment', "Error while processing: " . $c2pClient->getClientErrorMessage());
          zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, $payment_error_return, 'SSL', true, false));
        }
        // return false;
      } else {
        $payment_error_return = 'payment_error=' . "error";
        $messageStack->add_session('checkout_payment', "Error while processing: " . $c2pClient->getClientErrorMessage());
        zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, $payment_error_return, 'SSL', true, false));
      }
    }

    function get_error() {
      return false;
    }

    function check() {
      global $db;
      if (!isset($this->_check)) {
        $check_query = $db->Execute("select configuration_value from " . TABLE_CONFIGURATION . " where configuration_key = 'MODULE_PAYMENT_PAYXPERT_STATUS'");
        $this->_check = $check_query->RecordCount();
      }
      return $this->_check;
    }
    
    function get_customer_id($email) {
      global $db;
      $id = $db->Execute("select customers_id from " . TABLE_CUSTOMERS . " where customers_email_address = '" . $email . "' LIMIT 1;");

      while (!$id->EOF) {
        return $id->fields['customers_id'];
      }
    }

    function install() {
      global $db, $messageStack;
      // $check_query = $db->Execute("select orders_status_id from " . TABLE_ORDERS_STATUS . " where orders_status_name = 'Preparing [PayXpert]' limit 1");

      if (defined('MODULE_PAYMENT_PAYXPERT_STATUS')) {
        $messageStack->add_session('PayXpert module already installed.', 'error');
        zen_redirect(zen_href_link(FILENAME_MODULES, 'set=payment&module=payxpert', 'NONSSL'));
        return 'failed';
      }

      $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable PayXpert', 'MODULE_PAYMENT_PAYXPERT_STATUS', 'False', 'Do you want to accept PayXpert payments?', '6', '0', 'zen_cfg_select_option(array(\'True\', \'False\'), ', now())");
      $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Originator', 'MODULE_PAYMENT_PAYXPERT_ORIGINATOR', '', 'Your Originator ID', '6', '0', now())");
      $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Password', 'MODULE_PAYMENT_PAYXPERT_PASSWORD', '', 'Your password associated with your Originator', '6', '0', now())");
      $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Gateway URL', 'MODULE_PAYMENT_PAYXPERT_URL', 'https://connect2.payxpert.com', 'Leave this field empty unless, PayXpert provides you an URL', '6', '0', now())");
      $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Sort order of display.', 'MODULE_PAYMENT_PAYXPERT_SORT_ORDER', '0', 'Sort order of display. Lowest is displayed first.', '6', '0', now())");
      $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added) values ('Payment Zone', 'MODULE_PAYMENT_PAYXPERT_ZONE', '0', 'If a zone is selected, only enable this payment method for that zone.', '6', '2', 'zen_get_zone_class_title', 'zen_cfg_pull_down_zone_classes(', now())");
      $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Set Preparing Order Status', 'MODULE_PAYMENT_PAYXPERT_PREPARE_ORDER_STATUS_ID', '1', 'Set the status of prepared orders made with this payment module to this value', '6', '0', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())");
      $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Set Order Status', 'MODULE_PAYMENT_PAYXPERT_ORDER_STATUS_ID', '2', 'Set the status of orders made with this payment module to this value', '6', '0', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())");
    }

    function remove() {
      global $db;
      $db->Execute("delete from " . TABLE_CONFIGURATION . " where configuration_key in ('" . implode("', '", $this->keys()) . "')");
    }

    function keys() {
      return array('MODULE_PAYMENT_PAYXPERT_STATUS', 'MODULE_PAYMENT_PAYXPERT_ORIGINATOR', 'MODULE_PAYMENT_PAYXPERT_PASSWORD', 'MODULE_PAYMENT_PAYXPERT_URL', 'MODULE_PAYMENT_PAYXPERT_ZONE', 'MODULE_PAYMENT_PAYXPERT_PREPARE_ORDER_STATUS_ID', 'MODULE_PAYMENT_PAYXPERT_ORDER_STATUS_ID', 'MODULE_PAYMENT_PAYXPERT_SORT_ORDER');
    }
  }
?>
