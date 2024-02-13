<?php

class PayTabs_PayPageIpnModuleFrontController extends ModuleFrontController
{

    public function postProcess()
    {
        PaytabsHelper::log("IPN triggered", 1);

        $ipn_data = PaytabsHelper::read_ipn_response();

        if (PaytabsEnum::TranIsPaymentComplete($ipn_data)) {
            return $this->handle_ipn($ipn_data);
        } else {
            PaytabsHelper::log("Payment is not complete", 1);
        }
    }

    private function handle_ipn($ipn_data)
    {
        if (!$ipn_data) {
            return;
        }

        $cart_id = @$ipn_data->cart_id;
        $order = Order::getByCartId($cart_id);
        if (!$order) {
            return;
        }

        $paymentTitle = $order->payment;
        $paymentType = $this->extractPaymentType($paymentTitle);

        $paytabsApi = $this->getPaytabsInstance($paymentType);
        $response_data = $paytabsApi->read_response(true);

        $ipn_enable = Configuration::get("ipn_enable_{$paymentType}");

        if (!$ipn_enable) {
            PaytabsHelper::log("IPN handling is disabled, {$order->id}", 2);
            return;
        }

        $tran_type = strtolower($response_data->tran_type);

        $pt_success = $response_data->success;
        $pt_message = $response_data->message;

        switch ($tran_type) {
            case PaytabsEnum::TRAN_TYPE_CAPTURE:
                if ($pt_success) {
                    $this->successTransaction($order, $tran_type);
                } else {
                    $this->failedTransaction($order, $tran_type, $pt_message);
                }
                $order->save();
                break;

            case PaytabsEnum::TRAN_TYPE_VOID:
                if ($pt_success) {
                    $this->successTransaction($order, $tran_type);
                } else {
                    $this->failedTransaction($order, $tran_type, $pt_message);
                }
                break;
            default:
                PaytabsHelper::log("IPN does not recognize the Action {$tran_type}", 2);
                break;
        }

        return;
    }

    private function extractPaymentType($payment_title)
    {
        $pattern = '/\((.*?)\)/';
        preg_match($pattern, $payment_title, $matches);
        $result = isset($matches[1]) ? $matches[1] : '';
        return $result;
    }

    private function failedTransaction($order, $tran_type, $msg)
    {
        PaytabsHelper::log( ucfirst($tran_type) . " failed, {$order->id} - {$msg}", 3);
        $error_status = Configuration::get('PS_OS_ERROR');
        $order->setCurrentState($error_status);
    }

    private function successTransaction($order, $tran_type)
    {
        $success_status = "";
        if ($tran_type == 'void') {
            $success_status = Configuration::get('PS_OS_CANCELED');
        } elseif ($tran_type == 'capture') {
            $success_status = Configuration::get('PS_OS_PAYMENT');
        }
        file_put_contents('hanlde-ipn', ' success transaction method : '. $tran_type . $success_status, FILE_APPEND);
        $order->setCurrentState($success_status);
        PaytabsHelper::log( ucfirst($tran_type) . " done, $order->id", 1);
    }

    private function getPaytabsInstance($paymentType)
    {
        $endpoint = Configuration::get("endpoint_{$paymentType}");
        $merchant_id = Configuration::get("profile_id_{$paymentType}");
        $merchant_key = Configuration::get("server_key_{$paymentType}");

        return PaytabsApi::getInstance($endpoint, $merchant_id, $merchant_key);
    }
}
