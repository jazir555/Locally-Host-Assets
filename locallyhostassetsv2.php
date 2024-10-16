<?php
/*
Plugin Name: Self-Host Third-Party CSS, Fonts, and JavaScript
Description: Downloads and self-hosts third-party CSS, fonts, and JavaScript files with settings for enabling/disabling functionality, cache control, and cleanup.
Version: 1.5
Author: Jazir5
Text Domain: self-host-assets
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class SelfHostAssets {

    // Default cache expiration times in days
    const DEFAULT_CACHE_EXPIRATION_CSS = 7;
    const DEFAULT_CACHE_EXPIRATION_FONTS = 30;
    const DEFAULT_CACHE_EXPIRATION_JS = 7;

    // Array to store error messages
    private static $errors = array();

    public static function init() {
        // Hook into 'wp_enqueue_scripts' to process CSS, fonts, and JavaScript
        add_action('wp_enqueue_scripts', [__CLASS__, 'self_host_resources'], 20);

        // Add settings page for enabling/disabling features and cache control
        add_action('admin_menu', [__CLASS__, 'add_settings_page']);
        add_action('admin_init', [__CLASS__, 'register_settings']);

        // Display admin notices for errors
        add_action('admin_notices', [__CLASS__, 'display_admin_notices']);
    }

    public static function self_host_resources() {
        $self_host_css_enabled = get_option('self_host_css', 1);
        $self_host_fonts_enabled = get_option('self_host_fonts', 1);
        $self_host_js_enabled = get_option('self_host_js', 0);
        $force_refresh = get_option('force_refresh', 0);

        if (!$self_host_css_enabled && !$self_host_fonts_enabled && !$self_host_js_enabled) {
            return;
        }

        // Process styles and fonts
        if ($self_host_css_enabled || $self_host_fonts_enabled) {
            self::process_styles($self_host_css_enabled, $self_host_fonts_enabled, $force_refresh);
        }

        // Process JavaScript
        if ($self_host_js_enabled) {
            self::process_scripts($force_refresh);
        }

        // Reset force refresh option
        if ($force_refresh) {
            update_option('force_refresh', 0);
        }
    }

    private static function process_styles($self_host_css_enabled, $self_host_fonts_enabled, $force_refresh) {
        global $wp_styles;

        if (empty($wp_styles->queue)) {
            return;
        }

        foreach ($wp_styles->queue as $handle) {
            $style = $wp_styles->registered[$handle];

            if (!isset($style->src) || empty($style->src)) {
                continue;
            }

            $src = $style->src;

            // Only process external URLs
            $src_host = parse_url($src, PHP_URL_HOST);
            $home_host = parse_url(home_url(), PHP_URL_HOST);

            if ($src_host === $home_host || empty($src_host)) {
                continue; // Skip local styles
            }

            // Download and replace the stylesheet
            $local_url = self::download_and_replace_css($src, $self_host_fonts_enabled, $force_refresh);

            if ($local_url) {
                // Deregister the original style and register the new one
                wp_deregister_style($handle);
                wp_register_style($handle, $local_url, $style->deps, $style->ver, $style->media);
                wp_enqueue_style($handle);
            }
        }
    }

    private static function process_scripts($force_refresh) {
        global $wp_scripts;

        if (empty($wp_scripts->queue)) {
            return;
        }

        foreach ($wp_scripts->queue as $handle) {
            $script = $wp_scripts->registered[$handle];

            if (!isset($script->src) || empty($script->src)) {
                continue;
            }

            $src = $script->src;

            // Only process external URLs
            $src_host = parse_url($src, PHP_URL_HOST);
            $home_host = parse_url(home_url(), PHP_URL_HOST);

            if ($src_host === $home_host || empty($src_host)) {
                continue; // Skip local scripts
            }

            // Download and replace the script
            $local_url = self::download_and_replace_js($src, $force_refresh);

            if ($local_url) {
                // Deregister the original script and register the new one
                wp_deregister_script($handle);
                wp_register_script($handle, $local_url, $script->deps, $script->ver, $script->args);
                wp_enqueue_script($handle);
            }
        }
    }

    private static function download_and_replace_css($url, $process_fonts = false, $force_refresh = false) {
        $cache_expiration_days = intval(get_option('cache_expiration_days_css', self::DEFAULT_CACHE_EXPIRATION_CSS));
        $file_url = self::download_file($url, 'css', $cache_expiration_days, $force_refresh);

        if ($file_url) {
            $file_path = self::get_local_file_path($url, 'css');
            $file_content = file_get_contents($file_path);

            if ($file_content !== false) {
                $processed_urls = array();
                $updated_content = self::process_css_content($file_content, $url, $processed_urls, $force_refresh);
                file_put_contents($file_path, $updated_content);
            } else {
                self::log_error(__('Failed to read CSS file for processing:', 'self-host-assets') . ' ' . esc_url_raw($url));
                return false;
            }
        }

        return $file_url;
    }

    private static function download_and_replace_js($url, $force_refresh = false) {
        $cache_expiration_days = intval(get_option('cache_expiration_days_js', self::DEFAULT_CACHE_EXPIRATION_JS));
        return self::download_file($url, 'js', $cache_expiration_days, $force_refresh);
    }

    private static function download_file($url, $type, $cache_expiration_days, $force_refresh) {
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once(ABSPATH . '/wp-admin/includes/file.php');
            if (!WP_Filesystem()) {
                self::log_error(__('Failed to initialize the WordPress Filesystem API.', 'self-host-assets'));
                return false;
            }
        }

        $upload_dir = wp_upload_dir();
        $sub_dir = 'self-hosted-' . $type . '/';
        $dir = trailingslashit($upload_dir['basedir']) . $sub_dir;
        $url_dir = trailingslashit($upload_dir['baseurl']) . $sub_dir;
        $filename = md5($url) . '.' . $type;
        $file_path = $dir . $filename;
        $file_url = $url_dir . $filename;

        // Check if the file exists and if it's still fresh
        if ($wp_filesystem->exists($file_path) && !$force_refresh) {
            $file_mod_time = $wp_filesystem->mtime($file_path);
            $expiration_time = strtotime("-{$cache_expiration_days} days");
            if ($file_mod_time > $expiration_time) {
                // File is still fresh, no need to re-download
                return $file_url;
            }
        }

        // Download the file
        $response = wp_remote_get($url, ['timeout' => 30]);

        if (is_wp_error($response)) {
            self::log_error(sprintf(__('Failed to download %s file:', 'self-host-assets'), strtoupper($type)) . ' ' . esc_url_raw($url) . ' - ' . $response->get_error_message());
            return false;
        }

        $file_content = wp_remote_retrieve_body($response);

        if (empty($file_content)) {
            self::log_error(sprintf(__('Empty %s file content:', 'self-host-assets'), strtoupper($type)) . ' ' . esc_url_raw($url));
            return false;
        }

        // MIME type verification
        $headers = wp_remote_retrieve_headers($response);
        $content_type = isset($headers['content-type']) ? $headers['content-type'] : '';
        $allowed_types = self::get_allowed_mime_types($type);

        if (!in_array($content_type, $allowed_types)) {
            self::log_error(sprintf(__('Invalid content type for %s file:', 'self-host-assets'), strtoupper($type)) . ' ' . esc_url_raw($url) . ' - ' . esc_html($content_type));
            return false;
        }

        // Save the file
        if (!$wp_filesystem->is_dir($dir)) {
            if (!$wp_filesystem->mkdir($dir, FS_CHMOD_DIR)) {
                self::log_error(__('Failed to create directory:', 'self-host-assets') . ' ' . esc_html($dir));
                return false;
            }
        }

        $saved = $wp_filesystem->put_contents($file_path, $file_content, FS_CHMOD_FILE);

        if (!$saved) {
            self::log_error(sprintf(__('Failed to save %s file:', 'self-host-assets'), strtoupper($type)) . ' ' . esc_html($file_path) . '. ' . __('Please check file permissions.', 'self-host-assets'));
            return false;
        }

        return $file_url;
    }

    private static function get_allowed_mime_types($type) {
        switch ($type) {
            case 'css':
                return array('text/css', 'text/plain');
            case 'js':
                return array(
                    'application/javascript',
                    'text/javascript',
                    'application/x-javascript',
                    'application/ecmascript',
                    'text/ecmascript',
                );
            case 'font':
                return array(
                    'font/woff',
                    'font/woff2',
                    'application/font-woff',
                    'application/font-woff2',
                    'application/font-ttf',
                    'application/font-sfnt',
                    'application/vnd.ms-fontobject',
                    'font/otf',
                    'font/ttf',
                    'application/octet-stream', // Common for font files
                );
            default:
                return array();
        }
    }

    private static function get_local_file_path($url, $type) {
        $upload_dir = wp_upload_dir();
        $sub_dir = 'self-hosted-' . $type . '/';
        $dir = trailingslashit($upload_dir['basedir']) . $sub_dir;
        $filename = md5($url) . '.' . $type;
        return $dir . $filename;
    }

    /**
     * Processes CSS content to handle @import statements and font URLs.
     *
     * @param string $css_content The CSS content.
     * @param string $css_url The URL of the CSS file.
     * @param array  &$processed_urls An array of already processed URLs to avoid recursion.
     * @param bool   $force_refresh Whether to force refresh cached files.
     * @return string The updated CSS content.
     */
    private static function process_css_content($css_content, $css_url, &$processed_urls, $force_refresh) {
        if (in_array($css_url, $processed_urls)) {
            return $css_content; // Avoid infinite recursion
        }

        $processed_urls[] = $css_url;

        // Process @import statements
        $css_content = self::process_import_statements($css_content, $css_url, $processed_urls, $force_refresh);

        // Process font URLs
        $css_content = self::process_font_urls($css_content, $css_url, $force_refresh);

        return $css_content;
    }

    /**
     * Processes @import statements in CSS content.
     *
     * @param string $css_content The CSS content.
     * @param string $css_url The URL of the CSS file.
     * @param array  &$processed_urls An array of already processed URLs to avoid recursion.
     * @param bool   $force_refresh Whether to force refresh cached files.
     * @return string The updated CSS content.
     */
    private static function process_import_statements($css_content, $css_url, &$processed_urls, $force_refresh) {
        $pattern = '$pattern = '/@import\s+(?:url\()?["\']?([^"\')\s]+)["\']?\)?(?:\s+[^;]+)?;/i';';
        if (preg_match_all($pattern, $css_content, $matches)) {
            foreach ($matches[1] as $import_url) {
                $absolute_import_url = self::make_absolute_url($import_url, $css_url);
                $local_import_url = self::download_and_process_css_import($absolute_import_url, $processed_urls, $force_refresh);
                if ($local_import_url) {
                    // Replace the import URL in the CSS content
                    $css_content = str_replace($import_url, $local_import_url, $css_content);
                }
            }
        }
        return $css_content;
    }

    /**
     * Downloads and processes a CSS file referenced in an @import statement.
     *
     * @param string $url The URL of the imported CSS file.
     * @param array  &$processed_urls An array of already processed URLs to avoid recursion.
     * @param bool   $force_refresh Whether to force refresh cached files.
     * @return string|false The local URL of the processed CSS file, or false on failure.
     */
    private static function download_and_process_css_import($url, &$processed_urls, $force_refresh) {
        $cache_expiration_days = intval(get_option('cache_expiration_days_css', self::DEFAULT_CACHE_EXPIRATION_CSS));
        $local_url = self::download_file($url, 'css', $cache_expiration_days, $force_refresh);

        if ($local_url) {
            $file_path = self::get_local_file_path($url, 'css');
            $file_content = file_get_contents($file_path);

            if ($file_content !== false) {
                $updated_content = self::process_css_content($file_content, $url, $processed_urls, $force_refresh);
                file_put_contents($file_path, $updated_content);
            } else {
                self::log_error(__('Failed to read imported CSS file for processing:', 'self-host-assets') . ' ' . esc_url_raw($url));
                return false;
            }

            return $local_url;
        }

        return false;
    }

    /**
     * Processes font URLs in CSS content.
     *
     * @param string $css_content The CSS content.
     * @param string $css_url The URL of the CSS file.
     * @param bool   $force_refresh Whether to force refresh cached files.
     * @return string The updated CSS content.
     */
    private static function process_font_urls($css_content, $css_url, $force_refresh) {
        // Find all font URLs in the CSS
        $font_urls = self::get_font_urls($css_content);

        if (empty($font_urls)) {
            return $css_content;
        }

        $upload_dir = wp_upload_dir();
        $font_dir = trailingslashit($upload_dir['basedir']) . 'self-hosted-fonts/';
        $font_url_dir = trailingslashit($upload_dir['baseurl']) . 'self-hosted-fonts/';

        // Get cache expiration setting
        $cache_expiration_days = intval(get_option('cache_expiration_days_fonts', self::DEFAULT_CACHE_EXPIRATION_FONTS));

        // Download each font and replace URLs in CSS content
        foreach ($font_urls as $font_url) {
            $absolute_font_url = self::make_absolute_url($font_url, $css_url);
            $local_font_url = self::download_font_file($absolute_font_url, $font_dir, $font_url_dir, $cache_expiration_days, $force_refresh);
            if ($local_font_url) {
                $css_content = str_replace($font_url, $local_font_url, $css_content);
            }
        }

        return $css_content;
    }

    private static function download_font_file($url, $font_dir, $font_url_dir, $cache_expiration_days, $force_refresh) {
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once(ABSPATH . '/wp-admin/includes/file.php');
            if (!WP_Filesystem()) {
                self::log_error(__('Failed to initialize the WordPress Filesystem API.', 'self-host-assets'));
                return false;
            }
        }

        $filename = md5($url) . '.' . pathinfo($url, PATHINFO_EXTENSION);
        $file_path = $font_dir . $filename;
        $file_url = $font_url_dir . $filename;

        // Check if the file exists and if it's still fresh
        if ($wp_filesystem->exists($file_path) && !$force_refresh) {
            $file_mod_time = $wp_filesystem->mtime($file_path);
            $expiration_time = strtotime("-{$cache_expiration_days} days");
            if ($file_mod_time > $expiration_time) {
                // File is still fresh, no need to re-download
                return $file_url;
            }
        }

        // Download the font file
        $response = wp_remote_get($url, ['timeout' => 30]);

        if (is_wp_error($response)) {
            self::log_error(__('Failed to download font file:', 'self-host-assets') . ' ' . esc_url_raw($url) . ' - ' . $response->get_error_message());
            return false;
        }

        $file_content = wp_remote_retrieve_body($response);

        if (empty($file_content)) {
            self::log_error(__('Empty font file content:', 'self-host-assets') . ' ' . esc_url_raw($url));
            return false;
        }

        // MIME type verification
        $headers = wp_remote_retrieve_headers($response);
        $content_type = isset($headers['content-type']) ? $headers['content-type'] : '';
        $allowed_types = self::get_allowed_mime_types('font');

        if (!in_array($content_type, $allowed_types)) {
            self::log_error(__('Invalid content type for font file:', 'self-host-assets') . ' ' . esc_url_raw($url) . ' - ' . esc_html($content_type));
            return false;
        }

        // Save the file
        if (!$wp_filesystem->is_dir($font_dir)) {
            if (!$wp_filesystem->mkdir($font_dir, FS_CHMOD_DIR)) {
                self::log_error(__('Failed to create directory:', 'self-host-assets') . ' ' . esc_html($font_dir));
                return false;
            }
        }

        $saved = $wp_filesystem->put_contents($file_path, $file_content, FS_CHMOD_FILE);

        if (!$saved) {
            self::log_error(__('Failed to save font file:', 'self-host-assets') . ' ' . esc_html($file_path) . '. ' . __('Please check file permissions.', 'self-host-assets'));
            return false;
        }

        return $file_url;
    }

    /**
     * Extracts font URLs from CSS content.
     *
     * @param string $css_content The CSS content.
     * @return array An array of font URLs.
     */
    private static function get_font_urls($css_content) {
        $font_urls = [];
        preg_match_all('/url\(([^)]+)\)/i', $css_content, $matches);

        if (!empty($matches[1])) {
            foreach ($matches[1] as $url) {
                $url = trim($url, '\'"');
                if (preg_match('/\.(woff2?|ttf|otf|eot)(\?|#|$)/i', $url)) {
                    $font_urls[] = $url;
                }
            }
        }

        return array_unique($font_urls);
    }

    /**
     * Converts a relative URL to an absolute URL based on a base URL.
     *
     * @param string $relative_url The relative URL found in the CSS.
     * @param string $base_url The base URL of the CSS file.
     * @return string The absolute URL.
     */
    private static function make_absolute_url($relative_url, $base_url) {
        // If the URL is already absolute, return it
        if (parse_url($relative_url, PHP_URL_SCHEME) !== null) {
            return $relative_url;
        }

        // Build absolute URL
        $base_parts = parse_url($base_url);
        $base_host = $base_parts['scheme'] . '://' . $base_parts['host'];
        if (isset($base_parts['port'])) {
            $base_host .= ':' . $base_parts['port'];
        }

        // Handle relative URLs
        if (strpos($relative_url, '//') === 0) {
            return $base_parts['scheme'] . ':' . $relative_url;
        } elseif ($relative_url[0] === '/') {
            return $base_host . $relative_url;
        } else {
            $base_path = isset($base_parts['path']) ? dirname($base_parts['path']) : '';
            return $base_host . $base_path . '/' . $relative_url;
        }
    }

    // Settings page for enabling/disabling self-hosting CSS, fonts, JavaScript, and cache control
    public static function add_settings_page() {
        add_options_page(
            __('Self-Host Assets Settings', 'self-host-assets'),
            __('Self-Host Assets', 'self-host-assets'),
            'manage_options',
            'self-host-assets',
            [__CLASS__, 'render_settings_page']
        );
    }

    public static function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Self-Host Assets Settings', 'self-host-assets'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('self_host_assets_settings_group');
                do_settings_sections('self_host_assets_settings');
                submit_button();
                ?>
            </form>
            <form method="post" action="">
                <?php
                if (isset($_POST['force_refresh'])) {
                    update_option('force_refresh', 1);
                    echo '<div class="notice notice-success"><p>' . __('Cache will be refreshed on the next page load.', 'self-host-assets') . '</p></div>';
                }
                submit_button(__('Force Refresh Cache', 'self-host-assets'), 'secondary', 'force_refresh');
                ?>
            </form>
            <div class="notice notice-warning">
                <p><?php _e('Please ensure you have the rights to self-host third-party resources and be aware of the security implications. Regularly update cached files to include any security patches or updates.', 'self-host-assets'); ?></p>
            </div>
        </div>
        <?php
    }

    public static function register_settings() {
        register_setting('self_host_assets_settings_group', 'self_host_css', [
            'type' => 'boolean',
            'sanitize_callback' => 'absint',
            'default' => 1,
        ]);

        register_setting('self_host_assets_settings_group', 'self_host_fonts', [
            'type' => 'boolean',
            'sanitize_callback' => 'absint',
            'default' => 1,
        ]);

        register_setting('self_host_assets_settings_group', 'self_host_js', [
            'type' => 'boolean',
            'sanitize_callback' => 'absint',
            'default' => 0,
        ]);

        register_setting('self_host_assets_settings_group', 'cache_expiration_days_css', [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => self::DEFAULT_CACHE_EXPIRATION_CSS,
        ]);

        register_setting('self_host_assets_settings_group', 'cache_expiration_days_fonts', [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => self::DEFAULT_CACHE_EXPIRATION_FONTS,
        ]);

        register_setting('self_host_assets_settings_group', 'cache_expiration_days_js', [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => self::DEFAULT_CACHE_EXPIRATION_JS,
        ]);

        register_setting('self_host_assets_settings_group', 'force_refresh', [
            'type' => 'boolean',
            'sanitize_callback' => 'absint',
            'default' => 0,
        ]);

        add_settings_section('self_host_assets_section', __('Settings', 'self-host-assets'), null, 'self_host_assets_settings');

        add_settings_field(
            'self_host_css',
            __('Enable Self-Host for CSS', 'self-host-assets'),
            [__CLASS__, 'render_css_field'],
            'self_host_assets_settings',
            'self_host_assets_section'
        );

        add_settings_field(
            'self_host_fonts',
            __('Enable Self-Host for Fonts', 'self-host-assets'),
            [__CLASS__, 'render_fonts_field'],
            'self_host_assets_settings',
            'self_host_assets_section'
        );

        add_settings_field(
            'self_host_js',
            __('Enable Self-Host for JavaScript', 'self-host-assets'),
            [__CLASS__, 'render_js_field'],
            'self_host_assets_settings',
            'self_host_assets_section'
        );

        add_settings_field(
            'cache_expiration_days_css',
            __('Cache Expiration for CSS (Days)', 'self-host-assets'),
            [__CLASS__, 'render_cache_expiration_css_field'],
            'self_host_assets_settings',
            'self_host_assets_section'
        );

        add_settings_field(
            'cache_expiration_days_fonts',
            __('Cache Expiration for Fonts (Days)', 'self-host-assets'),
            [__CLASS__, 'render_cache_expiration_fonts_field'],
            'self_host_assets_settings',
            'self_host_assets_section'
        );

        add_settings_field(
            'cache_expiration_days_js',
            __('Cache Expiration for JavaScript (Days)', 'self-host-assets'),
            [__CLASS__, 'render_cache_expiration_js_field'],
            'self_host_assets_settings',
            'self_host_assets_section'
        );
    }

    public static function render_css_field() {
        $self_host_css = get_option('self_host_css', 1);
        ?>
        <input type="checkbox" name="self_host_css" value="1" <?php checked(1, $self_host_css); ?> />
        <label for="self_host_css"><?php _e('Enable', 'self-host-assets'); ?></label>
        <?php
    }

    public static function render_fonts_field() {
        $self_host_fonts = get_option('self_host_fonts', 1);
        ?>
        <input type="checkbox" name="self_host_fonts" value="1" <?php checked(1, $self_host_fonts); ?> />
        <label for="self_host_fonts"><?php _e('Enable', 'self-host-assets'); ?></label>
        <?php
    }

    public static function render_js_field() {
        $self_host_js = get_option('self_host_js', 0);
        ?>
        <input type="checkbox" name="self_host_js" value="1" <?php checked(1, $self_host_js); ?> />
        <label for="self_host_js"><?php _e('Enable', 'self-host-assets'); ?></label>
        <?php
    }

    public static function render_cache_expiration_css_field() {
        $cache_expiration_days = intval(get_option('cache_expiration_days_css', self::DEFAULT_CACHE_EXPIRATION_CSS));
        ?>
        <input type="number" name="cache_expiration_days_css" value="<?php echo esc_attr($cache_expiration_days); ?>" min="1" />
        <p class="description"><?php _e('Set the number of days after which cached CSS files should be refreshed.', 'self-host-assets'); ?></p>
        <?php
    }

    public static function render_cache_expiration_fonts_field() {
        $cache_expiration_days = intval(get_option('cache_expiration_days_fonts', self::DEFAULT_CACHE_EXPIRATION_FONTS));
        ?>
        <input type="number" name="cache_expiration_days_fonts" value="<?php echo esc_attr($cache_expiration_days); ?>" min="1" />
        <p class="description"><?php _e('Set the number of days after which cached font files should be refreshed.', 'self-host-assets'); ?></p>
        <?php
    }

    public static function render_cache_expiration_js_field() {
        $cache_expiration_days = intval(get_option('cache_expiration_days_js', self::DEFAULT_CACHE_EXPIRATION_JS));
        ?>
        <input type="number" name="cache_expiration_days_js" value="<?php echo esc_attr($cache_expiration_days); ?>" min="1" />
        <p class="description"><?php _e('Set the number of days after which cached JavaScript files should be refreshed.', 'self-host-assets'); ?></p>
        <?php
    }

    // Function to log errors and display them in admin notices
    private static function log_error($message) {
        $errors = get_option('self_host_assets_errors', array());
        $errors[] = $message;
        update_option('self_host_assets_errors', $errors);
    }

    public static function display_admin_notices() {
        $errors = get_option('self_host_assets_errors', array());
        if (!empty($errors)) {
            foreach ($errors as $error) {
                echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
            }
            delete_option('self_host_assets_errors');
        }
    }

    // Cleanup cached files when the plugin is uninstalled
    public static function uninstall() {
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once(ABSPATH . '/wp-admin/includes/file.php');
            if (!WP_Filesystem()) {
                // Cannot proceed without filesystem API
                return;
            }
        }

        $upload_dir = wp_upload_dir();
        self::remove_directory(trailingslashit($upload_dir['basedir']) . 'self-hosted-css/');
        self::remove_directory(trailingslashit($upload_dir['basedir']) . 'self-hosted-fonts/');
        self::remove_directory(trailingslashit($upload_dir['basedir']) . 'self-hosted-js/');
    }

    // Utility function to remove a directory and its files
    private static function remove_directory($dir) {
        global $wp_filesystem;

        if (!$wp_filesystem->is_dir($dir)) {
            return;
        }

        $wp_filesystem->delete($dir, true);
    }
}

// Initialize the plugin
SelfHostAssets::init();

// Register uninstall hook
register_uninstall_hook(__FILE__, ['SelfHostAssets', 'uninstall']);
