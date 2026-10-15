{**
 * Copyright (c) 2026 the hesabe SDK authors. MIT License, see LICENSE.
 *}
<div class="panel">
  <h3><i class="icon-info"></i> {l s='Hesabe' mod='hesabe'}</h3>
  {if !$hesabe_configured}
    <p class="alert alert-warning">{l s='Enter your Hesabe keys below. Checkout shows no Hesabe option until they are saved.' mod='hesabe'}</p>
  {elseif $hesabe_production}
    <p class="alert alert-success">{l s='Production: payments are real.' mod='hesabe'}</p>
  {else}
    <p class="alert alert-info">{l s='Sandbox: payments are tests and no money moves. Pay with Hesabe test cards.' mod='hesabe'}</p>
  {/if}
  <p>{l s='The order is created only after Hesabe confirms the payment through its transaction lookup. The webhook and the customer returning both trigger that lookup; neither is trusted on its own.' mod='hesabe'}</p>
  <p>
    {l s='Run this address every 15 minutes from a cron job. It catches payments whose customer never came back, and card declines, which Hesabe does not report.' mod='hesabe'}<br>
    <code>{$hesabe_cron_url|escape:'html':'UTF-8'}</code>
  </p>
  {if $hesabe_configured && $hesabe_reconciled_at == ''}
    <p class="alert alert-warning">{l s='The reconcile address has not run yet. Until it does, a payment whose customer never comes back gets no order.' mod='hesabe'}</p>
  {elseif $hesabe_configured && $hesabe_reconcile_late}
    <p class="alert alert-warning">{l s='The reconcile address last ran on %s. Check the cron job.' sprintf=[$hesabe_reconciled_at] mod='hesabe'}</p>
  {elseif $hesabe_reconciled_at != ''}
    <p class="text-muted">{l s='Last run: %s.' sprintf=[$hesabe_reconciled_at] mod='hesabe'}</p>
  {/if}
  {if !$hesabe_can_refund}
    <p>{l s='Refunds from the order page need the Merchant Panel login, below.' mod='hesabe'}</p>
  {/if}
</div>
{if $hesabe_without_order}
  <div class="panel">
    <h3><i class="icon-warning"></i> {l s='Paid at Hesabe, no order' mod='hesabe'}</h3>
    <p>{l s='Hesabe took these payments in the last %d days, but none has an order of its own. Refund each from the Hesabe Merchant Panel, or create the order by hand from the cart.' sprintf=[$hesabe_without_order_days] mod='hesabe'}</p>
    <table class="table">
      <thead><tr><th>{l s='Date' mod='hesabe'}</th><th>{l s='Reference' mod='hesabe'}</th><th>{l s='Cart' mod='hesabe'}</th><th>{l s='Amount' mod='hesabe'}</th><th>{l s='Status' mod='hesabe'}</th><th>{l s='Reason' mod='hesabe'}</th></tr></thead>
      <tbody>
        {foreach $hesabe_without_order as $payment}
          <tr>
            <td>{$payment.date_upd|escape:'html':'UTF-8'}</td>
            <td><code>{$payment.reference|escape:'html':'UTF-8'}</code></td>
            <td>{$payment.id_cart|intval}</td>
            <td>{$payment.amount|escape:'html':'UTF-8'} {$payment.currency|escape:'html':'UTF-8'}</td>
            <td>{$payment.status_label|escape:'html':'UTF-8'}</td>
            <td>{$payment.last_error|escape:'html':'UTF-8'}</td>
          </tr>
        {/foreach}
      </tbody>
    </table>
  </div>
{/if}
