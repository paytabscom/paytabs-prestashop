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

require_once __DIR__ . '/paytabs_core.php';

function paytabs_error_log($msg, $severity)
{
    PrestaShopLogger::addLog($msg, $severity);
}

class PayTabs_PayPage extends PaymentModule
{
    private $_html = '';
    private $_postErrors = array();

    public $address;

    public const ON_HOLD_STATUS = 'PS_OS_PAYTABS_PENDING';
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
        $this->controllers            = array('payment', 'validation', 'callback', 'ipn');
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
        $this->addOrderState($this->name);
        return parent::install()
            && (PS_VERSION_IS_NEW ? $this->registerHook('paymentOptions') : $this->registerHook('payment'))
            && $this->registerHook('paymentReturn');
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

            if (PaytabsHelper::canUseCardFeatures($code)) {
                $discounts = Tools::getValue("discount_cards_{$code}");
                $amounts = Tools::getValue("discount_amount_{$code}");

                if (!$discounts || !$amounts || count($discounts) < 1) continue;

                if (count($discounts) != count($amounts)) {
                    $this->_postErrors[] = "{$method['title']}: Invalid (cards, amounts) map.";
                    continue;
                }

                foreach ($discounts as $key => $card) {
                    if (!PaytabsHelper::isValidDiscountPatterns($card)) {
                        $this->_postErrors[] = "{$method['title']}: Invalid Card pattern, Must be digits only, Length between 4 and 10, Separated by commas, (e.g 5200,4411)";
                        continue;
                    }

                    $amount_int = $amounts[$key];
                    if (!(is_numeric($amount_int) && $amount_int > 0)) {
                        $this->_postErrors[] = "{$method['title']}: Invalid discount amount";
                        continue;
                    }
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

                if (PaytabsHelper::canUseCardFeatures($code)) {
                    if (PaytabsHelper::isCardPayment($code)) {
                        Configuration::updateValue("allow_associated_methods_{$code}", Tools::getValue("allow_associated_methods_{$code}"));
                    }

                    $discount_cards  = Tools::getValue("discount_cards_{$code}", array());
                    $discount_amounts = Tools::getValue("discount_amount_{$code}", array());
                    $discount_types  = Tools::getValue("discount_type_{$code}", array());

                    Configuration::updateValue("discount_cards_{$code}", json_encode($discount_cards));
                    Configuration::updateValue("discount_amount_{$code}", json_encode($discount_amounts));
                    Configuration::updateValue("discount_type_{$code}", json_encode($discount_types));

                    Configuration::updateValue("discount_enabled_{$code}", (bool)Tools::getValue("discount_enabled_{$code}"));
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

    public function addOrderState($name) {

        if (!Configuration::get(self::ON_HOLD_STATUS)) {

            $orderState              = new OrderState();
            $orderState->color       = '#c97200';
            $orderState->send_email  = false;
            $orderState->module_name = $name;
            $orderState->unremovable = true;
            $orderState->logable     = false;
            $orderState->name        = array();
            $languages               = Language::getLanguages(false);

            foreach ($languages as $language) {
                $orderState->name[$language['id_lang']] = $this->l('Paytabs On-Hold Payment');
            }

            if ($orderState->add()) {
                Configuration::updateValue(self::ON_HOLD_STATUS, (int) $orderState->id);
            }
        }
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
}
