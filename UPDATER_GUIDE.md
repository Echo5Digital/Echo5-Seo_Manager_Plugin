# GitHub Auto-Updater Setup Guide

## Overview
The Echo5 Seo Manager Plugin now includes automatic update functionality via GitHub. When you publish a new release on GitHub, all websites with the plugin installed will see update notifications in their WordPress admin.

## How It Works

1. **GitHub Integration**: The plugin checks your GitHub repository for new releases
2. **WordPress Notification**: Sites with the plugin see update notifications in the Plugins admin page
3. **One-Click Update**: Admins can update directly from WordPress like any other plugin
4. **Automatic Installation**: WordPress downloads and installs the latest version from GitHub

## Publishing Updates - Step by Step

### Step 1: Update Version Number

Update the version in `echo5-seo-exporter.php`:
```php
/**
 * Plugin Name: Echo5 Seo Manager Plugin
 * Version: 1.0.1  // <-- Update this
 */
define('ECHO5_SEO_VERSION', '1.0.1');  // <-- And this
```

### Step 2: Update readme.txt

Update the changelog in `readme.txt`:
```
== Changelog ==

= 1.0.1 =
* Fixed: Bug with API key validation
* Added: New endpoint for site health
* Improved: Performance optimizations

= 1.0.0 =
* Initial release
```

### Step 3: Commit and Push Changes

```bash
git add .
git commit -m "Version 1.0.1 - Bug fixes and improvements"
git push origin main
```

### Step 4: Create a GitHub Release

#### Option A: Via GitHub Web Interface

1. Go to: https://github.com/Echo5Digital/Echo5-Seo_Manager_Plugin/releases
2. Click **"Draft a new release"**
3. Click **"Choose a tag"** and create a new tag:
   - Tag: `v1.0.1` (must match version number with 'v' prefix)
   - Target: `main` branch
4. **Release title**: `Version 1.0.1`
5. **Description**: Add your changelog:
   ```markdown
   ## What's New in 1.0.1
   
   ### Fixed
   - Bug with API key validation
   - Rate limiting edge case
   
   ### Added
   - New endpoint for site health monitoring
   - Better error messages
   
   ### Improved
   - API response time (30% faster)
   - Memory usage optimizations
   ```
6. Click **"Publish release"**

#### Option B: Via Command Line (GitHub CLI)

```bash
gh release create v1.0.1 \
  --title "Version 1.0.1" \
  --notes "Bug fixes and performance improvements. See CHANGELOG.md for details."
```

### Step 5: Wait for Sites to Check for Updates

- WordPress checks for updates every 12 hours automatically
- Admins can manually check: **Dashboard > Updates > Check Again**
- Your sites will now show the update notification!

## Version Naming Convention

**Always use semantic versioning:**
- `v1.0.0` - Major release (breaking changes)
- `v1.1.0` - Minor release (new features, backward compatible)
- `v1.0.1` - Patch release (bug fixes)

**Important:** The tag MUST start with `v` (e.g., `v1.0.1`)

## Testing Updates Before Release

### Test in Development

1. Create a test release with tag `v1.0.1-beta`
2. Install plugin on test site
3. The updater will detect the beta version
4. Test the update process
5. Delete the beta release when done

### Force Update Check

Add this to your test site to force immediate update check:
```php
// In wp-admin or via plugin
delete_transient('echo5_seo_updater');
delete_site_transient('update_plugins');
```

Or visit: **Plugins > Installed Plugins** and refresh the page

## Update Notifications

When an update is available, site admins will see:

```
📦 Echo5 Seo Manager Plugin
There is a new version of Echo5 Seo Manager Plugin available.
Version 1.0.1 | View details | Update now

⚠️ Important: Please backup your site before updating.
View release notes on GitHub
```

## Troubleshooting

### Updates Not Showing?

1. **Check GitHub Release**: Ensure tag starts with `v` (e.g., `v1.0.1`)
2. **Check Version Format**: Tag version must be higher than current version
3. **Clear Cache**: 
   ```bash
   # Via WP-CLI
   wp transient delete echo5_seo_updater
   wp transient delete --all
   ```
4. **Check GitHub API**: Visit `https://api.github.com/repos/Echo5Digital/Echo5-Seo_Manager_Plugin/releases/latest`

### GitHub API Rate Limits?

- Anonymous requests: 60 per hour per IP
- For high-traffic sites, consider adding a GitHub token (optional enhancement)
- The plugin caches update info for 12 hours to minimize API calls

### Update Failed?

- Check WordPress error log
- Ensure proper file permissions (755 for directories, 644 for files)
- Verify GitHub release has the zipball
- Check that the GitHub repository is public

## Advanced: GitHub Personal Access Token (Optional)

For private repositories or to avoid rate limits:

1. Create a GitHub Personal Access Token
2. Add to the updater class:

```php
// In includes/class-updater.php, update get_remote_version():
$response = wp_remote_get($api_url, array(
    'timeout' => 10,
    'headers' => array(
        'Accept' => 'application/vnd.github.v3+json',
        'Authorization' => 'Bearer YOUR_GITHUB_TOKEN', // Add this
    ),
));
```

## Best Practices

1. **Always test updates** on a staging site first
2. **Write clear changelog** so users know what changed
3. **Follow semantic versioning** (major.minor.patch)
4. **Backup before releasing** - commit, push, then release
5. **Monitor first few updates** to ensure smooth rollout
6. **Keep releases stable** - only release tested code

## Release Checklist

- [ ] Version number updated in plugin header
- [ ] ECHO5_SEO_VERSION constant updated
- [ ] readme.txt changelog updated
- [ ] All changes committed and pushed
- [ ] Tested on local/staging environment
- [ ] GitHub release created with proper tag (v1.x.x)
- [ ] Release notes written clearly
- [ ] Verified update shows on test site

## Example Release Workflow

```bash
# 1. Update version in files (manually edit)
# 2. Commit changes
git add .
git commit -m "Bump version to 1.0.2"
git push origin main

# 3. Create and push tag
git tag v1.0.2
git push origin v1.0.2

# 4. Create GitHub release
gh release create v1.0.2 \
  --title "Version 1.0.2 - Security Updates" \
  --notes "Important security fixes and stability improvements"

# 5. Verify
curl https://api.github.com/repos/Echo5Digital/Echo5-Seo_Manager_Plugin/releases/latest
```

## Support

If you encounter issues with the auto-updater:
1. Check the GitHub Issues page
2. Review WordPress error logs
3. Verify GitHub API is accessible from your server
4. Contact Echo5 Digital support

---

**Note**: This auto-updater checks GitHub releases every 12 hours. Sites will automatically detect and offer updates without any manual intervention needed on the client sites!
