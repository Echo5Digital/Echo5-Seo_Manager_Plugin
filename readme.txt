=== Echo5 SEO Data Exporter ===
Contributors: echo5digital
Tags: seo, api, rest-api, export, seo-data
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export complete SEO data via REST API for Echo5 SEO Management Platform. No scraping needed!

== Description ==

Echo5 SEO Data Exporter provides secure REST API endpoints to export your WordPress site's content and SEO data directly to the Echo5 SEO Management Platform.

**Why use this plugin instead of web scraping?**

* **100% Success Rate** - Direct database access eliminates scraping failures
* **No Timeouts** - Instant data retrieval
* **No Blocking** - Never get blocked by security plugins or CDN
* **Complete Data** - Access all content including dynamic elements
* **Real-time** - Always get the latest data
* **Secure** - API key authentication and optional IP whitelisting

== Features ==

* **Comprehensive Data Export:**
  * All pages and posts with full HTML content
  * SEO metadata (title, description, keywords)
  * Open Graph and Twitter Card tags
  * Images with alt text and dimensions
  * Internal and external links
  * Headings structure (H1-H6)
  * Word count and reading time
  * Categories, tags, and author info
  * Schema.org structured data

* **SEO Plugin Integration:**
  * Yoast SEO
  * Rank Math
  * All in One SEO

* **Security:**
  * API key authentication
  * Rate limiting
  * IP whitelisting
  * Failed attempt logging

* **Performance:**
  * Built-in caching
  * Pagination support
  * Gzip compression ready

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/echo5-seo-exporter`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to Settings > Echo5 SEO Exporter to configure
4. Copy your API key and add it to your Echo5 platform

== Frequently Asked Questions ==

= Is this plugin free? =

Yes, this plugin is free and open source.

= Do I need an Echo5 account? =

This plugin is designed to work with the Echo5 SEO Management Platform, but the API can be used by any application that needs access to your WordPress content data.

= Will this affect my site performance? =

No. The plugin uses caching and efficient queries to minimize database load. API requests are handled asynchronously and don't affect regular site visitors.

= Is it secure? =

Yes. The plugin uses API key authentication, supports IP whitelisting, and includes rate limiting to prevent abuse.

= Which SEO plugins are supported? =

The plugin automatically detects and exports data from Yoast SEO, Rank Math, and All in One SEO.

== Changelog ==

= 1.0.0 =
* Initial release
* REST API endpoints for pages, posts, and content
* API key authentication
* Rate limiting and IP whitelisting
* Caching support
* SEO plugin integration (Yoast, RankMath, AIOSEO)
* Admin settings page

== Upgrade Notice ==

= 1.0.0 =
Initial release of Echo5 SEO Data Exporter.
