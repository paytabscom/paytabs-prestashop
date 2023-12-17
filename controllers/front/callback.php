<?php

class PayTabs_PayPageCallbackModuleFrontController extends ModuleFrontController
{

    public function postProcess()
    {
        $paymentKey = Tools::getValue('p');

        if ($paymentKey === false) {
            PrestaShopLogger::addLog('PayTabs: Callback payment key is missing', 3, null, 'Cart', null, true, null);
            return;
        }

        $paymentType = PaytabsHelper::paymentType($paymentKey);
        $endpoint = Configuration::get("endpoint_{$paymentType}");
        $merchant_id = Configuration::get("profile_id_{$paymentType}");
        $merchant_key = Configuration::get("server_key_{$paymentType}");

        $paytabsApi = PaytabsApi::getInstance($endpoint, $merchant_id, $merchant_key);

        //

        $result = $paytabsApi->read_response(true);
        if (!$result) {
            PrestaShopLogger::addLog('PayTabs: Callback reading response error', 3, null, 'Cart', null, true, null);
            return;
        }
        // $result = $paytabsApi->verify_payment($paymentRef);

        $success = $result->success;
        $failed = $result->failed;
        $is_on_hold = $result->is_on_hold;
        $is_pending = $result->is_pending;
        $res_msg = $result->message;
        $cartId = @$result->reference_no;
        $transaction_ref = @$result->transaction_id;
        $pt_prev_tran_ref = @$result->previous_tran_ref;
        $transaction_type = @$result->tran_type;
        $response_code = @$result->response_code;

        $amountPaid = $result->tran_total;
        $cart_currency = $result->cart_currency;

        if ($failed) {
            $logMsg = json_encode($result);
            PrestaShopLogger::addLog(
                "PayTabs: Callback payment failed, payment_ref = {$transaction_ref}, response: [{$logMsg}]",
                2,
                null,
                'Cart',
                $cartId,
                true,
                null
            );

            return;
        }

        if ($is_on_hold || $is_pending) {
            // ToDo

            PrestaShopLogger::addLog(
                "PayTabs: Callback payment needs review, payment_ref = {$transaction_ref}, response: [{$res_msg}]",
                2,
                null,
                'Cart',
                $cartId,
                true,
                null
            );

            return;
        }


        if (!$success) {
            $logMsg = json_encode($result);
            PrestaShopLogger::addLog(
                "PayTabs: Callback payment did not succeed, payment_ref = {$transaction_ref}, response: [{$logMsg}]",
                3,
                null,
                'Cart',
                $cartId,
                true,
                null
            );

            return;
        }

        /**
         * Get cart id from response
         */
        $cart = new Cart((int) $cartId);

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
            PrestaShopLogger::addLog('PayTabs: Authorization error', 3, null, 'Cart', null, true, null);
        }

        /** @var CustomerCore $customer */
        $customer = new Customer($cart->id_customer);

        /**
         * Place the order
         */

        $this->module->validateOrder(
            (int) $cart->id,
            Configuration::get('PS_OS_PAYMENT'),
            (float) $result->cart_amount,
            $this->module->displayName . " ({$paymentType})",
            $res_msg, // message
            ['transaction_id' => $transaction_ref, 'tran_type' => $transaction_type], // extra vars
            (int) $cart->id_currency,
            false,
            $customer->secure_key
        );

        $transaction_data = [
            'status' => $success,
            'transaction_ref' => $transaction_ref,
            'payment_method' => $paymentType,
            'parent_transaction_ref' => '',
            'transaction_amount'   => $amountPaid,
            'transaction_currency' => $cart_currency,
            'transaction_type' => $transaction_type
        ];
        file_put_contents('test-callback1', ' -callback- ' . json_encode($result));
        
        $order_id = $this->context->controller->module->currentOrder;

        if(PayTabs_PayPage_Helper::save_payment_reference($order_id, $transaction_data)){
            PaytabsHelper::log("transaction saved success, order [{$order_id} - {$res_msg}]");
        }
    }


    public function display()
    {
        return;
    }
}
