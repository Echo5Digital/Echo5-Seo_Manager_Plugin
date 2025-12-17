# Release Scripts Documentation

Automated scripts to increment version numbers and create production-ready zip packages.

## Available Scripts

- **`release.ps1`** - PowerShell script (Windows)
- **`release.sh`** - Bash script (Linux/Mac)

Both scripts do the same thing with the same syntax.

## Usage

### Increment Patch Version (default)
```bash
# Windows PowerShell
.\release.ps1

# Linux/Mac
./release.sh
```
Result: `1.0.0` → `1.0.1`

### Increment Minor Version
```bash
# Windows PowerShell
.\release.ps1 -Type minor

# Linux/Mac
./release.sh minor
```
Result: `1.0.5` → `1.1.0`

### Increment Major Version
```bash
# Windows PowerShell
.\release.ps1 -Type major

# Linux/Mac
./release.sh major
```
Result: `1.5.3` → `2.0.0`

### Set Specific Version
```bash
# Windows PowerShell
.\release.ps1 -Version "2.0.0"

# Linux/Mac
./release.sh 2.0.0
```
Result: Any version → `2.0.0`

## What the Scripts Do

1. **Read current version** from `echo5-seo-exporter.php`
2. **Calculate new version** based on increment type
3. **Update version** in:
   - Plugin header (`* Version:`)
   - PHP constant (`ECHO5_SEO_VERSION`)
   - `readme.txt` stable tag
4. **Create ZIP package** named `echo5-seo-manager-vX.X.X.zip`
5. **Exclude development files**:
   - All `.md` files (README, CHANGELOG, etc.)
   - Git files (`.git`, `.gitignore`, `.gitattributes`)
   - Scripts (`.ps1`, `.sh`, `.bat`)
   - IDE files (`.vscode`)
   - Build artifacts (`node_modules`, `*.zip`)

## Output

The script creates a production-ready zip file:
```
echo5-seo-manager-v1.0.1.zip
└── echo5-seo-manager/
    ├── admin/
    │   └── class-settings.php
    ├── includes/
    │   ├── class-api-handler.php
    │   ├── class-data-exporter.php
    │   ├── class-security.php
    │   └── class-updater.php
    ├── echo5-seo-exporter.php
    └── readme.txt
```

## Example Workflow

```bash
# 1. Run release script
.\release.ps1

# Output:
# Current Version: 1.0.0
# Incrementing patch version to: 1.0.1
# Proceed with version update? (Y/N): y
# ✓ Updated version in echo5-seo-exporter.php
# ✓ Updated version in readme.txt
# Copying files...
# ✓ Package created: echo5-seo-manager-v1.0.1.zip (45.2 KB)

# 2. Review changes
git diff

# 3. Commit changes
git add .
git commit -m "Version 1.0.1"
git push origin main

# 4. Create GitHub release
gh release create v1.0.1 \
  --title "Version 1.0.1" \
  --notes "Bug fixes and improvements"

# 5. Upload zip (optional - updater uses GitHub's automatic zipball)
gh release upload v1.0.1 echo5-seo-manager-v1.0.1.zip
```

## Making Scripts Executable (Linux/Mac)

```bash
chmod +x release.sh
```

## Troubleshooting

### PowerShell Execution Policy Error
```powershell
Set-ExecutionPolicy -Scope CurrentUser -ExecutionPolicy RemoteSigned
```

### Bash Script Not Found
Make sure you're in the plugin directory:
```bash
cd /path/to/Echo5-Seo_Manager_Plugin
./release.sh
```

### Version Not Found Error
Ensure `echo5-seo-exporter.php` exists and contains:
```php
* Version: 1.0.0
define('ECHO5_SEO_VERSION', '1.0.0');
```

## Files Modified by Script

- `echo5-seo-exporter.php` - Plugin version header and constant
- `readme.txt` - Stable tag version

## Files Excluded from ZIP

The following are automatically excluded:
- `*.md` (README.md, CHANGELOG.md, etc.)
- `.git*` (Git files and folders)
- `.vscode` (IDE config)
- `*.ps1`, `*.sh`, `*.bat` (Build scripts)
- `node_modules` (Dependencies)
- `.DS_Store`, `Thumbs.db` (OS files)
- `*.zip` (Old packages)

## Integration with Auto-Updater

The zip package is production-ready but **not required** for the auto-updater. The GitHub auto-updater uses GitHub's automatic zipball from releases. 

However, you can upload the generated zip if you want to provide a cleaner package without development files.

## Next Steps After Running Script

1. ✅ Review `git diff` to verify changes
2. ✅ Update `CHANGELOG.md` with version details
3. ✅ Commit and push changes
4. ✅ Create GitHub release (triggers auto-updater)
5. ✅ Test update on a staging site
6. ✅ Verify production sites receive update notification

---

**Need help?** See [QUICK_UPDATE.md](QUICK_UPDATE.md) for release workflow or [UPDATER_GUIDE.md](UPDATER_GUIDE.md) for comprehensive documentation.
