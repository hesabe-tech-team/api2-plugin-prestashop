{**
 * Copyright (c) 2026 the hesabe SDK authors. MIT License, see LICENSE.
 *}
<section class="hesabe-payment-return">
  <p>{l s='Your payment was received by Hesabe.' mod='hesabe'}</p>
  {if $hesabe_reference}
    <p>{l s='Payment reference: %s' sprintf=[$hesabe_reference] mod='hesabe'}</p>
  {/if}
</section>
