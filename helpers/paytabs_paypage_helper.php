<?php

class PayTabs_PayPage_Helper 
{
    public static function save_payment_reference($order_id, $transaction_data)
    {
        $sql_data = [
            'order_id' => (int) $order_id,
            'payment_method'    => pSQL($transaction_data['payment_method']),
            'transaction_ref'   => pSQL($transaction_data['transaction_ref']),
            'parent_ref'        => pSQL($transaction_data['parent_transaction_ref']),
            'transaction_type'  => strtolower(pSQL($transaction_data['transaction_type'])),
            'transaction_status'   => pSQL($transaction_data['status']),
            'transaction_amount'   => pSQL($transaction_data['transaction_amount']),
            'transaction_currency' => pSQL($transaction_data['transaction_currency']),
        ];

        // Map to array of values only
        $sql_data = array_map(fn ($key, $value) => "`$key` = '$value'", array_keys($sql_data), array_values($sql_data));

        // Merge all updates in one string
        $sql_cmd = implode(", ", $sql_data);

        $result = DB::getInstance()->execute("INSERT INTO `" . PT_DB_TRANSACTIONS_TABLE . "` SET $sql_cmd;");

        if (!$result) {
            return false;
        }
        return true;
    }

    public static function getOrderDetail($order_detail_id)
    {
        $tableName = _DB_PREFIX_ . 'order_detail';
        $stmt = "SELECT * FROM $tableName WHERE id_order_detail = $order_detail_id;";
        $result = DB::getInstance()->getRow($stmt);
        return $result;
    }
}
