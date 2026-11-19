# INI merge fixtures

Each sub directory is a fixed, reviewable merge scenario used by
`IniMergeTest` and `ScanMergeTest`:

- `legacy-single/` - one self contained `php.ini`; proves the legacy
  single-file behaviour stays byte-for-byte compatible.
- `multi-source/` - a primary `php.ini` plus ordered `scan.d/*.ini` files;
  proves benign duplicates, tolerated non-security overrides and the full
  provenance/order chain end to end.
- `dangerous/` - a later source silently weakens `allow_url_fopen`; the
  merge must fail closed with an `IniConflictException`.
- `in-file-duplicate/` - repeated key inside one file; last value wins but
  every declaration is retained in provenance.
- `broken/` - an unparsable source; must raise `IniParseException`.
