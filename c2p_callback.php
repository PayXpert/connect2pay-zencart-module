<?php

error_reporting(E_ALL);

require('includes/application_top.php');
require(DIR_WS_MODULES . 'payment/connect2pay/Connect2PayClient.php');

global $db, $messageStack;

$c2pClient = new PayXpert\Connect2Pay\Connect2PayClient(MODULE_PAYMENT_PAYXPERT_URL, MODULE_PAYMENT_PAYXPERT_ORIGINATOR, MODULE_PAYMENT_PAYXPERT_PASSWORD);

$merchantToken = $_SESSION['payxpertMerchantToken'] ? $_SESSION['payxpertMerchantToken'] : '';
$data = $_POST["data"] ? $_POST["data"] : '';

if ($c2pClient->handleCallbackStatus()) {

  // get the Error code
  $status = $c2pClient->getStatus();
  $errorCode = $status->getErrorCode();
  $errorMessage = $status->getErrorMessage();
  $merchantData = $status->getCtrlCustomData();
  $orderId = $status->getOrderID();

  $success = false;
  $message = "Unknow error";
  $order_query = $db->Execute("select * from " . TABLE_ORDERS . " where orders_id = '" . (int)$orderId . "' and customers_id = '" . (int)$merchantData . "' limit 1");
  while (!$order_query->EOF) {
    if ($order_query->RecordCount() > 0) {
      $order = $order_query->fields;
      
      $message = "PayXpert payment module:\n";
      $message .= "Received a new transaction status callback from " . $_SERVER["REMOTE_ADDR"] . ".\n";
      $message .= "Error code: " . $errorCode . "\n";
      $message .= "Error message: " . $errorMessage . "\n";
      $message .= "Transaction ID: " . $orderId . "\n";

      if ($errorCode == '000') {
        $_SESSION['cart']->reset(true);
        // if ($order['orders_status'] == MODULE_PAYMENT_PAYXPERT_PREPARE_ORDER_STATUS_ID) {
        $order_status_id = (MODULE_PAYMENT_PAYXPERT_ORDER_STATUS_ID > 0 ? (int)MODULE_PAYMENT_PAYXPERT_ORDER_STATUS_ID : (int)DEFAULT_ORDERS_STATUS_ID);

        $db->Execute("UPDATE " . TABLE_ORDERS . " SET orders_status = '" . $order_status_id . "', last_modified = now() WHERE orders_id = '" . (int)$orderId . "'");
        // }
      }
      $sql_data_array = array('orders_id' => $orderId,
                              'orders_status_id' => $order_status_id,
                              'date_added' => 'now()',
                              'customer_notified' => '0',
                              'comments' => $message);
      zen_db_perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);

    }

    $order_query->MoveNext();
  }

  $response = array("status" => "OK", "message" => "Status recorded");
  header("Content-type: application/json");
  echo json_encode($response);
} elseif ($c2pClient->handleRedirectStatus($data, $merchantToken)) {
    $status = $c2pClient->getStatus();
    // get the Error code
    $errorCode = $status->getErrorCode();
    $errorMessage = $status->getErrorMessage();
    $orderId = $status->getOrderID();
    $order_status_id = 4;
    // errorCode = 000 transaction is successfull
    if ($errorCode == '000') {
      $_SESSION['cart']->reset(true);
      // Display the success page
      zen_redirect(zen_href_link(FILENAME_CHECKOUT_SUCCESS, '', 'SSL'));
      $message = "Customer redirected back to store successfully.";
    } else {
      // Display the checkout page
      $messageStack->add_session('checkout_payment',"Transaction status: " . $status->getStatus() . " (Error code: " . $errorCode . ")");
      zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, null, 'SSL'));
      $message = "Customer didn't finish payment. " . $errorMessage;
    }

    $sql_data_array = array('orders_id' => $orderId,
                            'orders_status_id' => $order_status_id,
                            'date_added' => 'now()',
                            'customer_notified' => '0',
                            'comments' => $message);
    zen_db_perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);

}

require('includes/application_bottom.php'); 