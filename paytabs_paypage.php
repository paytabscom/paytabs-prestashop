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

class PayTabs_PayPage extends PaymentModule
{
    private $_html = '';
    private $_postErrors = array();

    public $address;

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
            && $this->registerHook('actionOrderStatusUpdate');

        /* Partial Refund hook */
        // && $this->registerHook('actionProductCancel');
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
        // init
        $endpoints = PaytabsApi::getEndpoints();
        $endpoints = array_map(function ($key, $value) {
            return [
                'key' => $key,
                'title' => $value
            ];
        }, array_keys($endpoints), $endpoints);

        $this->context->smarty->assign([
            'paytabs_action_url'    => "./index.php?tab=AdminModules&configure=$this->name&token=" . Tools::getAdminTokenLite("AdminModules") . "&tab_module=" . $this->tab . "&module_name=$this->name",
            'paytabs_endpoints'     => $endpoints,
            'paytabs_payment_types' => PaytabsApi::PAYMENT_TYPES,
        ]);

        // handle submit

        if (Tools::isSubmit('btnSubmit')) {

            $this->_postValidation();

            if (!count($this->_postErrors)) {

                $this->_postProcess();
            } else {

                foreach ($this->_postErrors as $err) {
                    $this->_html .= $this->displayError($err);
                }

                $this->context->smarty->assign([
                    'errors_html' => $this->_html,
                ]);

                return $this->display(__FILE__, 'views/templates/admin/configuration.tpl');
            }
        }

        return $this->display(__FILE__, 'views/templates/admin/configuration.tpl');
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

            if (PaytabsHelper::isCardPayment($code) && (Tools::getValue("discount_cards_{$code}"))) {

                $discount_cards  = array_filter((array)Tools::getValue("discount_cards_{$code}"), function ($card) {

                    $exploded = explode(',', $card);

                    foreach ($exploded as $prefix) {
                        if (!preg_match('/^[0-9]{4,10}$/', $prefix)) {
                            $this->_postErrors['unmatching'] = "Card discount cards prefix allow numbers only and must be between 4 and 10 digits (separated by commas e.g 5200,4411)";
                            return 0;
                        }
                    }

                    return 1;
                });

                $discount_amounts = array_filter(array_map(function ($amount) {
                    return (int)$amount;
                }, Tools::getValue("discount_amount_{$code}")), function ($amount) {
                    return (is_numeric($amount) && $amount != 0);
                });

                if ((($discount_cards && !$discount_amounts) ||
                        (!$discount_cards && $discount_amounts) ||
                        (count($discount_cards) != count($discount_amounts))) && (!array_key_exists('unmatching', $this->_postErrors))
                ) {
                    $this->_postErrors[] = "Both discount values (cards, amount) should be either set or not set.";
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

                    $discount_cards  = Tools::getValue("discount_cards_{$code}", array());
                    $discount_amounts = Tools::getValue("discount_amount_{$code}", array());
                    $discount_types  = Tools::getValue("discount_type_{$code}", array());

                    Configuration::updateValue("discount_cards_{$code}", json_encode($discount_cards));
                    Configuration::updateValue("discount_amount_{$code}", json_encode($discount_amounts));
                    Configuration::updateValue("discount_type_{$code}", json_encode($discount_types));
                }

                Configuration::updateValue("config_id_{$code}", (int)Tools::getValue("config_id_{$code}"));

                Configuration::updateValue("alt_currency_enable_{$code}", (bool)Tools::getValue("alt_currency_enable_{$code}"));
                Configuration::updateValue("alt_currency_{$code}", Tools::getValue("alt_currency_{$code}"));
            }
        }
        Tools::redirectAdmin("./index.php?tab=AdminModules&configure=$this->name&token=" . Tools::getAdminTokenLite("AdminModules") . "&tab_module=" . $this->tab . "&module_name=$this->name&success");
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

    public function hookActionOrderStatusUpdate($params)
    {
        $order = new Order((int) $params['id_order']);

        if (Validate::isLoadedObject($order) && $order->module == $this->name) {

            if ($params['oldOrderStatus']->id == Configuration::get('PS_OS_REFUND')) {
                $this->get('session')->getFlashBag()->add('error', "The refunded order cannot be changed");
                Tools::redirectAdmin(Context::getContext()->link->getAdminLink('AdminOrders', true));
                exit;
            }

            // Full-Refund

            if ($params['newOrderStatus']->id == Configuration::get('PS_OS_REFUND')) {

                $code = DB::getInstance()->getValue("SELECT payment_method FROM " . PT_DB_TRANSACTIONS_TABLE . " WHERE order_id = '" . (int) $order->id . "'");
                $refundResult = $this->processRefund($order, $code);

                if ($refundResult !== true) {
                    $this->get('session')->getFlashBag()->add('error', $refundResult);
                    Tools::redirectAdmin(Context::getContext()->link->getAdminLink('AdminOrders', true));
                    exit;
                }
            }
        }
    }

    private function processRefund(Order $order, $code)
    {
        $payment_refrence = DB::getInstance()->getRow("SELECT transaction_ref, transaction_amount FROM " . PT_DB_TRANSACTIONS_TABLE . " WHERE order_id = '" . (int) $order->id . "'");
        $currency = new Currency((int) $order->id_currency);

        $pt_refundHolder = new PaytabsFollowupHolder();
        $pt_refundHolder
            ->set02Transaction(PaytabsEnum::TRAN_TYPE_REFUND, PaytabsEnum::TRAN_CLASS_ECOM)
            ->set03Cart($order->id_cart, $currency->iso_code, $payment_refrence['transaction_amount'], "Admin Full Refund")
            ->set30TransactionInfo($payment_refrence['transaction_ref'])
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

            PaytabsHelper::log("Refund success, order [{$order->id} - {$message}]");

            if (!PayTabs_PayPage_Helper::save_payment_reference($order->id, $transaction_data)) {
                PaytabsHelper::log("DB insert failed [$order->id]", 3);
            }

            return true;
        } else {
            PaytabsHelper::log("Refund failed, {$order->id} - {$message}", 3);
            return "Refund Error: " . $message;
        }
    }

    /* To be Made :: Partial Refund */
    // public function hookActionProductCancel($params)
    // {
    // if ($params['action'] == CancellationActionType::STANDARD_REFUND ||
    //      $params['action'] == CancellationActionType::PARTIAL_REFUND) 
    // {

    // }
    // }

}
