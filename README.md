# DB Version Control

**Sync WordPress to version-controlled JSON files for easy Git workflows.**

[![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-blue.svg)](https://wordpress.org/) [![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net/) [![License](https://img.shields.io/badge/License-GPL%20v2%2B-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

## Overview

DB Version Control bridges the gap between WordPress content management and modern development workflows. 

Instead of wrestling with database dumps or complex migration tools, this plugin exports your WordPress content to clean, readable JSON files that work seamlessly with Git and other version control systems.

**Perfect for:**

- Development teams managing content across environments
- DevOps workflows requiring automated content deployment
- Agencies syncing content between staging and production
- Content editors who want change tracking and rollback capabilities

## Key Features

### Smart Content Export

- **Selective Post Types**: Choose which post types to include in exports
- **Automatic Triggers**: Content exports automatically on saves, updates, and changes
- **Organized Structure**: Each post type gets its own folder for clean organization
- **Complete Data**: Includes post content, meta fields, options, and navigation menus

### Flexible Sync Options

- **Custom Sync Paths**: Set your own export directory (supports relative and absolute paths)
- **WP-CLI Integration**: Command-line tools for automation and CI/CD pipelines
- **Manual Exports**: On-demand exports through the admin interface
- **Selective Imports**: Import specific content types as needed

### Enterprise Ready

- **Security First**: CSRF protection, capability checks, and input sanitization
- **Error Handling**: Comprehensive logging and graceful failure handling
- **Performance Optimized**: Efficient file operations with minimal overhead
- **Extensible**: 20+ filters and actions for custom integrations

## Requirements

- **WordPress**: 5.0 or higher
- **PHP**: 7.4 or higher
- **File Permissions**: Write access to sync directory
- **WP-CLI**: Optional, for command-line operations

## 🔧 Installation

### Via WordPress Admin

1. Download the plugin zip file
2. Go to **Plugins > Add New > Upload Plugin**
3. Upload and activate the plugin
4. Navigate to **DBVC Export** in your admin menu

### Manual Installation
1. Upload the `db-version-control` folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress admin
3. Configure your settings under **DBVC Export**

## 🎯 Quick Start

### 1. Configure Post Types

Navigate to **DBVC Export** and select which post types you want to sync:

- Posts and Pages (enabled by default)
- Custom post types (WooCommerce products, events, etc.)
- Choose based on your content strategy

### 2. Set Sync Path

Choose where to store your JSON files:

```
wp-content/uploads/dbvc-sync/                # Safe, backed up location
wp-content/plugins/db-version-control/sync/  # Plugin directory (default)
../site-content/                             # Outside web root (recommended)
```

### 3. Run Your First Export

**Via Admin Interface:**

Click "Run Full Export" to generate JSON files for all content.

**Via WP-CLI:**
```bash
wp dbvc export
```

### 4. Version Control Integration

Add your sync folder to Git:

```bash
cd your-sync-folder/
git init
git add .
git commit -m "Initial content export"
```

## WP-CLI Commands

### Export All Content

```bash
wp dbvc export
```

Exports all posts, pages, options, and menus to JSON files.

**Batch Processing Options:**
```bash
wp dbvc export --batch-size=100 # Process 100 posts per batch
wp dbvc export --batch-size=0   # Disable batching (process all at once)
```

### Import All Content

```bash
wp dbvc import
```

⚠️ **Warning**: This overwrites existing content. Always backup first!

**Batch Processing Options:**
```bash
wp dbvc import --batch-size=25 # Process 25 files per batch  
wp dbvc import --batch-size=0  # Disable batching (process all at once)
```

### Performance Considerations

**Batch Size Recommendations:**
- **Small sites** (< 1,000 posts): `--batch-size=100` or `--batch-size=0`
- **Medium sites** (1,000-10,000 posts): `--batch-size=50` (default)
- **Large sites** (> 10,000 posts): `--batch-size=25`
- **Very large sites**: `--batch-size=10` with monitoring

**Real-world Performance:**
```bash
# Example output from a site with 395 posts across 6 post types
wp dbvc export --batch-size=50

Starting batch export with batch size: 50
Processed batch: 50 posts | Total: 50/398 | Remaining: 348
Processed batch: 50 posts | Total: 100/398 | Remaining: 298
...
Processed batch: 45 posts | Total: 395/398 | Remaining: 3
Success: Batch export completed! Processed 395 posts across post types: post, page, docupress, boostbox_popups, product, projects
```

### Example Automation Script

```bash
#!/bin/bash
# Daily content backup
wp dbvc export
cd /path/to/sync/folder
git add -A
git commit -m "Automated content backup $(date)"
git push origin main
```

## File Structure

```
sync-folder/
├── options.json           # WordPress options/settings
├── menus.json             # Navigation menus
├── post/                  # Blog posts
│   ├── post-1.json
│   ├── post-2.json
│   └── ...
├── page/                  # Static pages
│   ├── page-10.json
│   ├── page-15.json
│   └── ...
└── product/               # WooCommerce products (if enabled)
    ├── product-100.json
    └── ...
```

## Workflow Examples

### Development to Production

```bash
# On staging site
wp dbvc export
git add sync/
git commit -m "Content updates for v2.1"
git push

# On production site  
git pull
wp dbvc import
```

### Team Collaboration

```bash
# Content editor exports changes
wp dbvc export

# Developer reviews in pull request
git diff sync/

# Changes merged and deployed
wp dbvc import
```

### Automated Deployment

```yaml
# GitHub Actions example
- name: Deploy Content
  run: |
    wp dbvc export
    git add sync/
    git commit -m "Auto-export: ${{ github.sha }}" || exit 0
    git push
```

## Developer Integration

### Filters

**Modify supported post types:**
```php
add_filter( 'dbvc_supported_post_types', function( $post_types ) {
    $post_types[] = 'my_custom_post_type';
    return $post_types;
});
```

**Exclude sensitive options:**
```php
add_filter( 'dbvc_excluded_option_keys', function( $excluded ) {
    $excluded[] = 'my_secret_api_key';
    return $excluded;
});
```

**Modify export data:**
```php
add_filter( 'dbvc_export_post_data', function( $data, $post_id, $post ) {
    // Add custom fields or modify data
    $data['custom_field'] = get_field( 'my_field', $post_id );
    return $data;
}, 10, 3 );
```

### Actions

**Custom export operations:**
```php
add_action( 'dbvc_after_export_post', function( $post_id, $post, $file_path ) {
    // Custom logic after post export
    do_something_with_exported_post( $post_id );
});
```

**Skip certain meta keys:**
```php
add_filter( 'dbvc_skip_meta_keys', function( $skip_keys ) {
    $skip_keys[] = '_temporary_data';
    return $skip_keys;
});
```

## ⚠️ Important Considerations

### Security
- **File Permissions**: Ensure proper write permissions for sync directory
- **Sensitive Data**: Some options are automatically excluded (API keys, salts, etc.)
- **Access Control**: Only users with `manage_options` capability can export/import

### Performance
- **Large Sites**: Batch processing automatically handles large datasets efficiently
- **Memory Usage**: Batching prevents memory exhaustion on large imports/exports  
- **Server Load**: Built-in delays (0.1s export, 0.25s import) prevent overwhelming server resources
- **Progress Tracking**: Real-time feedback shows processed/remaining counts during batch operations
- **Scalable**: Successfully tested with 395+ posts across 6 different post types

### Data Integrity
- **Always Backup**: Import operations overwrite existing content
- **Test First**: Use staging environments for testing import/export workflows
- **Validate JSON**: Malformed JSON files will be skipped during import

## Troubleshooting

### Common Issues

**Permission Denied Errors:**
```bash
# Fix directory permissions
chmod 755 wp-content/uploads/dbvc-sync/
chown www-data:www-data wp-content/uploads/dbvc-sync/
```

**WP-CLI Command Not Found:**
```bash
# Verify WP-CLI installation
wp --info

# Check plugin activation
wp plugin list | grep db-version-control
```

**Empty Export Files:**
- Check if post types are selected in settings
- Verify posts exist and are published
- Check error logs for file write issues

### Debug Mode
Enable WordPress debug logging to troubleshoot issues:
```php
// wp-config.php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );

// Check logs at: wp-content/debug.log
```

## Contributing

Contributions are always welcome! Here's how to get started:

1. **Fork** the repository
2. **Create** a feature branch (`git checkout -b feature/amazing-feature`)
3. **Commit** your changes (`git commit -m 'Add amazing feature'`)
4. **Push** to the branch (`git push origin feature/amazing-feature`)
5. **Open** a Pull Request

### Development Setup
```bash
git clone https://github.com/robertdevore/db-version-control.git
cd db-version-control
composer install
```

## License

This project is licensed under the GPL v2+ License - see the [LICENSE](LICENSE) file for details.

## 👨‍💻 Author

**Robert DeVore**
- Website: [robertdevore.com](https://robertdevore.com)
- GitHub: [@robertdevore](https://github.com/robertdevore)
- X: [@deviorobert](https://x.com/deviorobert)
