# Implementation Notes

## Main hooks used

- `woocommerce_shipping_methods` registers the custom shipping method.
- `woocommerce_shipping_init` ensures WooCommerce shipping classes are available.
- `WC_Shipping_Method::calculate_shipping()` generates rates.
- `woocommerce_available_payment_gateways` hides COD when the selected shipping method does not allow it.
- `woocommerce_cart_calculate_fees` adds the configured COD fee for the selected Lawhaa rate when COD is selected.
- `woocommerce_checkout_create_order` stores shipping rule metadata on the order.
- `woocommerce_checkout_process` prevents checkout if a Saudi order has no city.
- `woocommerce_shipping_calculator_enable_postcode` hides the cart shipping-calculator postcode field because Lawhaa rates use city/district.
- `woocommerce_package_rates` defensively removes stale non-heavy Lawhaa rates when heavy sink shipping is active.

WooCommerce's official Shipping Method API expects custom rates to be added from `calculate_shipping()`, and that is the pattern used here.
The zone method uses WooCommerce instance settings so each shipping-zone instance can be enabled, titled, and debugged independently.


## Admin configuration

Settings are managed in WordPress admin at:

`WooCommerce → Lawhaa Shipping Rules`

The per-zone WooCommerce shipping method still controls whether `Lawhaa Smart Shipping` is enabled in a Saudi shipping zone. The dedicated Lawhaa page controls rates, thresholds, city/district aliases, COD settings, labels, estimates, and debug logging.

City and district aliases are stored one value per line and are normalized before matching. The matching uses both city and district/address line 2 values populated by the National Address plugin.

## COD notes

The plugin owns Lawhaa COD availability and fee logic. Disable old custom snippets that add COD fees or hide COD for these same Lawhaa rates, otherwise WooCommerce may show duplicate or conflicting behavior. WooCommerce recalculates fees when the selected payment/shipping method changes; the frontend script debounces payment-method refreshes to one checkout update.

## Performance and security checks

- Rule configuration is normalized after filtering so unknown keys are stripped, numeric costs/thresholds cannot become negative, non-numeric values fall back safely, and the carrier divisor falls back to a safe default if a custom filter returns zero.
- Destination values are read from a small allowlist and array payloads are rejected before cleaning, preventing unexpected checkout/package shapes from reaching rate calculations.
- Location normalization is cached in request memory to reduce repeated Unicode normalization work during checkout recalculations.
- Checkout POST reads go through one sanitizing helper, order metadata saved by the plugin is cleaned before storage, and checkout address refreshes are debounced/client-side deduplicated.

## Why not use WooCommerce Zones only?

Zones are still used to scope the method to Saudi Arabia, but the actual calculation needs a rules engine because the logic depends on city/district, weight, order value, COD rules, and heavy-shipment overrides.

## Recommended checkout setup

Prefer one address source:

- National Address shortcode validates the address.
- OTO fills billing fields.
- Shipping to another address should be disabled unless the OTO plugin also fills shipping fields.

If `Ship to a different address` remains enabled, confirm that the shipping destination is also populated by National Address, otherwise WooCommerce may calculate using incomplete shipping fields.

## Block checkout warning

The package targets Classic Checkout for automatic address-refresh behavior. When Checkout Blocks are detected, the frontend script avoids forcing Classic Checkout `update_checkout` events so it does not break Blocks; test Blocks separately before production use because the National Address integration is checkout-theme/plugin dependent.
