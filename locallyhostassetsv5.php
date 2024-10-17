<?php
/*
Plugin Name: Self-Host Third-Party CSS, Fonts, and JavaScript
Description: Downloads and self-hosts third-party CSS, fonts, and JavaScript files with settings for enabling/disabling functionality, cache control, and cleanup.
Version: 1.9.1
Author: Jazir5
Text Domain: self-host-assets
Domain Path: /languages
*/

defined('ABSPATH') || exit; // Exit if accessed directly

// Use a namespace to avoid naming conflicts
namespace SelfHostAssetsPlugin;

use WP_Error;
use Exception;

/**
 * WP_Background_Process Class
 *
 * A base class for handling background processing.
 */
abstract class WP_Background_Process {

    /**
     * Action name for the background process.
     *
     * @var string
     */
    protected $action = '';

    /**
     * Identifier for the background process.
     *
     * @var string
     */
    protected $identifier = '';

    /**
     * Data queue.
     *
     * @var array
     */
    protected $queue = [];

    /**
     * Constructor.
     *
     * @throws Exception If the action is not set.
     */
    public function __construct() {
        if (empty($this->action)) {
            throw new \Exception(__('You must set the action for the background process.', 'self-host-assets'));
        }

        $this->identifier = 'wp_' . $this->action;

        add_action($this->identifier, [$this, 'process_queue']);
    }

    /**
     * Push data to the queue.
     *
     * @param mixed $data Data.
     * @return $this
     */
    public function push_to_queue($data) {
        $this->queue[] = $data;
        return $this;
    }

    /**
     * Save the queue to the database.
     *
     * @return $this
     */
    public function save() {
        set_transient($this->identifier, $this->queue, HOUR_IN_SECONDS);
        $this->queue = [];
        return $this;
    }

    /**
     * Dispatch the queue.
     */
    public function dispatch() {
        if (!wp_next_scheduled($this->identifier)) {
            wp_schedule_single_event(time(), $this->identifier, []);
        }
    }

    /**
     * Process the queue.
     */
    public function process_queue() {
        $this->queue = get_transient($this->identifier);
        if (!$this->queue) {
            return;
        }

        foreach ($this->queue as $item) {
            $continue = $this->task($item);
            if ($continue === false) {
                continue; // Remove the task from the queue
            }
        }

        delete_transient($this->identifier);

        $this->complete();
    }

    /**
     * Task to handle each queued item.
     *
     * @param mixed $item The queued item.
     * @return bool False if the task should be removed from the queue, true otherwise.
     */
    abstract protected function task($item);

    /**
     * Complete the background process.
     */
    protected function complete() {
        // This method can be overridden to perform actions when the queue is complete.
    }
}

/**
 * Asset_Process Class
 *
 * Handles background processing of assets.
 */
class Asset_Process extends WP_Background_Process {

    /**
     * Action name for the background process.
     *
     * @var string
     */
    protected $action = 'self_host_assets_background_process';

    /**
     * Main plugin instance.
     *
     * @var SelfHostAssets
     */
    protected $main_plugin;

    /**
     * Set the main plugin instance.
     *
     * @param SelfHostAssets $plugin The main plugin instance.
     * @return void
     */
    public function set_main_plugin($plugin) {
        $this->main_plugin = $plugin;
    }

    /**
     * Task to handle each queued item.
     *
     * @param array $item The queued item.
     *
     * @return bool False if the task should be removed from the queue, true otherwise.
     */
    protected function task($item) {
        if (!isset($item['type']) || !isset($item['url'])) {
            // Invalid task
            return false;
        }

        $type = sanitize_text_field($item['type']);
        $url = esc_url_raw($item['url']);
        $force_refresh = isset($item['force_refresh']) ? boolval($item['force_refresh']) : false;
        $current_depth = isset($item['current_depth']) ? intval($item['current_depth']) : 0;
        $retry_count = isset($item['retry_count']) ? intval($item['retry_count']) : 0;
        $max_retries = isset($item['max_retries']) ? intval($item['max_retries']) : 3; // Default max retries

        try {
            switch ($type) {
                case 'css':
                    $this->main_plugin->download_and_replace_css($url, $force_refresh, $current_depth);
                    break;
                case 'js':
                    $this->main_plugin->download_and_replace_js($url, $force_refresh);
                    break;
                case 'font':
                    // Handle additional asset types if needed
                    $this->main_plugin->download_font_file($url, 'font', $force_refresh);
                    break;
                default:
                    // Unsupported type
                    $this->main_plugin->log_error(sprintf(__('Unsupported asset type: %s', 'self-host-assets'), esc_html($type)), 'warning');
                    return false;
            }
        } catch (Exception $e) {
            // Log the exception
            $this->main_plugin->log_error($e->getMessage());

            // Retry logic
            if ($retry_count < $max_retries) {
                // Increment retry count and re-queue the task
                $item['retry_count'] = $retry_count + 1;
                $this->push_to_queue($item);
                $this->save();
                $this->dispatch();
                return true; // Keep the task in the queue for retry
            } else {
                // Max retries reached, log failure
                $this->main_plugin->log_error(sprintf(__('Max retries reached for asset: %s', 'self-host-assets'), esc_url_raw($url)), 'warning');
                return false; // Remove the task from the queue
            }
        }

        // Return false to remove the task from the queue
        return false;
    }

    /**
     * Complete the background process.
     */
    protected function complete() {
        parent::complete();
        // Notify the main plugin that processing is complete
        if (method_exists($this->main_plugin, 'on_background_process_complete')) {
            $this->main_plugin->on_background_process_complete();
        }
    }
}

/**
 * SelfHostAssets Class
 *
 * Main plugin class handling the self-hosting of third-party assets.
 */
class SelfHostAssets {

    // Plugin version
    const VERSION = '1.9.1';

    // Default cache expiration times in days
    const DEFAULT_CACHE_EXPIRATION_CSS   = 7;
    const DEFAULT_CACHE_EXPIRATION_FONTS = 30;
    const DEFAULT_CACHE_EXPIRATION_JS    = 7;

    // Maximum depth for nested @import statements
    const MAX_IMPORT_DEPTH = 5;

    // Maximum retries for failed downloads
    const MAX_RETRIES = 3;

    /**
     * Singleton instance
     *
     * @var SelfHostAssets|null
     */
    private static $instance = null;

    /**
     * Error storage
     *
     * @var array
     */
    private $errors = [];

    /**
     * Database table name for logs
     *
     * @var string
     */
    private $log_table;

    /**
     * Database table name for URL mappings
     *
     * @var string
     */
    private $mapping_table;

    /**
     * Background Process Instance
     *
     * @var Asset_Process|null
     */
    private $background_process = null;

    /**
     * Upload directory information.
     *
     * @var array|null
     */
    private $upload_dir = null;

    /**
     * Flag to indicate if background processing is complete.
     *
     * @var bool
     */
    private $background_complete = false;

