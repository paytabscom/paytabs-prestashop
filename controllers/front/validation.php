<?php

class PayTabs_PayPageValidationModuleFrontController extends ModuleFrontController
{

    public function postProcess()
    {
        $paymentKey = Tools::getValue('p');
        $paymentRef = Tools::getValue('tranRef');

        $url_step3 = $this->context->link->getPageLink('order', true, null, ['step' => '3']);

        if (!isset($paymentRef, $paymentKey) || !$paymentRef || !$paymentKey) {
            PrestaShopLogger::addLog('PayTabs - PagePage: params error', 3, null, 'Cart', null, true, null);

            $this->module->_redirectWithWarning($this->context, $url_step3, 'Payment reference is missing!');
            return;
        }

        $paymentType = PaytabsHelper::paymentType($paymentKey);
        $endpoint = Configuration::get("endpoint_{$paymentType}");
        $merchant_id = Configuration::get("profile_id_{$paymentType}");
        $merchant_key = Configuration::get("server_key_{$paymentType}");

        $paytabsApi = PaytabsApi::getInstance($endpoint, $merchant_id, $merchant_key);

        //

        $result = $paytabsApi->verify_payment($paymentRef);

        $success = $result->success;
        $message = $result->message;
        $orderId = @$result->reference_no;
        $transaction_ref = @$result->transaction_id;
        $amountPaid = $result->cart_amount;

        if (!$success) {
            $logMsg = json_encode($result);
            PrestaShopLogger::addLog(
                "PayTabs - PagePage: payment failed, payment_ref = {$paymentRef}, response: [{$logMsg}]",
                3,
                null,
                'Cart',
                $orderId,
                true,
                null
            );

            $this->module->_redirectWithWarning($this->context, $url_step3, $message);
            return;
        }

        /**
         * Get cart id from response
         */
        $cart = new Cart((int) $orderId);

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
        $authorized = false;
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] == $this->module->name) {
                $authorized = true;
                break;
            }
        }
        if (!$authorized) {
            // die($this->module->_trans('This payment method is not available.'));
            PrestaShopLogger::addLog('PayTabs - PagePage: redirecting error', 3, null, 'Cart', null, true, null);
        }

        /** @var CustomerCore $customer */
        $customer = new Customer($cart->id_customer);

        /**
         * Check if this is a valid customer account
         */
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        /**
         * Place the order
         */

        $this->module->validateOrder(
            (int) $cart->id,
            Configuration::get('PS_OS_PAYMENT'),
            (float) $amountPaid,
            $this->module->displayName . " ({$paymentType})",
            $message, // message
            ['transaction_id' => $transaction_ref], // extra vars
            (int) $cart->id_currency,
            false,
            $customer->secure_key
        );

        /**
         * Redirect the customer to the order confirmation page
         */
        if (PS_VERSION_IS_NEW) {
            Tools::redirect('index.php?controller=order-confirmation&id_cart=' . (int) $cart->id . '&id_module=' . (int) $this->module->id . '&id_order=' . $this->module->currentOrder . '&key=' . $customer->secure_key);
        } else {
            Tools::redirect('index.php?controller=order-detail&id_order=' . $this->module->currentOrder);
        }
    }
}
