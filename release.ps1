#!/usr/bin/env pwsh
<#
.SYNOPSIS
    Automates version increment and plugin packaging for WordPress release
.DESCRIPTION
    This script reads the current version, increments it, updates plugin files,
    and creates a production-ready zip file excluding development files.
.PARAMETER Type
    Version increment type: major, minor, or patch (default: patch)
.PARAMETER Version
    Specific version to set (overrides Type parameter)
.EXAMPLE
    .\release.ps1
    Increments patch version (1.0.0 -> 1.0.1)
.EXAMPLE
    .\release.ps1 -Type minor
    Increments minor version (1.0.0 -> 1.1.0)
.EXAMPLE
    .\release.ps1 -Version "2.0.0"
    Sets specific version to 2.0.0
#>

param(
    [Parameter()]
    [ValidateSet('major', 'minor', 'patch')]
    [string]$Type = 'patch',
    
    [Parameter()]
    [string]$Version = $null
)

# Configuration
$PluginFile = "echo5-seo-exporter.php"
$PluginSlug = "echo5-seo-manager"
$ExcludeFiles = @(
    "*.md",
    ".git*",
    ".vscode",
    "*.ps1",
    "*.sh",
    "*.bat",
    "node_modules",
    ".DS_Store",
    "Thumbs.db",
    "*.zip"
)

Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host "   Echo5 Seo Manager - Release Builder" -ForegroundColor Cyan
Write-Host "========================================`n" -ForegroundColor Cyan

# Function to extract current version
function Get-CurrentVersion {
    $content = Get-Content $PluginFile -Raw
    
    if ($content -match '\* Version:\s*([0-9]+\.[0-9]+\.[0-9]+)') {
        return $matches[1]
    }
    
    Write-Host "ERROR: Could not find version in $PluginFile" -ForegroundColor Red
    exit 1
}

# Function to increment version
function Get-NextVersion {
    param(
        [string]$CurrentVersion,
        [string]$IncrementType
    )
    
    $parts = $CurrentVersion.Split('.')
    $major = [int]$parts[0]
    $minor = [int]$parts[1]
    $patch = [int]$parts[2]
    
    switch ($IncrementType) {
        'major' {
            $major++
            $minor = 0
            $patch = 0
        }
        'minor' {
            $minor++
            $patch = 0
        }
        'patch' {
            $patch++
        }
    }
    
    return "$major.$minor.$patch"
}

# Function to update version in file
function Update-PluginVersion {
    param(
        [string]$NewVersion
    )
    
    $content = Get-Content $PluginFile -Raw
    
    # Update Plugin Name header version
    $content = $content -replace '(\* Version:\s*)([0-9]+\.[0-9]+\.[0-9]+)', "`${1}$NewVersion"
    
    # Update ECHO5_SEO_VERSION constant
    $content = $content -replace "(define\('ECHO5_SEO_VERSION',\s*')([0-9]+\.[0-9]+\.[0-9]+)('\))", "`${1}$NewVersion`${3}"
    
    Set-Content -Path $PluginFile -Value $content -NoNewline
    
    Write-Host "✓ Updated version in $PluginFile" -ForegroundColor Green
}

# Function to update readme.txt stable tag
function Update-ReadmeVersion {
    param(
        [string]$NewVersion
    )
    
    if (Test-Path "readme.txt") {
        $content = Get-Content "readme.txt" -Raw
        $content = $content -replace '(Stable tag:\s*)([0-9]+\.[0-9]+\.[0-9]+)', "`${1}$NewVersion"
        Set-Content -Path "readme.txt" -Value $content -NoNewline
        Write-Host "✓ Updated version in readme.txt" -ForegroundColor Green
    }
}

