#!/bin/bash

# Create shops directory if it doesn't exist
mkdir -p shops

# List of shop versions
VERSIONS=(
    "5.7.2"
    "5.6.2"
    "5.5.3"
    "5.4.1"
    "5.3.4"
)

# Function to convert version to URL format
version_to_url() {
    echo $1 | sed 's/\./-/g'
}

# Loop through versions
for version in "${VERSIONS[@]}"; do
    # Check if directory already exists
    if [ -d "shops/$version" ]; then
        echo "Shop version $version already exists, skipping..."
        continue
    fi

    echo "Processing shop version $version..."

    # Convert version for URL
    url_version=$(version_to_url $version)
    download_url="https://build.jtl-shop.de/get/shop-v${url_version}.zip"

    # Create temporary directory for download
    temp_dir=$(mktemp -d)

    # Download the file
    echo "Downloading from $download_url..."
    if curl -L "$download_url" -o "$temp_dir/shop.zip"; then
        # Create version directory
        mkdir -p "shops/$version"

        # Extract files
        echo "Extracting files to shops/$version..."
        if unzip -q "$temp_dir/shop.zip" -d "shops/$version"; then
            # Copy and rename config file
            config_dir="shops/$version/includes"
            mkdir -p "$config_dir"

            if [ -f "config.JTL-Shop.ini.shops.php" ]; then
                echo "Copying config file to $config_dir..."
                if cp "config.JTL-Shop.ini.shops.php" "$config_dir/config.JTL-Shop.ini.php"; then
                    echo "Config file successfully copied and renamed"
                else
                    echo "Error copying config file"
                fi
            else
                echo "Warning: config.JTL-Shop.ini.shops.php not found in current directory"
            fi

            echo "Successfully processed version $version"
        else
            echo "Error extracting files for version $version"
            rm -rf "shops/$version"
        fi
    else
        echo "Error downloading version $version"
    fi

    # Clean up temporary directory
    rm -rf "$temp_dir"
done

echo "All processing complete!"
