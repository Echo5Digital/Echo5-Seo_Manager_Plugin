# Release Scripts Documentation

Automated scripts to increment version numbers and create production-ready zip packages.

## Available Scripts

- **`release.ps1`** - PowerShell script (Windows)
- **`release.sh`** - Bash script (Linux/Mac)

Both scripts do the same thing with the same syntax.

## Usage

### Basic Usage (Manual Release)

#### Increment Patch Version (default)
```bash
# Windows PowerShell
.\release.ps1

# Linux/Mac
./release.sh
```
Result: `1.0.0` → `1.0.1`

#### Increment Minor Version
```bash
# Windows PowerShell
.\release.ps1 -Type minor

# Linux/Mac
./release.sh minor
```
Result: `1.0.5` → `1.1.0`

#### Increment Major Version
```bash
# Windows PowerShell
.\release.ps1 -Type major

# Linux/Mac
./release.sh major
```
Result: `1.5.3` → `2.0.0`

#### Set Specific Version
```bash
# Windows PowerShell
.\release.ps1 -Version "2.0.0"

# Linux/Mac
./release.sh 2.0.0
```
Result: Any version → `2.0.0`

---

### 🚀 Auto-Release (FULLY AUTOMATED)

**One command does everything:**
- ✅ Updates version numbers
- ✅ Creates ZIP package
- ✅ Commits changes to git
- ✅ Pushes to GitHub
- ✅ Creates GitHub release
- ✅ All sites get notified automatically!

#### Auto-Release with Interactive Notes
```bash
# Windows PowerShell
.\release.ps1 -AutoRelease

# Linux/Mac
./release.sh --auto
```
Script will prompt you to enter release notes.

#### Auto-Release with Inline Notes
```bash
# Windows PowerShell
.\release.ps1 -AutoRelease -Notes "Bug fixes and performance improvements"

# Linux/Mac
./release.sh --auto --notes "Bug fixes and performance improvements"
```

#### Auto-Release Minor Version
```bash
# Windows PowerShell
.\release.ps1 -Type minor -AutoRelease -Notes "Added new features"

# Linux/Mac
./release.sh minor --auto --notes "Added new features"
```

#### Auto-Release Without ZIP
```bash
# Windows PowerShell
.\release.ps1 -AutoRelease -SkipZip

# Linux/Mac
./release.sh --auto --skip-zip
```
Uses GitHub's automatic zipball only.

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

## Example Workflows

### Manual Workflow (Step by Step)

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
```

### 🔥 Auto-Release Workflow (ONE COMMAND!)

```bash
# Single command does EVERYTHING!
.\release.ps1 -AutoRelease -Notes "Bug fixes and security updates"

# Output:
# =Requirements for Auto-Release

1. **GitHub CLI (gh)** must be installed:
   - Windows: `winget install GitHub.cli`
   - Mac: `brew install gh`
   - Linux: See https://cli.github.com/
2. **Authenticated with GitHub**: Run `gh auth login` if not already logged in
3. **Git repository** must be initialized and connected to GitHub

Check if ready:
```bash
# Check if gh is installed
gh --version

# Check if authenticated
gh auth status
```

## Troubleshooting

### PowerShell Execution Policy Error
```powershell
Set-ExecutionPolicy -Scope CurrentUser -ExecutionPolicy RemoteSigned
```

### GitHub CLI Not Found
```bash
# Install GitHub CLI
# Windows
winget install GitHub.cli

# Mac
brew install gh

# Linux
curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg | sudo dd of=/usr/share/keyrings/githubcli-archive-keyring.gpg
echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main" | sudo tee /etc/apt/sources.list.d/github-cli.list > /dev/null
sudo apt update
sudo apt install gh
```

### Not Authenticated with GitHub
```bash
gh auth login
# Follow the prompts to authenticate
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

### Git Push Failed
Ensure you have push permissions and are on the main branch:
```bash
git status
git branch
gh auth status
# Adding changes to git...
# Committing changes...
# ✓ Committed: Version 1.0.1
# Pushing to GitHub...
# ✓ Pushed to GitHub
# 
# ========================================
#          GITHUB RELEASE
# ========================================
# 
# Creating GitHub release v1.0.1...
# ✓ GitHub release created: v1.0.1
# ✓ Release URL: https://github.com/Echo5Digital/Echo5-Seo_Manager_Plugin/releases/tag/v1.0.1
# 
# ========================================
#              RELEASE SUMMARY
# ========================================
# Old Version:  1.0.0
# New Version:  1.0.1
# Package:      echo5-seo-manager-v1.0.1.zip
# 
# Auto-Release: COMPLETED ✓
#   • Committed to git
#   • Pushed to GitHub
#   • Created GitHub release
# 
# Release URL: https://github.com/Echo5Digital/Echo5-Seo_Manager_Plugin/releases/tag/v1.0.1
# 
# All sites will receive update notification within 12 hours! 🎉
# ========================================

# Done! All sites will be notified automatically!
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