# Function to create zip package
function New-PluginPackage {
    param(
        [string]$Version
    )
    
    $ZipName = "$PluginSlug-v$Version.zip"
    $TempDir = "temp-$PluginSlug"
    
    # Remove old zip if exists
    if (Test-Path $ZipName) {
        Remove-Item $ZipName -Force
        Write-Host "✓ Removed old package: $ZipName" -ForegroundColor Yellow
    }
    
    # Remove old temp directory if exists
    if (Test-Path $TempDir) {
        Remove-Item $TempDir -Recurse -Force
    }
    
    # Create temp directory
    New-Item -ItemType Directory -Path $TempDir -Force | Out-Null
    New-Item -ItemType Directory -Path "$TempDir\$PluginSlug" -Force | Out-Null
    
    Write-Host "`nCopying files..." -ForegroundColor Cyan
    
    # Get all files and directories to include
    $items = Get-ChildItem -Path . -Recurse | Where-Object {
        $item = $_
        $shouldExclude = $false
        
        # Check against exclude patterns
        foreach ($pattern in $ExcludeFiles) {
            if ($item.Name -like $pattern -or $item.FullName -like "*\$pattern*") {
                $shouldExclude = $true
                break
            }
        }
        
        # Exclude temp directory and zip files
        if ($item.FullName -like "*\$TempDir*" -or $item.Extension -eq '.zip') {
            $shouldExclude = $true
        }
        
        -not $shouldExclude
    }
    
    # Copy files maintaining structure
    foreach ($item in $items) {
        $relativePath = $item.FullName.Substring($PWD.Path.Length + 1)
        $targetPath = Join-Path "$TempDir\$PluginSlug" $relativePath
        
        if ($item.PSIsContainer) {
            New-Item -ItemType Directory -Path $targetPath -Force | Out-Null
        } else {
            $targetDir = Split-Path $targetPath -Parent
            if (-not (Test-Path $targetDir)) {
                New-Item -ItemType Directory -Path $targetDir -Force | Out-Null
            }
            Copy-Item $item.FullName -Destination $targetPath -Force
            Write-Host "  • $relativePath" -ForegroundColor Gray
        }
    }
    
    Write-Host "`nCreating zip package..." -ForegroundColor Cyan
    
    # Create zip
    Compress-Archive -Path "$TempDir\$PluginSlug" -DestinationPath $ZipName -Force
    
    # Clean up temp directory
    Remove-Item $TempDir -Recurse -Force
    
    # Get file size
    $fileSize = (Get-Item $ZipName).Length / 1KB
    
    Write-Host "`n✓ Package created: $ZipName ($([math]::Round($fileSize, 2)) KB)" -ForegroundColor Green
}

# Function to display summary
function Show-Summary {
    param(
        [string]$OldVersion,
        [string]$NewVersion,
        [string]$ZipFile
    )
    
    Write-Host "`n========================================" -ForegroundColor Cyan
    Write-Host "             RELEASE SUMMARY" -ForegroundColor Cyan
    Write-Host "========================================" -ForegroundColor Cyan
    Write-Host "Old Version:  $OldVersion" -ForegroundColor Yellow
    Write-Host "New Version:  $NewVersion" -ForegroundColor Green
    Write-Host "Package:      $ZipFile" -ForegroundColor Green
    Write-Host "========================================`n" -ForegroundColor Cyan
    
    Write-Host "Next Steps:" -ForegroundColor Cyan
    Write-Host "1. Review changes with: git diff" -ForegroundColor White
    Write-Host "2. Update CHANGELOG.md with version changes" -ForegroundColor White
    Write-Host "3. Commit: git add . && git commit -m 'Version $NewVersion'" -ForegroundColor White
    Write-Host "4. Push: git push origin main" -ForegroundColor White
    Write-Host "5. Create release: gh release create v$NewVersion --title 'Version $NewVersion' --notes 'Release notes here'" -ForegroundColor White
    Write-Host ""
}

# Main execution
try {
    # Check if plugin file exists
    if (-not (Test-Path $PluginFile)) {
        Write-Host "ERROR: Plugin file not found: $PluginFile" -ForegroundColor Red
        exit 1
    }
    
    # Get current version
    $currentVersion = Get-CurrentVersion
    Write-Host "Current Version: $currentVersion" -ForegroundColor Yellow
    
    # Determine new version
    if ($Version) {
        # Validate custom version format
        if ($Version -notmatch '^[0-9]+\.[0-9]+\.[0-9]+$') {
            Write-Host "ERROR: Invalid version format. Use semantic versioning (e.g., 1.0.0)" -ForegroundColor Red
            exit 1
        }
        $newVersion = $Version
        Write-Host "Setting version to: $newVersion (custom)" -ForegroundColor Cyan
    } else {
        $newVersion = Get-NextVersion -CurrentVersion $currentVersion -IncrementType $Type
        Write-Host "Incrementing $Type version to: $newVersion" -ForegroundColor Cyan
    }
    
    # Confirm with user
    Write-Host "`nProceed with version update? (Y/N): " -ForegroundColor Yellow -NoNewline
    $confirmation = Read-Host
    
    if ($confirmation -ne 'Y' -and $confirmation -ne 'y') {
        Write-Host "`nRelease cancelled by user." -ForegroundColor Yellow
        exit 0
    }
    
    Write-Host ""
    
    # Update version in files
    Update-PluginVersion -NewVersion $newVersion
    Update-ReadmeVersion -NewVersion $newVersion
    
    # Create package
    New-PluginPackage -Version $newVersion
    
    # Show summary
    Show-Summary -OldVersion $currentVersion -NewVersion $newVersion -ZipFile "$PluginSlug-v$newVersion.zip"
    
} catch {
    Write-Host "`nERROR: $($_.Exception.Message)" -ForegroundColor Red
    exit 1
}
