<?php
/*
Plugin Name: Self-Host Third-Party CSS and Fonts
Description: Downloads and self-hosts third-party CSS and fonts with settings for enabling/disabling functionality, cache control, and cleanup.
Version: 1.2
Author: Your Name
Text Domain: self-host-css-fonts
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class SelfHostCSSFonts {

    // Cache expiration time in days
    const DEFAULT_CACHE_EXPIRATION = 7;

    // Array to store error messages
    private static $errors = array();

    public static function init() {
        // Hook into 'wp_enqueue_scripts' to process CSS and fonts
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

        if (!$self_host_css_enabled && !$self_host_fonts_enabled) {
            return;
        }

        // Get all enqueued styles
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
            $local_url = self::download_and_replace($src, $self_host_fonts_enabled);

            if ($local_url) {
                // Deregister the original style and register the new one
                wp_deregister_style($handle);
                wp_register_style($handle, $local_url, $style->deps, $style->ver, $style->media);
                wp_enqueue_style($handle);
            }
        }
    }

    private static function download_and_replace($url, $process_fonts = false) {
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once(ABSPATH . '/wp-admin/includes/file.php');
            if (!WP_Filesystem()) {
                self::log_error(__('Failed to initialize the WordPress Filesystem API.', 'self-host-css-fonts'));
                return false;
            }
        }

        $upload_dir = wp_upload_dir();
        $css_dir = trailingslashit($upload_dir['basedir']) . 'self-hosted-css/';
        $css_url_dir = trailingslashit($upload_dir['baseurl']) . 'self-hosted-css/';
        $filename = md5($url) . '.css';
        $file_path = $css_dir . $filename;
        $file_url = $css_url_dir . $filename;

        // Get cache expiration setting
        $cache_expiration_days = intval(get_option('cache_expiration_days', self::DEFAULT_CACHE_EXPIRATION));

        // Check if the file exists and if it's still fresh
        if ($wp_filesystem->exists($file_path)) {
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
            self::log_error(__('Failed to download CSS file:', 'self-host-css-fonts') . ' ' . esc_url_raw($url) . ' - ' . $response->get_error_message());
            return false;
        }

        $file_content = wp_remote_retrieve_body($response);

        if (empty($file_content)) {
            self::log_error(__('Empty CSS file content:', 'self-host-css-fonts') . ' ' . esc_url_raw($url));
            return false;
        }

        // MIME type verification
        $headers = wp_remote_retrieve_headers($response);
        $content_type = isset($headers['content-type']) ? $headers['content-type'] : '';
        $allowed_types = array('text/css', 'text/plain');
        if (!in_array($content_type, $allowed_types)) {
            self::log_error(__('Invalid content type for CSS file:', 'self-host-css-fonts') . ' ' . esc_url_raw($url) . ' - ' . esc_html($content_type));
            return false;
        }

        // Optionally process fonts in the CSS
        if ($process_fonts) {
            $file_content = self::process_font_urls($file_content, $url);
        }

        // Save the file
        if (!$wp_filesystem->is_dir($css_dir)) {
            if (!$wp_filesystem->mkdir($css_dir, FS_CHMOD_DIR)) {
                self::log_error(__('Failed to create directory:', 'self-host-css-fonts') . ' ' . esc_html($css_dir));
                return false;
            }
        }

        $saved = $wp_filesystem->put_contents($file_path, $file_content, FS_CHMOD_FILE);

        if (!$saved) {
            self::log_error(__('Failed to save CSS file:', 'self-host-css-fonts') . ' ' . esc_html($file_path) . '. ' . __('Please check file permissions.', 'self-host-css-fonts'));
            return false;
        }

        return $file_url;
    }

    private static function process_font_urls($css_content, $css_url) {
        // Find all font URLs in the CSS
        $font_urls = self::get_font_urls($css_content);

        if (empty($font_urls)) {
            return $css_content;
        }

        $upload_dir = wp_upload_dir();
        $font_dir = trailingslashit($upload_dir['basedir']) . 'self-hosted-fonts/';
        $font_url_dir = trailingslashit($upload_dir['baseurl']) . 'self-hosted-fonts/';

        // Download each font and replace URLs in CSS content
        foreach ($font_urls as $font_url) {
            $absolute_font_url = self::make_absolute_url($font_url, $css_url);
            $local_font_url = self::download_font_file($absolute_font_url, $font_dir, $font_url_dir);
            if ($local_font_url) {
                $css_content = str_replace($font_url, $local_font_url, $css_content);
            }
        }

        return $css_content;
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

    private static function download_font_file($url, $font_dir, $font_url_dir) {
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once(ABSPATH . '/wp-admin/includes/file.php');
            if (!WP_Filesystem()) {
                self::log_error(__('Failed to initialize the WordPress Filesystem API.', 'self-host-css-fonts'));
                return false;
            }
        }

        $filename = md5($url) . '.' . pathinfo($url, PATHINFO_EXTENSION);
        $file_path = $font_dir . $filename;
        $file_url = $font_url_dir . $filename;

        // Get cache expiration setting
        $cache_expiration_days = intval(get_option('cache_expiration_days', self::DEFAULT_CACHE_EXPIRATION));

        // Check if the file exists and if it's still fresh
        if ($wp_filesystem->exists($file_path)) {
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
            self::log_error(__('Failed to download font file:', 'self-host-css-fonts') . ' ' . esc_url_raw($url) . ' - ' . $response->get_error_message());
            return false;
        }

        $file_content = wp_remote_retrieve_body($response);

        if (empty($file_content)) {
            self::log_error(__('Empty font file content:', 'self-host-css-fonts') . ' ' . esc_url_raw($url));
            return false;
        }

        // MIME type verification
        $headers = wp_remote_retrieve_headers($response);
        $content_type = isset($headers['content-type']) ? $headers['content-type'] : '';
        $allowed_types = array(
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
        if (!in_array($content_type, $allowed_types)) {
            self::log_error(__('Invalid content type for font file:', 'self-host-css-fonts') . ' ' . esc_url_raw($url) . ' - ' . esc_html($content_type));
            return false;
        }

        // Save the file
        if (!$wp_filesystem->is_dir($font_dir)) {
            if (!$wp_filesystem->mkdir($font_dir, FS_CHMOD_DIR)) {
                self::log_error(__('Failed to create directory:', 'self-host-css-fonts') . ' ' . esc_html($font_dir));
                return false;
            }
        }

        $saved = $wp_filesystem->put_contents($file_path, $file_content, FS_CHMOD_FILE);

        if (!$saved) {
            self::log_error(__('Failed to save font file:', 'self-host-css-fonts') . ' ' . esc_html($file_path) . '. ' . __('Please check file permissions.', 'self-host-css-fonts'));
            return false;
        }

        return $file_url;
    }

    // Settings page for enabling/disabling self-hosting CSS and fonts and cache control
    public static function add_settings_page() {
        add_options_page(
            __('Self-Host CSS & Fonts Settings', 'self-host-css-fonts'),
            __('Self-Host CSS & Fonts', 'self-host-css-fonts'),
            'manage_options',
            'self-host-css-fonts',
            [__CLASS__, 'render_settings_page']
        );
    }

    public static function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Self-Host CSS & Fonts Settings', 'self-host-css-fonts'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('self_host_css_fonts_settings_group');
                do_settings_sections('self_host_css_fonts_settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public static function register_settings() {
        register_setting('self_host_css_fonts_settings_group', 'self_host_css', [
            'type' => 'boolean',
            'sanitize_callback' => 'absint',
            'default' => 1,
        ]);

        register_setting('self_host_css_fonts_settings_group', 'self_host_fonts', [
            'type' => 'boolean',
            'sanitize_callback' => 'absint',
            'default' => 1,
        ]);

        register_setting('self_host_css_fonts_settings_group', 'cache_expiration_days', [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => self::DEFAULT_CACHE_EXPIRATION,
        ]);

        add_settings_section('self_host_css_fonts_section', __('Settings', 'self-host-css-fonts'), null, 'self_host_css_fonts_settings');

        add_settings_field(
            'self_host_css',
            __('Enable Self-Host for CSS', 'self-host-css-fonts'),
            [__CLASS__, 'render_css_field'],
            'self_host_css_fonts_settings',
            'self_host_css_fonts_section'
        );

        add_settings_field(
            'self_host_fonts',
            __('Enable Self-Host for Fonts', 'self-host-css-fonts'),
            [__CLASS__, 'render_fonts_field'],
            'self_host_css_fonts_settings',
            'self_host_css_fonts_section'
        );

        add_settings_field(
            'cache_expiration_days',
            __('Cache Expiration (Days)', 'self-host-css-fonts'),
            [__CLASS__, 'render_cache_expiration_field'],
            'self_host_css_fonts_settings',
            'self_host_css_fonts_section'
        );
    }

    public static function render_css_field() {
        $self_host_css = get_option('self_host_css', 1);
        ?>
        <input type="checkbox" name="self_host_css" value="1" <?php checked(1, $self_host_css); ?> />
        <label for="self_host_css"><?php _e('Enable', 'self-host-css-fonts'); ?></label>
        <?php
    }

    public static function render_fonts_field() {
        $self_host_fonts = get_option('self_host_fonts', 1);
        ?>
        <input type="checkbox" name="self_host_fonts" value="1" <?php checked(1, $self_host_fonts); ?> />
        <label for="self_host_fonts"><?php _e('Enable', 'self-host-css-fonts'); ?></label>
        <?php
    }

    public static function render_cache_expiration_field() {
        $cache_expiration_days = intval(get_option('cache_expiration_days', self::DEFAULT_CACHE_EXPIRATION));
        ?>
        <input type="number" name="cache_expiration_days" value="<?php echo esc_attr($cache_expiration_days); ?>" min="1" />
        <p class="description"><?php _e('Set the number of days after which cached CSS and font files should be refreshed.', 'self-host-css-fonts'); ?></p>
        <?php
    }

    // Function to log errors and display them in admin notices
    private static function log_error($message) {
        $errors = get_option('self_host_css_fonts_errors', array());
        $errors[] = $message;
        update_option('self_host_css_fonts_errors', $errors);
    }

    public static function display_admin_notices() {
        $errors = get_option('self_host_css_fonts_errors', array());
        if (!empty($errors)) {
            foreach ($errors as $error) {
                echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
            }
            delete_option('self_host_css_fonts_errors');
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
SelfHostCSSFonts::init();

// Register uninstall hook
register_uninstall_hook(__FILE__, ['SelfHostCSSFonts', 'uninstall']);
