<?php

/**
 * PayTabs - A 3rd party Payment Module for PrestaShop 1.6 & 1.7
 *
 * This file is the declaration of the module.
 *
 * @author PayTabs <https://paytabs.com>
 * @license https://opensource.org/licenses/afl-3.0.php
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

define('PS_VERSION_IS_NEW', version_compare(_PS_VERSION_, '1.7.0', '>='));
define('PAYTABS_PAYPAGE_VERSION', '3.8.0');
define('PT_DB_TRANSACTIONS_TABLE', _DB_PREFIX_ . 'pt_transactions');


require_once __DIR__ . '/paytabs_core.php';
require_once __DIR__ . '/helpers/paytabs_paypage_helper.php';

function paytabs_error_log($msg, $severity)
{
    PrestaShopLogger::addLog($msg, $severity);
}

use PrestaShop\PrestaShop\Core\Domain\Order\CancellationActionType;
class PayTabs_PayPage extends PaymentModule
{
    private $_html = '';
    private $_postErrors = array();

    public $address;
    public static $counter = 1;
    public static $shipping_refunded = false;
    /**
     * PayTabs constructor.
     *
     * Set the information about this module
     */
    public function __construct()
    {
        $this->name                   = 'paytabs_paypage';
        $this->tab                    = 'payments_gateways';
        $this->version                = PAYTABS_PAYPAGE_VERSION;
        $this->author                 = 'PayTabs';
        $this->controllers            = array('payment', 'validation', 'callback');
        $this->currencies             = true;
        $this->currencies_mode        = 'checkbox';
        $this->bootstrap              = true;
        $this->displayName            = 'PayTabs - PayPage';
        $this->description            = 'A simple payment gateway that can be quickly integrated with merchant websites and it enables fast deposit of payments to the merchant account. Equipped with PCI DSS certification and anti-fraud protection.';
        $this->confirmUninstall       = 'Are you sure you want to uninstall this module?';
        $this->ps_versions_compliancy = array('min' => '1.6.0', 'max' => _PS_VERSION_);

        parent::__construct();
    }


    /**
     * Install this module and register the following Hooks:
     *
     * @return bool
     */
    public function install()
    {
        return parent::install()
            && $this->generate_transactions_table()
            && (PS_VERSION_IS_NEW ? $this->registerHook('paymentOptions') : $this->registerHook('payment'))
            && $this->registerHook('paymentReturn')
            
            && ( PS_VERSION_IS_NEW ? $this->registerHook('actionProductCancel') :
                ( $this->registerHook('actionProductCancel') && $this->registerHook('actionOrderSlipAdd') )
            );            
    }


    /**
     * Uninstall this module and remove it from all hooks
     *
     * @return bool
     */
    public function uninstall()
    {
        return parent::uninstall();
    }


    public function generate_transactions_table()
    {
        return DB::getInstance()->execute("
            CREATE TABLE IF NOT EXISTS `" . PT_DB_TRANSACTIONS_TABLE . "` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `order_id` INT(11) NOT NULL,
            `payment_method` VARCHAR(32) NOT NULL,
            `transaction_ref` VARCHAR(64) NOT NULL,
            `parent_ref` VARCHAR(64)  NULL,
            `transaction_type` VARCHAR(32) NOT NULL,
            `transaction_status` TINYINT(1) NOT NULL,
            `transaction_amount` DECIMAL(15,4) NOT NULL,
            `transaction_currency` VARCHAR(8) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
            );
        ");
    }

    /**
     * Returns a string containing the HTML necessary to
     * generate a configuration screen on the admin
     *
     * @return string
     */
    public function getContent()
    {
        if (Tools::isSubmit('btnSubmit')) {
            $this->_postValidation();
            if (!count($this->_postErrors)) {
                $this->_postProcess();
            } else {
                foreach ($this->_postErrors as $err) {
                    $this->_html .= $this->displayError($err);
                }
            }
        } else {
            $this->_html .= '<br />';
        }

        //

        $endpoints = PaytabsApi::getEndpoints();
        $endpoints = array_map(function ($key, $value) {
            return [
                'key' => $key,
                'title' => $value
            ];
        }, array_keys($endpoints), $endpoints);

        $forms = array_map(function ($method) use ($endpoints) {
            $code = $method['name'];
            $form = array(
                'form' => array(
                    'legend' => array(
                        'title' => ($method['title']),
                        'icon' => 'icon-key'
                    ),
                    'input' => array(
                        array(
                            'type' => 'switch',
                            'label' => 'Enabled',
                            'name' => 'active_' . $code,
                            'is_bool' => true,
                            'required' => true,
                            'values' => array(
                                array(
                                    'id' => 'active_on',
                                    'value' => true,
                                    'label' => $this->_trans('Enabled', array(), 'Admin.Global'),
                                ),
                                array(
                                    'id' => 'active_off',
                                    'value' => false,
                                    'label' => $this->_trans('Disabled', array(), 'Admin.Global'),
                                )
                            ),
                        ),
                        array(
                            'type' => 'select',
                            'label' => 'Endpoint region',
                            'name' => 'endpoint_' . $code,
                            'required' => true,
                            'options' => [
                                'query' => $endpoints,
                                'id' => 'key',
                                'name' => 'title'
                            ],
                        ),
                        array(
                            'type' => 'text',
                            'label' => ('Profile ID'),
                            'name' => 'profile_id_' . $code,
                            'required' => true
                        ),
                        array(
                            'type' => 'text',
                            'label' => ('Server Key'),
                            'name' => 'server_key_' . $code,
                            'required' => true
                        ),
                        array(
                            'type' => 'switch',
                            'label' => 'Hide Shipping info',
                            'name' => 'hide_shipping_' . $code,
                            'is_bool' => true,
                            'values' => array(
                                [
                                    'value' => true,
                                    'label' => $this->_trans('Yes', array(), 'Admin.Global'),
                                ],
                                [
                                    'value' => false,
                                    'label' => $this->_trans('No', array(), 'Admin.Global'),
                                ]
                            ),
                        ),
                        array(
                            'type' => 'text',
                            'label' => 'Order in Checkout page',
                            'name' => 'sort_' . $code
                        ),
                        array(
                            'type' => 'text',
                            'label' => 'Config id (Theme)',
                            'name' => 'config_id_' . $code,
                        ),
                        array(
                            'type' => 'switch',
                            'label' => 'Alternative currency enable',
                            'name' => 'alt_currency_enable_' . $code,
                            'is_bool' => true,
                            'values' => array(
                                array(
                                    'value' => true,
                                    'label' => $this->_trans('Enabled', array(), 'Admin.Global'),
                                ),
                                array(
                                    'value' => false,
                                    'label' => $this->_trans('Disabled', array(), 'Admin.Global'),
                                )
                            ),
                        ),
                        array(
                            'type' => 'text',
                            'label' => 'Alternative Currency',
                            'name' => 'alt_currency_' . $code,
                        ),

                    )
                ),
            );

            if ($code === 'valu') {
                $form['form']['input'][] = array(
                    'type' => 'text',
                    'label' => ('valU product ID'),
                    'name' => 'valu_product_id_' . $code,
                    'required' => true
                );
            }

            if (PaytabsHelper::isCardPayment($code)) {
                $form['form']['input'][] = array(
                    'type'  => 'switch',
                    'label' => 'Allow associated methods',
                    'name'  => 'allow_associated_methods_' . $code,
                    'is_bool' => true,
                    'values' => array(
                        [
                            'value' => true,
                            'label' => $this->_trans('Yes', array(), 'Admin.Global'),
                        ],
                        [
                            'value' => false,
                            'label' => $this->_trans('No', array(), 'Admin.Global'),
                        ]
                    ),
                );
            }

            return $form;
        }, PaytabsApi::PAYMENT_TYPES);

        // Submit button for all Sections
        $forms[] = [
            'form' => [
                'legend' => array(
                    'title' => 'Save settings',
                    'icon' => 'icon-gears'
                ),
                'submit' => array(
                    'title' => $this->_trans('Save', array(), 'Admin.Actions'),
                )
            ]
        ];

        $helper = new HelperForm();
        $helper->submit_action = 'btnSubmit';

        $i = 2;
        $values = array_reduce(PaytabsApi::PAYMENT_TYPES, function ($acc, $method) use (&$i) {
            $code = $method['name'];

            $acc["active_{$code}"] = Tools::getValue("active_{$code}", Configuration::get("active_{$code}"));

            $acc["endpoint_{$code}"] = Tools::getValue("endpoint_{$code}", Configuration::get("endpoint_{$code}"));

            $acc["profile_id_{$code}"] = Tools::getValue("profile_id_{$code}", Configuration::get("profile_id_{$code}"));
            $acc["server_key_{$code}"] = Tools::getValue("server_key_{$code}", Configuration::get("server_key_{$code}"));

            $acc["hide_shipping_{$code}"] = Tools::getValue("hide_shipping_{$code}", Configuration::get("hide_shipping_{$code}"));

            $acc["config_id_{$code}"] = Tools::getValue("config_id_{$code}", Configuration::get("config_id_{$code}"));

            $acc["alt_currency_enable_{$code}"] = Tools::getValue("alt_currency_enable_{$code}", Configuration::get("alt_currency_enable_{$code}"));
            $acc["alt_currency_{$code}"] = Tools::getValue("alt_currency_{$code}", Configuration::get("alt_currency_{$code}"));

            $sort = (int)Tools::getValue("sort_{$code}", Configuration::get("sort_{$code}"));
            if (!$sort) {
                $sort = ($code == 'mada') ? 1 : $i++;
            }
            $acc["sort_{$code}"] = $sort;

            if ($code === 'valu') {
                $acc["valu_product_id_{$code}"] = Tools::getValue("valu_product_id_{$code}", Configuration::get("valu_product_id_{$code}"));
            }

            if (PaytabsHelper::isCardPayment($code)) {
                $acc["allow_associated_methods_{$code}"] = Tools::getValue("allow_associated_methods_{$code}", Configuration::get("allow_associated_methods_{$code}"));
            }

            return $acc;
        }, []);

        $helper->tpl_vars = array(
            'fields_value' => $values
        );

        $this->_html .= $helper->generateForm($forms);

        return $this->_html;
    }


    protected function _postValidation()
    {
        if (!Tools::isSubmit('btnSubmit')) return;

        foreach (PaytabsApi::PAYMENT_TYPES as $index => $method) {
            $code = $method['name'];
            if (Tools::getValue("active_{$code}")) {
                if (!Tools::getValue("endpoint_{$code}")) {
                    $this->_postErrors[] = "{$method['title']}: Endpoint is required.";
                }
                if (!Tools::getValue("profile_id_{$code}")) {
                    $this->_postErrors[] = "{$method['title']}: Profile ID is required.";
                }
                if (!Tools::getValue("server_key_{$code}")) {
                    $this->_postErrors[] = "{$method['title']}: Server Key is required.";
                }

                if ($code === 'valu' && !Tools::getValue("valu_product_id_{$code}")) {
                    $this->_postErrors[] = "{$method['title']}: valU product ID is required.";
                }
            }
        }
    }


    protected function _postProcess()
    {
        if (Tools::isSubmit('btnSubmit')) {
            foreach (PaytabsApi::PAYMENT_TYPES as $index => $method) {
                $code = $method['name'];
                Configuration::updateValue("active_{$code}", Tools::getValue("active_{$code}"));

                Configuration::updateValue("endpoint_{$code}", Tools::getValue("endpoint_{$code}"));

                Configuration::updateValue("profile_id_{$code}", Tools::getValue("profile_id_{$code}"));
                Configuration::updateValue("server_key_{$code}", Tools::getValue("server_key_{$code}"));

                Configuration::updateValue("hide_shipping_{$code}", Tools::getValue("hide_shipping_{$code}"));

                Configuration::updateValue("sort_{$code}", (int)Tools::getValue("sort_{$code}"));

                if ($code === 'valu') {
                    Configuration::updateValue("valu_product_id_{$code}", Tools::getValue("valu_product_id_{$code}"));
                }

                if (PaytabsHelper::isCardPayment($code)) {
                    Configuration::updateValue("allow_associated_methods_{$code}", Tools::getValue("allow_associated_methods_{$code}"));
                }

                Configuration::updateValue("config_id_{$code}", (int)Tools::getValue("config_id_{$code}"));

                Configuration::updateValue("alt_currency_enable_{$code}", (bool)Tools::getValue("alt_currency_enable_{$code}"));
                Configuration::updateValue("alt_currency_{$code}", Tools::getValue("alt_currency_{$code}"));
            }
        }
        $this->_html .= $this->displayConfirmation($this->_trans('Settings updated', array(), 'Admin.Global'));
    }


    /**
     * Display this module as a payment option during the checkout
     *
     * @param array $params
     * @return array|void
     */
    public function hookPaymentOptions($params)
    {
        /*
         * Verify if this module is active
         */
        if (!$this->active) {
            return;
        }


        /**
         * Assign the url form action to the template var $action
         */
        // $this->smarty->assign(['action' => $formAction]);

        /**
         *  Load form template to be displayed in the checkout step
         */
        // $paymentForm = $this->fetch('module:paytabs_paypage/views/templates/hook/payment_options.tpl');

        /**
         * Create the PaymentOption objects containing the necessary data
         * to display this module in the checkout
         */

        $currency = $this->context->currency;

        foreach (PaytabsApi::PAYMENT_TYPES as $index => $payment) {
            $code = $payment['name'];
            $title = $payment['title'];
            $this->smarty->assign(['code' => $code]);

            if (!PaytabsHelper::paymentAllowed($code, $currency->iso_code)) continue;
            if (!Configuration::get("active_{$code}")) continue;

            /**
             * Form action URL. The form data will be sent to the
             * validation controller when the user finishes
             * the order process.
             */
            $formAction = $this->context->link->getModuleLink($this->name, 'payment', ['method' => $index], true);

            if ($code == 'all') {
                $title = 'Online payments powered by PayTabs';
                $desc = 'Online payments powered by PayTabs';
            } else {
                $desc = "Pay by PayTabs using $code";
            }

            $newOption = new PrestaShop\PrestaShop\Core\Payment\PaymentOption;
            $newOption
                ->setModuleName($code)
                ->setCallToActionText($title)
                ->setAction($formAction)
                ->setAdditionalInformation($desc)
                // ->setForm($paymentForm)
            ;

            $logo = $this->_get_icon($code);
            if ($logo) {
                $newOption->setLogo($logo);
            }

            $newOption->sort = (int)Configuration::get("sort_{$code}");

            $payment_options[] = $newOption;
        }

        uasort($payment_options, function ($a, $b) {
            return $a->sort > $b->sort;
        });

        return $payment_options;
    }


    private function _get_icon($code)
    {
        $logo = "/icons/{$code}";

        $logo_svg = '.svg';
        $logo_png = '.png';

        $logo_path_svg = (__DIR__ . $logo . $logo_svg);
        $logo_path_png = (__DIR__ . $logo . $logo_png);

        $logo_ext = null;

        if (file_exists($logo_path_svg)) {
            $logo_ext = $logo_svg;
        } else if (file_exists($logo_path_png)) {
            $logo_ext = $logo_png;
        }

        if ($logo_ext) {
            $logo_path = (_MODULE_DIR_ . "{$this->name}{$logo}{$logo_ext}");
            return $logo_path;
        }

        return null;
    }


    /**
     * PrestaShop 1.6 (Display the payment method template)
     *
     * @param array $params
     * @return array|void
     */
    public function hookPayment($params)
    {
        /*
         * Verify if this module is active
         */
        if (!$this->active) {
            return;
        }

        $currency = $this->context->currency;

        foreach (PaytabsApi::PAYMENT_TYPES as $index => $payment) {
            $code = $payment['name'];
            $title = $payment['title'];
            $this->smarty->assign(['code' => $code]);

            if (!PaytabsHelper::paymentAllowed($code, $currency->iso_code)) continue;
            if (!Configuration::get("active_{$code}")) continue;

            /**
             * Form action URL. The form data will be sent to the
             * validation controller when the user finishes
             * the order process.
             */
            $formAction = $this->context->link->getModuleLink($this->name, 'payment', ['method' => $index], true);

            if ($code == 'all') {
                $title = 'Online payments powered by PayTabs';
                $desc = 'Online payments powered by PayTabs';
            } else {
                $desc = "Pay by PayTabs using $code";
            }

            $newOption = []; // new PrestaShop\PrestaShop\Core\Payment\PaymentOption;
            $newOption = [
                'code' => $code,
                'title' => $title,
                'action' => $formAction,
                'desc' => $desc
                // ->setForm($paymentForm)
            ];

            $logo = $this->_get_icon($code);
            if ($logo) {
                $newOption['logo'] = $logo;
            }

            $newOption['sort'] = (int)Configuration::get("sort_{$code}");

            $payment_options[] = $newOption;
        }

        uasort($payment_options, function ($a, $b) {
            return $a['sort'] > $b['sort'];
        });

        $this->smarty->assign([
            'payment_options' => $payment_options
        ]);
        return $this->display(__FILE__, 'payment_option.tpl');
    }


    /**
     * Display a message in the paymentReturn hook
     * 
     * @param array $params
     * @return string
     */
    public function hookPaymentReturn($params)
    {
        /**
         * Verify if this module is enabled
         */
        if (!$this->active) {
            return;
        }

        if (!isset($params['order']) || ($params['order']->module != $this->name)) {
            return false;
        }

        $view = $this->fetch("module:{$this->name}/views/templates/hook/payment_return.tpl");

        return $view;
    }

    //

    public function _trans($id, $params = [], $domain = null, $locale = null)
    {
        if (PS_VERSION_IS_NEW) {
            return $this->trans($id, $params, $domain, $locale);
        } else {
            $specific = $params;
            return $this->l($id, $specific);
        }
    }


    public function _redirectWithWarning($context, $url, $message)
    {
        $controller = $context->controller;

        $p_message = $this->_trans($message);
        $controller->warning[] = $p_message;

        if (PS_VERSION_IS_NEW) {
            $controller->redirectWithNotifications($url);
        } else {
            $context->smarty->assign([
                'message'  => $p_message,
                'redirect' => $url
            ]);
            $controller->setTemplate('payment_error.tpl');
        }
    }

    public function hookActionProductCancel($params)
    {
        if (PS_VERSION_IS_NEW) {

            $refundType = $this->getRefundType($params['action']);

            if ( ($refundType != 'standard' && $refundType != 'partial') || $this->hasVoucher()) {
                return false;
            }    
        }
        
        $id_order_detail = $params['id_order_detail'];
        if (PS_VERSION_IS_NEW) {
            $cancel_quantity = $params['cancel_quantity'];
            if ($refundType == 'standard') {
                $refund_amount = $this->getRefundAmount($id_order_detail, $cancel_quantity);
            } else { 
                $refund_amount = $params['cancel_amount'];
            }    
        } else {
            $cancel_quantity = $_POST['cancelQuantity'][$id_order_detail];
            $refund_amount = $this->getRefundAmount($id_order_detail, $cancel_quantity);
        }

        $refund_amount += $this->getShippingAmount($refundType, $params);

        $orderId = $params['order']->id;
        $refundResult = $this->processRefund($orderId, $refund_amount);

        if ( $refundResult !== true){
            if (PS_VERSION_IS_NEW) {
                $this->get('session')->getFlashBag()->add('error', $refundResult);
            }
            Tools::redirectAdmin(Context::getContext()->link->getAdminLink('AdminOrders',true));
            exit;
        }


    }


    public function hookActionOrderSlipAdd($params)
    {
        if (PS_VERSION_IS_NEW || !array_key_exists('partialRefund', $_POST) || $this->hasVoucher()){
            return;
        }

        $product_list = $params['productList'];
        $total_amount = 0;

        foreach ($product_list as $key => $product){
            $total_amount += $product['amount'];
        }

        // add shipping amount
        $total_amount += $this->getShippingAmount();

        $orderId = $params['order']->id;
        $refundResult = $this->processRefund($orderId, $total_amount);
            
        if ( $refundResult !== true){
            if (PS_VERSION_IS_NEW) {
                $this->get('session')->getFlashBag()->add('error', $refundResult);
            }
            Tools::redirectAdmin(Context::getContext()->link->getAdminLink('AdminOrders',true));
            exit;
        }

    }

    private function processRefund($orderId, $refund_amount)
    {   
        $order = new Order((int) $orderId);

        if (! Validate::isLoadedObject($order) && $order->module != $this->name) {
            return;
        }

        $code = DB::getInstance()->getValue("SELECT payment_method FROM " . PT_DB_TRANSACTIONS_TABLE . " WHERE order_id = '" . (int) $order->id . "' AND (transaction_type='sale' or transaction_type='capture')");

        $tran_refrence = DB::getInstance()->getValue("SELECT transaction_ref FROM " . PT_DB_TRANSACTIONS_TABLE . " WHERE order_id = '" . (int) $order->id . "' AND (transaction_type='sale' or transaction_type='capture') ");
        $currency = new Currency((int) $order->id_currency);

        $pt_refundHolder = new PaytabsFollowupHolder();
        $pt_refundHolder
            ->set02Transaction(PaytabsEnum::TRAN_TYPE_REFUND, PaytabsEnum::TRAN_CLASS_ECOM)
            ->set03Cart($order->id_cart.'-'.time(), $currency->iso_code, $refund_amount, "Admin Partial Refund")
            ->set30TransactionInfo($tran_refrence)
            ->set99PluginInfo('PrestaShop', _PS_VERSION_, PAYTABS_PAYPAGE_VERSION);

        $values = $pt_refundHolder->pt_build();

        $endpoint = Configuration::get("endpoint_$code");
        $merchant_id = Configuration::get("profile_id_$code");
        $merchant_key = Configuration::get("server_key_$code");

        $paytabsApi = PaytabsApi::getInstance($endpoint, $merchant_id, $merchant_key);
        $refundRes = $paytabsApi->request_followup($values);

        $tran_ref = @$refundRes->tran_ref;
        $success = $refundRes->success;
        $message = $refundRes->message;

        if ($success) {

            $transaction_data = [
                'status' => $success,
                'transaction_ref' => $tran_ref,
                'parent_transaction_ref' => $values['tran_ref'],
                'transaction_amount' => $values['cart_amount'],
                'transaction_type' => $values['tran_type'],
                'transaction_currency' => $values['cart_currency'],
                'payment_method' => $code
            ];

            if(!PayTabs_PayPage_Helper::save_payment_reference($order->id, $transaction_data)){
                PaytabsHelper::log("Refund success, Paytabs table insert failed, [$order->id]", 3);
                return true;
            }

            PaytabsHelper::log("Refund success, order [{$order->id} - {$message}]");
            return true;

        } else {
            PaytabsHelper::log("Refund failed, {$order->id} - {$message}", 3);
            return "Refund Error: " . $message;
        }
    }

    private function hasVoucher()
    {
        if(PS_VERSION_IS_NEW){
            $has_voucher = array_key_exists('voucher', $_POST['cancel_product']);
        }else{
            $has_voucher = false;
            if (array_key_exists('generateDiscountRefund', $_POST) || array_key_exists('generateDiscount', $_POST)){
                $has_voucher = true;
            }
        }
        
        if ($has_voucher) {
            return true;
        }

        return false;
    }

    // Only with version >= 1.7
    private function getRefundType($action)
    {
        $type = false;
        switch ($action) {
            case CancellationActionType::STANDARD_REFUND:
                $type = 'standard';
                break;
            case CancellationActionType::PARTIAL_REFUND:
                $type = 'partial';
                break;
        }
        return $type;
    }

    private function getRefundAmount($id_order_detail, $cancel_quantity)
    {
        $order_detail = new OrderDetail((int) $id_order_detail);
        $refund_amount = $order_detail->unit_price_tax_incl * $cancel_quantity;
        return $refund_amount;
    }

    private function getShippingAmount($refundType=null, $params=null)
    {
        $refundShipping = 0;

        if (PS_VERSION_IS_NEW) {
            if ($refundType == 'standard' && array_key_exists('shipping', $_POST['cancel_product']) && !self::$shipping_refunded) {
                $totalOrderShipping = $params['order']->total_shipping;
                $refundShipping = $totalOrderShipping - (float) PayTabs_PayPage_Helper::getShippedAmount($params['order']->id);
                self::$shipping_refunded = true;
            }
            else if ($refundType == 'partial' && !self::$shipping_refunded) {
                $refundShipping = (float) $_POST['cancel_product']['shipping_amount'];
                self::$shipping_refunded = true;
            }
        } else {
            $refundShipping = (float) $_POST['partialRefundShippingCost'];
        }

        return $refundShipping;
    }
}
