<?php

class PayTabs_PayPageCallbackModuleFrontController extends ModuleFrontController
{

    public function postProcess()
    {
        PaytabsHelper::log("Callback triggered", 1);

        $paymentKey = Tools::getValue('p');

        if ($paymentKey === false) {
            PrestaShopLogger::addLog('PayTabs: Callback payment key is missing', 3, null, 'Cart', null, true, null);
            return;
        }

        $paymentType = PaytabsHelper::paymentType($paymentKey);
        $endpoint = Configuration::get("endpoint_{$paymentType}");
        $merchant_id = Configuration::get("profile_id_{$paymentType}");
        $merchant_key = Configuration::get("server_key_{$paymentType}");

        // Should use Admin control
        $discount_enabled = Configuration::get("discount_enabled_$paymentType");

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
        $orderId = @$result->reference_no;
        $transaction_ref = @$result->transaction_id;
        $pt_prev_tran_ref = @$result->previous_tran_ref;
        $transaction_type = @$result->tran_type;
        $response_code = @$result->response_code;

        $cart_amount = $result->cart_amount;
        $tran_total  = $result->tran_total;

        /**
         * The actual paid amount in PT
         * By default it must be the tran_total
         * Except in the discount card mode: where it will be the cart_amount
         */
        $amountPaid = $tran_total;

        if ($failed) {
            $logMsg = json_encode($result);
            PrestaShopLogger::addLog(
                "PayTabs: Callback payment failed, payment_ref = {$transaction_ref}, response: [{$logMsg}]",
                2,
                null,
                'Cart',
                $orderId,
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
                $orderId,
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
                $orderId,
                true,
                null
            );

            return;
        }

        /**
         * Get cart id from response
         */
        $cart = new Cart((int) $orderId);

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
            PrestaShopLogger::addLog('PayTabs: Authorization error', 2, null, 'Cart', $orderId, true, null);
        }

        /** @var CustomerCore $customer */
        $customer = new Customer($cart->id_customer);

        // PT Discount logic

        if ($discount_enabled) {
            $discountPatterns = json_decode(Configuration::get("discount_cards_{$paymentType}"));
            $discountTypes = json_decode(Configuration::get("discount_type_$paymentType"));
            $discountAmounts = json_decode(Configuration::get("discount_amount_$paymentType"));

            $hasDiscounted = PaytabsHelper::hasDiscountApplied($discountPatterns, $discountAmounts, $discountTypes, $result);
            if ($hasDiscounted !== false) {
                $amountPaid = $cart_amount;

                PrestaShopLogger::addLog(
                    "PayTabs ({$paymentType}): Discount detected, {$transaction_ref}, Original amount: {$cart_amount}, Paid amount: {$tran_total}",
                    2,
                    null,
                    'Cart',
                    $orderId,
                    true,
                    null
                );
            }
        }

        /**
         * Place the order
         */

        $extras = [
            'transaction_id' => $transaction_ref,
            'tran_type' => $transaction_type
        ];

        $this->module->validateOrder(
            (int) $cart->id,
            Configuration::get('PS_OS_PAYMENT'),
            (float) $amountPaid,
            $this->module->displayName . " ({$paymentType})",
            $res_msg, // message
            $extras, // extra vars
            (int) $cart->id_currency,
            false,
            $customer->secure_key
        );

        /*
        * Add discount to order
        */
        if ($discount_enabled && $hasDiscounted !== false) {
            $index = $hasDiscounted;

            // $orderId = $this->context->controller->module->currentOrder;
            // $order = new Order((int)$orderId);
            $order = Order::getByCartId($orderId);

            $discountType = $discountTypes[$index];
            $discountAmount = (float) $cart_amount - (float) $tran_total;
            if ($discountType === PaytabsEnum::DISCOUNT_PERCENTAGE) {
                $discountAmount = $discountAmounts[$index];
            } else {
                if ($discountAmount != $discountAmounts[$index]) {
                    PaytabsHelper::log('Discount amount not match ' . $discountAmount . ' <> ' . $discountAmounts[$index], 2);
                }
            }

            $this->addCartRule($order, $discountType, $discountAmount);
        }
    }


    public function display()
    {
        return;
    }

    private function addCartRule(Order $order, $discountType, $discountAmount)
    {
        $cart_rule = new CartRule();
        $cart_rule->code = CartRule::BO_ORDER_CODE_PREFIX . $order->id_cart;
        $cart_rule->name[Configuration::get('PS_LANG_DEFAULT')] = 'Card Discount order #' . $order->id;
        $cart_rule->id_customer = $order->id_customer;
        $cart_rule->minimum_amount = 1;
        $cart_rule->minimum_amount_tax = 1;
        $cart_rule->minimum_amount_currency = 1;
        $cart_rule->minimum_amount_shipping = 0;
        $cart_rule->date_from = date('Y-m-d H:i:s', time());
        $cart_rule->date_to = date('Y-m-d H:i:s', time() + 10);
        $cart_rule->active = true;

        if ($discountType === PaytabsEnum::DISCOUNT_PERCENTAGE) {
            // $cart_rule->reduction_percent = (float) $discountAmount;
            $discountEstimatedValue = ($discountAmount / 100) * ($order->total_paid_real);
            $cart_rule->reduction_amount = (float) $discountEstimatedValue;
            $cart_rule->reduction_tax = true;
        } else if ($discountType === PaytabsEnum::DISCOUNT_FIXED) {
            $discountEstimatedValue = $discountAmount;
            $cart_rule->reduction_amount = (float) $discountEstimatedValue;
            $cart_rule->reduction_tax = true;
        }

        try {
            if (!$cart_rule->add()) {
                PaytabsHelper::log("CartRule could not be added, Order {$order->id}", 3);
            } else {
                $newCartRuleId = $cart_rule->id;
                $cart = Cart::getCartByOrderId($order->id);
                $cart->addCartRule($newCartRuleId);
                $cart->update();
                $order->addCartRule($newCartRuleId, 'PT-CardDiscount-' . time(), ['tax_incl' => $discountEstimatedValue, 'tax_excl' => $discountEstimatedValue], 0, false);
                $invoice_id = $order->invoice_number ?? null;
                $computingPrecision = OrderHelper::getPrecisionFromCart($cart);
                $order = OrderHelper::updateOrderCartRules($order, $cart, $computingPrecision, $invoice_id);
                $order = OrderHelper::updateOrderTotals($order, $cart, $computingPrecision);
                $order = OrderHelper::updateOrderInvoices($order, $cart, $computingPrecision);
                $order->update();
            }
        } catch (PrestaShopException $e) {
            PaytabsHelper::log("CartRule creation error, Order {$order->id}", 3);
        }
    }
}
