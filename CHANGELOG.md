# Changelog

All notable changes to the Echo5 Seo Manager Plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.2.0] - 2026-01-23

### Added
- **H1 Creation**: If page has no H1 heading, plugin now creates one:
  1. First tries to find and update existing H1
  2. If no H1, converts first H2/H3 to H1 with new title
  3. If no headings at all, adds new H1 section at top of page
- Response now includes `elementor_h1_action` to report what was done (created/converted/updated)

## [2.1.9] - 2026-01-23

### Fixed
- **Critical**: Removed call to non-existent `get_active_seo_plugin()` method that caused 500 error

## [2.1.8] - 2026-01-23

### Fixed
- **H1 Update for Elementor**: Now updates Elementor heading widget with H1 tag (not just post_title)
- Response now includes `seo_plugin` detected and proper `updates_applied` list
- Improved update tracking for page_title and schema in API response

### Changed
- H1 category applies to both WordPress post_title AND first Elementor H1 heading widget

## [2.1.7] - 2026-01-23

### Fixed
- **Schema Injection**: Schema/structured data now properly injected into page content (not just meta)
- Schema saved to both `_echo5_structured_data` and `_echo5_schemas` meta keys for redundancy
- Schema HTML wrapped with Echo5 markers for clean updates
- **Rollback Enhancement**: Version rollback now restores SEO meta fields (title, description, keywords)
- SEO Meta Handler checks both schema meta keys for wp_head output

### Improved
- Blueprint publish now saves schema to meta keys during page creation
- Better schema parsing in update-seo endpoint

## [2.1.6] - 2026-01-22

### Added
- **SEO Update API**: New `POST /update-seo/{id}` endpoint to apply SEO fixes directly from the manager
- Support for updating meta title, meta description, focus keyword, canonical URL
- Support for updating Open Graph tags (title, description, image)
- Support for updating structured data/schema markup
- Support for updating page title (H1/post_title)
- Multi-plugin compatibility via SEO Meta Handler (Yoast, RankMath, AIOSEO, SEOPress, etc.)
- Tracking fields for SEO update history (_echo5_last_seo_update, _echo5_seo_update_source)

## [2.0.0] - 2025-12-30

### Added - Publisher System
- **Echo5 Publisher**: Push landing pages directly from Echo5 AI to WordPress
- HMAC signature authentication for secure API requests
- Version snapshots with rollback support (last 10 versions)
- Safe update mode preserves existing content sections
- Full update mode replaces entire page content
- Scheduled publishing with cron integration
- Automatic "Echo5-Seo" author assignment for published pages

### Added - Media Handler
- Image upload with URL-based deduplication
- Gallery creation and management
- Featured image support
- Alt text and caption preservation

### Added - SEO Meta Handler
- Multi-plugin support: Yoast SEO, Rank Math, All in One SEO, SEOPress, The SEO Framework
- Focus keyword and secondary keywords
- Meta title and description management
- Automatic SEO plugin detection

### Added - Publish Logger
- Complete audit trail for all publish actions
- Custom database table for logs
- Webhook notifications on publish events
- Log retention management

### Security
- HMAC-SHA256 signature verification
- Nonce-based replay attack prevention
- Timestamp validation (5-minute window)
- Temporary unfiltered HTML for script/style injection
- Automatic kses filter bypass for trusted content

### Fixed
- WordPress content sanitization stripping script tags
- Author not being set on published pages

## [1.1.3] - 2025-12-20

### Added
- GitHub-powered automatic update system
- Auto-updater checks for new releases every 12 hours
- Update notifications in WordPress admin dashboard
- One-click plugin updates from GitHub releases

### Documentation
- Added UPDATER_GUIDE.md with comprehensive update publishing instructions
- Added QUICK_UPDATE.md for fast reference
- Updated README.md with auto-update feature information

## [1.0.0] - 2025-12-17

### Added
- Initial release of Echo5 Seo Manager Plugin
- REST API endpoints for pages, posts, and content export
- API key authentication system
- Rate limiting and IP whitelisting
- Comprehensive SEO data export (meta titles, descriptions, keywords)
- SEO plugin integration (Yoast SEO, Rank Math, All in One SEO)
- Page builder content extraction (Elementor, Divi, WPBakery, Beaver Builder)
- Image extraction with alt text and dimensions
- Internal and external link analysis
- Site structure and navigation export
- Admin settings page for configuration
- Built-in caching (5-minute TTL)
- Pagination support for large datasets
- Failed authentication attempt logging

### Security
- API key authentication (Bearer token, X-API-Key header, query parameter)
- Configurable rate limiting (default: 60 requests per 60 seconds)
- Optional IP whitelisting with CIDR notation support
- WordPress role-based access control

### Performance
- Transient caching for API responses
- Efficient WP_Query usage
- Optimized database queries
- Support for Gzip compression

### Documentation
- Comprehensive README.md with API documentation
- WordPress plugin directory readme.txt
- Installation and configuration guide
- API endpoint examples
- Security best practices

---

## Version History

- **1.0.0** - Initial release with core functionality
- **Future** - GitHub auto-updater, webhook support, multi-site compatibility

[Unreleased]: https://github.com/Echo5Digital/Echo5-Seo_Manager_Plugin/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/Echo5Digital/Echo5-Seo_Manager_Plugin/releases/tag/v1.0.0
