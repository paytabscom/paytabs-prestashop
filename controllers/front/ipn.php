<?php

class PayTabs_PayPageIpnModuleFrontController extends ModuleFrontController
{

    public function postProcess()
    {
        header("HTTP/1.1 200 OK");
        header('Content-Type: application/json');
        echo json_encode(array('status' => 'success'));

        //

        PaytabsHelper::log("IPN triggered", 1);

        $ipn_data = PaytabsHelper::read_ipn_response();
        return $this->handle_ipn($ipn_data);
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

        // TO-DO (after adding config option)

        // $ipn_enable = Configuration::get("ipn_enable_{$paymentType}");

        // if (!$ipn_enable) {
            // PaytabsHelper::log("IPN handling is disabled, {$order->id}", 2);
            // return;
        // }

        $tran_type = strtolower($response_data->tran_type);

        $pt_success = $response_data->success;
        $pt_message = $response_data->message;

        switch ($tran_type) {
            case PaytabsEnum::TRAN_TYPE_CAPTURE:
                if ($pt_success) {
                    $this->successTransaction($order, $tran_type);
                    $this->checkPartialCapture($order, $ipn_data);
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
                $order->save();
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
        if (PaytabsEnum::TranIsVoid($tran_type)) {
            $success_status = Configuration::get('PS_OS_CANCELED');
        } elseif (PaytabsEnum::TranIsCapture($tran_type)) {
            $success_status = Configuration::get('PS_OS_PAYMENT');
        }
        
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

    private function checkPartialCapture($order, $ipn_data)
    {
        if ($order->getTotalPaid() != $ipn_data->tran_total) {
            PaytabsHelper::log( "Order is partially captured {$order->id} - {$ipn_data->tran_total} ", 1);
        }
    }
}
