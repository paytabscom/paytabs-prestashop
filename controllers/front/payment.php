<?php


class PayTabs_PayPagePaymentModuleFrontController extends ModuleFrontController
{
  public $ssl = true;

  /**
   * Order submitted, redirect to PayTabs
   */
  public function initContent()
  {
    parent::initContent();

    $paymentKey = Tools::getValue('method');
    $paymentType = PaytabsHelper::paymentType($paymentKey);

    $profile_id = Configuration::get("profile_id_{$paymentType}");
    $server_key = Configuration::get("server_key_{$paymentType}");

    $paytabsApi = PaytabsApi::getInstance($profile_id, $server_key);

    //

    $cart = $this->context->cart;

    $request_param = $this->prepare_order($cart, $paymentKey);


    // Create paypage
    $paypage = $paytabsApi->create_pay_page($request_param);

    $_logMsg = 'PayTabs: ' . json_encode($paypage);
    PrestaShopLogger::addLog($_logMsg, ($paypage->success ? 1 : 3), null, 'Cart', $cart->id, true, $cart->id_customer);

    //

    if ($paypage->success) {
      $payment_url = $paypage->redirect_url;
      header("location: $payment_url");
    } else {
      $this->warning[] = $this->l($paypage->message);
      $this->redirectWithNotifications($this->context->link->getPageLink('order', true, null, [
        'step' => '3'
      ]));
    }
  }


  function prepare_order($cart, $paymentKey)
  {
    $paymentType = PaytabsHelper::paymentType($paymentKey);

    $currency = new Currency((int) ($cart->id_currency));
    $customer = new Customer(intval($cart->id_customer));

    $address_invoice = new Address(intval($cart->id_address_invoice));
    $address_shipping = new Address(intval($cart->id_address_delivery));

    $invoice_country = new Country($address_invoice->id_country);
    $shipping_country = new Country($address_shipping->id_country);

    if ($address_invoice->id_state)
      $invoice_state = new State((int) ($address_invoice->id_state));

    if ($address_shipping->id_state)
      $shipping_state = new State((int) ($address_shipping->id_state));

    // Amount
    $totals = $cart->getSummaryDetails();
    $amount = $totals['total_price']; // number_format($cart->getOrderTotal(true, Cart::BOTH), 2, '.', '');

    $total_discount = $totals['total_discounts']; // $total_product_ammout + $cart->getOrderTotal(true, Cart::ONLY_SHIPPING) - $amount;
    // $total_shipping = $totals['total_shipping'];
    // $total_tax = $totals['total_tax'];

    $amount += $total_discount;


    $products = $cart->getProducts();

    $items_arr = array_map(function ($p) {
      return [
        'name' => $p['name'],
        'quantity' => $p['cart_quantity'],
        'price' => $p['price']
      ];
    }, $products);

    //

    $lang_ = $this->context->language->iso_code;

    // $siteUrl = Context::getContext()->shop->getBaseURL(true);
    $return_url = Context::getContext()->link->getModuleLink($this->module->name, 'validation', ['p' => $paymentKey]);

    //

    // $country_details = PaytabsHelper::getCountryDetails($invoice_country->iso_code);

    $phone_number = PaytabsHelper::getNonEmpty(
      $address_invoice->phone,
      $address_invoice->phone_mobile
    );

    $shipping_phone_number = PaytabsHelper::getNonEmpty(
      $address_shipping->phone,
      $address_shipping->phone_mobile
    );

    if (empty($invoice_state)) {
      $invoice_state = null;
    } else {
      $invoice_state = $invoice_state->name;
    }

    if (empty($shipping_state)) {
      $shipping_state = null;
    } else {
      $shipping_state = $shipping_state->name;
    }

    $ip_customer = Tools::getRemoteAddr();

    $pt_holder = new PaytabsHolder2();
    $pt_holder
      ->set01PaymentCode($paymentType)
      ->set02Transaction('sale', 'ecom')
      ->set03Cart(
        $cart->id,
        strtoupper($currency->iso_code),
        $amount,
        json_encode($items_arr)
      )
      ->set04CustomerDetails(
        $address_invoice->firstname . ' ' . $address_invoice->lastname,
        $customer->email,
        $phone_number,
        $address_invoice->address1 . ' ' . $address_invoice->address2,
        $address_invoice->city,
        $invoice_state,
        PaytabsHelper::countryGetiso3($invoice_country->iso_code),
        $address_invoice->postcode,
        $ip_customer
      )
      ->set05ShippingDetails(
        $address_shipping->firstname . ' ' . $address_shipping->lastname,
        $customer->email,
        $shipping_phone_number,
        $address_shipping->address1 . ' ' . $address_shipping->address2,
        $address_shipping->city,
        $shipping_state,
        PaytabsHelper::countryGetiso3($shipping_country->iso_code),
        $address_shipping->postcode,
        null
      )
      ->set06HideShipping(false)
      ->set07URLs($return_url, null)
      ->set08Lang($lang_);

    $post_arr = $pt_holder->pt_build();

    return $post_arr;
  }
}
