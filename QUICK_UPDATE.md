# Quick Update Publishing Guide

## TL;DR - Publish an Update in 5 Steps

```bash
# 1. Update version numbers in echo5-seo-exporter.php
# Edit: Plugin Name header & ECHO5_SEO_VERSION constant

# 2. Update changelog in readme.txt

# 3. Commit and push
git add .
git commit -m "Version 1.0.X - Description"
git push origin main

# 4. Create GitHub release via web or CLI:
gh release create v1.0.X --title "Version 1.0.X" --notes "Your changelog here"

# 5. Done! All sites will see the update within 12 hours
```

## Files to Update

### 1. `echo5-seo-exporter.php`
```php
* Version: 1.0.X  // Line 6
define('ECHO5_SEO_VERSION', '1.0.X');  // Line 18
```

### 2. `readme.txt`
```
== Changelog ==

= 1.0.X =
* Your changes here
```

## Create Release on GitHub

### Web Interface
1. Go to: https://github.com/Echo5Digital/Echo5-Seo_Manager_Plugin/releases/new
2. Tag: `v1.0.X` (must start with 'v')
3. Title: `Version 1.0.X`
4. Description: Your changelog
5. Click "Publish release"

### Command Line
```bash
gh release create v1.0.X \
  --title "Version 1.0.X" \
  --notes "
## Changes
- Fixed: Something
- Added: New feature
- Improved: Performance
"
```

## Verify Release
```bash
# Check that release is published
curl https://api.github.com/repos/Echo5Digital/Echo5-Seo_Manager_Plugin/releases/latest

# Should show your new version
```

## Testing
Test sites can force check for updates:
- Go to: **Dashboard > Updates > Check Again**
- Or visit: **Plugins > Installed Plugins** and refresh

---

**Full documentation**: See [UPDATER_GUIDE.md](UPDATER_GUIDE.md)
