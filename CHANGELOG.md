# Changelog

All notable changes to the Echo5 Seo Manager Plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
