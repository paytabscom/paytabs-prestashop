<?php

class PayTabs_PayPageValidationModuleFrontController extends ModuleFrontController
{

    public function postProcess()
    {
        $paymentKey = Tools::getValue('p');
        $paymentRef = Tools::getValue('payment_reference');

        if (!isset($paymentRef, $paymentKey)) {
            PrestaShopLogger::addLog('PayTabs - PagePage: params error', 3, null, 'Cart', null, true, null);
            return;
        }

        $paymentType = PaytabsHelper::paymentType($paymentKey);
        $merchant_email = Configuration::get("merchant_email_{$paymentType}");
        $merchant_secretKey = Configuration::get("merchant_secret_{$paymentType}");

        $paytabsApi = PaytabsApi::getInstance($merchant_email, $merchant_secretKey);

        $result = $paytabsApi->verify_payment($paymentRef);

        $response = $result->success;
        if (!$response) {
            $logMsg = json_encode($result);
            PrestaShopLogger::addLog(
                "PayTabs - PagePage: payment failed, payment_ref = {$paymentRef}, response: [{$logMsg}]",
                3,
                null,
                'Cart',
                $result->reference_no,
                true,
                null
            );

            $this->warning[] = $this->l($result->result);
            $this->redirectWithNotifications($this->context->link->getPageLink('order', true, null, [
                'step' => '3'
            ]));
            return;
        }

        /**
         * Get cart id from response
         */
        $cartId = $result->reference_no;
        $cart = new Cart((int) $cartId);
        $authorized = false;

        /**
         * Verify if this module is enabled and if the cart has
         * a valid customer, delivery address and invoice address
         */
        if (
            !$this->module->active || $cart->id_customer == 0 || $cart->id_address_delivery == 0
            || $cart->id_address_invoice == 0
        ) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        /**
         * Verify if this payment module is authorized
         */
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] == 'paytabs_paypage') {
                $authorized = true;
                break;
            }
        }

        if (!$authorized) {
            die($this->l('This payment method is not available.'));
        }

        /** @var CustomerCore $customer */
        $customer = new Customer($cart->id_customer);

        /**
         * Check if this is a vlaid customer account
         */
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        /**
         * Place the order
         */
        $amountPaid = $result->amount;
        $this->module->validateOrder(
            (int) $cart->id,
            Configuration::get('PS_OS_PAYMENT'),
            (float) $amountPaid,
            $this->module->displayName . " ({$paymentType})",
            $result->result, // message
            null, // extra vars
            (int) $cart->id_currency,
            false,
            $customer->secure_key
        );

        /**
         * Redirect the customer to the order confirmation page
         */
        Tools::redirect('index.php?controller=order-confirmation&id_cart=' . (int) $cart->id . '&id_module=' . (int) $this->module->id . '&id_order=' . $this->module->currentOrder . '&key=' . $customer->secure_key);
    }
}
