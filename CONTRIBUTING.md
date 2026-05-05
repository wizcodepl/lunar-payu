# Contributing

Thanks for your interest. PRs and issues are welcome.

## Reporting bugs

Please open a GitHub issue with:
- Lunar core version
- Laravel version
- PHP version
- PayU account type (sandbox / production)
- A minimal reproduction (the smallest payload or test that triggers the bug)
- Expected vs. actual behavior

If you suspect a security issue (e.g. signature bypass, leaked secrets in logs), do **not** open a public issue — email security@wizcode.pl.

## Proposing changes

1. Fork and create a topic branch off `main`.
2. Add or update tests covering the change.
3. Run the suite:
   ```bash
   composer install
   composer test
   ```
4. Run the formatter and static analysis:
   ```bash
   composer format
   composer analyse
   ```
5. Open a PR against `main` with a description of the change and any context (linked issue, PayU docs reference, etc.).

## Versioning

[Semantic versioning](https://semver.org/). Breaking changes bump the major (after 1.0); new features bump the minor; bug fixes bump the patch.
