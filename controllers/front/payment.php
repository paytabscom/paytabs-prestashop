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
    $paytabsHelper = new PaytabsHelper();
    $paytabsApi = $paytabsHelper->pt($paymentKey);

    //

    $cart = $this->context->cart;

    $request_param = $this->prepare_order($cart, $paymentKey);


    // Create paypage
    $paypage = $paytabsApi->create_pay_page($request_param);

    PrestaShopLogger::addLog(json_encode($paypage), 1, null, 'Cart', $cart->id, true, $cart->id_customer);

    //

    if ($paypage && $paypage->response_code == 4012) {
      $payment_url = $paypage->payment_url;
      header("location: $payment_url");
    } else {
      $this->warning[] = $this->l($paypage->details . $paypage->result);
      $this->redirectWithNotifications($this->context->link->getPageLink('order', true, null, [
        'step' => '3'
      ]));
    }
  }


  function prepare_order($cart, $paymentKey)
  {
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

    $products_str = implode(' || ', array_map(function ($p) {
      return $p['name'];
    }, $products));

    $quantity = implode(' || ', array_map(function ($p) {
      return $p['cart_quantity'];
    }, $products));

    $unit_price = implode(' || ', array_map(function ($p) {
      return $p['price_wt'];
    }, $products));


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

    $request_param = [
      'cc_first_name'        => $address_invoice->firstname,
      'cc_last_name'         => $address_invoice->lastname,
      'phone_number'         => $phone_number,
      'cc_phone_number'      => $country_details['phone'],
      'billing_address'      => $address_invoice->address1 . ' ' . $address_invoice->address2,
      'city'                 => $address_invoice->city,
      'state'                => PaytabsHelper::getNonEmpty($invoice_state->name, $address_invoice->city),
      'postal_code'          => $address_invoice->postcode,
      'country'              => PaytabsHelper::countryGetiso3($invoice_country->iso_code),
      'email'                => $customer->email,

      'amount'               => $amount + $total_discount, // $total_product_ammout + $cart->getOrderTotal(true, Cart::ONLY_SHIPPING),
      'currency'             => strtoupper($currency->iso_code),
      'quantity'             => $quantity,
      "discount"             => $total_discount,
      'other_charges'        => $total_shipping + $total_tax,
      'unit_price'           => $unit_price,

      'title'                => $address_invoice->firstname . '  ' . $address_invoice->lastname,
      'products_per_title'   => $products_str,

      'address_shipping'     => $address_shipping->address1 . ' ' . $address_shipping->address2,
      'city_shipping'        => $address_shipping->city,
      'state_shipping'       => $address_shipping->id_state ? $shipping_state->name : $address_shipping->city,
      'postal_code_shipping' => $address_shipping->postcode,
      'country_shipping'     => PaytabsHelper::countryGetiso3($shipping_country->iso_code),

      // 'ShippingMethod'       => $shippingMethod->name,
      // 'DeliveryType'         => $shippingMethod->delay[1],

      'msg_lang'             => $lang_,
      'cms_with_version'     => 'Prestashop ' . _PS_VERSION_,
      'reference_no'         => $cart->id,
      'CustomerId'           => $customer->id,

      'site_url'             => $siteUrl,
      'return_url'           => $return_url
    ];

    return $request_param;
  }
}
