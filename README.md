# Locally-Host-CSS-and-Fonts

Wordpress Plugin to Locally Host Third-Party CSS and Font Files To Eliminate Third Party HTTP Requests

**Purpose and Benefits**

Performance Improvement: Self-hosting external resources can reduce DNS lookups, decrease latency, and improve page load times, leading to a better user experience.

Privacy Compliance: By hosting third-party assets locally, you minimize the exposure of user data to external servers, which can help with compliance to privacy regulations like GDPR.

Reliability: Reduces dependency on third-party servers, preventing issues if the external resource becomes unavailable.

Control Over Assets: Allows you to manage updates and changes to the assets directly, ensuring compatibility and security.

**Key Features**

**1. Self-Hosting of External Resources**

CSS Files: Downloads and self-hosts external CSS files enqueued by WordPress.

Fonts: Processes CSS files to find and download font files referenced via @font-face rules.

JavaScript Files: Downloads and self-hosts external JavaScript files enqueued by WordPress.

@import Statements: Recursively processes @import statements within CSS files to ensure all linked stylesheets are self-hosted.

**2. Configurable Settings**

Enable/Disable Features: Options to enable or disable self-hosting for CSS, fonts, and JavaScript individually.
Cache Expiration Control: Set cache expiration times (in days) for CSS, fonts, and JavaScript files to control how often they are refreshed.
Force Cache Refresh: A manual option to force a refresh of all cached resources on the next page load.

**3. Admin Interface**

Settings Page: An intuitive settings page in the WordPress admin area under "Settings > Self-Host Assets."
Admin Notices: Displays error messages and notifications in the WordPress admin area to inform you of any issues during processing.

**4. Error Handling**
   
Logging: Captures and logs errors encountered during the download and processing of resources.
User Notifications: Provides clear and informative admin notices to help troubleshoot any problems.

**5. Cleanup on Uninstall**

Cache Removal: Automatically removes all cached files (CSS, fonts, JavaScript) from the server when the plugin is uninstalled.

Option Cleanup: Cleans up plugin-related options from the WordPress database upon uninstallation.

**How the Plugin Works**

**Initialization**

Hooks into WordPress actions to process enqueued scripts and styles.

Adds an admin menu and registers settings for user configuration.

**Processing Enqueued Resources**

**Resource Detection:**

Scans enqueued CSS and JavaScript files registered with WordPress.

Identifies external resources by comparing the host of the resource URL with the site's host.

**Downloading Resources:**

Uses WordPress HTTP API (wp_remote_get) to download external CSS and JavaScript files.

Saves the downloaded files to the wp-content/uploads directory in subfolders (self-hosted-css, self-hosted-js, self-hosted-fonts).

**MIME Type Verification:**

Verifies the MIME type of downloaded files to ensure they match expected content types.

Prevents malicious files from being saved and executed.

Cache Expiration Handling:

Checks the modification time of cached files.

Redownloads files if they are older than the user-defined cache expiration period or if a force refresh is triggered.

**Processing CSS Files**

**Font URL Extraction:**

Parses CSS content to find url() references that point to font files (.woff, .woff2, .ttf, .otf, .eot).

Converts relative URLs to absolute URLs based on the original CSS file's location.

**Downloading Fonts:**

Downloads the font files and saves them locally.

Replaces the original font URLs in the CSS content with the local URLs.

**Handling @import Statements:**

Recursively processes @import statements to download and process imported CSS files.

Ensures that all nested stylesheets and their resources are self-hosted.

**Updating CSS Content:**

Modifies the CSS content to replace external URLs with local paths.

Saves the updated CSS file to the local server.

**Processing JavaScript Files**

Downloads external JavaScript files similar to CSS processing.

Replaces the enqueued script URLs with local URLs pointing to the downloaded files.

**Resource Replacement**

Deregisters the original external styles and scripts.
Registers and enqueues the new local versions with WordPress.
Ensures that the dependency chain (deps) is maintained for proper loading order.

**Technical Details**

**File Storage**

Uploads Directory: Utilizes the WordPress uploads directory to store cached resources.

Subdirectories:
self-hosted-css/
self-hosted-fonts/
self-hosted-js/

File Naming: Uses MD5 hashes of the original URLs combined with the appropriate file extensions to prevent naming conflicts.

**Security Measures**

WordPress Filesystem API: Uses WP_Filesystem for all file operations to ensure compatibility and security.
MIME Type Checks: Verifies the content type of downloaded files to prevent the execution of unexpected file types.
Permissions: Sets appropriate file and directory permissions (FS_CHMOD_FILE, FS_CHMOD_DIR).

**Error Handling and Logging**

Error Storage: Stores error messages in a WordPress option (self_host_assets_errors) for retrieval.

Admin Notices: Displays errors in the WordPress admin area to alert administrators.

Logging Content: Includes detailed messages about failed downloads, invalid content types, and file system issues.

**Settings and Configuration**

Options Page: Accessible via "Settings > Self-Host Assets" in the WordPress admin dashboard.

Settings Fields:

Enable Self-Host for CSS: Checkbox to enable or disable CSS processing.

Enable Self-Host for Fonts: Checkbox to enable or disable font processing within CSS files.

Enable Self-Host for JavaScript: Checkbox to enable or disable JavaScript processing.

Cache Expiration for CSS (Days): Numeric input to set CSS cache expiration.

Cache Expiration for Fonts (Days): Numeric input to set font cache expiration.

Cache Expiration for JavaScript (Days): Numeric input to set JavaScript cache expiration.

Force Refresh Cache: Button to trigger a manual refresh of all cached resources.
