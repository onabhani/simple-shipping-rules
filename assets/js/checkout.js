(function($) {
  'use strict';

  var timers = [];

  function debugLog() {
    if (window.lawhaaShipping && window.lawhaaShipping.debug && window.console) {
      console.log.apply(console, arguments);
    }
  }

  function triggerClassicCheckoutUpdate() {
    if (!$('body').hasClass('woocommerce-checkout')) {
      return;
    }
    debugLog('[Lawhaa Shipping] update_checkout triggered');
    $(document.body).trigger('update_checkout');
  }

  function triggerDeferredUpdates() {
    timers.forEach(function(timer) { clearTimeout(timer); });
    timers = [];

    // OTO waits 1000ms before validating then performs an AJAX request.
    // Run several delayed refreshes so rates update after the address fields are populated.
    [600, 1800, 3200, 5200].forEach(function(delay) {
      timers.push(setTimeout(triggerClassicCheckoutUpdate, delay));
    });
  }

  $(document.body).on('change input blur', '#billing_city, #billing_state, #billing_postcode, #shipping_city, #shipping_state, #shipping_postcode', triggerDeferredUpdates);
  $(document.body).on('change input blur', '#ksa_national_address_shortcode, #billing-otoksa-shortcode', triggerDeferredUpdates);
  $(document.body).on('updated_checkout payment_method_selected', function() {
    debugLog('[Lawhaa Shipping] checkout updated/payment method selected');
  });

  // Recommended event for the patched OTO plugin. The plugin also works without it through delayed updates above.
  window.addEventListener('otoksa:address_validated', function() {
    debugLog('[Lawhaa Shipping] received otoksa:address_validated');
    triggerDeferredUpdates();
  });
})(jQuery);
