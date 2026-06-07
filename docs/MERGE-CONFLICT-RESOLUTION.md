# Merge conflict resolution for the Lawhaa Shipping Rules PR

If GitHub still says this PR has conflicts in these files:

- `docs/IMPLEMENTATION-NOTES.md`
- `includes/class-lawhaa-checkout.php`
- `includes/class-lawhaa-shipping-rules.php`
- `tests/rules-smoke.php`

then the conflict is between the PR branch and the current target branch on GitHub, not unresolved conflict markers in this working tree. The local tree should stay clean and the smoke test checks these files for conflict markers.

## Codex limitation

Codex cannot update a PR branch after that PR branch has been modified outside of Codex. If the GitHub PR still shows conflicts and you cannot use GitHub's "Update branch" button, create a new clean PR instead of continuing to push to the conflicted PR.

## Recommended path

Prefer updating the existing PR branch if you can push to it:

```bash
git fetch origin
git checkout <pr-branch>
git merge origin/<target-branch>
# Resolve the four listed files, keeping the Lawhaa admin-settings/rate-engine changes.
php -d error_reporting=E_ALL tests/rules-smoke.php
git add docs/IMPLEMENTATION-NOTES.md includes/class-lawhaa-checkout.php includes/class-lawhaa-shipping-rules.php tests/rules-smoke.php
git commit
git push origin <pr-branch>
```

## If the existing PR branch is hard to repair

Create a fresh branch from the latest target branch and cherry-pick the Lawhaa changes. This is often cleaner when GitHub cannot update the existing PR automatically:

```bash
git fetch origin
git checkout -b lawhaa-settings-clean origin/<target-branch>
git cherry-pick 6e8f5dc
# Resolve any conflicts once, then run tests.
php -d error_reporting=E_ALL tests/rules-smoke.php
git push origin lawhaa-settings-clean
```

Then open a new PR from `lawhaa-settings-clean` and close the old conflicted PR.

## Conflict resolution notes

When resolving the listed files, keep the current Lawhaa implementations for:

- Admin-configurable settings and sanitized defaults.
- COD decisions through `cod_allowed_for_rate()` and `cod_fee_for_rate()`.
- Debounced Classic Checkout updates and postcode-optional behavior.
- Smoke-test coverage for pricing, sanitization, and conflict-marker checks.

Do not reintroduce the compiled Arabic `.mo` binary diff into the PR. Keep `.po` and `.pot` source changes reviewable, and regenerate `.mo` during release if needed.
