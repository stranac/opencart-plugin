<?php

use CoinGate\CoinGate;

require_once(DIR_SYSTEM . 'library/vendor/coingate/init.php');
require_once(DIR_SYSTEM . 'library/vendor/coingate/version.php');

class ControllerExtensionPaymentCoingate extends Controller
{
  public function __construct($registry)
  {
    parent::__construct($registry);

    CoinGate::config(array(
      'app_id'      => $this->config->get('coingate_app_id'),
      'api_key'     => $this->config->get('coingate_api_key'),
      'api_secret'  => $this->config->get('coingate_api_secret'),
      'environment' => $this->config->get('coingate_test') == 1 ? 'sandbox' : 'live',
      'user_agent'  => 'CoinGate - OpenCart v'.VERSION.' Extension v' . COINGATE_OPENCART_EXTENSION_VERSION
    ));

    $this->load->language('extension/payment/coingate');
  }

  public function index()
  {
    $data['button_confirm'] = $this->language->get('button_confirm');
    $data['button_back']    = $this->language->get('button_back');

    $data['confirm'] = $this->url->link('extension/payment/coingate/confirm', '', $this->config->get('config_secure'));

    if ($this->request->get['route'] != 'checkout/guest/confirm')
      $data['back'] = $this->url->link('checkout/payment', '', $this->config->get('config_secure'));
    else
      $data['back'] = $this->url->link('checkout/guest', '', $this->config->get('config_secure'));

    return $this->load->view('extension/payment/coingate', $data);
  }

  public function confirm()
  {
    $this->load->model('checkout/order');

    $order = $this->model_checkout_order->getOrder($this->session->data['order_id']);

    $token = $this->generate_token($order['order_id']);

    $description = array();
    foreach ($this->cart->getProducts() as $product) {
      $description[] = $product['quantity'] . ' × ' . $product['name'];
    }

    $order = \CoinGate\Merchant\Order::createOrFail(array(
      'order_id'         => $order['order_id'],
      'price'            => number_format($order['total'], 2, '.', ''),
      'currency'         => $order['currency_code'],
      'receive_currency' => $this->config->get('coingate_receive_currency'),
      'cancel_url'       => $this->url->link('extension/payment/coingate/cancel', '', $this->config->get('config_secure')),
      'callback_url'     => $this->url->link('extension/payment/coingate/callback', '', $this->config->get('config_secure')) . '&cg_token=' . $token,
      'success_url'      => $this->url->link('extension/payment/coingate/accept', '', $this->config->get('config_secure')),
      'title'            => $this->config->get('config_meta_title') . ' Order #' . $order['order_id'],
      'description'      => join($description, ', ')
    ));

    $this->model_checkout_order->addOrderHistory($order->order_id, $this->config->get('coingate_new_order_status_id'));

    $this->response->redirect($order->payment_url);
  }

  public function accept()
  {
    if (isset($this->session->data['token']))
      $this->response->redirect($this->url->link('checkout/success', 'token=' . $this->session->data['token'], $this->config->get('config_secure')));
    else
      $this->response->redirect($this->url->link('checkout/success', '', $this->config->get('config_secure')));
  }

  public function cancel()
  {
    $this->response->redirect($this->url->link('checkout/cart', '', $this->config->get('config_secure')));
  }

  public function callback()
  {
    try {
      $this->load->model('checkout/order');

      $order = $this->model_checkout_order->getOrder($_REQUEST['order_id']);

      if (!$order || !$order['order_id'])
        throw new Exception('Order #' . $_REQUEST['order_id'] . ' does not exists');

      $token = $this->generate_token($order['order_id']);

      if ($token == '' || $_GET['cg_token'] != $token)
        throw new Exception('Token: ' . $_GET['cg_token'] . ' do not match');

      $order = \CoinGate\Merchant\Order::findOrFail($_REQUEST['id']);

      switch ($order->status) {
        case 'paid':
          $cg_order_status = 'coingate_completed_order_status_id';
          break;
        case 'canceled':
          $cg_order_status = 'coingate_cancelled_order_status_id';
          break;
        case 'expired':
          $cg_order_status = 'coingate_expired_order_status_id';
          break;
        case 'invalid':
          $cg_order_status = 'coingate_failed_order_status_id';
          break;
        case 'refunded':
          $cg_order_status = 'coingate_refunded_order_status_id';
          break;
        default:
          $cg_order_status = NULL;
      }

      if (!is_null($cg_order_status)) {
        $this->model_checkout_order->addOrderHistory($order->order_id, $this->config->get($cg_order_status));
      }
    } catch (Exception $e) {
      echo $e;
    }
  }

  private function generate_token($order_id)
  {
    return hash('sha256', $order_id + $this->config->get('coingate_api_secret'));
  }
}