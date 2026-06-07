(function($) {
  'use strict';

  if (window.lawhaaShippingCheckoutBound) {
    return;
  }
  window.lawhaaShippingCheckoutBound = true;

  var debounceTimer = null;
  var refreshLocked = false;
  var lastSnapshot = '';
  var debounceDelay = (window.lawhaaShipping && window.lawhaaShipping.debounceDelay) || 400;

  function debugLog() {
    if (window.lawhaaShipping && window.lawhaaShipping.debug && window.console) {
      console.log.apply(console, arguments);
    }
  }

  function isClassicCheckout() {
    return $('body').hasClass('woocommerce-checkout') && !$('.wc-block-checkout').length;
  }

  function fieldValue(selector) {
    var $field = $(selector);
    return $field.length ? String($field.val() || '').trim() : '';
  }

  function currentSnapshot() {
    return [
      fieldValue('#shipping_country') || fieldValue('#billing_country'),
      fieldValue('#shipping_city') || fieldValue('#billing_city'),
      fieldValue('#shipping_state') || fieldValue('#billing_state'),
      fieldValue('#shipping_address_1') || fieldValue('#billing_address_1'),
      fieldValue('#shipping_address_2') || fieldValue('#billing_address_2'),
      fieldValue('input[name="payment_method"]:checked')
    ].join('|');
  }

  function triggerCheckoutUpdate(reason, force) {
    if (!isClassicCheckout() || refreshLocked) {
      return;
    }

    var snapshot = currentSnapshot();
    if (!force && snapshot === lastSnapshot) {
      debugLog('[Lawhaa Shipping] checkout update skipped; no relevant address/payment changes', reason);
      return;
    }

    lastSnapshot = snapshot;
    refreshLocked = true;
    debugLog('[Lawhaa Shipping] update_checkout triggered', reason);
    $(document.body).trigger('update_checkout');

    window.setTimeout(function() {
      refreshLocked = false;
    }, 900);
  }

  function scheduleCheckoutUpdate(reason, force) {
    window.clearTimeout(debounceTimer);
    debounceTimer = window.setTimeout(function() {
      triggerCheckoutUpdate(reason, force);
    }, debounceDelay);
  }

  $(function() {
    lastSnapshot = currentSnapshot();
  });

  $(document.body)
    .off('.lawhaaShipping')
    .on('change.lawhaaShipping input.lawhaaShipping blur.lawhaaShipping', '#billing_country, #billing_city, #billing_state, #billing_address_1, #billing_address_2, #shipping_country, #shipping_city, #shipping_state, #shipping_address_1, #shipping_address_2', function() {
      scheduleCheckoutUpdate('address field changed', false);
    })
    .on('change.lawhaaShipping input.lawhaaShipping blur.lawhaaShipping', '#ksa_national_address_shortcode, #billing-otoksa-shortcode', function() {
      scheduleCheckoutUpdate('national address shortcode changed', false);
    })
    .on('payment_method_selected.lawhaaShipping change.lawhaaShipping', 'input[name="payment_method"]', function() {
      scheduleCheckoutUpdate('payment method changed', true);
    })
    .on('updated_checkout.lawhaaShipping', function() {
      refreshLocked = false;
      lastSnapshot = currentSnapshot();
      debugLog('[Lawhaa Shipping] checkout updated');
    });

  window.addEventListener('otoksa:address_validated', function() {
    debugLog('[Lawhaa Shipping] received otoksa:address_validated');
    scheduleCheckoutUpdate('otoksa:address_validated', false);
  });
})(jQuery);
