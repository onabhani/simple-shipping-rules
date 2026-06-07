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

## Deferred — owner decision required (behavior/UX change, not bugs)

- **Dual group/alias gating model.** Eligibility for fast/pickup is now gated by *both*
  `fast_groups`/`pickup_groups` *and* the per-method alias lists (`mrsool_aliases`,
  `c4d_aliases`, `pickup_aliases`, plus the legacy `fast_aliases`/`fast_local`). This is
  coherent but redundant: editing a per-method alias box may have no effect if the group
  gate (filter-only, not in the UI) blocks it. Recommend choosing one source of truth and
  retiring `fast_aliases`/`fast_local`. Not changed here because it alters documented
  behavior and the public filter surface.
- **Block (Store API) checkout.** COD hide/fee logic is classic-checkout-only and silently
  inert on the now-default Block checkout. Needs a Store-API integration or an explicit
  admin notice gating to classic checkout.
- **i18n of rate `meta` keys and delivery estimates.** Translated strings are used as `meta`
  array keys (locale-dependent key collisions possible), and `*_delivery_estimate` values are
  single-language admin strings shown verbatim. Recommend structured meta entries and
  localized estimate companions for the Arabic storefront.
- **Heavy threshold inclusivity.** Heavy triggers on `weight > heavy_threshold_kg` (strict),
  so exactly 150 kg ships as a carrier parcel. Matches the Arabic doc ("above 150") and is now
  pinned by a boundary test; flagged in case `>=` is intended.
- **Dead back-compat key `fast_max_kg`.** Written but never read by the rate engine; a
  filter-set value is clobbered. Keep (documented) or remove with a migration.

## Confirmed solid (no change)

No raw SQL, no `unserialize`, no SSRF/file-inclusion. Input is consistently unslashed +
sanitized; settings writes are capability- and nonce-gated; the option sanitizer whitelists
keys via `array_intersect_key` (no mass-assignment). `checkout.js` bind-once + namespaced
handlers + snapshot dedup + debounce are correct. Order meta is sanitized before save.
