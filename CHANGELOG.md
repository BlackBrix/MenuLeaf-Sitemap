# Changelog — MenuLeaf Sitemap

## [1.0.1] — 2026-11-08 — Tested stable release

### Changed
- Validate direct single-article menu targets against the same public/guest access, category and ancestor access, publishing-state, publishing-window, robots `noindex`, and optional archived-article checks used for automatically discovered articles.
- Scan the public menu set for article-level exclusions before adding article or menu URLs. When one public direct menu item for an article specifies `noindex`, omit all direct menu routes and the automatically discovered route for that article.
- Keep HTML sitemap output, XML endpoint, configuration options and Joomla extension identity unchanged. No schema migration, external dependency or new service is required.

### Verified
- Joomla 5.4.10: valid XML with 377 URLs (archived OFF) and 2,174 URLs (archived ON), all unique. Compared with the v1.0.0 baseline, the only deleted URL belongs to the old 3rd-party-sitemap sitemap menu item, intentionally removed by the site operator. All remaining URLs and their sequence match.
- Joomla 6.2.0: valid XML with 77 URLs (archived OFF) and 137 URLs (archived ON), all unique and unchanged from the baseline, using 150 test fixtures covering published, archived, restricted, `noindex`, unpublished, and future-dated articles.
- Joomla 6.2.0 targeted menu checks: a publicly visible menu item targeting a registered-only article was omitted; a publicly visible menu item targeting an unpublished article was omitted; and two public menu items for the same article, with `noindex` on one, removed all routes to that article (archived ON count: 136, with only the expected original URL removed).
- The inverse assignment of `noindex` between those two menu items was not tested separately.

### Notes
- This release has no Joomla automatic-update URL. Subsequent ZIP releases can be installed as upgrades.
- Runtime tests and source review are not a formal security audit or guarantee that all third-party SEO configurations are covered.

## [1.0.0] — 2026-11-07 — Initial MenuLeaf release candidate

- Introduced the independent `menuleafsitemap` Joomla system-plugin identity, title, PHP namespace/class, language keys, HTML placeholder `{menuleafsitemap}`, and XML query key `menuleaf_sitemap`.
- Preserved the menu-based HTML sitemap, Joomla content article discovery for XML, XML deduplication, article and menu-level `noindex` filtering, and the optional inclusion of archived articles (default OFF).
- Replaced older plugin prototypes under a new extension identity. Migration from those prototypes requires switching the shortcode and XML endpoint; MenuLeaf 1.0.1 upgrades MenuLeaf 1.0.0 in place.
