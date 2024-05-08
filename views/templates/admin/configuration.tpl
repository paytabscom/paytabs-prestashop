<style>
    .discount-card .input-group-addon{
        padding:0;
        width:100px;
    }

    .discount-card .input-group-addon select{
        width: 100%;
        background-color: rgb(248, 248, 248);
        padding: 0;
        text-align: center;
        border: 0;
    }

    .discount-card .input-group-addon select option{
        font-size:13px !important;
    }

    .discount-card button.add-discount, .discount-card button.remove-discount-row{
        padding-block: 10px;
    }

    .discount-card button.remove-discount-row{
        padding-inline: 18px 
    }
    
</style>

{if isset($smarty.get.success)}
    <div class="alert alert-success" role="alert">
       <button type="button" class="close" data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
       </button>
       <p class="alert-text">Settings updated</p>
    </div>
{/if}
{if isset($errors_html)}
    {$errors_html}
{/if}

{foreach $paytabs_payment_types as $payment_type}

    <form id="configuration_form" class="defaultForm form-horizontal" method="POST" enctype="multipart/form-data"
        novalidate action="{$paytabs_action_url}">
        
        {$i = 2}

        {$code = $payment_type['name']}
        <input name="payment_method" value="{$code}" hidden/>

        <div class="panel">

            <div class="panel-heading">
                <i class="icon-key"></i> {$payment_type['title']}
            </div>

            <div class="form-wrapper">

                <div class="form-group">

                    <label class="control-label col-lg-3 required">
                        Enabled
                    </label>

                    <div class="col-lg-9">

                        <span class="switch prestashop-switch fixed-width-lg">
                            <input type="radio" name="active_{$code}" id="active_{$code}_on" value="1" {if (Configuration::get("active_$code"))} checked="checked" {/if}>
                            <label for="active_{$code}_on">Enabled</label>
                            <input type="radio" name="active_{$code}" id="active_{$code}_off" value="" {if (!Configuration::get("active_$code"))} checked="checked" {/if}>
                            <label for="active_{$code}_off">Disabled</label>
                            <a class="slide-button btn"></a>
                        </span>

                    </div>

                </div>

                <div class="form-group">

                    <label class="control-label col-lg-3 required">
                        Endpoint region
                    </label>

                    <div class="col-lg-9">

                        <select name="endpoint_{$code}" class=" fixed-width-xl" id="endpoint_{$code}">
                            {foreach $paytabs_endpoints as $endpoint}                                
                                <option value="{$endpoint['key']}" {if (Configuration::get("endpoint_$code") == "{$endpoint['key']}")} selected {/if}>
                                    {$endpoint['title']}
                                </option>
                            {/foreach}
                        </select>

                    </div>

                </div>

                <div class="form-group">
                    
                    <label class="control-label col-lg-3 required">
                        Profile ID
                    </label>

                    <div class="col-lg-9">
                        <input type="text" name="profile_id_{$code}" id="profile_id_{$code}" value="{Configuration::get("profile_id_$code")}" class="" required="required">
                    </div>

                </div>

                <div class="form-group">

                    <label class="control-label col-lg-3 required">
                        Server Key
                    </label>

                    <div class="col-lg-9">
                        <input type="text" name="server_key_{$code}" id="server_key_{$code}" value="{Configuration::get("server_key_$code")}" class="" required="required">
                    </div>

                </div>

                <div class="form-group">

                    <label class="control-label col-lg-3">
                        Hide Shipping info
                    </label>

                    <div class="col-lg-9">

                        <span class="switch prestashop-switch fixed-width-lg">
                            <input type="radio" name="hide_shipping_{$code}" id="hide_shipping_{$code}_on" value="1" {if (Configuration::get("hide_shipping_$code"))} checked="checked" {/if} >
                            <label for="hide_shipping_{$code}_on">Yes</label>
                            <input type="radio" name="hide_shipping_{$code}" id="hide_shipping_{$code}_off" value="" {if (!Configuration::get("hide_shipping_$code"))} checked="checked" {/if}>
                            <label for="hide_shipping_{$code}_off">No</label>
                            <a class="slide-button btn"></a>
                        </span>

                    </div>
                </div>

                <div class="form-group">

                    <label class="control-label col-lg-3">
                        Order in Checkout page
                    </label>

                    <div class="col-lg-9">
                        <input type="text" name="sort_{$code}" id="sort_{$code}" value="{(Configuration::get("sort_$code")) ? Configuration::get("sort_$code") : (($code == 'mada') ? 1 : $i++)}" class="">
                    </div>

                </div>


                <div class="form-group">

                    <label class="control-label col-lg-3">
                        Config id (Theme)
                    </label>

                    <div class="col-lg-9">
                        <input type="text" name="config_id_{$code}" id="config_id_{$code}" value="{Configuration::get("config_id_$code")}" class="">
                    </div>

                </div>


                <div class="form-group">

                    <label class="control-label col-lg-3">
                        Alternative Currency Enable
                    </label>

                    <div class="col-lg-9">

                        <span class="switch prestashop-switch fixed-width-lg">
                            <input type="radio" name="alt_currency_enable_{$code}" id="alt_currency_enable_{$code}_on" value="1" {if (Configuration::get("alt_currency_enable_$code"))} checked="checked" {/if}>
                            <label for="alt_currency_enable_{$code}_on">Enabled</label>
                            <input type="radio" name="alt_currency_enable_{$code}" id="alt_currency_enable_{$code}_off" value="" {if (!Configuration::get("alt_currency_enable_$code"))} checked="checked" {/if}>
                            <label for="alt_currency_enable_{$code}_off">Disabled</label>
                            <a class="slide-button btn"></a>
                        </span>

                    </div>

                </div>


                <div class="form-group">

                    <label class="control-label col-lg-3">
                        Alternative Currency
                    </label>

                    <div class="col-lg-9">
                        <input type="text" name="alt_currency_{$code}" id="alt_currency_{$code}" value="{Configuration::get("alt_currency_$code")}" class="">
                    </div>

                </div>

                {if ($code === 'valu')}

                    <div class="form-group">

                        <label class="control-label col-lg-3 required">
                            valU product ID
                        </label>

                        <div class="col-lg-9">
                            <input type="text" name="valu_product_id_valu" id="valu_product_id_valu" value="{Configuration::get("valu_product_id_valu")}" class="" required="required">
                        </div>
                    </div>

                {/if}


                {if (PaytabsHelper::isCardPayment($code)) }
                    <div class="form-group">

                        <label class="control-label col-lg-3">
                            Allow associated methods
                        </label>

                        <div class="col-lg-9">

                            <span class="switch prestashop-switch fixed-width-lg">
                                <input type="radio" name="allow_associated_methods_{$code}" id="allow_associated_methods_{$code}_on" value="1" {if (Configuration::get("allow_associated_methods_$code"))} checked="checked" {/if}>
                                <label for="allow_associated_methods_{$code}_on">Yes</label>
                                <input type="radio" name="allow_associated_methods_{$code}" id="allow_associated_methods_{$code}_off" value="" {if (!Configuration::get("allow_associated_methods_$code"))} checked="checked" {/if}>
                                <label for="allow_associated_methods_{$code}_off">No</label>
                                <a class="slide-button btn"></a>
                            </span>

                        </div>

                    </div>
                {/if}

                {if (PaytabsHelper::supportIframe($code))}
                    <div class="form-group">

                        <label class="control-label col-lg-3 required">
                            Payment form type
                        </label>

                        <div class="col-lg-9">

                            <select name="payment_form_{$code}" id="payment_form_{$code}">
                                {foreach $redirect_modes as $mode => $desc}                                
                                    <option value="{$mode}" {if (Configuration::get("payment_form_$code") == "{$mode}")} selected {/if}>
                                        {$desc}
                                    </option>
                                {/foreach}
                            </select>

                        </div>

                    </div>
                {/if}

                {if (PaytabsHelper::canUseCardFeatures($code)) }

                    <div class="form-group">

                        <label class="control-label col-lg-3">
                            Card Discounts (Beta)
                        </label>

                        <div class="col-lg-9">

                            <span class="switch prestashop-switch fixed-width-lg">
                                <input type="radio" name="discount_enabled_{$code}" class="pt-discount-switch" id="discount_enabled_{$code}_on" value="1" {if (Configuration::get("discount_enabled_$code"))} checked="checked" {/if}>
                                <label for="discount_enabled_{$code}_on">Enabled</label>
                                <input type="radio" name="discount_enabled_{$code}" class="pt-discount-switch" id="discount_enabled_{$code}_off" value="" {if (!Configuration::get("discount_enabled_$code"))} checked="checked" {/if}>
                                <label for="discount_enabled_{$code}_off">Disabled</label>
                                <a class="slide-button btn"></a>
                            </span>
                            <span class="help-block">Apply a payment gateway discounts on cards matching the blow defined list.</span>

                        </div>

                    </div>

                    <div class="form-group">
                        <label class="control-label col-lg-3">
                            Discount Cards:
                        </label>
                    </div>
                    <div class="form-group discount-card">
                        <div class="col-lg-12">
                            <div class="row">
                                <label class="control-label col-lg-3">
                                    Card Prefix :
                                </label>
                                <div class="col-lg-3">
                                    <input type="text" class="form-control" name="discount_cards_input_{$code}" value="">
                                    <span class="help-block">Digits only, length between 4 and 10, Separated by commas, (e.g 5200,441123).</span>
                                </div>
                                <div class="col-lg-5">
                                    <div class="row">
                                        <label class="control-label col-lg-3">
                                            Amount:
                                        </label>
                                        <div class="input-group col-lg-9">
                                            <input type="text" class="form-control" name="discount_amount_input_{$code}" value="">
                                            <div class="input-group-addon">
                                                <select class="form-select" name="discount_type_select_{$code}">
                                                    <option value="fixed">Fixed</option>
                                                    <option value="percentage">Percentage</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-1">
                                    <button type="button" class="btn btn-success add-discount" data-code="{$code}">+</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="cards-container">
                        {$discountCardsArr = Configuration::get("discount_cards_$code")|json_decode}
                        {$discountAmountArr = Configuration::get("discount_amount_$code")|json_decode}
                        {$discountTypeArr = Configuration::get("discount_type_$code")|json_decode}
                        
                        {if (is_array($discountCardsArr) && count($discountCardsArr) > 0) }
                            {foreach $discountCardsArr as $key => $card }
                                {* {if (!$discountCardsArr[$key] || !$discountAmountArr[$key])}
                                    {continue}
                                {/if} *}
                                <div class="form-group discount-card">
                                    <div class="col-lg-12">
                                        <div class="row">
                                            <label class="control-label col-lg-3">
                                                Card Prefix :
                                            </label>
                                            <div class="col-lg-3">
                                                <input type="text" name="discount_cards_{$code}[]" value="{$discountCardsArr[$key]}" class="" readonly>
                                            </div>
                                            <div class="col-lg-5">
                                                <div class="row">
                                                    <label class="control-label col-lg-3">
                                                        Amount:
                                                    </label>
                                                    <div class="input-group col-lg-9">
                                                        <input type="text" name="discount_amount_{$code}[]" value="{$discountAmountArr[$key]}" class="" readonly>
                                                        <div class="input-group-addon">
                                                            <select name="discount_type_{$code}[]" readonly>
                                                                <option value="fixed" {(($discountTypeArr[$key] == "fixed") ? "selected" : "")}>Fixed</option>
                                                                <option value="percentage" {(($discountTypeArr[$key] == "percentage") ? "selected" : "")}>Percentage</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-lg-1">
                                                <button type="button" class="btn btn-danger remove-discount-row" onclick="removeRow(this)" data-code="` + code + `">-</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            {/foreach}
                        {/if}
                    </div>

                {/if}
            </div><!-- /.form-wrapper -->

            <div class="panel-footer">
                <button type="submit" value="1" id="configuration_form_submit_btn" name="btnSubmit"
                    class="btn btn-default pull-right">
                    <i class="process-icon-save"></i> Save
                </button>
            </div>

        </div>

    </form>

