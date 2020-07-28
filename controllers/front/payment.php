<?php


class PayTabs_PayPagePaymentModuleFrontController extends ModuleFrontController
{
  public $ssl = true;

  private $paymentType;

  /**
   * Order submitted, redirect to PayTabs
   */
  public function initContent()
  {
    parent::initContent();

    $paymentKey = Tools::getValue('method');
    $this->paymentType = PaytabsHelper::paymentType($paymentKey);

    $merchant_email = $this->getConfig('merchant_email');
    $merchant_secretKey = $this->getConfig('merchant_secret');

    $paytabsApi = PaytabsApi::getInstance($merchant_email, $merchant_secretKey);

    //

    $cart = $this->context->cart;

    $request_param = $this->prepare_order($cart, $paymentKey);


    // Create paypage
    $paypage = $paytabsApi->create_pay_page($request_param);

    $_logMsg = 'PayTabs: ' . json_encode($paypage);
    PrestaShopLogger::addLog($_logMsg, ($paypage->success ? 1 : 3), null, 'Cart', $cart->id, true, $cart->id_customer);

    //

    if ($paypage->success) {
      $payment_url = $paypage->payment_url;
      header("location: $payment_url");
    } else {
      $this->warning[] = $this->l($paypage->result);
      $this->redirectWithNotifications($this->context->link->getPageLink('order', true, null, [
        'step' => '3'
      ]));
    }
  }


  function prepare_order($cart, $paymentKey)
  {
    $hide_personal_info = (bool) $this->getConfig('hide_personal_info');
    $hide_billing = (bool) $this->getConfig('hide_billing');
    $hide_view_invoice = (bool) $this->getConfig('hide_view_invoice');

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
    $total_shipping = $totals['total_shipping'];
    $total_tax = $totals['total_tax'];


    $products = $cart->getProducts();

    $items_arr = array_map(function ($p) {
      return [
        'name' => $p['name'],
        'quantity' => $p['cart_quantity'],
        'price' => $p['price']
      ];
    }, $products);

    //

    $lang_ = "English";
    if ($this->context->language->iso_code == "ar") {
      $lang_  = "Arabic";
    }

    $siteUrl = Context::getContext()->shop->getBaseURL(true);
    $return_url = Context::getContext()->link->getModuleLink($this->module->name, 'validation', ['p' => $paymentKey]);

    //

    $country_details = PaytabsHelper::getCountryDetails($invoice_country->iso_code);

    $phone_number = PaytabsHelper::getNonEmpty(
      $address_invoice->phone,
      $address_invoice->phone_mobile,
      '111111'
    );

    if (empty($invoice_state)) {
      $invoice_state = null;
    } else {
      $invoice_state = $invoice_state->name;
    }

    $ip_customer = Tools::getRemoteAddr();

    $pt_holder = new PaytabsHolder();
    $pt_holder
      ->set01PaymentCode($this->paymentType)
      ->set02ReferenceNum($cart->id)
      ->set03InvoiceInfo(
        $address_invoice->firstname . ' ' . $address_invoice->lastname,
        $lang_
      )
      ->set04Payment(
        strtoupper($currency->iso_code),
        $amount + $total_discount,
        $total_shipping + $total_tax,
        $total_discount
      )
      ->set05Products($items_arr)
      ->set06CustomerInfo(
        $address_invoice->firstname,
        $address_invoice->lastname,
        $country_details['phone'],
        $phone_number,
        $customer->email
      )
      ->set07Billing(
        $address_invoice->address1 . ' ' . $address_invoice->address2,
        $invoice_state,
        $address_invoice->city,
        $address_invoice->postcode,
        PaytabsHelper::countryGetiso3($invoice_country->iso_code)
      )
      ->set08Shipping(
        $address_shipping->firstname,
        $address_shipping->lastname,
        $address_shipping->address1 . ' ' . $address_shipping->address2,
        $address_shipping->id_state ? $shipping_state->name : $address_shipping->city,
        $address_shipping->city,
        $address_shipping->postcode,
        PaytabsHelper::countryGetiso3($shipping_country->iso_code)
      )
      ->set09HideOptions(
        $hide_personal_info,
        $hide_billing,
        $hide_view_invoice
      )
      ->set10URLs($siteUrl, $return_url)
      ->set11CMSVersion('Prestashop ' . _PS_VERSION_)
      ->set12IPCustomer($ip_customer);

    $post_arr = $pt_holder->pt_build(true);

    return $post_arr;
  }

  //

  private function getConfig($key)
  {
    return Configuration::get("{$key}_{$this->paymentType}");
  }
}
