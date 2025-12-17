#!/bin/bash
#
# Echo5 Seo Manager - Release Builder
# Automates version increment and plugin packaging
#
# Usage:
#   ./release.sh              # Increment patch version
#   ./release.sh minor        # Increment minor version
#   ./release.sh major        # Increment major version
#   ./release.sh 2.0.0        # Set specific version

set -e

# Configuration
PLUGIN_FILE="echo5-seo-exporter.php"
PLUGIN_SLUG="echo5-seo-manager"
EXCLUDE_PATTERN="*.md .git* .vscode *.ps1 *.sh *.bat node_modules .DS_Store Thumbs.db *.zip"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

echo -e "${CYAN}"
echo "========================================"
echo "   Echo5 Seo Manager - Release Builder"
echo "========================================"
echo -e "${NC}"

# Function to get current version
get_current_version() {
    if [[ ! -f "$PLUGIN_FILE" ]]; then
        echo -e "${RED}ERROR: Plugin file not found: $PLUGIN_FILE${NC}"
        exit 1
    fi
    
    version=$(grep -E '^\s*\* Version:' "$PLUGIN_FILE" | sed -E 's/.*Version:\s*([0-9]+\.[0-9]+\.[0-9]+).*/\1/')
    
    if [[ -z "$version" ]]; then
        echo -e "${RED}ERROR: Could not find version in $PLUGIN_FILE${NC}"
        exit 1
    fi
    
    echo "$version"
}

# Function to increment version
increment_version() {
    local version=$1
    local type=$2
    
    IFS='.' read -r -a parts <<< "$version"
    local major="${parts[0]}"
    local minor="${parts[1]}"
    local patch="${parts[2]}"
    
    case "$type" in
        major)
            ((major++))
            minor=0
            patch=0
            ;;
        minor)
            ((minor++))
            patch=0
            ;;
        patch|*)
            ((patch++))
            ;;
    esac
    
    echo "$major.$minor.$patch"
}

# Function to update version in plugin file
update_plugin_version() {
    local new_version=$1
    
    # Update Version header
    sed -i.bak -E "s/(^\s*\* Version:\s*)([0-9]+\.[0-9]+\.[0-9]+)/\1$new_version/" "$PLUGIN_FILE"
    
    # Update ECHO5_SEO_VERSION constant
    sed -i.bak -E "s/(define\('ECHO5_SEO_VERSION',\s*')([0-9]+\.[0-9]+\.[0-9]+)('\))/\1$new_version\3/" "$PLUGIN_FILE"
    
    # Remove backup file
    rm -f "${PLUGIN_FILE}.bak"
    
    echo -e "${GREEN}✓ Updated version in $PLUGIN_FILE${NC}"
}

# Function to update readme.txt
update_readme_version() {
    local new_version=$1
    
    if [[ -f "readme.txt" ]]; then
        sed -i.bak -E "s/(^Stable tag:\s*)([0-9]+\.[0-9]+\.[0-9]+)/\1$new_version/" "readme.txt"
        rm -f "readme.txt.bak"
        echo -e "${GREEN}✓ Updated version in readme.txt${NC}"
    fi
}

# Function to create zip package
create_package() {
    local version=$1
    local zip_name="${PLUGIN_SLUG}-v${version}.zip"
    local temp_dir="temp-${PLUGIN_SLUG}"
    
    # Remove old package
    if [[ -f "$zip_name" ]]; then
        rm -f "$zip_name"
        echo -e "${YELLOW}✓ Removed old package: $zip_name${NC}"
    fi
    
    # Remove old temp directory
    if [[ -d "$temp_dir" ]]; then
        rm -rf "$temp_dir"
    fi
    
    # Create temp directory structure
    mkdir -p "$temp_dir/$PLUGIN_SLUG"
    
    echo -e "\n${CYAN}Copying files...${NC}"
    
    # Copy all files except excluded ones
    rsync -av \
        --exclude="$temp_dir" \
        --exclude="*.md" \
        --exclude=".git*" \
        --exclude=".vscode" \
        --exclude="*.ps1" \
        --exclude="*.sh" \
        --exclude="*.bat" \
        --exclude="node_modules" \
        --exclude=".DS_Store" \
        --exclude="Thumbs.db" \
        --exclude="*.zip" \
        ./ "$temp_dir/$PLUGIN_SLUG/" | sed 's/^/  • /'
    
    echo -e "\n${CYAN}Creating zip package...${NC}"
    
    # Create zip
    cd "$temp_dir"
    zip -r "../$zip_name" "$PLUGIN_SLUG" -q
    cd ..
    
    # Clean up
    rm -rf "$temp_dir"
    
    # Get file size
    size=$(du -h "$zip_name" | cut -f1)
    
    echo -e "\n${GREEN}✓ Package created: $zip_name ($size)${NC}"
}

# Function to display summary
show_summary() {
    local old_version=$1
    local new_version=$2
    local zip_file=$3
    
    echo -e "\n${CYAN}========================================"
    echo "             RELEASE SUMMARY"
    echo "========================================${NC}"
    echo -e "${YELLOW}Old Version:${NC}  $old_version"
    echo -e "${GREEN}New Version:${NC}  $new_version"
    echo -e "${GREEN}Package:${NC}      $zip_file"
    echo -e "${CYAN}========================================${NC}\n"
    
    echo -e "${CYAN}Next Steps:${NC}"
    echo "1. Review changes with: git diff"
    echo "2. Update CHANGELOG.md with version changes"
    echo "3. Commit: git add . && git commit -m 'Version $new_version'"
    echo "4. Push: git push origin main"
    echo "5. Create release: gh release create v$new_version --title 'Version $new_version' --notes 'Release notes here'"
    echo ""
}

# Main execution
main() {
    # Get current version
    current_version=$(get_current_version)
    echo -e "${YELLOW}Current Version: $current_version${NC}"
    
    # Determine new version
    if [[ $# -eq 0 ]]; then
        increment_type="patch"
    elif [[ "$1" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        new_version="$1"
        echo -e "${CYAN}Setting version to: $new_version (custom)${NC}"
    else
        increment_type="$1"
    fi
    
    # Calculate new version if not set
    if [[ -z "$new_version" ]]; then
        new_version=$(increment_version "$current_version" "$increment_type")
        echo -e "${CYAN}Incrementing $increment_type version to: $new_version${NC}"
    fi
    
    # Confirm with user
    echo -e -n "\n${YELLOW}Proceed with version update? (y/N): ${NC}"
    read -r confirmation
    
    if [[ ! "$confirmation" =~ ^[Yy]$ ]]; then
        echo -e "\n${YELLOW}Release cancelled by user.${NC}"
        exit 0
    fi
    
    echo ""
    
    # Update versions
    update_plugin_version "$new_version"
    update_readme_version "$new_version"
    
    # Create package
    create_package "$new_version"
    
    # Show summary
    show_summary "$current_version" "$new_version" "${PLUGIN_SLUG}-v${new_version}.zip"
}

# Run main function
main "$@"