{/foreach}


<script>
    
    let addDiscountBtns = document.getElementsByClassName('add-discount');
    
    for (let i = 0; i < addDiscountBtns.length; i++) {
        let btn = addDiscountBtns[i];
        btn.addEventListener('click', (e) => addDiscountRow(btn));
    }


    function addDiscountRow(btn)
    {        
        let code = btn.getAttribute('data-code');
        let discountCard  = btn.closest('.discount-card');

        let cardsInput = discountCard.querySelector('input[name="discount_cards_input_'+code+'"]');
        let amountInput = discountCard.querySelector('input[name="discount_amount_input_'+code+'"]');
        let typeInput = discountCard.querySelector('select[name="discount_type_select_'+code+'"]');

        let discount    = {};
        discount.cards  = cardsInput.value;
        discount.amount = amountInput.value;
        discount.type   = typeInput.value;

        cardsInput.value = amountInput.value = "";
        typeInput.selectedIndex = "0"

        let newDiscountRow = getRowHtml(code, discount);

        discountCard.insertAdjacentHTML('afterend', newDiscountRow);
    }

    function getRowHtml(code, discount) 
    {
        let cardHtml = `
            <div class="form-group discount-card">
                <div class="col-lg-12">
                    <div class="row">
                        <label class="control-label col-lg-3">
                            Card Prefix :
                        </label>
                        <div class="col-lg-3">
                            <input type="text" name="discount_cards_` + code + `[]" value="` + discount.cards + `" class="" readonly>
                        </div>
                        <div class="col-lg-5">
                            <div class="row">
                                <label class="control-label col-lg-3">
                                    Amount:
                                </label>
                                <div class="input-group col-lg-9">
                                    <input type="text" name="discount_amount_` + code + `[]" value="` + discount.amount + `" class="" readonly>
                                    <div class="input-group-addon">
                                        <select name="discount_type_` + code + `[]" readonly>
                                            <option value="fixed" ` + ((discount.type == "fixed") ? "selected" : "") + `>Fixed</option>
                                            <option value="percentage" `+ ((discount.type == "percentage") ? "selected" : "") + `>Percentage</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-1">
                            <button type="button" class="btn btn-danger remove-discount-row" onclick="removeRow(this)" data-code="` + code + `">-</button>
                        </div>
                    </div>
                </div>
            </div>
        `

        return cardHtml;
    }

    function removeRow(btn)
    {
        btn.closest('.discount-card').remove();
    }
</script>