    /**
     * Singleton instance.
     *
     * @return SelfHostAssets
     */
    public static function get_instance() {
        if (is_null(self::$instance)) {
            self::$instance = new SelfHostAssets();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     *
     * Private to enforce Singleton pattern.
     */
    private function __construct() {
        global $wpdb;
        $prefix = is_multisite() ? $wpdb->get_blog_prefix() : $wpdb->prefix;
        $this->log_table     = $prefix . 'self_host_assets_logs';
        $this->mapping_table = $prefix . 'self_host_assets_mapping';

        // Initialize background process
        $this->initialize_background_process();

        // Initialize hooks
        $this->init_hooks();
    }

    // Prevent cloning and unserialization
    private function __clone() {}
    private function __wakeup() {}

    /**
     * Initialize WordPress hooks and actions.
     *
     * @return void
     */
    private function init_hooks() {
        // Load plugin text domain for translations
        add_action('plugins_loaded', [$this, 'load_textdomain']);

        // Hook into 'wp_enqueue_scripts' to enqueue cached CSS, fonts, and JavaScript
        add_action('wp_enqueue_scripts', [$this, 'self_host_resources'], 20);

        // Add settings pages
        add_action('admin_menu', [$this, 'add_settings_pages']);

        // Register settings
        add_action('admin_init', [$this, 'register_settings']);

        // Display admin notices for errors
        add_action('admin_notices', [$this, 'display_admin_notices']);

        // Register activation and deactivation hooks with fully qualified class names
        register_activation_hook(__FILE__, ['SelfHostAssetsPlugin\SelfHostAssets', 'activate_plugin']);
        register_deactivation_hook(__FILE__, ['SelfHostAssetsPlugin\SelfHostAssets', 'deactivate_plugin']);

        // Add cron event hook
        add_action('self_host_assets_cron_event', [$this, 'cron_process_resources']);

        // Reschedule cron event when schedule changes
        add_action('update_option_cron_schedule', [$this, 'reschedule_cron_event'], 10, 2);

        // Handle force refresh action
        add_action('admin_post_force_refresh', [$this, 'handle_force_refresh']);

        // Add custom cron schedules
        add_filter('cron_schedules', [$this, 'add_custom_cron_schedule']);

        // Ensure compatibility with Multisite installations
        if (is_multisite()) {
            add_action('network_admin_menu', [$this, 'add_settings_pages']);
        }
    }

    /**
     * Load plugin textdomain for translations.
     *
     * @return void
     */
    public function load_textdomain() {
        load_plugin_textdomain('self-host-assets', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }

    /**
     * Activation hook to schedule the cron event and process resources.
     *
     * @return void
     */
    public static function activate_plugin() {
        $instance = self::get_instance();
        $instance->create_logs_table();
        $instance->create_mapping_table(); // Create mapping table
        $instance->activate();
    }

    /**
     * Deactivation hook to clear the scheduled cron event.
     *
     * @return void
     */
    public static function deactivate_plugin() {
        $instance = self::get_instance();
        $instance->deactivate();
    }

    /**
     * Activation method to schedule the cron event and process resources.
     *
     * @return void
     */
    public function activate() {
        // Schedule the cron event if not already scheduled
        $custom_schedule = get_option('cron_schedule', 'daily');
        if (!wp_next_scheduled('self_host_assets_cron_event')) {
            wp_schedule_event(time(), $custom_schedule, 'self_host_assets_cron_event');
        }

        // Initialize the WordPress Filesystem API
        if (!$this->initialize_filesystem()) {
            $this->log_error(__('Failed to initialize the WordPress Filesystem API during activation.', 'self-host-assets'));
            return;
        }

        // Process all resources upon activation with force_refresh
        $this->process_all_resources(true);
    }

    /**
     * Deactivation method to clear the scheduled cron event.
     *
     * @return void
     */
    public function deactivate() {
        // Clear the scheduled cron event
        $timestamp = wp_next_scheduled('self_host_assets_cron_event');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'self_host_assets_cron_event');
        }
    }

    /**
     * Create logs table in the database.
     *
     * @return void
     */
    public function create_logs_table() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->log_table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            timestamp datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            message text NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_timestamp (timestamp)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Create mapping table in the database.
     *
     * @return void
     */
    public function create_mapping_table() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->mapping_table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            original_url text NOT NULL,
            hashed_filename varchar(255) NOT NULL,
            type varchar(10) NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY hashed_filename_unique (hashed_filename, type),
            KEY idx_type_original_url (type, original_url(255))
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Cron job function to process resources.
     *
     * @return void
     */
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

    /**
     * Initialize background process.
     *
     * @return void
     */
    public function initialize_background_process() {
        // Initialize the background process
        $this->background_process = new Asset_Process();
        $this->background_process->set_main_plugin($this);
    }

    /**
     * Main function to self-host resources.
     *
     * @return void
     */
    public function self_host_resources() {
        $self_host_css_enabled = get_option('self_host_css', 1);
        $self_host_js_enabled  = get_option('self_host_js', 0);

        // Enqueue cached CSS and Fonts
        if ($self_host_css_enabled) {
            $this->enqueue_cached_styles();
        }

        // Enqueue cached JavaScript
        if ($self_host_js_enabled) {
            $this->enqueue_cached_scripts();
        }
    }

    /**
     * Process all resources, optionally forcing a refresh.
     *
     * @param bool $force_refresh Whether to force refresh cached files.
     *
     * @return void
     */
    private function process_all_resources($force_refresh = false) {
        $self_host_css_enabled = get_option('self_host_css', 1);
        $self_host_js_enabled  = get_option('self_host_js', 0);

        if ($self_host_css_enabled) {
            $this->process_all_styles($force_refresh);
        }

        if ($self_host_js_enabled) {
            $this->process_all_scripts($force_refresh);
        }
    }

    /**
     * Process all stylesheets in the queue.
     *
     * @param bool $force_refresh Whether to force refresh cached files.
     *
     * @return void
     */
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
            if (!$this->is_external_url($src)) {
                continue; // Skip local styles
            }

            if (!wp_http_validate_url($src)) {
                $this->log_error(sprintf(__('Invalid URL: %s', 'self-host-assets'), esc_url_raw($src)));
                continue;
            }

            // Queue the stylesheet for background processing with current_depth = 0 and retry_count = 0
            $this->background_process->push_to_queue([
                'type'          => 'css',
                'url'           => $src,
                'force_refresh' => $force_refresh,
                'current_depth' => 0, // Initialize recursion depth
                'retry_count'   => 0, // Initialize retry count
                'max_retries'   => self::MAX_RETRIES,
            ]);
        }

