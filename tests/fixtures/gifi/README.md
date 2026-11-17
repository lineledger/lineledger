# CRA GIFI item names (RC4088)

`rc4088-names.json` holds the CRA's English and French name for every GIFI code
in `App\Support\Gifi\GifiCatalog`, plus the CRA total items the catalog leaves
out on purpose. `tests/Feature/Reporting/GifiCatalogTest.php` checks every
catalog label against it, and checks that no total item is offered as a line.

- **Source (English):** RC4088, "General Index of Financial Information (GIFI)",
  <https://www.canada.ca/en/revenue-agency/services/forms-publications/publications/rc4088/general-index-financial-information-gifi.html>
- **Source (French):** RC4088, « Index général des renseignements financiers (IGRF) »,
  <https://www.canada.ca/fr/agence-revenu/services/formulaires-publications/publications/rc4088/general-renseignements-financiers-igrf.html>
- **Retrieved:** 2026-11-16. The names were parsed from the item listing of each
  page and are copied verbatim, punctuation included.
- **SHA-256 of the pages as downloaded:** English
  `470010e78eb9492991f36233c53da23544114e540eea6e7b2210f3f771ead82f`, French
  `c17acd969e92da62dbedb6f299ef3973bc1fea03890b14a285b610a7e099a74e`.

The total items are the ones RC4088 describes as the sum of a block ("This
item represents the total of…", "…the sum of all…"): 1599, 2008, 2009, 2178,
2179, 2589, 2599, 3139, 3450, 3499, 3620, 3640, 3849, 8089, 8299, 8519, 9367,
9368, 9369 and 9999. Item 8518 Cost of sales is also described as a sum, but
RC4088's own GIFI-Short example reports it on its own, so the catalog keeps it.

To add a code to the catalog, add its two names here from the current RC4088
pages first; the test fails until the catalog label matches.
