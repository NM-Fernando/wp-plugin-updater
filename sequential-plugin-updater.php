<?php
/**
 * Plugin Name: Sequential Plugin Updater
 * Description: Updates selected plugins one by one. Supports manual run (with/without email report), multiple emails, site name, schedule options, and tracks last update date/time.
 * Version: 1.8.0
 * Author: Nimesh Fernando
 */

if (!defined('ABSPATH')) exit;

class Sequential_Plugin_Updater {
    private $option_key = 'seq_updater_selected_plugins';
    private $email_key = 'seq_updater_custom_emails';
    private $site_name_key = 'seq_updater_site_name';
    private $last_update_key = 'seq_updater_last_update';
    private $schedule_key = 'seq_updater_schedule_option';

    public function __construct() {
        // Admin menu & assets
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);

        // AJAX actions
        add_action('wp_ajax_seq_update_plugin', [$this, 'ajax_update_plugin']);
        add_action('wp_ajax_get_saved_plugins', [$this, 'ajax_get_saved_plugins']);
        add_action('wp_ajax_run_updates_with_email', [$this, 'ajax_run_updates_with_email']);

        // Save form
        add_action('admin_post_save_seq_updater_selection', [$this, 'save_selection']);

        // Cron schedules
        add_filter('cron_schedules', [$this, 'add_custom_schedules']);
        add_action('seq_updater_cron_event', [$this, 'run_scheduled_updates']);
        register_activation_hook(__FILE__, [$this, 'activate_cron']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate_cron']);

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    }

    // Admin menu
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

