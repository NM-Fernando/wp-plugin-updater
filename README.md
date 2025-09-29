# Auto Plugin Updater

A custom WordPress plugin that allows administrators to automatically update all installed plugins based on a schedule (hourly, daily, weekly, or monthly).

The plugin also provides a manual "Run Updates Now" button and a results table that shows the outcome of each update attempt.

---

## Features

* ✅ Automatically updates all plugins on a schedule (hourly, daily, weekly, monthly).
* ✅ Manual update trigger.
* ✅ Results table with status messages (success, already updated, or errors).
* ✅ Simple settings interface inside the WordPress admin dashboard.

---

## Installation

1. Download or clone the repository.
2. Place the plugin folder into the `/wp-content/plugins/` directory.
3. Activate the plugin in the **WordPress Admin > Plugins** page.
4. Navigate to **Settings > Auto Plugin Updater** to configure.

---

## Usage

* Select your preferred schedule (hourly, daily, weekly, or monthly).
* Click **Save Schedule** to enable automatic updates.
* Click **Run Updates Now** to manually update all plugins.
* View results of the latest update in the results table.

---

## Requirements

* WordPress 5.0+
* PHP 7.2+
* Proper cron setup if you want guaranteed automatic execution (see note below).

---

## Cron Setup (Recommended)

WordPress uses WP-Cron, which only runs when a page is loaded.
For reliable automation, add a server cron job:

```bash
*/30 * * * * wget -q -O - https://your-site.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```

## License

GPL-2.0-or-later
Free to use, modify, and distribute.

---

## Author

**Nimesh Fernando**
Site Reliability Engineer @ Villvay
