# MenuLeaf Sitemap v1.0.1

**Joomla 5.4.x and 6.2.x — Tested release (2026-11-08)**

A lightweight Joomla system plugin that generates a nested, menu-driven HTML sitemap (`{menuleafsitemap}`) and a dynamic XML sitemap (`?menuleaf_sitemap=xml`) including public Joomla articles. No OSMap, Joomlashack or external library dependency.

## Highlights

- Hardened direct single-article XML menu routes: verifies that the destination article and its category hierarchy are publicly accessible, appropriately published, in date, and eligible under the archived-content setting.
- Two-pass handling of `noindex` on duplicate menu routes: a `noindex` public menu item suppresses other routes to the same article in XML.
- HTML output, existing options and endpoint are unchanged; safe in-place upgrade from MenuLeaf v1.0.0.

## Test results

- **Joomla 5.4.10:** 377 URLs with archived OFF; 2,174 with archived ON. Exactly the same URLs as the v1.0.0 baseline except for the intentionally deleted previous OSMap sitemap link.
- **Joomla 6.2.0:** 77 URLs with archived OFF; 137 with archived ON. Baseline output unchanged, all unique. Regression coverage included 150 fixture articles.
- **Joomla 6.2.0 security regression tests:** public menu items to registered-only or unpublished article targets were absent from XML. Two public menu entries for one article, one set to `noindex`, suppressed all article routes (136 URLs with archived ON during the test).

## Installation

Upload **`plg_system_menuleafsitemap-1.0.1.zip`** via Joomla's **System → Install → Extensions** and enable the plugin. Existing MenuLeaf v1.0.0 installations can be upgraded directly.

## Important

This version does **not** contain a Joomla update-server URL; future updates require installing a newer ZIP manually. See README.md for setup details, SEO limits and testing notes. GPL-2.0-or-later.
