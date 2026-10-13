{**
 * Copyright (c) 2026 the hesabe SDK authors. MIT License, see LICENSE.
 *}
<div class="card mt-2" id="hesabe-order-panel">
  <div class="card-header">
    <h3 class="card-header-title">{l s='Hesabe' mod='hesabe'}{if $hesabe_environment == 'sandbox'} ({l s='sandbox' mod='hesabe'}){/if}</h3>
  </div>
  <div class="card-body">
    {if $hesabe_flash}
      <div class="alert alert-{if $hesabe_flash.type == 'success'}success{elseif $hesabe_flash.type == 'warning'}warning{else}danger{/if}">
        {$hesabe_flash.text|escape:'html':'UTF-8'}
      </div>
    {/if}
    <table class="table">
      <tbody>
        <tr><th>{l s='Payment' mod='hesabe'}</th><td>{$hesabe_method|escape:'html':'UTF-8'}, {$hesabe_payment.amount|escape:'html':'UTF-8'} {$hesabe_payment.currency|escape:'html':'UTF-8'}</td></tr>
        <tr><th>{l s='Reference' mod='hesabe'}</th><td><code>{$hesabe_payment.reference|escape:'html':'UTF-8'}</code></td></tr>
        <tr><th>{l s='Hesabe token' mod='hesabe'}</th><td><code>{$hesabe_payment.token|escape:'html':'UTF-8'}</code></td></tr>
        <tr><th>{l s='Status' mod='hesabe'}</th><td>{$hesabe_status|escape:'html':'UTF-8'}</td></tr>
      </tbody>
    </table>

    {if $hesabe_refunds}
      <h4>{l s='Refund requests' mod='hesabe'}</h4>
      <table class="table">
        <thead><tr><th>{l s='Date' mod='hesabe'}</th><th>{l s='Amount' mod='hesabe'}</th><th>{l s='Status' mod='hesabe'}</th><th>{l s='Hesabe id' mod='hesabe'}</th><th></th></tr></thead>
        <tbody>
          {foreach $hesabe_refunds as $refund}
            <tr>
              <td>{$refund.date_add|escape:'html':'UTF-8'}</td>
              <td>{$refund.amount|escape:'html':'UTF-8'}</td>
              <td>{$refund.status_label|escape:'html':'UTF-8'}</td>
              <td>{$refund.hesabe_refund_id|escape:'html':'UTF-8'}</td>
              <td>{$refund.message|escape:'html':'UTF-8'}</td>
            </tr>
          {/foreach}
        </tbody>
      </table>
    {/if}

    {if $hesabe_can_refund}
      <form method="post" action="{$hesabe_refund_action|escape:'html':'UTF-8'}" class="form-inline">
        <input type="hidden" name="id_order" value="{$hesabe_payment.id_order|intval}">
        <label for="hesabe-refund-amount" class="mr-2">{l s='Refund through Hesabe (KWD)' mod='hesabe'}</label>
        <input type="text" inputmode="decimal" class="form-control mr-2" id="hesabe-refund-amount" name="hesabe_refund_amount" value="{$hesabe_refundable|escape:'html':'UTF-8'}" size="10">
        <button type="submit" name="submitHesabeRefund" class="btn btn-primary">{l s='Send refund request' mod='hesabe'}</button>
      </form>
      <p class="text-muted mt-2">{l s='Hesabe approves each request before money moves. The minimum is %s KWD, and one request can be pending at a time. This does not create a credit slip; use the order\'s own refund buttons for that.' sprintf=[$hesabe_minimum] mod='hesabe'}</p>
    {/if}
  </div>
</div>
