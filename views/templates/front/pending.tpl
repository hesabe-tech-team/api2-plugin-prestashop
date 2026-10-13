{**
 * Copyright (c) 2026 the hesabe SDK authors. MIT License, see LICENSE.
 *}
{extends file='page.tpl'}

{block name='page_title'}
  {l s='Confirming your payment' mod='hesabe'}
{/block}

{block name='page_content'}
  {if $hesabe_retry_url}
    <p>{l s='Hesabe has not confirmed your payment yet. This page checks again in a few seconds.' mod='hesabe'}</p>
    <p><a class="btn btn-secondary" href="{$hesabe_retry_url|escape:'html':'UTF-8'}">{l s='Check now' mod='hesabe'}</a></p>
    <script>
      setTimeout(function () { window.location.href = {$hesabe_retry_url|json_encode nofilter}; }, 5000);
    </script>
  {else}
    <p>{l s='We have not received a confirmation from Hesabe yet.' mod='hesabe'}</p>
    <p>{l s='If you completed the payment, your order appears in your order history within a few minutes and you receive a confirmation email. If you did not, your cart is still here and you can try again.' mod='hesabe'}</p>
    <p>
      <a class="btn btn-primary" href="{$hesabe_history_url|escape:'html':'UTF-8'}">{l s='Order history' mod='hesabe'}</a>
      <a class="btn btn-secondary" href="{$hesabe_checkout_url|escape:'html':'UTF-8'}">{l s='Back to checkout' mod='hesabe'}</a>
    </p>
  {/if}
{/block}
