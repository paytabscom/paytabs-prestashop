<?php
use PrestaShop\PrestaShop\Core\Localization\CLDR\ComputingPrecision;

class OrderHelper 
{
    private const KEEP_ORDER_PRICES = true;

    public static function updateOrderTotals(Order $order, Cart $cart, int $computingPrecision)
    {
        $orderProducts = $order->getCartProducts();

        $carrierId = $order->id_carrier;
        $order->total_discounts = (float) abs($cart->getOrderTotal(true, Cart::ONLY_DISCOUNTS, $orderProducts, $carrierId, false, self::KEEP_ORDER_PRICES));
        $order->total_discounts_tax_excl = (float) abs($cart->getOrderTotal(false, Cart::ONLY_DISCOUNTS, $orderProducts, $carrierId, false, self::KEEP_ORDER_PRICES));
        $order->total_discounts_tax_incl = (float) abs($cart->getOrderTotal(true, Cart::ONLY_DISCOUNTS, $orderProducts, $carrierId, false, self::KEEP_ORDER_PRICES));

        // We should always use Cart::BOTH for the order total since it contains all products, shipping fees and cart rules
        $order->total_paid = Tools::ps_round(
            (float) $cart->getOrderTotal(true, Cart::BOTH, $orderProducts, $carrierId, false, self::KEEP_ORDER_PRICES),
            $computingPrecision
        );
        $order->total_paid_tax_excl = Tools::ps_round(
            (float) $cart->getOrderTotal(false, Cart::BOTH, $orderProducts, $carrierId, false, self::KEEP_ORDER_PRICES),
            $computingPrecision
        );
        $order->total_paid_tax_incl = Tools::ps_round(
            (float) $cart->getOrderTotal(true, Cart::BOTH, $orderProducts, $carrierId, false, self::KEEP_ORDER_PRICES),
            $computingPrecision
        );

        $order->total_products = (float) $cart->getOrderTotal(false, Cart::ONLY_PRODUCTS, $orderProducts, $carrierId, false, self::KEEP_ORDER_PRICES);
        $order->total_products_wt = (float) $cart->getOrderTotal(true, Cart::ONLY_PRODUCTS, $orderProducts, $carrierId, false, self::KEEP_ORDER_PRICES);

        $order->total_wrapping = abs($cart->getOrderTotal(true, Cart::ONLY_WRAPPING, $orderProducts, $carrierId, false, self::KEEP_ORDER_PRICES));
        $order->total_wrapping_tax_excl = abs($cart->getOrderTotal(false, Cart::ONLY_WRAPPING, $orderProducts, $carrierId, false, self::KEEP_ORDER_PRICES));
        $order->total_wrapping_tax_incl = abs($cart->getOrderTotal(true, Cart::ONLY_WRAPPING, $orderProducts, $carrierId, false, self::KEEP_ORDER_PRICES));

        return $order;
    }


    public static function updateOrderInvoices(Order $order, Cart $cart, int $computingPrecision)
    {
        $invoiceProducts = [];
        foreach ($order->getCartProducts() as $orderProduct) {
            if (!empty($orderProduct['id_order_invoice'])) {
                $invoiceProducts[$orderProduct['id_order_invoice']][] = $orderProduct;
            }
        }

        $invoiceCollection = $order->getInvoicesCollection();
        $firstInvoice = $invoiceCollection->getFirst();

        foreach ($invoiceCollection as $invoice) {
            // If all the invoice's products have been removed the offset won't exist
            $currentInvoiceProducts = isset($invoiceProducts[$invoice->id]) ? $invoiceProducts[$invoice->id] : [];

            // Shipping are computed on first invoice only
            $carrierId = $order->id_carrier;
            $totalMethod = ($firstInvoice === false || $firstInvoice->id == $invoice->id) ? Cart::BOTH : Cart::BOTH_WITHOUT_SHIPPING;
            $invoice->total_paid_tax_excl = Tools::ps_round(
                (float) $cart->getOrderTotal(false, $totalMethod, $currentInvoiceProducts, $carrierId, false, self::KEEP_ORDER_PRICES),
                $computingPrecision
            );
            $invoice->total_paid_tax_incl = Tools::ps_round(
                (float) $cart->getOrderTotal(true, $totalMethod, $currentInvoiceProducts, $carrierId, false, self::KEEP_ORDER_PRICES),
                $computingPrecision
            );

            $invoice->total_products = Tools::ps_round(
                (float) $cart->getOrderTotal(false, Cart::ONLY_PRODUCTS, $currentInvoiceProducts, $carrierId, false, self::KEEP_ORDER_PRICES),
                $computingPrecision
            );
            $invoice->total_products_wt = Tools::ps_round(
                (float) $cart->getOrderTotal(true, Cart::ONLY_PRODUCTS, $currentInvoiceProducts, $carrierId, false, self::KEEP_ORDER_PRICES),
                $computingPrecision
            );

            $invoice->total_discount_tax_excl = Tools::ps_round(
                (float) $cart->getOrderTotal(false, Cart::ONLY_DISCOUNTS, $currentInvoiceProducts, $carrierId, false, self::KEEP_ORDER_PRICES),
                $computingPrecision
            );

            $invoice->total_discount_tax_incl = Tools::ps_round(
                (float) $cart->getOrderTotal(true, Cart::ONLY_DISCOUNTS, $currentInvoiceProducts, $carrierId, false, self::KEEP_ORDER_PRICES),
                $computingPrecision
            );
        }

        return $order;
    }