    // JS / CSS
    public function assets($hook) {
        if ($hook !== 'toplevel_page_sequential-updater') return;

        wp_enqueue_script(
            'seq-updater',
            plugin_dir_url(__FILE__) . 'seq-updater.js',
            ['jquery'],
            '1.8.0',
            true
        );

        wp_localize_script('seq-updater', 'SeqUpdater', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('seq_updater_nonce'),
        ]);
    }

    // Dashboard
    public function dashboard() {
        $all_plugins = get_plugins();
        $saved_plugins = get_option($this->option_key, []);
        $emails = get_option($this->email_key, ['', '']);
        $site_name = get_option($this->site_name_key, get_bloginfo('name'));
        $schedule = get_option($this->schedule_key, 'weekly');
        $last_update = get_option($this->last_update_key, []);
        ?>
        <div class="wrap" style="font-family: Arial, sans-serif; color: #222;">
            <h1 style="margin-bottom: 20px;">Sequential Plugin Updater</h1>

            <!-- Settings Form -->
            <div style="background: #fff; padding: 20px; border: 1px solid #ddd; margin-bottom: 30px; border-radius: 8px;">
                <h2>🔧 Plugin Selection & Settings</h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="save_seq_updater_selection">
                    <?php wp_nonce_field('save_seq_updater_selection'); ?>

                    <!-- Plugin selection -->
                    <div style="max-height: 250px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; border-radius: 5px; background: #f9f9f9;">
                        <?php foreach ($all_plugins as $file => $data): ?>
                            <label style="display: block; margin-bottom: 5px;">
                                <input type="checkbox" name="plugins[]" value="<?php echo esc_attr($file); ?>"
                                    <?php checked(in_array($file, $saved_plugins)); ?>>
                                <?php echo esc_html($data['Name']); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <!-- Emails -->
                    <h3 style="margin-top:20px;">📧 Notification Emails</h3>
                    <p>Up to two custom emails (in addition to admin email).</p>
                    <input type="email" name="emails[]" value="<?php echo esc_attr($emails[0]); ?>" placeholder="First email" style="width:300px; display:block; margin-bottom:10px;">
                    <input type="email" name="emails[]" value="<?php echo esc_attr($emails[1]); ?>" placeholder="Second email" style="width:300px; display:block;">

                    <!-- Site Name -->
                    <h3 style="margin-top:20px;">🏷️ Site Name</h3>
                    <input type="text" name="site_name" value="<?php echo esc_attr($site_name); ?>" placeholder="Site Name" style="width:300px; display:block;">

                    <!-- Schedule -->
                    <h3 style="margin-top:20px;">⏰ Schedule Updates</h3>
                    <select name="schedule_option">
                        <option value="hourly" <?php selected($schedule,'hourly'); ?>>Hourly</option>
                        <option value="daily" <?php selected($schedule,'daily'); ?>>Daily</option>
                        <option value="weekly" <?php selected($schedule,'weekly'); ?>>Weekly</option>
                        <option value="monthly" <?php selected($schedule,'monthly'); ?>>Monthly</option>
                    </select>

                    <br><br>
                    <button type="submit" class="button button-primary">💾 Save Settings</button>
                </form>
            </div>

            <!-- Manual Run -->
            <div style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 8px;">
                <h2>▶ Run Updates Manually</h2>
                <div style="margin-bottom: 15px;">
                    <button id="run-updates" class="button button-primary" style="margin-right: 10px;">Run Updates (No Email)</button>
                    <button id="run-updates-email" class="button button-secondary">Run Updates + Send Email</button>
                </div>

                <h3>Update Log:</h3>
                <ul id="update-log" style="list-style: disc; padding-left: 20px; background: #f9f9f9; border: 1px solid #ccc; border-radius: 5px; max-height: 250px; overflow-y: auto; padding: 10px;">
                    <?php
                    foreach ($last_update as $plugin => $time) {
                        echo '<li><strong>'.$plugin.'</strong>: Last Updated - '.date('Y-m-d H:i:s', $time).'</li>';
                    }
                    ?>
                </ul>
            </div>
        </div>
        <?php
    }

    // Save form
    public function save_selection() {
        check_admin_referer('save_seq_updater_selection');

        $plugins = isset($_POST['plugins']) ? array_map('sanitize_text_field', $_POST['plugins']) : [];
        $emails  = isset($_POST['emails']) ? array_map('sanitize_email', $_POST['emails']) : [];
        $site_name = sanitize_text_field($_POST['site_name'] ?? get_bloginfo('name'));
        $schedule = sanitize_text_field($_POST['schedule_option'] ?? 'weekly');

        update_option($this->option_key, $plugins);
        update_option($this->email_key, $emails);
        update_option($this->site_name_key, $site_name);
        update_option($this->schedule_key, $schedule);

        $this->activate_cron(); // reset cron with new schedule

        wp_redirect(admin_url('admin.php?page=sequential-updater&saved=1'));
        exit;
    }

    // AJAX: get plugins
    public function ajax_get_saved_plugins() {
        check_ajax_referer('seq_updater_nonce', 'nonce');
        $saved = get_option($this->option_key, []);
        wp_send_json_success($saved);
    }

    // AJAX: update single plugin
    public function ajax_update_plugin() {
        check_ajax_referer('seq_updater_nonce', 'nonce');
        $plugin = sanitize_text_field($_POST['plugin']);
        $status = $this->update_plugin($plugin);
        wp_send_json_success(['plugin' => $plugin, 'status' => $status]);
    }

    // AJAX: run updates with email
    public function ajax_run_updates_with_email() {
        check_ajax_referer('seq_updater_nonce', 'nonce');
        $saved_plugins = get_option($this->option_key, []);
        if (empty($saved_plugins)) wp_send_json_error("No plugins selected.");

        $results = [];
        foreach ($saved_plugins as $plugin) {
            $results[$plugin] = $this->update_plugin($plugin);
        }

        $this->send_email_report($results);
        wp_send_json_success($results);
    }

    // Update plugin and save last update time
    private function update_plugin($plugin) {
        wp_update_plugins();
        $available = get_site_transient('update_plugins')->response ?? [];
        $status = "⏭ No update available";

        if (isset($available[$plugin])) {
            $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
            $result = $upgrader->upgrade($plugin);

            if ($result && !is_wp_error($result)) {
                if (!is_plugin_active($plugin)) {
                    $activate = activate_plugin($plugin);
                    if (is_wp_error($activate)) return "⚠️ Updated but activation failed: " . $activate->get_error_message();
                }
                $status = "✅ Updated & Active";
            } else {
                $status = "❌ Update failed";
            }
        }

        // Save last update time
        $last_update = get_option($this->last_update_key, []);
        $last_update[$plugin] = time();
        update_option($this->last_update_key, $last_update);

        return $status;
    }

    // Cron schedules
    public function add_custom_schedules($schedules) {
        return array_merge($schedules, [
            'hourly' => ['interval' => 3600, 'display' => __('Hourly')],
            'daily'  => ['interval' => 86400, 'display' => __('Daily')],
            'weekly' => ['interval' => 604800, 'display' => __('Weekly')],
            'monthly'=> ['interval' => 2592000, 'display' => __('Monthly')],
        ]);
    }

    // Activate cron
    public function activate_cron() {
        $schedule = get_option($this->schedule_key, 'weekly');

        // Clear existing cron
        $this->deactivate_cron();

        if (!wp_next_scheduled('seq_updater_cron_event')) {
            wp_schedule_event(time(), $schedule, 'seq_updater_cron_event');
        }
    }

    // Deactivate cron
    public function deactivate_cron() {
        wp_clear_scheduled_hook('seq_updater_cron_event');
    }

    // Run scheduled updates
    public function run_scheduled_updates() {
        $saved_plugins = get_option($this->option_key, []);
        if (empty($saved_plugins)) return;

        $results = [];
        foreach ($saved_plugins as $plugin) {
            $results[$plugin] = $this->update_plugin($plugin);
        }

        $this->send_email_report($results);
    }

    // Send email
    private function send_email_report($results) {
        $admin_email = get_option('admin_email');
        $custom_emails = get_option($this->email_key, []);
        $site_name = get_option($this->site_name_key, get_bloginfo('name'));

        $emails = array_filter(array_merge([$admin_email], $custom_emails));
        $subject = "🔔 Plugin Update Report - {$site_name}";
        $body = "<h2>Plugin Update Results for {$site_name}</h2><ul>";
        foreach ($results as $plugin => $status) {
            $last_update = get_option($this->last_update_key, []);
            $time = isset($last_update[$plugin]) ? date('Y-m-d H:i:s', $last_update[$plugin]) : 'N/A';
            $body .= "<li><strong>{$plugin}</strong>: {$status} (Last Updated: {$time})</li>";
        }
        $body .= "</ul><p>-- End of Report --</p>";

        foreach ($emails as $email) {
            wp_mail($email, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
        }
    }
}

new Sequential_Plugin_Updater();