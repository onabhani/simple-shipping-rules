# Simple Shipping Rule — Codex Brief

This package contains a companion WooCommerce plugin for KSA shipping rules.

It is designed to work beside the existing plugin:

`KSA National Address Validator v1.2.2` by OTO.

Do **not** edit the OTO plugin directly unless you intentionally apply the optional patch in `/patches`. The shipping plugin reads the city/district values that the OTO plugin writes into WooCommerce checkout fields.

---

## Main goals

1. Generate shipping rates from:
   - Saudi National Address city.
   - Saudi National Address district/area.
   - Cart/package weight in kg.
   - Cart/package value.
2. Support the following shipping methods:
   - Center delivery.
   - Fast Mrsool.
   - Fast C4D.
   - Pickup from center.
   - Carrier shipping for cities outside the local area.
   - Heavy sink shipping for orders above 150 kg.
3. Control Cash on Delivery:
   - Allowed without fee for center delivery.
   - Not allowed for Mrsool, C4D, or heavy sink shipping.
   - Allowed for carrier shipping with 15 SAR fee.
4. Support Arabic and English strings through WordPress translation files.
5. Stay HPOS-compatible.

---

## Required WooCommerce setup

1. Install this folder as a WordPress plugin:

   `/wp-content/plugins/lawhaa-shipping-rules/`

2. Activate the plugin.

3. Go to:

   `WooCommerce → Settings → Shipping → Shipping zones`

4. Create/confirm a Saudi Arabia shipping zone.

5. Add shipping method:

   `Simple Smart Shipping`

6. Disable overlapping Flat Rate / Free Shipping methods in the same zone unless you intentionally want fallback methods.

7. Make sure WooCommerce product weights are entered correctly and the WooCommerce weight unit is correct. The plugin converts the store unit to kg.

---

## Shipping rules currently implemented

### Center delivery

Local group 15 SAR:

- Dammam
- Saihat

Local group 25 SAR:

- Qatif
- Khobar
- Tarout
- Aziziyah

Rules:

- 15 SAR for Dammam/Saihat.
- 25 SAR for Qatif/Khobar/Tarout/Aziziyah.
- Free above 300 SAR.
- COD allowed with no COD fee.
- Delivery time: 24-48 hours.

### Mrsool

- Local groups only.
- Max 50 kg.
- 30 SAR for first kg.
- +2 SAR for each additional kg.
- COD not allowed.
- Delivery time: 2-4 hours.

### C4D

- Local groups only.
- Max 50 kg.
- 25 SAR for first kg.
- +1 SAR for each additional kg.
- COD not allowed.
- Delivery time: 2-4 hours.

### Pickup

- Available for local groups.
- Free.
- COD allowed by default.

### Carrier shipping

For cities outside the local groups:

- 18 SAR for first 10 kg.
- +1 SAR for each 0.9 kg after the first 10 kg.
- Free above 350 SAR only if weight is 50 kg or less.
- COD allowed.
- COD fee: 15 SAR.
- Delivery time: 1-5 business days.

### Heavy sink shipping

- Activates above 150 kg for all cities.
- When active, it removes all other shipping rates.
- 25 SAR for first 10 kg.
- +2 SAR for each additional kg.
- COD not allowed.
- Delivery time: 1-5 business days.

---

## National Address compatibility

The OTO plugin fills:

- `billing_city`
- `billing_state` / district depending on the OTO setting
- `billing_postcode`
- `billing_address_1`
- `billing_address_2`

This plugin reads the WooCommerce package destination first, then falls back to `WC()->customer` billing/shipping values.

The current city/district matching is intentionally alias-based. It supports Arabic and English aliases for:

- الدمام / Dammam
- سيهات / Saihat
- القطيف / Qatif
- الخبر / Khobar
- تاروت / Tarout
- العزيزية / Aziziyah

Adjust aliases using the filter:

```php
add_filter( 'lawhaa_shipping_city_aliases', function( $aliases ) {
    $aliases['local_15'][] = 'newalias';
    return $aliases;
} );
```

Or override grouping:

```php
add_filter( 'lawhaa_shipping_city_group', function( $group, $city, $district, $city_key, $district_key ) {
    if ( $city_key === 'customcity' ) {
        return 'local_25';
    }
    return $group;
}, 10, 5 );
```

---

## Optional OTO patch

File:

`/patches/ksa-national-address-validator-i18n-and-event.patch`

This optional patch does two things:

1. Adds JS translation support for hardcoded OTO checkout messages.
2. Dispatches this browser event after successful National Address validation:

```js
window.dispatchEvent(new CustomEvent('otoksa:address_validated', { detail: response.data }));
```

This plugin already works without this event by forcing delayed checkout refreshes after the National Address shortcode changes. The event makes the integration cleaner and faster.

---

## Important test cases

Use products with known weights.

1. Dammam, 5 kg, 200 SAR:
   - Center delivery = 15 SAR.
   - Mrsool = 38 SAR.
   - C4D = 29 SAR.
   - Pickup = free.
   - COD allowed only for center/pickup.

2. Qatif/Tarout, 5 kg, 200 SAR:
   - Center delivery = 25 SAR.
   - Mrsool = 38 SAR.
   - C4D = 29 SAR.
   - Pickup = free.

3. Dammam, 5 kg, 350 SAR:
   - Center delivery = free.
   - Fast methods still paid.

4. Riyadh, 20 kg, 200 SAR:
   - Carrier = 18 + ceil((20 - 10) / 0.9) = 30 SAR.
   - COD fee = +15 SAR only if COD selected.

5. Riyadh, 40 kg, 400 SAR:
   - Carrier shipping = free.
   - COD fee = +15 SAR only if COD selected.

6. Riyadh, 60 kg, 400 SAR:
   - Carrier is not free because weight exceeds 50 kg.
   - Carrier = 18 + ceil((60 - 10) / 0.9) = 74 SAR.

7. Any city, 151 kg:
   - Only heavy sink shipping appears.
   - COD hidden.

---

## Known decisions that may need confirmation

1. Pickup is currently enabled for both local_15 and local_25 groups.
2. Fast delivery is currently enabled for both local_15 and local_25 groups, including Tarout/Aziziyah when the National Address city/district maps to Qatif/Khobar.
3. Carrier COD fee is added even when carrier shipping itself is free.
4. Heavy shipping activates strictly by total package weight above 150 kg, not by product category or shipping class.

If any of these assumptions are wrong, update `Lawhaa_Shipping_Rules::config()` or use filters.