    public static function getPrecisionFromCart(Cart $cart): int
    {
        $computingPrecision = new ComputingPrecision();
        $currency = new Currency((int) $cart->id_currency);

        return $computingPrecision->getPrecision((int) $currency->precision);
    }

    public static function updateOrderCartRules(
        Order $order,
        Cart $cart,
        int $computingPrecision,
        ?int $orderInvoiceId
    ) {
        CartRule::autoAddToCart(null, true);
        CartRule::autoRemoveFromCart(null, true);
        $carrierId = $order->id_carrier;

        $newCartRules = $cart->getCartRules(CartRule::FILTER_ACTION_ALL, false);
        // We need the calculator to compute the discount on the whole products because they can interact with each
        // other so they can't be computed independently, it needs to keep order prices
        $calculator = $cart->newCalculator($cart->getProducts(), $newCartRules, $carrierId, $computingPrecision, self::KEEP_ORDER_PRICES);
        $calculator->processCalculation();

        foreach ($order->getCartRules() as $orderCartRuleData) {
            foreach ($calculator->getCartRulesData() as $cartRuleData) {
                $cartRule = $cartRuleData->getCartRule();
                if ($cartRule->id == $orderCartRuleData['id_cart_rule']) {
                    // Cart rule is still in the cart no need to remove it, but we update it as the amount may have changed
                    $orderCartRule = new OrderCartRule($orderCartRuleData['id_order_cart_rule']);
                    $orderCartRule->id_order = $order->id;
                    $orderCartRule->name = $cartRule->name;
                    $orderCartRule->free_shipping = $cartRule->free_shipping;
                    $orderCartRule->value = Tools::ps_round($cartRuleData->getDiscountApplied()->getTaxIncluded(), $computingPrecision);
                    $orderCartRule->value_tax_excl = Tools::ps_round($cartRuleData->getDiscountApplied()->getTaxExcluded(), $computingPrecision);

                    $orderCartRule->save();
                    continue 2;
                }
            }

        }

        // Finally add the new cart rules that are not in the Order
        foreach ($calculator->getCartRulesData() as $cartRuleData) {
            $cartRule = $cartRuleData->getCartRule();
            foreach ($order->getCartRules() as $orderCartRuleData) {
                if ($cartRule->id == $orderCartRuleData['id_cart_rule']) {
                    // This cart rule is already present no need to add it
                    continue 2;
                }
            }

            // Add missing order cart rule
            $orderCartRule = new OrderCartRule();
            $orderCartRule->id_order = $order->id;
            $orderCartRule->id_cart_rule = $cartRule->id;
            $orderCartRule->id_order_invoice = $orderInvoiceId ?? 0;
            $orderCartRule->name = $cartRule->name;
            $orderCartRule->free_shipping = $cartRule->free_shipping;
            $orderCartRule->value = Tools::ps_round($cartRuleData->getDiscountApplied()->getTaxIncluded(), $computingPrecision);
            $orderCartRule->value_tax_excl = Tools::ps_round($cartRuleData->getDiscountApplied()->getTaxExcluded(), $computingPrecision);
            $orderCartRule->save();
        }

        return $order;

    }

}
