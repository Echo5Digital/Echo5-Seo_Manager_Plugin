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

Write-Host "🚀 Releasing Echo5 SEO Manager Plugin v$Version" -ForegroundColor Cyan
Write-Host "   Message: $Message" -ForegroundColor Gray

# Step 1: Sync files to local wordpress-plugin folder
Write-Host "`n📦 Syncing files to local wordpress-plugin folder..." -ForegroundColor Yellow
Copy-Item -Path "$PluginDir\includes\class-data-exporter.php" -Destination "$LocalPluginDir\includes\class-data-exporter.php" -Force
Copy-Item -Path "$PluginDir\echo5-seo-exporter.php" -Destination "$LocalPluginDir\echo5-seo-exporter.php" -Force
Copy-Item -Path "$PluginDir\admin\class-settings.php" -Destination "$LocalPluginDir\admin\class-settings.php" -Force
Write-Host "   ✅ Files synced" -ForegroundColor Green

# Step 2: Commit and push plugin repo
Write-Host "`n📤 Pushing to plugin repository..." -ForegroundColor Yellow
Set-Location $PluginDir
git add -A
git commit -m "v$Version`: $Message"
git push origin main
Write-Host "   ✅ Pushed to main" -ForegroundColor Green

# Step 3: Create and push tag
Write-Host "`n🏷️ Creating release tag v$Version..." -ForegroundColor Yellow
git tag -a "v$Version" -m "v$Version`: $Message"
git push origin "v$Version"
Write-Host "   ✅ Tag v$Version pushed" -ForegroundColor Green

# Step 4: Commit local copy changes to main repo
Write-Host "`n📤 Committing local plugin copy to main repo..." -ForegroundColor Yellow
Set-Location $MainRepoDir
git add "wordpress-plugin/echo5-seo-exporter"
git commit -m "Sync WordPress plugin to v$Version" 2>$null
if ($LASTEXITCODE -eq 0) {
    git push origin main
    Write-Host "   ✅ Main repo updated" -ForegroundColor Green
} else {
    Write-Host "   ⚠️ No changes to commit in main repo" -ForegroundColor Yellow
}

Write-Host "`n🎉 Release v$Version complete!" -ForegroundColor Green
Write-Host "   Plugin repo: https://github.com/Echo5Digital/Echo5-Seo_Manager_Plugin/releases/tag/v$Version" -ForegroundColor Cyan
