<?php
/**
 * Plugin Name: Sequential Plugin Updater
 * Description: Updates selected plugins one by one. Supports manual run (with/without email report) and weekly cron with email.
 * Version: 1.6.0
 * Author: Nimesh Custom
 */

if (!defined('ABSPATH')) exit; // Prevent direct file access

class Sequential_Plugin_Updater {
    private $option_key = 'seq_updater_selected_plugins';

    public function __construct() {
        // Admin menu + scripts
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);

        // AJAX handlers
        add_action('wp_ajax_seq_update_plugin', [$this, 'ajax_update_plugin']);
        add_action('wp_ajax_get_saved_plugins', [$this, 'ajax_get_saved_plugins']);
        add_action('wp_ajax_run_updates_with_email', [$this, 'ajax_run_updates_with_email']);

        // Save plugin selections
        add_action('admin_post_save_seq_updater_selection', [$this, 'save_selection']);

        // Cron schedule & hooks
        add_filter('cron_schedules', [$this, 'add_weekly_schedule']);
        add_action('seq_updater_weekly_event', [$this, 'run_weekly_updates']);

        // Activation / Deactivation hooks
        register_activation_hook(__FILE__, [$this, 'activate_cron']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate_cron']);

        // Load WP upgrader classes
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    }

    /**
     * Add admin menu
     */
    public function menu() {
        add_menu_page(
            'Sequential Updater',
            'WP-Plugin Updater',
            'manage_options',
            'sequential-updater',
            [$this, 'dashboard'],
            'dashicons-update',
            65
        );
    }

    /**
     * Load JS assets only on our plugin page
     */
    public function assets($hook) {
        if ($hook !== 'toplevel_page_sequential-updater') return;

        wp_enqueue_script(
            'seq-updater',
            plugin_dir_url(__FILE__) . 'seq-updater.js',
            ['jquery'],
            '1.6.0',
            true
        );

        wp_localize_script('seq-updater', 'SeqUpdater', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('seq_updater_nonce'),
        ]);
    }

    /**
     * Dashboard UI
     */
    public function dashboard() {
        $all_plugins = get_plugins();
        $saved = get_option($this->option_key, []);
        ?>
        <div class="wrap">
            <h1>Sequential Plugin Updater</h1>

            <!-- Save selection form -->
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="save_seq_updater_selection">
                <?php wp_nonce_field('save_seq_updater_selection'); ?>
                <h3>Select Plugins:</h3>
                <?php foreach ($all_plugins as $file => $data): ?>
                    <label>
                        <input type="checkbox" name="plugins[]" value="<?php echo esc_attr($file); ?>"
                            <?php checked(in_array($file, $saved)); ?>>
                        <?php echo esc_html($data['Name']); ?>
                    </label><br>
                <?php endforeach; ?>
                <br>
                <button type="submit" class="button">💾 Save Selection</button>
            </form>

            <hr>

            <!-- Manual Run -->
            <h3>Run Updates Manually:</h3>
            <button id="run-updates" class="button button-primary">▶ Run Updates (No Email)</button>
            <button id="run-updates-email" class="button button-secondary">📧 Run Updates + Email Report</button>

            <h3>Update Log:</h3>
            <ul id="update-log"></ul>

            <hr>
            <p><strong>Weekly Cron:</strong> Updates will also run automatically once per week and send an email report.</p>
        </div>
        <?php
    }

    /**
     * Save plugin selection
     */
    public function save_selection() {
        check_admin_referer('save_seq_updater_selection');
        $plugins = isset($_POST['plugins']) ? array_map('sanitize_text_field', $_POST['plugins']) : [];
        update_option($this->option_key, $plugins);
        wp_redirect(admin_url('admin.php?page=sequential-updater&saved=1'));
        exit;
    }

    /**
     * AJAX - return saved plugins
     */
    public function ajax_get_saved_plugins() {
        check_ajax_referer('seq_updater_nonce', 'nonce');
        $saved = get_option($this->option_key, []);
        wp_send_json_success($saved);
    }

    /**
     * AJAX - update one plugin (manual step-by-step mode)
     */
    public function ajax_update_plugin() {
        check_ajax_referer('seq_updater_nonce', 'nonce');
        $plugin = sanitize_text_field($_POST['plugin']);
        $status = $this->update_plugin($plugin);
        wp_send_json_success(['plugin' => $plugin, 'status' => $status]);
    }

    /**
     * AJAX - run all updates and send single email
     */
    public function ajax_run_updates_with_email() {
        check_ajax_referer('seq_updater_nonce', 'nonce');
        $saved_plugins = get_option($this->option_key, []);
        if (empty($saved_plugins)) {
            wp_send_json_error("No plugins selected.");
        }

        $results = [];
        foreach ($saved_plugins as $plugin) {
            $results[$plugin] = $this->update_plugin($plugin);
        }

        // Send email report once
        $this->send_email_report($results);

        wp_send_json_success($results);
    }

    /**
     * Helper - update one plugin
     */
    private function update_plugin($plugin) {
        wp_update_plugins(); 
        $available = get_site_transient('update_plugins')->response ?? [];
        $status = "⏭ No update available";

        if (isset($available[$plugin])) {
            $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
            $result   = $upgrader->upgrade($plugin);

            if ($result && !is_wp_error($result)) {
                if (!is_plugin_active($plugin)) {
                    $activate = activate_plugin($plugin);
                    if (is_wp_error($activate)) {
                        return "⚠️ Updated but activation failed: " . $activate->get_error_message();
                    }
                }
                $status = "✅ Updated & Active";
            } else {
                $status = "❌ Update failed";
            }
        }
        return $status;
    }

    /* -------------------
     * CRON SUPPORT
     * ------------------- */

    // Add weekly schedule
    public function add_weekly_schedule($schedules) {
        $schedules['weekly'] = [
            'interval' => 604800, // 7 days
            'display'  => __('Once Weekly')
        ];
        return $schedules;
    }

    // Schedule on activation
    public function activate_cron() {
        if (!wp_next_scheduled('seq_updater_weekly_event')) {
            wp_schedule_event(time(), 'weekly', 'seq_updater_weekly_event');
        }
    }

    // Clear cron on deactivation
    public function deactivate_cron() {
        wp_clear_scheduled_hook('seq_updater_weekly_event');
    }

    // Run updates via cron
    public function run_weekly_updates() {
        $saved_plugins = get_option($this->option_key, []);
        if (empty($saved_plugins)) return;

        $results = [];
        foreach ($saved_plugins as $plugin) {
            $results[$plugin] = $this->update_plugin($plugin);
        }

        $this->send_email_report($results);
    }

    /**
     * Send single email with all results
     */
    private function send_email_report($results) {
        $admin_email = get_option('admin_email');
        $subject = "Plugin Update Report";
        $body  = "<h2>Plugin Update Results</h2><ul>";
        foreach ($results as $plugin => $status) {
            $body .= "<li><strong>{$plugin}</strong>: {$status}</li>";
        }
        $body .= "</ul><p>-- End of Report --</p>";

        wp_mail($admin_email, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
    }
}

// Boot plugin
new Sequential_Plugin_Updater();