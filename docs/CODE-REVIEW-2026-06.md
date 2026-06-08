# Code review — performance, architecture, security (2026-06)

Multi-agent review of the plugin after the admin-settings rate engine landed. Three
independent passes (security, architecture/correctness, performance) plus expanded
tests. This document records what was fixed and what was deliberately deferred.

## Fixed in this change

| # | Area | Finding | Fix |
|---|------|---------|-----|
| 1 | Performance (High) | `config()` re-read + re-sanitized ~20× per package, and `aliases()` re-normalized ~150 alias lines several times per package, on every cart/checkout recalc. | Request-level memoization of `config()` and `aliases()` (`$config_cache`/`$aliases_cache`) with `reset_cache()` hooked to `add_option_/update_option_lawhaa_shipping_rules_settings`. ~95% hot-path CPU cut; also guarantees one config snapshot per request, removing the intra-request divergence risk from non-idempotent filters. |
| 2 | Performance (Medium) | `lawhaa_shipping_rules_settings` (a multi-KB array) was autoloaded on every site-wide page load. | `add_option(..., '', 'no')` for new installs; activation flips existing rows to `autoload = no` via `update_option(..., 'no')`. With memoization, the single `get_option()` per request runs only on cart/checkout. **Deployment note:** existing installs apply the flip on (re)activation. |
| 3 | Security (Medium) | Customer-controlled `city`/`district`/`group` injected into debug rate `meta_data` relied solely on input sanitization for output safety. | Explicit `esc_html()` at the point the debug meta is built (`class-lawhaa-shipping-method.php`). |
| 4 | Performance (Low) | `normalize_location()` cache eviction used `array_shift()` (O(n) reindex of a 500-entry assoc array). | `unset( $cache[ array_key_first( $cache ) ] )`. |
| 5 | Correctness (Codex P2) | `pickup_groups`/`fast_groups` config keys were retained but never read, breaking the documented `docs/FILTER-EXAMPLES.php` #3/#4 filters. | `build_rates()` now gates mrsool/c4d on `fast_groups` and pickup on `pickup_groups`. Defaults include both local groups, so default behavior is unchanged. |
| 6 | Tests | No coverage at the expensive weight/amount boundaries or for the group filters. | Added boundary tests at exactly `mrsool_max_kg`, `carrier_free_max_kg`, `heavy_threshold_kg` (and just past each), a zero-weight case, and the `fast_groups`/`pickup_groups` regression. |

## Previously deferred — now resolved (2026-06, "rebrand + deferred" change)

- **Dual group/alias gating model — simplified.** Removed the redundant third source: the
  `fast_aliases` config key and its `fast_local` alias bucket (which duplicated the
  `mrsool_aliases`/`c4d_aliases` lists and OR'd into fast eligibility) are gone. Fast eligibility
  is now exactly: `fast_groups` (coarse region gate, filter-only, documented in FILTER-EXAMPLES)
  **and** the per-method `mrsool_aliases`/`c4d_aliases` (fine-grained, in the UI). Two clear,
  purposeful gates instead of three overlapping ones.
- **Dead key `fast_max_kg` — removed.** It was written but never read by the rate engine.
- **Block (Store API) checkout — supported.** Declares `cart_checkout_blocks` compatibility.
  Rates come from the `WC_Shipping_Method` (Blocks-native); COD hide/fee run through
  Store-API-compatible hooks (`woocommerce_available_payment_gateways`,
  `woocommerce_cart_calculate_fees`); the SA-city requirement is enforced on Blocks via
  `woocommerce_store_api_cart_errors` (mirrors the classic `woocommerce_checkout_process` guard).
  **Verification note:** implemented to spec but not runtime-tested against a live Block
  checkout in this environment — smoke-test on a real WooCommerce Blocks store before release.
- **Localized delivery estimates.** Each estimate has an optional `*_delivery_estimate_ar`
  companion (UI field added); rate labels pick the Arabic value on an Arabic/RTL storefront.
  The label wrapper `'%1$s (%2$s)'` is now translatable.
- **Heavy threshold inclusivity — decided.** Kept strict `>` ("orders above N kg"); documented
  at the gate and pinned by boundary tests (exactly N ships as carrier, just past N is heavy).

## Still open (intentional, not a bug)

- **Translated strings as rate `meta` keys.** WooCommerce renders rate `meta_data` as
  label⇒value, so these keys are *meant* to be localized display labels; the theoretical
  collision risk (two English strings translating identically) doesn't apply to the distinct
  strings used here. Left as-is to avoid a display regression.

## Confirmed solid (no change)

No raw SQL, no `unserialize`, no SSRF/file-inclusion. Input is consistently unslashed +
sanitized; settings writes are capability- and nonce-gated; the option sanitizer whitelists
keys via `array_intersect_key` (no mass-assignment). `checkout.js` bind-once + namespaced
handlers + snapshot dedup + debounce are correct. Order meta is sanitized before save.
