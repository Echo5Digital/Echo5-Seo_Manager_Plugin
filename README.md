# Echo5 SEO Data Exporter - WordPress Plugin

A powerful WordPress plugin that exports complete SEO data via REST API for the Echo5 SEO Management Platform.

## 🎯 Purpose

This plugin eliminates web scraping issues by providing direct access to WordPress content and SEO data through secure REST API endpoints.

## ✅ Advantages Over Web Scraping

- **No blocking** - Direct database access via WordPress
- **No timeouts** - Instant data retrieval  
- **No incomplete scrapes** - Full access to all content
- **No rate limiting** - Unlimited requests from your platform
- **Structured data** - Clean JSON responses
- **Real-time updates** - Always current data
- **SEO plugin integration** - Works with Yoast, RankMath, All in One SEO

## 📦 Features

### Data Exported:
- ✅ All pages and posts with full HTML content
- ✅ SEO metadata (title, description, keywords)
- ✅ Open Graph and Twitter Card tags
- ✅ All images with alt text and dimensions
- ✅ Internal and external links
- ✅ Headings structure (H1-H6)
- ✅ Word count and reading time
- ✅ Featured images
- ✅ Categories and tags
- ✅ Author information
- ✅ Publish and modified dates
- ✅ Schema.org structured data
- ✅ Site navigation structure

### Security Features:
- 🔐 API key authentication
- 🛡️ Rate limiting (configurable)
- 🌐 IP whitelisting (optional)
- 📝 Failed attempt logging
- 🔒 WordPress role-based access control

### Performance:
- ⚡ Built-in caching (5-minute default)
- 📄 Pagination support
- 🎯 Selective field retrieval
- 🗜️ Gzip compression ready

## 🚀 Installation

1. **Upload Plugin:**
   - Upload the `echo5-seo-exporter` folder to `/wp-content/plugins/`
   - Or zip the folder and upload via WordPress admin

2. **Activate:**
   - Go to **Plugins** in WordPress admin
   - Click **Activate** on Echo5 SEO Data Exporter

3. **Configure:**
   - Go to **Settings > Echo5 SEO Exporter**
   - Copy the API key
   - Configure security settings as needed

## 📡 API Endpoints

Base URL: `https://yoursite.com/wp-json/echo5-seo/v1`

### Main Endpoints:

#### Get All Content (Pages + Posts)
```bash
GET /content/all?per_page=50&page=1&include_content=true
```

#### Get All Pages
```bash
GET /pages?per_page=20&page=1&fields=all
```

#### Get Single Page
```bash
GET /pages/{id}
```

#### Get All Posts
```bash
GET /posts?per_page=20&page=1
```

#### Get Site Structure
```bash
GET /structure
```

#### Get Internal Links Map
```bash
GET /links/internal
```

#### Get SEO Plugins Info
```bash
GET /seo-plugins
```

#### Health Check
```bash
GET /health
```

## 🔑 Authentication

Include the API key in your requests using one of these methods:

### Method 1: Authorization Header (Recommended)
```bash
curl -H "Authorization: Bearer echo5_your_api_key_here" \
  https://yoursite.com/wp-json/echo5-seo/v1/content/all
```

### Method 2: X-API-Key Header
```bash
curl -H "X-API-Key: echo5_your_api_key_here" \
  https://yoursite.com/wp-json/echo5-seo/v1/content/all
```

### Method 3: Query Parameter (Testing Only)
```bash
curl "https://yoursite.com/wp-json/echo5-seo/v1/content/all?api_key=echo5_your_api_key_here"
```

## 📊 Response Format

All successful responses follow this format:

```json
{
  "success": true,
  "data": [...],
  "pagination": {
    "total": 45,
    "pages": 3,
    "current_page": 1,
    "per_page": 20
  },
  "site_info": {
    "name": "My Website",
    "url": "https://mysite.com",
    "description": "Site description",
    "language": "en-US"
  },
  "timestamp": "2025-11-25 10:30:00"
}
```

## 🔧 Configuration Options

### Caching
- Enable/disable response caching (5-minute TTL)
- Improves performance for repeated requests

### Rate Limiting
- Set maximum requests per time window
- Default: 60 requests per 60 seconds
- Prevents API abuse

### IP Whitelisting
- Restrict API access to specific IPs
- Supports CIDR notation (e.g., 192.168.1.0/24)
- Optional but recommended for production

## 🔗 SEO Plugin Compatibility

Automatically detects and exports data from:
- **Yoast SEO** - Complete metadata, focus keywords, social tags
- **Rank Math** - All SEO settings and configurations
- **All in One SEO** - Title, description, canonical URLs

## 🛠️ Backend Integration

### Example Node.js Integration:

```javascript
const axios = require('axios');

class WordPressDataFetcher {
  constructor(siteUrl, apiKey) {
    this.baseUrl = `${siteUrl}/wp-json/echo5-seo/v1`;
    this.apiKey = apiKey;
  }
  
  async getAllContent(page = 1, perPage = 50) {
    const response = await axios.get(`${this.baseUrl}/content/all`, {
      headers: {
        'X-API-Key': this.apiKey
      },
      params: {
        page,
        per_page: perPage,
        include_content: true
      }
    });
    
    return response.data;
  }
  
  async getPage(pageId) {
    const response = await axios.get(`${this.baseUrl}/pages/${pageId}`, {
      headers: {
        'X-API-Key': this.apiKey
      }
    });
    
    return response.data;
  }
}

// Usage
const fetcher = new WordPressDataFetcher(
  'https://client-website.com',
  'echo5_your_api_key_here'
);

const content = await fetcher.getAllContent();
console.log(`Fetched ${content.data.length} pages/posts`);
```

## 🎯 Success Rate

**100% Success Rate** when:
- Plugin is installed and activated
- API key is valid
- Site is accessible
- WordPress is functioning normally

**No scraping issues:**
- ✅ No timeouts
- ✅ No bot detection
- ✅ No incomplete data
- ✅ No rate limiting from hosting
- ✅ No JavaScript rendering needed
- ✅ No cloudflare/security bypass needed

## 📝 Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- WordPress REST API enabled (default)

## 🔐 Security Best Practices

1. **Always use HTTPS** in production
2. **Enable IP whitelisting** for maximum security
3. **Regenerate API key** if compromised
4. **Enable rate limiting** to prevent abuse
5. **Monitor failed attempts** in WordPress error logs

## 📞 Support

For issues or questions about this plugin, contact Echo5 Digital support.

## 📄 License

GPL v2 or later

## 🚀 Roadmap

- [ ] WebSocket support for real-time updates
- [ ] Batch update endpoints
- [ ] Custom field mapping
- [ ] Webhook notifications
- [ ] Advanced analytics export
- [ ] Multi-site support