        // Dispatch the queue
        $this->background_process->save()->dispatch();
    }

    /**
     * Process all scripts in the queue.
     *
     * @param bool $force_refresh Whether to force refresh cached files.
     *
     * @return void
     */
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
            if (!$this->is_external_url($src)) {
                continue; // Skip local scripts
            }

            if (!wp_http_validate_url($src)) {
                $this->log_error(sprintf(__('Invalid URL: %s', 'self-host-assets'), esc_url_raw($src)));
                continue;
            }

            // Queue the script for background processing with retry_count = 0
            $this->background_process->push_to_queue([
                'type'          => 'js',
                'url'           => $src,
                'force_refresh' => $force_refresh,
                'retry_count'   => 0, // Initialize retry count
                'max_retries'   => self::MAX_RETRIES,
            ]);
        }

        // Dispatch the queue
        $this->background_process->save()->dispatch();
    }

    /**
     * Download and process a CSS file.
     *
     * @param string $url           The URL of the CSS file.
     * @param bool   $force_refresh Whether to force refresh cached files.
     * @param int    $current_depth Current recursion depth.
     *
     * @return string|false The local URL of the cached CSS file or false on failure.
     */
    public function download_and_replace_css($url, $force_refresh = false, $current_depth = 0) {
        $cache_expiration_days = intval(get_option('cache_expiration_days_css', self::DEFAULT_CACHE_EXPIRATION_CSS));
        $file_url              = $this->download_file($url, 'css', $cache_expiration_days, $force_refresh);

        if ($file_url) {
            $file_path = $this->get_local_file_path($url, 'css');

            if ($this->initialize_filesystem() && $GLOBALS['wp_filesystem']->exists($file_path)) {
                $file_content = $GLOBALS['wp_filesystem']->get_contents($file_path);

                if ($file_content !== false) {
                    $processed_urls  = [];
                    $updated_content = $this->process_css_content($file_content, $url, $processed_urls, $force_refresh, $current_depth + 1); // Increment depth

                    $result = $GLOBALS['wp_filesystem']->put_contents($file_path, $updated_content, FS_CHMOD_FILE);

                    if ($result === false) {
                        $this->log_error(sprintf(__('Failed to write updated CSS content to file: %s', 'self-host-assets'), esc_html($file_path)));
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

    /**
     * Download and process a JavaScript file.
     *
     * @param string $url           The URL of the JavaScript file.
     * @param bool   $force_refresh Whether to force refresh cached files.
     *
     * @return string|false The local URL of the cached JavaScript file or false on failure.
     */
    public function download_and_replace_js($url, $force_refresh = false) {
        $cache_expiration_days = intval(get_option('cache_expiration_days_js', self::DEFAULT_CACHE_EXPIRATION_JS));
        return $this->download_file($url, 'js', $cache_expiration_days, $force_refresh);
    }

    /**
     * Download a file and save it locally.
     *
     * @param string $url                  The URL of the file.
     * @param string $type                 The type of the file ('css', 'js', 'font', 'image').
     * @param int    $cache_expiration_days Cache expiration time in days.
     * @param bool   $force_refresh        Whether to force refresh the cached file.
     *
     * @return string|false The local URL of the cached file or false on failure.
     */
    private function download_file($url, $type, $cache_expiration_days, $force_refresh) {
        global $wpdb, $wp_filesystem;

        if (!$this->initialize_filesystem()) {
            return false;
        }

        if (!wp_http_validate_url($url)) {
            $this->log_error(sprintf(__('Invalid URL: %s', 'self-host-assets'), esc_url_raw($url)));
            return false;
        }

        $upload_dir = $this->get_upload_dir();
        $sub_dir    = 'self-hosted-' . $type . '/';
        $dir        = trailingslashit($upload_dir['basedir']) . $sub_dir;
        $url_dir    = trailingslashit($upload_dir['baseurl']) . $sub_dir;
        $filename   = md5($url) . '.' . $type;
        $file_path  = $dir . $filename;
        $file_url   = $url_dir . $filename;

        // Check if the file exists and if it's still fresh
        if ($this->is_file_fresh($file_path, $cache_expiration_days) && !$force_refresh) {
            $file_mod_time = method_exists($wp_filesystem, 'mtime') ? $wp_filesystem->mtime($file_path) : filemtime($file_path);
            if ($file_mod_time !== false) {
                $file_url = add_query_arg('ver', $file_mod_time, $file_url);
            }
            return $file_url;
        }

        // Download the file with SSL verification
        $response = wp_remote_get($url, [
            'timeout'     => 30,
            'redirection' => 10,
            'user-agent'  => 'SelfHostAssetsPlugin/' . self::VERSION,
            'sslverify'   => true,
        ]);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            throw new Exception(sprintf(__('Failed to download %s file: %s - %s', 'self-host-assets'), strtoupper($type), esc_url_raw($url), $error_message));
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code >= 400) {
            throw new Exception(sprintf(__('HTTP Error %d while downloading %s file: %s', 'self-host-assets'), $response_code, strtoupper($type), esc_url_raw($url)));
        }

        $file_content = wp_remote_retrieve_body($response);

        if (empty($file_content)) {
            throw new Exception(sprintf(__('Empty %s file content: %s', 'self-host-assets'), strtoupper($type), esc_url_raw($url)));
        }

        // Save the file
        if (!$wp_filesystem->is_dir($dir)) {
            if (!$wp_filesystem->mkdir($dir, FS_CHMOD_DIR)) {
                throw new Exception(sprintf(__('Failed to create directory: %s', 'self-host-assets'), esc_html($dir)));
            }
            // Create a marker file to indicate ownership
            $wp_filesystem->put_contents($dir . '.self-host-assets', 'Plugin marker file', FS_CHMOD_FILE);
        }

        $saved = $wp_filesystem->put_contents($file_path, $file_content, FS_CHMOD_FILE);

        if (!$saved) {
            throw new Exception(sprintf(__('Failed to save %s file: %s. Please check file permissions.', 'self-host-assets'), strtoupper($type), esc_html($file_path)));
        }

        // MIME type verification using wp_check_filetype_and_ext
        $filetype_info = wp_check_filetype_and_ext($file_path, $filename);
        $allowed_types = $this->get_allowed_mime_types($type);

        if (!in_array($filetype_info['type'], $allowed_types, true)) {
            $this->log_error(sprintf(__('Invalid file type for %s: %s', 'self-host-assets'), strtoupper($type), esc_html($file_path)));
            // Remove the invalid file
            $wp_filesystem->delete($file_path);
            return false;
        }

        // Set strict file permissions
        $wp_filesystem->chmod($file_path, 0644);

        // Insert or replace mapping into the database using prepared statements
        $wpdb->replace(
            $this->mapping_table,
            [
                'original_url'    => $url,
                'hashed_filename' => $filename,
                'type'            => $type,
            ],
            [
                '%s',
                '%s',
                '%s',
            ]
        );

        // Ensure accurate versioning
        $file_mod_time = method_exists($wp_filesystem, 'mtime') ? $wp_filesystem->mtime($file_path) : filemtime($file_path);
        if ($file_mod_time !== false) {
            $file_url = add_query_arg('ver', $file_mod_time, $file_url);
        }

        return $file_url;
    }

    /**
     * Enqueue cached stylesheets.
     *
     * @return void
     */
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
            if (!$this->is_external_url($src)) {
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

    /**
     * Enqueue cached scripts.
     *
     * @return void
     */
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
            if (!$this->is_external_url($src)) {
                continue; // Skip local scripts
            }

            $local_url = $this->get_local_url($src, 'js');

            if ($local_url) {
                // Deregister the original script and register the new one
                wp_deregister_script($handle);
                wp_register_script($handle, $local_url, $script->deps, $script->ver, isset($script->extra['group']) ? $script->extra['group'] : false);
                wp_enqueue_script($handle);
            }
        }
    }

    /**
     * Get the local URL of a cached file.
     *
     * @param string $url  The external URL of the asset.
     * @param string $type The type of the asset ('css', 'js', 'font', 'image').
     *
     * @return string|false The local URL or false if not found.
     */
    private function get_local_url($url, $type) {
        global $wpdb;
        $upload_dir = $this->get_upload_dir();
        $sub_dir    = 'self-hosted-' . $type . '/';
        $url_dir    = trailingslashit($upload_dir['baseurl']) . $sub_dir;
        $filename   = md5($url) . '.' . $type;
        $file_url   = $url_dir . $filename;
        $file_path  = trailingslashit($upload_dir['basedir']) . $sub_dir . $filename;

        if ($this->initialize_filesystem() && $GLOBALS['wp_filesystem']->exists($file_path)) {
            // Ensure accurate versioning
            $file_mod_time = method_exists($GLOBALS['wp_filesystem'], 'mtime') ? $GLOBALS['wp_filesystem']->mtime($file_path) : filemtime($file_path);
            if ($file_mod_time !== false) {
                $file_url = add_query_arg('ver', $file_mod_time, $file_url);
            }
            return $file_url;
        }

        return false;
    }

    /**
     * Get the local file path based on the original URL and type.
     *
     * @param string $url  The original URL.
     * @param string $type The type of the asset.
     *
     * @return string The local file path.
     */
    private function get_local_file_path($url, $type) {
        $upload_dir = $this->get_upload_dir();
        $sub_dir    = 'self-hosted-' . $type . '/';
        $dir        = trailingslashit($upload_dir['basedir']) . $sub_dir;
        $filename   = md5($url) . '.' . $type;
        return $dir . $filename;
    }

    /**
     * Check if a file is fresh based on cache expiration settings.
     *
     * @param string $file_path             The path to the cached file.
     * @param int    $cache_expiration_days Cache expiration time in days.
     *
     * @return bool True if the file is fresh, false otherwise.
     */
    private function is_file_fresh($file_path, $cache_expiration_days) {
        if ($this->initialize_filesystem() && $GLOBALS['wp_filesystem']->exists($file_path)) {
            $file_mod_time   = method_exists($GLOBALS['wp_filesystem'], 'mtime') ? $GLOBALS['wp_filesystem']->mtime($file_path) : filemtime($file_path);
            $expiration_time = time() - ($cache_expiration_days * DAY_IN_SECONDS);
            if ($file_mod_time > $expiration_time) {
                // File is still fresh
                return true;
            }
        }

        return false;
    }

    /**
     * Initialize the WordPress Filesystem API.
     *
     * @return bool True on success, false on failure.
     */
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

    /**
     * Processes CSS content to handle @import statements and font URLs.
     *
     * @param string $css_content      The CSS content.
     * @param string $css_url          The URL of the CSS file.
     * @param array  &$processed_urls  An array of already processed URLs to avoid recursion.
     * @param bool   $force_refresh    Whether to force refresh cached files.
     * @param int    $current_depth     Current depth of nested imports.
     *
     * @return string The updated CSS content.
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
     *
     * @param string $css_content       The CSS content.
     * @param string $css_url           The URL of the CSS file.
     * @param array  &$processed_urls   An array of already processed URLs to avoid recursion.
     * @param bool   $force_refresh     Whether to force refresh cached files.
     * @param int    $current_depth      Current depth of nested imports.
     *
     * @return string The updated CSS content.
     */
    private function process_import_statements($css_content, $css_url, &$processed_urls, $force_refresh, $current_depth) {
        // Optimized regex: non-greedy matching and minimal capturing
        $pattern = '/@import\s+(?:url\(\s*)?(["\']?)([^"\')\s]+)\1(?:\s+([^{;]+))?\s*\)?\s*;/i';

        if (preg_match_all($pattern, $css_content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $import_url  = $match[2];
                $media_query = isset($match[3]) ? $match[3] : '';

                $absolute_import_url = $this->make_absolute_url($import_url, $css_url);

                // Queue the imported CSS for background processing with incremented current_depth
                $this->background_process->push_to_queue([
                    'type'          => 'css',
                    'url'           => $absolute_import_url,
                    'force_refresh' => $force_refresh,
                    'current_depth' => $current_depth + 1,
                    'retry_count'   => 0, // Initialize retry count
                    'max_retries'   => self::MAX_RETRIES,
                ]);
            }

            // Dispatch the queue once after processing all imports
            $this->background_process->save()->dispatch();

            // Retrieve the local URLs from the mapping
            foreach ($matches as $match) {
                $import_url  = $match[2];
                $media_query = isset($match[3]) ? $match[3] : '';

                $absolute_import_url = $this->make_absolute_url($import_url, $css_url);
                $local_import_url    = $this->get_local_url($absolute_import_url, 'css');

                if ($local_import_url) {
                    // Reconstruct the @import statement with the local URL and original media queries
                    $new_import = '@import url("' . esc_url($local_import_url) . '")';
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
     * Processes font URLs in CSS content.
     *
     * @param string $css_content    The CSS content.
     * @param string $css_url        The URL of the CSS file.
     * @param bool   $force_refresh  Whether to force refresh cached files.
     *
     * @return string The updated CSS content.
     */
    private function process_font_urls($css_content, $css_url, $force_refresh) {
        // Find all font URLs in the CSS
        $font_urls = $this->get_font_urls($css_content);

        if (empty($font_urls)) {
            return $css_content;
        }

        $upload_dir   = $this->get_upload_dir();
        $font_dir     = trailingslashit($upload_dir['basedir']) . 'self-hosted-fonts/';
        $font_url_dir = trailingslashit($upload_dir['baseurl']) . 'self-hosted-fonts/';

        // Get cache expiration setting
        $cache_expiration_days = intval(get_option('cache_expiration_days_fonts', self::DEFAULT_CACHE_EXPIRATION_FONTS));

        // Download each font and replace URLs in CSS content
        foreach ($font_urls as $font_url) {
            $absolute_font_url = $this->make_absolute_url($font_url, $css_url);
            $local_font_url    = $this->download_font_file($absolute_font_url, 'font', $force_refresh);
            if ($local_font_url) {
                $css_content = str_replace($font_url, $local_font_url, $css_content);
            }
        }

        return $css_content;
    }

    /**
     * Download and save a font file locally.
     *
     * @param string $url                   The URL of the font file.
     * @param string $type                  The type of the asset ('font').
     * @param bool   $force_refresh         Whether to force refresh cached files.
     *
     * @return string|false The local URL of the cached font file or false on failure.
     */
    public function download_font_file($url, $type, $force_refresh) {
        // Reuse the download_file method with appropriate type and extension handling
        return $this->download_file($url, $type, self::DEFAULT_CACHE_EXPIRATION_FONTS, $force_refresh);
    }

    /**
     * Extracts font URLs from CSS content.
     *
     * @param string $css_content The CSS content.
     *
     * @return array An array of unique font URLs.
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
     *
     * @param string $relative_url The relative URL found in the CSS.
     * @param string $base_url     The base URL of the CSS file.
     *
     * @return string The absolute URL.
     */
    private function make_absolute_url($relative_url, $base_url) {
        // If the URL is already absolute or protocol-relative, return it
        if (parse_url($relative_url, PHP_URL_SCHEME) !== null || strpos($relative_url, '//') === 0) {
            return $relative_url;
        }

        // Parse base URL
        $parsed_base = wp_parse_url($base_url);
        if (!isset($parsed_base['scheme']) || !isset($parsed_base['host'])) {
            // Invalid base URL
            return $relative_url;
        }

        $base_scheme = $parsed_base['scheme'];
        $base_host   = $parsed_base['host'];
        $base_port   = isset($parsed_base['port']) ? ':' . $parsed_base['port'] : '';
        $base_path   = isset($parsed_base['path']) ? $parsed_base['path'] : '/';

        // If the relative URL starts with '/', it's an absolute path on the same host
        if (strpos($relative_url, '/') === 0) {
            return $base_scheme . '://' . $base_host . $base_port . $relative_url;
        } else {
            // Remove filename from base path
            $base_path = rtrim(dirname($base_path), '/') . '/';

            // Resolve any '../' or './' in the relative URL
            $relative_url = preg_replace('/\/\.\//', '/', $relative_url);
            while (preg_match('/[^\/]+\/\.\.\//', $relative_url)) {
                $relative_url = preg_replace('/[^\/]+\/\.\.\//', '', $relative_url, 1);
            }

            // Build absolute URL
            return $base_scheme . '://' . $base_host . $base_port . $base_path . $relative_url;
        }
    }

    /**
     * Check if a URL is external.
     *
     * @param string $url The URL to check.
     *
     * @return bool True if external, false otherwise.
     */
    private function is_external_url($url) {
        $src_host  = wp_parse_url($url, PHP_URL_HOST);
        $home_host = wp_parse_url(home_url(), PHP_URL_HOST);

        return $src_host && $home_host && $src_host !== $home_host;
    }

    /**
     * Add custom cron schedules if needed.
     *
     * @param array $schedules Existing cron schedules.
     *
     * @return array Modified cron schedules.
     */
    public function add_custom_cron_schedule($schedules) {
        // Add a weekly schedule
        $schedules['weekly'] = [
            'interval' => WEEK_IN_SECONDS,
            'display'  => __('Once Weekly', 'self-host-assets'),
        ];
        return $schedules;
    }

    /**
     * Add settings pages for the plugin.
     *
     * @return void
     */
    public function add_settings_pages() {
        // Main settings page
        add_options_page(
            __('Self-Host Assets Settings', 'self-host-assets'),
            __('Self-Host Assets', 'self-host-assets'),
            'manage_options',
            'self-host-assets',
            [$this, 'render_settings_page']
        );

        // Asset management page
        add_submenu_page(
            'self-host-assets',
            __('Manage Assets', 'self-host-assets'),
            __('Manage Assets', 'self-host-assets'),
            'manage_options',
            'self-host-assets-manage',
            [$this, 'render_manage_assets_page']
        );

        // Error logs page
        add_submenu_page(
            'self-host-assets',
            __('Error Logs', 'self-host-assets'),
            __('Error Logs', 'self-host-assets'),
            'manage_options',
            'self-host-assets-logs',
            [$this, 'render_error_logs_page']
        );
    }

    /**
     * Render the main settings page.
     *
     * @return void
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Self-Host Assets Settings', 'self-host-assets'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('self_host_assets_settings_group');
                do_settings_sections('self_host_assets_settings');
                submit_button();
                ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('force_refresh_nonce', 'force_refresh_nonce_field'); ?>
                <input type="hidden" name="action" value="force_refresh">
                <?php
                submit_button(__('Force Refresh Cache', 'self-host-assets'), 'secondary', 'force_refresh', false);
                ?>
            </form>
            <?php settings_errors('self_host_assets_messages'); ?>
            <div class="notice notice-warning">
                <p><?php esc_html_e('Please ensure you have the rights to self-host third-party resources and be aware of the security implications. Regularly update cached files to include any security patches or updates.', 'self-host-assets'); ?></p>
            </div>
            <?php
            // Display background processing status
            if (!$this->background_complete) {
                ?>
                <div class="notice notice-info is-dismissible">
                    <p><?php esc_html_e('Background processing of assets is currently in progress. Please check the "Manage Assets" page for updates.', 'self-host-assets'); ?></p>
                </div>
                <?php
            }
            ?>
        </div>
        <?php
    }

    /**
     * Render the Manage Assets page.
     *
     * @return void
     */
    public function render_manage_assets_page() {
        global $wpdb;

        // Handle asset deletion
        if (isset($_POST['delete_asset']) && isset($_POST['asset_type']) && isset($_POST['asset_url'])) {
            // Verify nonce
            if (!isset($_POST['manage_assets_nonce']) || !wp_verify_nonce($_POST['manage_assets_nonce'], 'manage_assets_action')) {
                $this->log_error(__('Nonce verification failed while attempting to delete an asset.', 'self-host-assets'));
            } else {
                $asset_type = sanitize_text_field($_POST['asset_type']);
                $asset_url  = esc_url_raw($_POST['asset_url']);

                // Retrieve the hashed filename from the mapping table using prepared statements
                $hashed_filename = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT hashed_filename FROM {$this->mapping_table} WHERE original_url = %s AND type = %s LIMIT 1",
                        $asset_url,
                        $asset_type
                    )
                );

                if ($hashed_filename) {
                    $file_path = $this->get_local_file_path($asset_url, $asset_type);
                    if ($this->initialize_filesystem() && $GLOBALS['wp_filesystem']->exists($file_path)) {
                        if ($GLOBALS['wp_filesystem']->delete($file_path)) {
                            // Delete the mapping entry
                            $wpdb->delete(
                                $this->mapping_table,
                                [
                                    'original_url'    => $asset_url,
                                    'hashed_filename' => $hashed_filename,
                                    'type'            => $asset_type,
                                ],
                                [
                                    '%s',
                                    '%s',
                                    '%s',
                                ]
                            );

                            $this->log_error(sprintf(__('Successfully deleted %s asset: %s', 'self-host-assets'), strtoupper($asset_type), esc_url_raw($asset_url)), 'notice');
                        } else {
                            $this->log_error(sprintf(__('Failed to delete %s asset: %s', 'self-host-assets'), strtoupper($asset_type), esc_url_raw($asset_url)));
                        }
                    } else {
                        $this->log_error(sprintf(__('Asset file not found: %s', 'self-host-assets'), esc_url_raw($file_path)));
                    }
                } else {
                    $this->log_error(sprintf(__('No mapping found for asset: %s', 'self-host-assets'), esc_url_raw($asset_url)));
                }
            }
        }

        // Retrieve all cached assets
        $assets = $this->get_all_cached_assets();

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Manage Assets', 'self-host-assets'); ?></h1>
            <?php if (empty($assets)): ?>
                <p><?php esc_html_e('No cached assets found.', 'self-host-assets'); ?></p>
            <?php else: ?>
                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Asset Type', 'self-host-assets'); ?></th>
                            <th><?php esc_html_e('Original URL', 'self-host-assets'); ?></th>
                            <th><?php esc_html_e('Local Path', 'self-host-assets'); ?></th>
                            <th><?php esc_html_e('Last Updated', 'self-host-assets'); ?></th>
                            <th><?php esc_html_e('Status', 'self-host-assets'); ?></th>
                            <th><?php esc_html_e('Actions', 'self-host-assets'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assets as $asset): ?>
                            <tr>
                                <td><?php echo esc_html(strtoupper($asset->type)); ?></td>
                                <td><a href="<?php echo esc_url($asset->original_url); ?>" target="_blank"><?php echo esc_html($asset->original_url); ?></a></td>
                                <td><a href="<?php echo esc_url($asset->local_url); ?>" target="_blank"><?php echo esc_html($asset->local_url); ?></a></td>
                                <td><?php echo esc_html($asset->last_updated); ?></td>
                                <td><?php echo esc_html($asset->status); ?></td>
                                <td>
                                    <form method="post" action="">
                                        <?php wp_nonce_field('manage_assets_action', 'manage_assets_nonce'); ?>
                                        <input type="hidden" name="asset_type" value="<?php echo esc_attr($asset->type); ?>">
                                        <input type="hidden" name="asset_url" value="<?php echo esc_url($asset->original_url); ?>">
                                        <?php submit_button(__('Delete', 'self-host-assets'), 'delete', 'delete_asset', false); ?>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render the Error Logs page.
     *
     * @return void
     */
    public function render_error_logs_page() {
        global $wpdb;

        // Handle log deletion
        if (isset($_POST['delete_logs'])) {
            // Verify nonce
            if (!isset($_POST['delete_logs_nonce']) || !wp_verify_nonce($_POST['delete_logs_nonce'], 'delete_logs_action')) {
                $this->log_error(__('Nonce verification failed while attempting to delete logs.', 'self-host-assets'));
            } else {
                $deleted = $wpdb->query("TRUNCATE TABLE {$this->log_table}");
                if ($deleted !== false) {
                    $this->log_error(__('Successfully deleted all error logs.', 'self-host-assets'), 'notice');
                } else {
                    $this->log_error(__('Failed to delete error logs.', 'self-host-assets'));
                }
            }
        }

        // Retrieve all logs using direct query
        $logs = $wpdb->get_results("SELECT * FROM {$this->log_table} ORDER BY timestamp DESC");

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Error Logs', 'self-host-assets'); ?></h1>
            <?php if (empty($logs)): ?>
                <p><?php esc_html_e('No error logs found.', 'self-host-assets'); ?></p>
            <?php else: ?>
                <form method="post" action="">
                    <?php wp_nonce_field('delete_logs_action', 'delete_logs_nonce'); ?>
                    <?php submit_button(__('Delete All Logs', 'self-host-assets'), 'delete', 'delete_logs', false); ?>
                </form>
                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Timestamp', 'self-host-assets'); ?></th>
                            <th><?php esc_html_e('Message', 'self-host-assets'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo esc_html($log->timestamp); ?></td>
                                <td><?php echo esc_html($log->message); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Handle the force refresh action.
     *
     * @return void
     */
    public function handle_force_refresh() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'self-host-assets'));
        }

        check_admin_referer('force_refresh_nonce', 'force_refresh_nonce_field');

        update_option('force_refresh', 1);
        add_settings_error('self_host_assets_messages', 'force_refresh', __('Cache refresh initiated successfully.', 'self-host-assets'), 'updated');

        wp_redirect(admin_url('options-general.php?page=self-host-assets'));
        exit;
    }

    /**
     * Register plugin settings.
     *
     * @return void
     */
    public function register_settings() {
        register_setting('self_host_assets_settings_group', 'self_host_css', [
            'type'              => 'boolean',
            'sanitize_callback' => 'absint',
            'default'           => 1,
        ]);

        register_setting('self_host_assets_settings_group', 'self_host_js', [
            'type'              => 'boolean',
            'sanitize_callback' => 'absint',
            'default'           => 0,
        ]);

        register_setting('self_host_assets_settings_group', 'cache_expiration_days_css', [
            'type'              => 'integer',
            'sanitize_callback' => [$this, 'sanitize_cache_expiration_days'],
            'default'           => self::DEFAULT_CACHE_EXPIRATION_CSS,
        ]);

        register_setting('self_host_assets_settings_group', 'cache_expiration_days_fonts', [
            'type'              => 'integer',
            'sanitize_callback' => [$this, 'sanitize_cache_expiration_days'],
            'default'           => self::DEFAULT_CACHE_EXPIRATION_FONTS,
        ]);

        register_setting('self_host_assets_settings_group', 'cache_expiration_days_js', [
            'type'              => 'integer',
            'sanitize_callback' => [$this, 'sanitize_cache_expiration_days'],
            'default'           => self::DEFAULT_CACHE_EXPIRATION_JS,
        ]);

        register_setting('self_host_assets_settings_group', 'force_refresh', [
            'type'              => 'boolean',
            'sanitize_callback' => 'absint',
            'default'           => 0,
        ]);

        register_setting('self_host_assets_settings_group', 'cron_schedule', [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitize_cron_schedule'],
            'default'           => 'daily',
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

    /**
     * Sanitize cache expiration days input.
     *
     * @param mixed $value The input value.
     *
     * @return int The sanitized value.
     */
    public function sanitize_cache_expiration_days($value) {
        $value = absint($value);
        if ($value < 1) {
            $value = 1;
        } elseif ($value > 365) {
            $value = 365;
        }
        return $value;
    }

    /**
     * Sanitize cron schedule input.
     *
     * @param mixed $value The input value.
     *
     * @return string The sanitized value.
     */
    public function sanitize_cron_schedule($value) {
        $valid = ['hourly', 'twicedaily', 'daily', 'weekly'];
        if (!in_array($value, $valid, true)) {
            $value = 'daily';
        }
        return $value;
    }

    /**
     * Render the CSS field.
     *
     * @return void
     */
    public function render_css_field() {
        $self_host_css = get_option('self_host_css', 1);
        ?>
        <input type="checkbox" id="self_host_css" name="self_host_css" value="1" <?php checked(1, $self_host_css); ?> />
        <label for="self_host_css"><?php esc_html_e('Enable', 'self-host-assets'); ?></label>
        <?php
    }

    /**
     * Render the JavaScript field.
     *
     * @return void
     */
    public function render_js_field() {
        $self_host_js = get_option('self_host_js', 0);
        ?>
        <input type="checkbox" id="self_host_js" name="self_host_js" value="1" <?php checked(1, $self_host_js); ?> />
        <label for="self_host_js"><?php esc_html_e('Enable', 'self-host-assets'); ?></label>
        <?php
    }

    /**
     * Render the CSS cache expiration field.
     *
     * @return void
     */
    public function render_cache_expiration_css_field() {
        $cache_expiration_days = intval(get_option('cache_expiration_days_css', self::DEFAULT_CACHE_EXPIRATION_CSS));
        ?>
        <input type="number" name="cache_expiration_days_css" value="<?php echo esc_attr($cache_expiration_days); ?>" min="1" max="365" />
        <p class="description"><?php esc_html_e('Set the number of days after which cached CSS files should be refreshed.', 'self-host-assets'); ?></p>
        <?php
    }

    /**
     * Render the fonts cache expiration field.
     *
     * @return void
     */
    public function render_cache_expiration_fonts_field() {
        $cache_expiration_days = intval(get_option('cache_expiration_days_fonts', self::DEFAULT_CACHE_EXPIRATION_FONTS));
        ?>
        <input type="number" name="cache_expiration_days_fonts" value="<?php echo esc_attr($cache_expiration_days); ?>" min="1" max="365" />
        <p class="description"><?php esc_html_e('Set the number of days after which cached font files should be refreshed.', 'self-host-assets'); ?></p>
        <?php
    }

    /**
     * Render the JavaScript cache expiration field.
     *
     * @return void
     */
    public function render_cache_expiration_js_field() {
        $cache_expiration_days = intval(get_option('cache_expiration_days_js', self::DEFAULT_CACHE_EXPIRATION_JS));
        ?>
        <input type="number" name="cache_expiration_days_js" value="<?php echo esc_attr($cache_expiration_days); ?>" min="1" max="365" />
        <p class="description"><?php esc_html_e('Set the number of days after which cached JavaScript files should be refreshed.', 'self-host-assets'); ?></p>
        <?php
    }

    /**
     * Render the cron schedule field.
     *
     * @return void
     */
    public function render_cron_schedule_field() {
        $cron_schedule = get_option('cron_schedule', 'daily');
        ?>
        <select name="cron_schedule">
            <option value="hourly" <?php selected($cron_schedule, 'hourly'); ?>><?php esc_html_e('Hourly', 'self-host-assets'); ?></option>
            <option value="twicedaily" <?php selected($cron_schedule, 'twicedaily'); ?>><?php esc_html_e('Twice Daily', 'self-host-assets'); ?></option>
            <option value="daily" <?php selected($cron_schedule, 'daily'); ?>><?php esc_html_e('Daily', 'self-host-assets'); ?></option>
            <option value="weekly" <?php selected($cron_schedule, 'weekly'); ?>><?php esc_html_e('Weekly', 'self-host-assets'); ?></option>
        </select>
        <p class="description"><?php esc_html_e('Select how often to refresh cached assets.', 'self-host-assets'); ?></p>
        <?php
    }

    /**
     * Reschedule the cron event when the cron schedule option changes.
     *
     * @param mixed $old_value The old value of the option.
     * @param mixed $new_value The new value of the option.
     *
     * @return void
     */
    public function reschedule_cron_event($old_value, $new_value) {
        if ($old_value !== $new_value) {
            $timestamp = wp_next_scheduled('self_host_assets_cron_event');
            if ($timestamp) {
                wp_unschedule_event($timestamp, 'self_host_assets_cron_event');
            }
            wp_schedule_event(time(), $new_value, 'self_host_assets_cron_event');
        }
    }

    /**
     * Cleanup cached files and delete plugin options when the plugin is uninstalled.
     *
     * @return void
     */
    public static function uninstall() {
        if (!defined('WP_UNINSTALL_PLUGIN')) {
            exit();
        }

        global $wpdb, $wp_filesystem;

        require_once ABSPATH . 'wp-admin/includes/file.php';
        if (!WP_Filesystem()) {
            return;
        }

        $upload_dir = wp_upload_dir();

        $directories = [
            trailingslashit($upload_dir['basedir']) . 'self-hosted-css/',
            trailingslashit($upload_dir['basedir']) . 'self-hosted-fonts/',
            trailingslashit($upload_dir['basedir']) . 'self-hosted-js/',
            // trailingslashit($upload_dir['basedir']) . 'self-hosted-images/', // Uncomment if image handling is implemented
        ];

        foreach ($directories as $dir) {
            if ($wp_filesystem->is_dir($dir)) {
                // Check for marker file before deletion
                if ($wp_filesystem->exists($dir . '.self-host-assets')) {
                    $wp_filesystem->delete($dir, true);
                }
            }
        }

        // Delete related options
        $options = [
            'self_host_css',
            'self_host_js',
            'cache_expiration_days_css',
            'cache_expiration_days_fonts',
            'cache_expiration_days_js',
            'force_refresh',
            'cron_schedule',
            // 'self_host_assets_errors', // Removed as it's unused
        ];

        foreach ($options as $option) {
            delete_option($option);
            if (is_multisite()) {
                delete_site_option($option);
            }
        }

        // Delete logs table
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}self_host_assets_logs");

        // Delete mapping table
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}self_host_assets_mapping");
    }

    /**
     * Retrieve all cached assets from the mapping table.
     *
     * @return array An array of cached assets with details.
     */
    private function get_all_cached_assets() {
        global $wpdb;

        // Initialize Filesystem once to avoid redundant calls
        if (!$this->initialize_filesystem()) {
            return [];
        }

        $assets = $wpdb->get_results("SELECT * FROM {$this->mapping_table} ORDER BY type ASC, original_url ASC");

        if (empty($assets)) {
            return [];
        }

        $processed_assets = [];

        foreach ($assets as &$asset) {
            $sub_dir = 'self-hosted-' . $asset->type . '/';
            $file_path = trailingslashit($this->upload_dir['basedir']) . $sub_dir . $asset->hashed_filename;
            $file_url = trailingslashit($this->upload_dir['baseurl']) . $sub_dir . $asset->hashed_filename;

            if ($GLOBALS['wp_filesystem']->exists($file_path)) {
                $file_mod_time = method_exists($GLOBALS['wp_filesystem'], 'mtime') ? 
                                 $GLOBALS['wp_filesystem']->mtime($file_path) : 
                                 filemtime($file_path);

                if ($file_mod_time !== false) {
                    $local_url = add_query_arg('ver', $file_mod_time, esc_url($file_url));
                    $last_updated = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $file_mod_time);
                } else {
                    $local_url = '';
                    $last_updated = __('Unknown', 'self-host-assets');
                }

                // Determine the status based on recent processing
                $status = $this->determine_asset_status($asset->original_url, $asset->type);

                $processed_assets[] = (object) [
                    'type'         => $asset->type,
                    'original_url' => $asset->original_url,
                    'local_url'    => $local_url,
                    'last_updated' => $last_updated,
                    'status'       => $status,
                ];
            } else {
                $processed_assets[] = (object) [
                    'type'         => $asset->type,
                    'original_url' => $asset->original_url,
                    'local_url'    => '',
                    'last_updated' => __('File not found', 'self-host-assets'),
                    'status'       => __('Missing', 'self-host-assets'),
                ];
            }
        }

        return $processed_assets;
    }

    /**
     * Determine the processing status of an asset.
     *
     * @param string $url  The original URL of the asset.
     * @param string $type The type of the asset.
     *
     * @return string The status of the asset.
     */
    private function determine_asset_status($url, $type) {
        global $wpdb;

        // Check if the asset is currently queued or in processing
        $queue = get_transient($this->background_process->identifier);
        if (is_array($queue)) {
            foreach ($queue as $task) {
                if ($task['url'] === $url && $task['type'] === $type) {
                    return __('Queued', 'self-host-assets');
                }
            }
        }

        // Check if there are any recent errors related to this asset
        $recent_errors = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->log_table} WHERE original_url = %s AND message LIKE %s",
                $url,
                '%' . $wpdb->esc_like('Failed to download') . '%'
            )
        );

        if ($recent_errors > 0) {
            return __('Failed', 'self-host-assets');
        }

        return __('Completed', 'self-host-assets');
    }

    /**
     * Callback method when background processing is complete.
     *
     * @return void
     */
    public function on_background_process_complete() {
        $this->background_complete = true;
        // Optionally, add an admin notice or perform other actions
    }

    /**
     * Cleanup cached files and delete plugin options when the plugin is uninstalled.
     *
     * @return void
     */
    public static function uninstall() {
        if (!defined('WP_UNINSTALL_PLUGIN')) {
            exit();
        }

        global $wpdb, $wp_filesystem;

        require_once ABSPATH . 'wp-admin/includes/file.php';
        if (!WP_Filesystem()) {
            return;
        }

        $upload_dir = wp_upload_dir();

        $directories = [
            trailingslashit($upload_dir['basedir']) . 'self-hosted-css/',
            trailingslashit($upload_dir['basedir']) . 'self-hosted-fonts/',
            trailingslashit($upload_dir['basedir']) . 'self-hosted-js/',
            // trailingslashit($upload_dir['basedir']) . 'self-hosted-images/', // Uncomment if image handling is implemented
        ];

        foreach ($directories as $dir) {
            if ($wp_filesystem->is_dir($dir)) {
                // Check for marker file before deletion
                if ($wp_filesystem->exists($dir . '.self-host-assets')) {
                    $wp_filesystem->delete($dir, true);
                }
            }
        }

        // Delete related options
        $options = [
            'self_host_css',
            'self_host_js',
            'cache_expiration_days_css',
            'cache_expiration_days_fonts',
            'cache_expiration_days_js',
            'force_refresh',
            'cron_schedule',
            // 'self_host_assets_errors', // Removed as it's unused
        ];

        foreach ($options as $option) {
            delete_option($option);
            if (is_multisite()) {
                delete_site_option($option);
            }
        }

        // Delete logs table
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}self_host_assets_logs");

        // Delete mapping table
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}self_host_assets_mapping");
    }

    /**
     * Retrieve all cached assets from the mapping table.
     *
     * @return array An array of cached assets with details.
     */
    private function get_all_cached_assets() {
        global $wpdb;

        // Initialize Filesystem once to avoid redundant calls
        if (!$this->initialize_filesystem()) {
            return [];
        }

        // Ensure upload_dir is set
        if (is_null($this->upload_dir)) {
            $this->upload_dir = $this->get_upload_dir();
        }

        $assets = $wpdb->get_results("SELECT * FROM {$this->mapping_table} ORDER BY type ASC, original_url ASC");

        if (empty($assets)) {
            return [];
        }

        $processed_assets = [];

        foreach ($assets as &$asset) {
            $sub_dir = 'self-hosted-' . $asset->type . '/';
            $file_path = trailingslashit($this->upload_dir['basedir']) . $sub_dir . $asset->hashed_filename;
            $file_url = trailingslashit($this->upload_dir['baseurl']) . $sub_dir . $asset->hashed_filename;

            if ($GLOBALS['wp_filesystem']->exists($file_path)) {
                $file_mod_time = method_exists($GLOBALS['wp_filesystem'], 'mtime') ? 
                                 $GLOBALS['wp_filesystem']->mtime($file_path) : 
                                 filemtime($file_path);

                if ($file_mod_time !== false) {
                    $local_url = add_query_arg('ver', $file_mod_time, esc_url($file_url));
                    $last_updated = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $file_mod_time);
                } else {
                    $local_url = '';
                    $last_updated = __('Unknown', 'self-host-assets');
                }

                // Determine the status based on recent processing
                $status = $this->determine_asset_status($asset->original_url, $asset->type);

                $processed_assets[] = (object) [
                    'type'         => $asset->type,
                    'original_url' => $asset->original_url,
                    'local_url'    => $local_url,
                    'last_updated' => $last_updated,
                    'status'       => $status,
                ];
            } else {
                $processed_assets[] = (object) [
                    'type'         => $asset->type,
                    'original_url' => $asset->original_url,
                    'local_url'    => '',
                    'last_updated' => __('File not found', 'self-host-assets'),
                    'status'       => __('Missing', 'self-host-assets'),
                ];
            }
        }

        return $processed_assets;
    }

    /**
     * Determine the processing status of an asset.
     *
     * @param string $url  The original URL of the asset.
     * @param string $type The type of the asset.
     *
     * @return string The status of the asset.
     */
    private function determine_asset_status($url, $type) {
        global $wpdb;

        // Check if the asset is currently queued or in processing
        $queue = get_transient($this->background_process->identifier);
        if (is_array($queue)) {
            foreach ($queue as $task) {
                if ($task['url'] === $url && $task['type'] === $type) {
                    return __('Queued', 'self-host-assets');
                }
            }
        }

        // Check if there are any recent errors related to this asset
        $recent_errors = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->log_table} WHERE original_url = %s AND message LIKE %s",
                $url,
                '%' . $wpdb->esc_like('Failed to download') . '%'
            )
        );

        if ($recent_errors > 0) {
            return __('Failed', 'self-host-assets');
        }

        return __('Completed', 'self-host-assets');
    }

    /**
     * Log errors and store them in the database.
     *
     * @param string $message The error message.
     * @param string $level   The severity level ('notice', 'warning', 'error').
     *
     * @return void
     */
    private function log_error($message, $level = 'error') {
        // Remove the limit to store all errors
        $this->errors[] = sprintf('[%s] %s', strtoupper($level), $message);

        // Include context using debug_backtrace
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $caller = isset($backtrace[1]['function']) ? $backtrace[1]['function'] : 'global';

        $full_message = sprintf('[%s] %s: %s', strtoupper($level), $caller, $message);

        // Insert log into the database using prepared statements
        global $wpdb;
        $wpdb->insert(
            $this->log_table,
            [
                'timestamp' => current_time('mysql'),
                'message'   => $full_message,
            ],
            [
                '%s',
                '%s',
            ]
        );

        // Additionally, log to PHP error log
        error_log('SelfHostAssets Plugin Error: ' . $full_message);
    }

    /**
     * Display admin notices for errors.
     *
     * @return void
     */
    public function display_admin_notices() {
        if (!current_user_can('manage_options') || empty($this->errors)) {
            return;
        }
        foreach ($this->errors as $error) {
            echo '<div class="notice notice-' . esc_attr($this->get_notice_class($error)) . '"><p>' . esc_html($error) . '</p></div>';
        }
        // Reset errors after displaying
        $this->errors = [];
    }

    /**
     * Determine the notice class based on the error message.
     *
     * @param string $error The error message.
     *
     * @return string The CSS class for the notice.
     */
    private function get_notice_class($error) {
        if (strpos($error, '[ERROR]') !== false) {
            return 'error';
        } elseif (strpos($error, '[WARNING]') !== false) {
            return 'warning';
        } elseif (strpos($error, '[NOTICE]') !== false) {
            return 'updated'; // 'updated' class for success notices
        } else {
            return 'info';
        }
    }

    /**
     * Get the upload directory information.
     *
     * @return array The upload directory information.
     */
    private function get_upload_dir() {
        return wp_upload_dir();
    }

    /**
     * Get allowed MIME types based on the asset type.
     *
     * @param string $type The type of the asset ('css', 'js', 'font', 'image').
     *
     * @return array An array of allowed MIME types.
     */
    private function get_allowed_mime_types($type) {
        $allowed = [];
        switch ($type) {
            case 'css':
                $allowed = ['text/css'];
                break;
            case 'js':
                $allowed = ['application/javascript', 'application/x-javascript'];
                break;
            case 'font':
                $allowed = [
                    'font/woff',
                    'font/woff2',
                    'application/font-woff',
                    'application/font-woff2',
                    'application/octet-stream', // eot
                    'application/x-font-ttf',    // ttf
                    'font/ttf',
                    'font/otf',
                    'application/font-sfnt',    // svg
                ];
                break;
            case 'image':
                $allowed = ['image/png', 'image/jpeg', 'image/gif', 'image/svg+xml'];
                break;
            default:
                $allowed = [];
        }
        return $allowed;
    }
}

/**
 * Plugin Initialization
 */

// Initialize the plugin
SelfHostAssets::get_instance();

// Register uninstall hook with fully qualified class name
register_uninstall_hook(__FILE__, ['SelfHostAssetsPlugin\SelfHostAssets', 'uninstall']);
