<?php
/*
Plugin Name: Self-Host Third-Party CSS, Fonts, and JavaScript
Description: Downloads and self-hosts third-party CSS, fonts, and JavaScript files with settings for enabling/disabling functionality, cache control, and cleanup.
Version: 1.7.0
Author: Jazir5
Text Domain: self-host-assets
Domain Path: /languages
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class SelfHostAssets {

    // Default cache expiration times in days
    const DEFAULT_CACHE_EXPIRATION_CSS = 7;
    const DEFAULT_CACHE_EXPIRATION_FONTS = 30;
    const DEFAULT_CACHE_EXPIRATION_JS = 7;

    // Maximum depth for nested @import statements
    const MAX_IMPORT_DEPTH = 5;

    // Property to store error messages
    private static $errors = [];

    // Property to store the instance of the class (Singleton pattern)
    private static $instance = null;

    // Get the instance of the class
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new SelfHostAssets();
        }
        return self::$instance;
    }

    // Constructor is made private to prevent direct instantiation
    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        // Load plugin text domain for translations
        add_action('plugins_loaded', [$this, 'load_textdomain']);

        // Hook into 'wp_enqueue_scripts' to enqueue cached CSS, fonts, and JavaScript
        add_action('wp_enqueue_scripts', [$this, 'self_host_resources'], 20);

        // Add settings page for enabling/disabling features and cache control
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);

        // Display admin notices for errors
        add_action('admin_notices', [$this, 'display_admin_notices']);

        // Register activation and deactivation hooks
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        // Add cron event hook
        add_action('self_host_assets_cron_event', [$this, 'cron_process_resources']);

        // Add custom cron schedules
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);

        // Reschedule cron event when schedule changes
        add_action('update_option_cron_schedule', [$this, 'reschedule_cron_event'], 10, 2);

        // Enqueue admin styles for settings page
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_styles']);
    }

    public function load_textdomain() {
        load_plugin_textdomain('self-host-assets', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }

    public function activate() {
        // Ensure the WordPress Filesystem API is initialized
        if (!$this->initialize_filesystem()) {
            return;
        }

        // Process all resources upon activation
        $this->process_all_resources(true);

        // Schedule the cron event if not already scheduled
        $custom_schedule = get_option('cron_schedule', 'daily');
        if (!wp_next_scheduled('self_host_assets_cron_event')) {
            wp_schedule_event(time(), $custom_schedule, 'self_host_assets_cron_event');
        }
    }

    public function deactivate() {
        // Clear the scheduled cron event
        $timestamp = wp_next_scheduled('self_host_assets_cron_event');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'self_host_assets_cron_event');
        }
    }

    public function cron_process_resources() {
        // Retrieve the force_refresh option
        $force_refresh = get_option('force_refresh', 0);

        // Process all resources with the force_refresh flag
        $this->process_all_resources((bool) $force_refresh);

        // Reset the force_refresh option if it was set
        if ($force_refresh) {
            update_option('force_refresh', 0);
        }
    }

    public function self_host_resources() {
        $self_host_css_enabled = get_option('self_host_css', 1);
        $self_host_js_enabled = get_option('self_host_js', 0);

        // Enqueue cached CSS and Fonts
        if ($self_host_css_enabled) {
            $this->enqueue_cached_styles();
        }

        // Enqueue cached JavaScript
        if ($self_host_js_enabled) {
            $this->enqueue_cached_scripts();
        }
    }

    private function process_all_resources($force_refresh = false) {
        $self_host_css_enabled = get_option('self_host_css', 1);
        $self_host_js_enabled = get_option('self_host_js', 0);

        if ($self_host_css_enabled) {
            $this->process_all_styles($force_refresh);
        }

        if ($self_host_js_enabled) {
            $this->process_all_scripts($force_refresh);
        }
    }

    private function process_all_styles($force_refresh) {
        global $wp_styles;

        if (empty($wp_styles->queue)) {
            return;
        }

        foreach ($wp_styles->queue as $handle) {
            if (!isset($wp_styles->registered[$handle])) {
                continue;
            }

            $style = $wp_styles->registered[$handle];

            if (empty($style->src)) {
                continue;
            }

            $src = $style->src;

            // Only process external URLs
            $src_host = wp_parse_url($src, PHP_URL_HOST);
            $home_host = wp_parse_url(home_url(), PHP_URL_HOST);

            if ($src_host === $home_host || empty($src_host)) {
                continue; // Skip local styles
            }

            if (!wp_http_validate_url($src)) {
                $this->log_error(sprintf(__('Invalid URL: %s', 'self-host-assets'), esc_url_raw($src)));
                continue;
            }

            // Download and replace the stylesheet
            $local_url = $this->download_and_replace_css($src, $force_refresh);

            if ($local_url) {
                // Deregister the original style and register the new one
                wp_deregister_style($handle);
                wp_register_style($handle, $local_url, $style->deps, $style->ver, $style->media);
                wp_enqueue_style($handle);
            }
        }
    }

    private function process_all_scripts($force_refresh) {
        global $wp_scripts;

        if (empty($wp_scripts->queue)) {
            return;
        }

        foreach ($wp_scripts->queue as $handle) {
            if (!isset($wp_scripts->registered[$handle])) {
                continue;
            }

            $script = $wp_scripts->registered[$handle];

            if (empty($script->src)) {
                continue;
            }

            $src = $script->src;

            // Only process external URLs
            $src_host = wp_parse_url($src, PHP_URL_HOST);
            $home_host = wp_parse_url(home_url(), PHP_URL_HOST);

            if ($src_host === $home_host || empty($src_host)) {
                continue; // Skip local scripts
            }

            if (!wp_http_validate_url($src)) {
                $this->log_error(sprintf(__('Invalid URL: %s', 'self-host-assets'), esc_url_raw($src)));
                continue;
            }

            // Download and replace the script
            $local_url = $this->download_and_replace_js($src, $force_refresh);

            if ($local_url) {
                // Deregister the original script and register the new one
                wp_deregister_script($handle);
                wp_register_script($handle, $local_url, $script->deps, $script->ver, $script->args);
                wp_enqueue_script($handle);
            }
        }
    }

    private function download_and_replace_css($url, $force_refresh = false) {
        $cache_expiration_days = intval(get_option('cache_expiration_days_css', self::DEFAULT_CACHE_EXPIRATION_CSS));
        $file_url = $this->download_file($url, 'css', $cache_expiration_days, $force_refresh);

        if ($file_url) {
            $file_path = $this->get_local_file_path($url, 'css');

            if (file_exists($file_path) && is_readable($file_path)) {
                $file_content = file_get_contents($file_path);

                if ($file_content !== false) {
                    $processed_urls = [];
                    $updated_content = $this->process_css_content($file_content, $url, $processed_urls, $force_refresh, 0);

                    if (is_writable($file_path)) {
                        file_put_contents($file_path, $updated_content);
                    } else {
                        $this->log_error(sprintf(__('CSS file is not writable: %s', 'self-host-assets'), esc_html($file_path)));
                        return false;
                    }
                } else {
                    $this->log_error(sprintf(__('Failed to read CSS file for processing: %s', 'self-host-assets'), esc_url_raw($url)));
                    return false;
                }
            } else {
                $this->log_error(sprintf(__('CSS file not found or not readable: %s', 'self-host-assets'), esc_html($file_path)));
                return false;
            }
        }

        return $file_url;
    }

    private function download_and_replace_js($url, $force_refresh = false) {
        $cache_expiration_days = intval(get_option('cache_expiration_days_js', self::DEFAULT_CACHE_EXPIRATION_JS));
        return $this->download_file($url, 'js', $cache_expiration_days, $force_refresh);
    }

    private function download_file($url, $type, $cache_expiration_days, $force_refresh) {
        if (!$this->initialize_filesystem()) {
            return false;
        }

        if (!wp_http_validate_url($url)) {
            $this->log_error(sprintf(__('Invalid URL: %s', 'self-host-assets'), esc_url_raw($url)));
            return false;
        }

        $upload_dir = wp_upload_dir();
        $sub_dir = 'self-hosted-' . $type . '/';
        $dir = trailingslashit($upload_dir['basedir']) . $sub_dir;
        $url_dir = trailingslashit($upload_dir['baseurl']) . $sub_dir;
        $filename = md5($url) . '.' . $type;
        $file_path = $dir . $filename;
        $file_url = $url_dir . $filename;

        // Check if the file exists and if it's still fresh
        if ($this->is_file_fresh($file_path, $cache_expiration_days) && !$force_refresh) {
            $file_url = add_query_arg('ver', filemtime($file_path), $file_url);
            return $file_url;
        }

        // Download the file
        $response = wp_remote_get($url, ['timeout' => 30, 'redirection' => 5]);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->log_error(sprintf(__('Failed to download %s file: %s - %s', 'self-host-assets'), strtoupper($type), esc_url_raw($url), $error_message));
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code >= 400) {
            $this->log_error(sprintf(__('HTTP Error %d while downloading %s file: %s', 'self-host-assets'), $response_code, strtoupper($type), esc_url_raw($url)));
            return false;
        }

        $file_content = wp_remote_retrieve_body($response);

        if (empty($file_content)) {
            $this->log_error(sprintf(__('Empty %s file content: %s', 'self-host-assets'), strtoupper($type), esc_url_raw($url)));
            return false;
        }

        // MIME type verification
        $headers = wp_remote_retrieve_headers($response);
        $content_type = isset($headers['content-type']) ? $headers['content-type'] : '';
        $allowed_types = $this->get_allowed_mime_types($type);

        if (!in_array($content_type, $allowed_types, true)) {
            $this->log_error(sprintf(__('Invalid content type for %s file: %s - %s', 'self-host-assets'), strtoupper($type), esc_url_raw($url), esc_html($content_type)));
            return false;
        }

        // Save the file
        if (!$this->wp_filesystem()->is_dir($dir)) {
            if (!$this->wp_filesystem()->mkdir($dir, FS_CHMOD_DIR)) {
                $this->log_error(sprintf(__('Failed to create directory: %s', 'self-host-assets'), esc_html($dir)));
                return false;
            }
            // Create a marker file to indicate ownership
            $this->wp_filesystem()->put_contents($dir . '.self-host-assets', 'Plugin marker file', FS_CHMOD_FILE);
        }

        $saved = $this->wp_filesystem()->put_contents($file_path, $file_content, FS_CHMOD_FILE);

        if (!$saved) {
            $this->log_error(sprintf(__('Failed to save %s file: %s. Please check file permissions.', 'self-host-assets'), strtoupper($type), esc_html($file_path)));
            return false;
        }

        $file_url = add_query_arg('ver', filemtime($file_path), $file_url);

        return $file_url;
    }

    private function enqueue_cached_styles() {
        global $wp_styles;

        if (empty($wp_styles->queue)) {
            return;
        }

        foreach ($wp_styles->queue as $handle) {
            if (!isset($wp_styles->registered[$handle])) {
                continue;
            }

            $style = $wp_styles->registered[$handle];

            if (empty($style->src)) {
                continue;
            }

            $src = $style->src;

            // Only process external URLs
            $src_host = wp_parse_url($src, PHP_URL_HOST);
            $home_host = wp_parse_url(home_url(), PHP_URL_HOST);

            if ($src_host === $home_host || empty($src_host)) {
                continue; // Skip local styles
            }

            $local_url = $this->get_local_url($src, 'css');

            if ($local_url) {
                // Deregister the original style and register the new one
                wp_deregister_style($handle);
                wp_register_style($handle, $local_url, $style->deps, $style->ver, $style->media);
                wp_enqueue_style($handle);
            }
        }
    }

    private function enqueue_cached_scripts() {
        global $wp_scripts;

        if (empty($wp_scripts->queue)) {
            return;
        }

        foreach ($wp_scripts->queue as $handle) {
            if (!isset($wp_scripts->registered[$handle])) {
                continue;
            }

            $script = $wp_scripts->registered[$handle];

            if (empty($script->src)) {
                continue;
            }

            $src = $script->src;

            // Only process external URLs
            $src_host = wp_parse_url($src, PHP_URL_HOST);
            $home_host = wp_parse_url(home_url(), PHP_URL_HOST);

            if ($src_host === $home_host || empty($src_host)) {
                continue; // Skip local scripts
            }

            $local_url = $this->get_local_url($src, 'js');

            if ($local_url) {
                // Deregister the original script and register the new one
                wp_deregister_script($handle);
                wp_register_script($handle, $local_url, $script->deps, $script->ver, $script->args);
                wp_enqueue_script($handle);
            }
        }
    }

    private function get_local_url($url, $type) {
        $upload_dir = wp_upload_dir();
        $sub_dir = 'self-hosted-' . $type . '/';
        $url_dir = trailingslashit($upload_dir['baseurl']) . $sub_dir;
        $filename = md5($url) . '.' . $type;
        $file_url = $url_dir . $filename;
        $file_path = trailingslashit($upload_dir['basedir']) . $sub_dir . $filename;

        if (file_exists($file_path)) {
            $file_url = add_query_arg('ver', filemtime($file_path), $file_url);
            return $file_url;
        }

        return false;
    }

    private function is_file_fresh($file_path, $cache_expiration_days) {
        if (file_exists($file_path)) {
            $file_mod_time = filemtime($file_path);
            $expiration_time = strtotime("-{$cache_expiration_days} days");
            if ($file_mod_time > $expiration_time) {
                // File is still fresh
                return true;
            }
        }

        return false;
    }

    private function initialize_filesystem() {
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            if (!WP_Filesystem()) {
                $this->log_error(__('Failed to initialize the WordPress Filesystem API.', 'self-host-assets'));
                return false;
            }
        }
        return true;
    }

    private function wp_filesystem() {
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            $this->initialize_filesystem();
        }
        return $wp_filesystem;
    }

    /**
     * Processes CSS content to handle @import statements and font URLs.
     */
    private function process_css_content($css_content, $css_url, &$processed_urls, $force_refresh, $current_depth) {
        if ($current_depth >= self::MAX_IMPORT_DEPTH) {
            $this->log_error(sprintf(__('Maximum import depth of %d reached for CSS file: %s', 'self-host-assets'), self::MAX_IMPORT_DEPTH, esc_url_raw($css_url)));
            return $css_content;
        }

        if (in_array($css_url, $processed_urls, true)) {
            return $css_content; // Avoid infinite recursion
        }

        $processed_urls[] = $css_url;

        // Remove CSS comments to avoid processing @import statements within them
        $css_content = preg_replace('/\/\*.*?\*\//s', '', $css_content);

        // Process @import statements
        $css_content = $this->process_import_statements($css_content, $css_url, $processed_urls, $force_refresh, $current_depth);

        // Process font URLs
        $css_content = $this->process_font_urls($css_content, $css_url, $force_refresh);

        return $css_content;
    }

    /**
     * Processes @import statements in CSS content.
     */
    private function process_import_statements($css_content, $css_url, &$processed_urls, $force_refresh, $current_depth) {
        // Optimized regex: non-greedy matching and minimal capturing
        $pattern = '/@import\s+(?:url\(\s*)?(["\']?)([^"\')\s]+)\1(?:\s+([^{;]+))?\s*\)?\s*;/i';

        if (preg_match_all($pattern, $css_content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $import_url = $match[2];
                $media_query = isset($match[3]) ? $match[3] : '';

                $absolute_import_url = $this->make_absolute_url($import_url, $css_url);
                $local_import_url = $this->download_and_process_css_import($absolute_import_url, $processed_urls, $force_refresh, $current_depth + 1);

                if ($local_import_url) {
                    // Reconstruct the @import statement with the local URL and original media queries
                    $new_import = '@import url("' . esc_url_raw($local_import_url) . '")';
                    if (!empty($media_query)) {
                        $new_import .= ' ' . esc_html($media_query);
                    }
                    $new_import .= ';';

                    // Replace the original @import statement with the new one
                    $css_content = str_replace($match[0], $new_import, $css_content);
                }
            }
        }

        return $css_content;
    }

    /**
     * Downloads and processes a CSS file referenced in an @import statement.
     */
    private function download_and_process_css_import($url, &$processed_urls, $force_refresh, $current_depth) {
        if ($current_depth >= self::MAX_IMPORT_DEPTH) {
            $this->log_error(sprintf(__('Maximum import depth of %d reached for CSS file: %s', 'self-host-assets'), self::MAX_IMPORT_DEPTH, esc_url_raw($url)));
            return false;
        }

        $cache_expiration_days = intval(get_option('cache_expiration_days_css', self::DEFAULT_CACHE_EXPIRATION_CSS));
        $local_url = $this->download_file($url, 'css', $cache_expiration_days, $force_refresh);

        if ($local_url) {
            $file_path = $this->get_local_file_path($url, 'css');

            if (file_exists($file_path) && is_readable($file_path)) {
                $file_content = file_get_contents($file_path);

                if ($file_content !== false) {
                    $updated_content = $this->process_css_content($file_content, $url, $processed_urls, $force_refresh, $current_depth);
                    if (is_writable($file_path)) {
                        file_put_contents($file_path, $updated_content);
                    } else {
                        $this->log_error(sprintf(__('CSS file is not writable: %s', 'self-host-assets'), esc_html($file_path)));
                        return false;
                    }
                } else {
                    $this->log_error(sprintf(__('Failed to read imported CSS file for processing: %s', 'self-host-assets'), esc_url_raw($url)));
                    return false;
                }

                return $local_url;
            } else {
                $this->log_error(sprintf(__('Imported CSS file not found or not readable: %s', 'self-host-assets'), esc_html($file_path)));
                return false;
            }
        }

        return false;
    }

    /**
     * Processes font URLs in CSS content.
     */
    private function process_font_urls($css_content, $css_url, $force_refresh) {
        // Find all font URLs in the CSS
        $font_urls = $this->get_font_urls($css_content);

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
            $absolute_font_url = $this->make_absolute_url($font_url, $css_url);
            $local_font_url = $this->download_font_file($absolute_font_url, $font_dir, $font_url_dir, $cache_expiration_days, $force_refresh);
            if ($local_font_url) {
                $css_content = str_replace($font_url, $local_font_url, $css_content);
            }
        }

        return $css_content;
    }

    private function download_font_file($url, $font_dir, $font_url_dir, $cache_expiration_days, $force_refresh) {
        if (!$this->initialize_filesystem()) {
            return false;
        }

        if (!wp_http_validate_url($url)) {
            $this->log_error(sprintf(__('Invalid font URL: %s', 'self-host-assets'), esc_url_raw($url)));
            return false;
        }

        $extension = pathinfo($url, PATHINFO_EXTENSION);
        if (empty($extension)) {
            $this->log_error(sprintf(__('Cannot determine file extension for font URL: %s', 'self-host-assets'), esc_url_raw($url)));
            return false;
        }

        $filename = md5($url) . '.' . $extension;
        $file_path = $font_dir . $filename;
        $file_url = $font_url_dir . $filename;

        // Check if the file exists and if it's still fresh
        if ($this->is_file_fresh($file_path, $cache_expiration_days) && !$force_refresh) {
            $file_url = add_query_arg('ver', filemtime($file_path), $file_url);
            return $file_url;
        }

        // Download the font file
        $response = wp_remote_get($url, ['timeout' => 30, 'redirection' => 5]);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->log_error(sprintf(__('Failed to download font file: %s - %s', 'self-host-assets'), esc_url_raw($url), $error_message));
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code >= 400) {
            $this->log_error(sprintf(__('HTTP Error %d while downloading font file: %s', 'self-host-assets'), $response_code, esc_url_raw($url)));
            return false;
        }

        $file_content = wp_remote_retrieve_body($response);

        if (empty($file_content)) {
            $this->log_error(sprintf(__('Empty font file content: %s', 'self-host-assets'), esc_url_raw($url)));
            return false;
        }

        // MIME type verification
        $headers = wp_remote_retrieve_headers($response);
        $content_type = isset($headers['content-type']) ? $headers['content-type'] : '';
        $allowed_types = $this->get_allowed_mime_types('font');

        if (!in_array($content_type, $allowed_types, true)) {
            $this->log_error(sprintf(__('Invalid content type for font file: %s - %s', 'self-host-assets'), esc_url_raw($url), esc_html($content_type)));
            return false;
        }

        // Save the file
        if (!$this->wp_filesystem()->is_dir($font_dir)) {
            if (!$this->wp_filesystem()->mkdir($font_dir, FS_CHMOD_DIR)) {
                $this->log_error(sprintf(__('Failed to create directory: %s', 'self-host-assets'), esc_html($font_dir)));
                return false;
            }
            // Create a marker file to indicate ownership
            $this->wp_filesystem()->put_contents($font_dir . '.self-host-assets', 'Plugin marker file', FS_CHMOD_FILE);
        }

        $saved = $this->wp_filesystem()->put_contents($file_path, $file_content, FS_CHMOD_FILE);

        if (!$saved) {
            $this->log_error(sprintf(__('Failed to save font file: %s. Please check file permissions.', 'self-host-assets'), esc_html($file_path)));
            return false;
        }

        $file_url = add_query_arg('ver', filemtime($file_path), $file_url);

        return $file_url;
    }

    /**
     * Extracts font URLs from CSS content.
     */
    private function get_font_urls($css_content) {
        $font_urls = [];
        // Optimized regex: non-greedy matching and minimal capturing
        preg_match_all('/url\(\s*["\']?([^"\')]+)\s*["\']?\)/i', $css_content, $matches);

        if (!empty($matches[1])) {
            foreach ($matches[1] as $url) {
                $url = trim($url, '\'"');
                if (preg_match('/\.(woff2?|ttf|otf|eot|svg)(\?|#|$)/i', $url)) {
                    $font_urls[] = $url;
                }
            }
        }

        return array_unique($font_urls);
    }

    /**
     * Converts a relative URL to an absolute URL based on a base URL.
     */
    private function make_absolute_url($relative_url, $base_url) {
        // If the URL is already absolute, return it
        if (parse_url($relative_url, PHP_URL_SCHEME) !== null) {
            return $relative_url;
        }

        // Handle protocol-relative URLs
        if (strpos($relative_url, '//') === 0) {
            $scheme = parse_url($base_url, PHP_URL_SCHEME);
            return $scheme . ':' . $relative_url;
        }

        // Use wp_make_link_relative to handle relative URLs
        return wp_normalize_path(trailingslashit(dirname($base_url)) . ltrim($relative_url, '/'));
    }

    // Settings page for enabling/disabling self-hosting CSS, fonts, JavaScript, and cache control
    public function add_settings_page() {
        add_options_page(
            __('Self-Host Assets Settings', 'self-host-assets'),
            __('Self-Host Assets', 'self-host-assets'),
            'manage_options',
            'self-host-assets',
            [$this, 'render_settings_page']
        );
    }

    public function render_settings_page() {
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
                <?php wp_nonce_field('force_refresh_nonce', 'force_refresh_nonce_field'); ?>
                <?php
                // Check if the form is submitted and verify nonce
                if (isset($_POST['force_refresh']) && check_admin_referer('force_refresh_nonce', 'force_refresh_nonce_field')) {
                    update_option('force_refresh', 1);
                    echo '<div class="notice notice-success"><p>' . __('Cache will be refreshed on the next scheduled run.', 'self-host-assets') . '</p></div>';
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

    public function register_settings() {
        register_setting('self_host_assets_settings_group', 'self_host_css', [
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
            'sanitize_callback' => [$this, 'sanitize_cache_expiration_days'],
            'default' => self::DEFAULT_CACHE_EXPIRATION_CSS,
        ]);

        register_setting('self_host_assets_settings_group', 'cache_expiration_days_fonts', [
            'type' => 'integer',
            'sanitize_callback' => [$this, 'sanitize_cache_expiration_days'],
            'default' => self::DEFAULT_CACHE_EXPIRATION_FONTS,
        ]);

        register_setting('self_host_assets_settings_group', 'cache_expiration_days_js', [
            'type' => 'integer',
            'sanitize_callback' => [$this, 'sanitize_cache_expiration_days'],
            'default' => self::DEFAULT_CACHE_EXPIRATION_JS,
        ]);

        register_setting('self_host_assets_settings_group', 'force_refresh', [
            'type' => 'boolean',
            'sanitize_callback' => 'absint',
            'default' => 0,
        ]);

        register_setting('self_host_assets_settings_group', 'cron_schedule', [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_cron_schedule'],
            'default' => 'daily',
        ]);

        add_settings_section('self_host_assets_section', __('Settings', 'self-host-assets'), null, 'self_host_assets_settings');

        add_settings_field(
            'self_host_css',
            __('Enable Self-Host for CSS', 'self-host-assets'),
            [$this, 'render_css_field'],
            'self_host_assets_settings',
            'self_host_assets_section'
        );

        add_settings_field(
            'self_host_js',
            __('Enable Self-Host for JavaScript', 'self-host-assets'),
            [$this, 'render_js_field'],
            'self_host_assets_settings',
            'self_host_assets_section'
        );

        add_settings_field(
            'cache_expiration_days_css',
            __('Cache Expiration for CSS (Days)', 'self-host-assets'),
            [$this, 'render_cache_expiration_css_field'],
            'self_host_assets_settings',
            'self_host_assets_section'
        );

        add_settings_field(
            'cache_expiration_days_fonts',
            __('Cache Expiration for Fonts (Days)', 'self-host-assets'),
            [$this, 'render_cache_expiration_fonts_field'],
            'self_host_assets_settings',
            'self_host_assets_section'
        );

        add_settings_field(
            'cache_expiration_days_js',
            __('Cache Expiration for JavaScript (Days)', 'self-host-assets'),
            [$this, 'render_cache_expiration_js_field'],
            'self_host_assets_settings',
            'self_host_assets_section'
        );

        add_settings_field(
            'cron_schedule',
            __('Cron Schedule', 'self-host-assets'),
            [$this, 'render_cron_schedule_field'],
            'self_host_assets_settings',
            'self_host_assets_section'
        );
    }

    public function sanitize_cache_expiration_days($value) {
        $value = absint($value);
        if ($value < 1) {
            $value = 1;
        } elseif ($value > 365) {
            $value = 365;
        }
        return $value;
    }

    public function sanitize_cron_schedule($value) {
        $valid = ['hourly', 'twicedaily', 'daily'];
        if (!in_array($value, $valid, true)) {
            $value = 'daily';
        }
        return $value;
    }

    public function render_css_field() {
        $self_host_css = get_option('self_host_css', 1);
        ?>
        <input type="checkbox" id="self_host_css" name="self_host_css" value="1" <?php checked(1, $self_host_css); ?> />
        <label for="self_host_css"><?php _e('Enable', 'self-host-assets'); ?></label>
        <?php
    }

    public function render_js_field() {
        $self_host_js = get_option('self_host_js', 0);
        ?>
        <input type="checkbox" id="self_host_js" name="self_host_js" value="1" <?php checked(1, $self_host_js); ?> />
        <label for="self_host_js"><?php _e('Enable', 'self-host-assets'); ?></label>
        <?php
    }

    public function render_cache_expiration_css_field() {
        $cache_expiration_days = intval(get_option('cache_expiration_days_css', self::DEFAULT_CACHE_EXPIRATION_CSS));
        ?>
        <input type="number" name="cache_expiration_days_css" value="<?php echo esc_attr($cache_expiration_days); ?>" min="1" max="365" />
        <p class="description"><?php _e('Set the number of days after which cached CSS files should be refreshed.', 'self-host-assets'); ?></p>
        <?php
    }

    public function render_cache_expiration_fonts_field() {
        $cache_expiration_days = intval(get_option('cache_expiration_days_fonts', self::DEFAULT_CACHE_EXPIRATION_FONTS));
        ?>
        <input type="number" name="cache_expiration_days_fonts" value="<?php echo esc_attr($cache_expiration_days); ?>" min="1" max="365" />
        <p class="description"><?php _e('Set the number of days after which cached font files should be refreshed.', 'self-host-assets'); ?></p>
        <?php
    }

    public function render_cache_expiration_js_field() {
        $cache_expiration_days = intval(get_option('cache_expiration_days_js', self::DEFAULT_CACHE_EXPIRATION_JS));
        ?>
        <input type="number" name="cache_expiration_days_js" value="<?php echo esc_attr($cache_expiration_days); ?>" min="1" max="365" />
        <p class="description"><?php _e('Set the number of days after which cached JavaScript files should be refreshed.', 'self-host-assets'); ?></p>
        <?php
    }

    public function render_cron_schedule_field() {
        $cron_schedule = get_option('cron_schedule', 'daily');
        ?>
        <select name="cron_schedule">
            <option value="hourly" <?php selected($cron_schedule, 'hourly'); ?>><?php _e('Hourly', 'self-host-assets'); ?></option>
            <option value="twicedaily" <?php selected($cron_schedule, 'twicedaily'); ?>><?php _e('Twice Daily', 'self-host-assets'); ?></option>
            <option value="daily" <?php selected($cron_schedule, 'daily'); ?>><?php _e('Daily', 'self-host-assets'); ?></option>
        </select>
        <p class="description"><?php _e('Select how often to refresh cached assets.', 'self-host-assets'); ?></p>
        <?php
    }

    public function reschedule_cron_event($old_value, $new_value) {
        if ($old_value !== $new_value) {
            $timestamp = wp_next_scheduled('self_host_assets_cron_event');
            if ($timestamp) {
                wp_unschedule_event($timestamp, 'self_host_assets_cron_event');
            }
            wp_schedule_event(time(), $new_value, 'self_host_assets_cron_event');
        }
    }

    // Function to log errors and display them in admin notices
    private function log_error($message) {
        self::$errors[] = $message;
    }

    public function display_admin_notices() {
        if (!empty(self::$errors)) {
            foreach (self::$errors as $error) {
                echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
            }
            // Reset errors after displaying
            self::$errors = [];
        }
    }

    // Cleanup cached files when the plugin is uninstalled
    public static function uninstall() {
        $instance = self::get_instance();
        if (!$instance->initialize_filesystem()) {
            return;
        }

        $upload_dir = wp_upload_dir();
        $instance->remove_directory(trailingslashit($upload_dir['basedir']) . 'self-hosted-css/');
        $instance->remove_directory(trailingslashit($upload_dir['basedir']) . 'self-hosted-fonts/');
        $instance->remove_directory(trailingslashit($upload_dir['basedir']) . 'self-hosted-js/');
    }

    // Utility function to remove a directory and its files
    private function remove_directory($dir) {
        if (!$this->wp_filesystem()->is_dir($dir)) {
            return;
        }

        // Check for marker file before deletion
        if ($this->wp_filesystem()->exists($dir . '.self-host-assets')) {
            $this->wp_filesystem()->delete($dir, true);
        }
    }

    private function get_allowed_mime_types($type) {
        switch ($type) {
            case 'css':
                return ['text/css', 'text/plain'];
            case 'js':
                return [
                    'application/javascript',
                    'text/javascript',
                    'application/x-javascript',
                    'application/ecmascript',
                    'text/ecmascript',
                ];
            case 'font':
                return [
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
                    'image/svg+xml', // For SVG fonts
                ];
            default:
                return [];
        }
    }

    /**
     * Retrieves the local file path for a given external URL and asset type.
     */
    private function get_local_file_path($url, $type) {
        $upload_dir = wp_upload_dir();
        $sub_dir = 'self-hosted-' . $type . '/';
        $filename = md5($url) . '.' . $type;
        $file_path = trailingslashit($upload_dir['basedir']) . $sub_dir . $filename;
        return $file_path;
    }

    public function add_cron_schedules($schedules) {
        $schedules['hourly'] = [
            'interval' => HOUR_IN_SECONDS,
            'display'  => __('Once Hourly', 'self-host-assets'),
        ];
        $schedules['twicedaily'] = [
            'interval' => 12 * HOUR_IN_SECONDS,
            'display'  => __('Twice Daily', 'self-host-assets'),
        ];
        return $schedules;
    }

    // Enqueue styles for admin settings page
    public function enqueue_admin_styles($hook) {
        if ('settings_page_self-host-assets' !== $hook) {
            return;
        }
        wp_enqueue_style('self-host-assets-admin', plugin_dir_url(__FILE__) . 'admin.css', [], '1.0.0');
    }
}

// Initialize the plugin
SelfHostAssets::get_instance();

// Register uninstall hook
register_uninstall_hook(__FILE__, ['SelfHostAssets', 'uninstall']);
