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
  {if !$hesabe_can_refund}
    <p>{l s='Refunds from the order page need the Merchant Panel login, below.' mod='hesabe'}</p>
  {/if}
</div>
