<?php

class PayTabs_PayPageValidationModuleFrontController extends ModuleFrontController
{

    public function postProcess()
    {
        $paymentKey = Tools::getValue('p');
        $paymentRef = Tools::getValue('tranRef');

        if (!isset($paymentRef, $paymentKey) || !$paymentRef || !$paymentKey) {
            PrestaShopLogger::addLog('PayTabs - PagePage: params error', 3, null, 'Cart', null, true, null);
            $this->warning[] = $this->l('Payment reference is missing!');
            $this->redirectWithNotifications($this->context->link->getPageLink('order', true, null, [
                'step' => '3'
            ]));
            return;
        }

        $paymentType = PaytabsHelper::paymentType($paymentKey);
        $profile_id = Configuration::get("profile_id_{$paymentType}");
        $server_key = Configuration::get("server_key_{$paymentType}");

        $paytabsApi = PaytabsApi::getInstance($profile_id, $server_key);

        $result = $paytabsApi->verify_payment($paymentRef);

        $response = $result->success;
        if (!$response) {
            $logMsg = json_encode($result);
            PrestaShopLogger::addLog(
                "PayTabs - PagePage: payment failed, payment_ref = {$paymentRef}, response: [{$logMsg}]",
                3,
                null,
                'Cart',
                $result->cart_id,
                true,
                null
            );

            $this->warning[] = $this->l($result->message);
            $this->redirectWithNotifications($this->context->link->getPageLink('order', true, null, [
                'step' => '3'
            ]));
            return;
        }

        /**
         * Get cart id from response
         */
        $cartId = $result->cart_id;
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
        $amountPaid = $result->cart_amount;
        $this->module->validateOrder(
            (int) $cart->id,
            Configuration::get('PS_OS_PAYMENT'),
            (float) $amountPaid,
            $this->module->displayName . " ({$paymentType})",
            $result->message, // message
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
