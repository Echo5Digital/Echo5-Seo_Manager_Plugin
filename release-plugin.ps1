# Echo5 SEO Manager Plugin - Release Script
# This script syncs files, commits, and pushes a new release

param(
    [Parameter(Mandatory=$true)]
    [string]$Version,
    
    [Parameter(Mandatory=$true)]
    [string]$Message
)

$PluginDir = $PSScriptRoot
$MainRepoDir = Split-Path $PluginDir -Parent
$LocalPluginDir = Join-Path $MainRepoDir "wordpress-plugin\echo5-seo-exporter"

Write-Host "Releasing Echo5 SEO Manager Plugin v$Version" -ForegroundColor Cyan
Write-Host "   Message: $Message" -ForegroundColor Gray

# Step 1: Sync files to local wordpress-plugin folder
Write-Host "`nSyncing files to local wordpress-plugin folder..." -ForegroundColor Yellow

# Create directories if they don't exist
New-Item -ItemType Directory -Path "$LocalPluginDir\includes" -Force | Out-Null
New-Item -ItemType Directory -Path "$LocalPluginDir\admin" -Force | Out-Null

# Core files
Copy-Item -Path "$PluginDir\echo5-seo-exporter.php" -Destination "$LocalPluginDir\echo5-seo-exporter.php" -Force
Copy-Item -Path "$PluginDir\README.md" -Destination "$LocalPluginDir\README.md" -Force
Copy-Item -Path "$PluginDir\readme.txt" -Destination "$LocalPluginDir\readme.txt" -Force
Copy-Item -Path "$PluginDir\CHANGELOG.md" -Destination "$LocalPluginDir\CHANGELOG.md" -Force

# Includes folder
Copy-Item -Path "$PluginDir\includes\class-api-handler.php" -Destination "$LocalPluginDir\includes\class-api-handler.php" -Force
Copy-Item -Path "$PluginDir\includes\class-data-exporter.php" -Destination "$LocalPluginDir\includes\class-data-exporter.php" -Force
Copy-Item -Path "$PluginDir\includes\class-security.php" -Destination "$LocalPluginDir\includes\class-security.php" -Force
Copy-Item -Path "$PluginDir\includes\class-updater.php" -Destination "$LocalPluginDir\includes\class-updater.php" -Force
Copy-Item -Path "$PluginDir\includes\class-publisher.php" -Destination "$LocalPluginDir\includes\class-publisher.php" -Force
Copy-Item -Path "$PluginDir\includes\class-media-handler.php" -Destination "$LocalPluginDir\includes\class-media-handler.php" -Force
Copy-Item -Path "$PluginDir\includes\class-seo-meta-handler.php" -Destination "$LocalPluginDir\includes\class-seo-meta-handler.php" -Force
Copy-Item -Path "$PluginDir\includes\class-publish-logger.php" -Destination "$LocalPluginDir\includes\class-publish-logger.php" -Force

# Admin folder
Copy-Item -Path "$PluginDir\admin\class-settings.php" -Destination "$LocalPluginDir\admin\class-settings.php" -Force

Write-Host "   Files synced" -ForegroundColor Green

# Step 2: Commit and push plugin repo
Write-Host "`nPushing to plugin repository..." -ForegroundColor Yellow
Set-Location $PluginDir
git add -A
git commit -m "v${Version}: $Message"
git push origin main
Write-Host "   Pushed to main" -ForegroundColor Green

# Step 3: Create and push tag
Write-Host "`nCreating release tag v$Version..." -ForegroundColor Yellow
git tag -a "v$Version" -m "v${Version}: $Message"
git push origin "v$Version"
Write-Host "   Tag v$Version pushed" -ForegroundColor Green

# Step 4: Commit local copy changes to main repo
Write-Host "`nCommitting local plugin copy to main repo..." -ForegroundColor Yellow
Set-Location $MainRepoDir
git add "wordpress-plugin/echo5-seo-exporter"
git commit -m "Sync WordPress plugin to v$Version" 2>$null
if ($LASTEXITCODE -eq 0) {
    git push origin main
    Write-Host "   Main repo updated" -ForegroundColor Green
} else {
    Write-Host "   No changes to commit in main repo" -ForegroundColor Yellow
}

Write-Host "`nRelease v$Version complete!" -ForegroundColor Green
$releaseUrl = "https://github.com/Echo5Digital/Echo5-Seo_Manager_Plugin/releases/tag/v$Version"
Write-Host "   Plugin repo: $releaseUrl" -ForegroundColor Cyan
