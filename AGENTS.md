# Padstone Mason — agent notes

## Release version tags

When shipping a version bump (`composer.json` `version` + git tag `X.Y.Z`):

1. Commit message body lists **what changed since the prior release** as bullets only (no “Since X.Y.Z:” heading or similar wrapper).
2. First line is `Release X.Y.Z.`
3. Leave a blank line, then the bullets.

Example:

```
Release 0.1.2.

- Editable discussion subjects (titleCustom migration)
- Resizable preview drawer with persisted width
```

Push `main` and the matching tag to `origin` (this repo: `imarc/padstone-mason`).

## Verification

Run checks from a Padstone (or Ops) environment with DB access:

```bash
ops craft migrate/up --plugin=padstone-mason
ops craft padstone-mason/check
```

Run migrations after every deploy that bumps `imarc/craft-padstone-mason`; missing columns (for example `titleCustom`) cause CP errors until migrate runs.

Craft CLI in Ops projects: prefer `ops craft`, not bare `php craft`.
