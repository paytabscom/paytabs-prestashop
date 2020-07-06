<?php

/**
 * PayTabs - A 3rd party Payment Module for PrestaShop 1.7
 *
 * This file is the declaration of the module.
 *
 * @author PayTabs <https://paytabs.com>
 * @license https://opensource.org/licenses/afl-3.0.php
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/paytabs_core2.php';

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
        $this->version                = '3.0.0';
        $this->author                 = 'PayTabs';
        $this->controllers            = array('payment', 'validation');
        $this->currencies             = true;
        $this->currencies_mode        = 'checkbox';
        $this->bootstrap              = true;
        $this->displayName            = 'PayTabs - PayPage';
        $this->description            = 'A simple payment gateway that can be quickly integrated with merchant websites and it enables fast deposit of payments to the merchant account. Equipped with PCI DSS certification and anti-fraud protection.';
        $this->confirmUninstall       = 'Are you sure you want to uninstall this module?';
        $this->ps_versions_compliancy = array('min' => '1.7.0', 'max' => _PS_VERSION_);

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
            && $this->registerHook('paymentOptions')
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

        $forms = array_map(function ($method) {
            $code = $method['name'];
            return array(
                'form' => array(
                    'legend' => array(
                        'title' => ($method['title']),
                        'icon' => 'icon-key'
                    ),
                    'input' => array(
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
                            'label' => 'Enabled',
                            'name' => 'active_' . $code,
                            'is_bool' => true,
                            'required' => true,
                            'values' => array(
                                array(
                                    'id' => 'active_on',
                                    'value' => true,
                                    'label' => $this->trans('Enabled', array(), 'Admin.Global'),
                                ),
                                array(
                                    'id' => 'active_off',
                                    'value' => false,
                                    'label' => $this->trans('Disabled', array(), 'Admin.Global'),
                                )
                            ),
                        ),
                    )
                ),
            );
        }, PaytabsApi::PAYMENT_TYPES);

        // Submit button for all Sections
        $forms[] = [
            'form' => [
                'legend' => array(
                    'title' => 'Save settings',
                    'icon' => 'icon-gears'
                ),
                'submit' => array(
                    'title' => $this->trans('Save', array(), 'Admin.Actions'),
                )
            ]
        ];

        $helper = new HelperForm();
        $helper->submit_action = 'btnSubmit';

        $values = array_reduce(PaytabsApi::PAYMENT_TYPES, function ($acc, $method) {
            $code = $method['name'];
            $acc["profile_id_{$code}"] = Tools::getValue("profile_id_{$code}", Configuration::get("profile_id_{$code}"));
            $acc["server_key_{$code}"] = Tools::getValue("server_key_{$code}", Configuration::get("server_key_{$code}"));
            $acc["active_{$code}"] = Tools::getValue("active_{$code}", Configuration::get("active_{$code}"));
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
                if (!Tools::getValue("profile_id_{$code}")) {
                    $this->_postErrors[] = "{$method['title']}: Profile ID is required.";
                }
                if (!Tools::getValue("server_key_{$code}")) {
                    $this->_postErrors[] = "{$method['title']}: Server Key is required.";
                }
            }
        }
    }

    protected function _postProcess()
    {
        if (Tools::isSubmit('btnSubmit')) {
            foreach (PaytabsApi::PAYMENT_TYPES as $index => $method) {
                $code = $method['name'];
                Configuration::updateValue("profile_id_{$code}", Tools::getValue("profile_id_{$code}"));
                Configuration::updateValue("server_key_{$code}", Tools::getValue("server_key_{$code}"));
                Configuration::updateValue("active_{$code}", Tools::getValue("active_{$code}"));
            }
        }
        $this->_html .= $this->displayConfirmation($this->trans('Settings updated', array(), 'Admin.Global'));
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

            $newOption = new PrestaShop\PrestaShop\Core\Payment\PaymentOption;
            $newOption
                ->setModuleName($code)
                ->setCallToActionText($title)
                ->setAction($formAction)
                ->setAdditionalInformation("Pay by PayTabs using $code")
                // ->setForm($paymentForm)
            ;

            $logo = "/icons/{$code}.png";
            $logo_path = (__DIR__ . $logo);
            if (file_exists($logo_path)) {
                $logo_path = (_MODULE_DIR_ . "{$this->name}{$logo}");
                $newOption->setLogo($logo_path);
            }

            $payment_options[] = $newOption;
        }

        return $payment_options;
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

        return $this->fetch("module:{$this->name}/views/templates/hook/payment_return.tpl");
    }
}
