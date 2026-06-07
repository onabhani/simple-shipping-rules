# Implementation Notes

## Main hooks used

- `woocommerce_shipping_methods` registers the custom shipping method.
- `woocommerce_shipping_init` ensures WooCommerce shipping classes are available.
- `WC_Shipping_Method::calculate_shipping()` generates rates.
- `woocommerce_available_payment_gateways` hides COD when the selected shipping method does not allow it.
- `woocommerce_cart_calculate_fees` adds the 15 SAR COD fee only for carrier shipping.
- `woocommerce_checkout_create_order` stores shipping rule metadata on the order.
- `woocommerce_checkout_process` prevents checkout if a Saudi order has no city.
- `woocommerce_package_rates` defensively removes all other rates when heavy sink shipping is active.

WooCommerce's official Shipping Method API expects custom rates to be added from `calculate_shipping()`, and that is the pattern used here.

## Why not use WooCommerce Zones only?

Zones are still used to scope the method to Saudi Arabia, but the actual calculation needs a rules engine because the logic depends on city/district, weight, order value, COD rules, and heavy-shipment overrides.

## Recommended checkout setup

Prefer one address source:

- National Address shortcode validates the address.
- OTO fills billing fields.
- Shipping to another address should be disabled unless the OTO plugin also fills shipping fields.

If `Ship to a different address` remains enabled, confirm that the shipping destination is also populated by National Address, otherwise WooCommerce may calculate using incomplete shipping fields.

## Block checkout warning

The package is written to be compatible with classic checkout and reasonably compatible with Blocks. The OTO plugin itself supports Blocks. Still, the first production version should be tested on the actual checkout type used by the store.
