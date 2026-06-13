<?php
/**
 * Plugin Name: HELLO
 * Description: Syncs WordPress post comments with Matrix rooms that can be opened in Beeper.
 * Plugin URI: https://github.com/RegionallyFamous/HELLO
 * Version: 0.1.0
 * Author: HELLO
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP: 8.0
 * Requires at least: 7.0
 * Text Domain: hello
 * Update URI: https://github.com/RegionallyFamous/HELLO
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('HELLO_VERSION', '0.1.0');
define('HELLO_FILE', __FILE__);
define('HELLO_DIR', plugin_dir_path(__FILE__));
define('HELLO_URL', plugin_dir_url(__FILE__));
define('HELLO_DEFAULT_BRIDGE_URL', 'https://hellobridge.up.railway.app');

require_once HELLO_DIR . 'includes/class-bridge-api.php';
require_once HELLO_DIR . 'includes/class-gravatar.php';
require_once HELLO_DIR . 'includes/class-comment-sync.php';
require_once HELLO_DIR . 'includes/class-admin-settings.php';

use Hello\Admin_Settings;
use Hello\Comment_Sync;
use Hello\Gravatar;

function hello_boot(): void
{
    Gravatar::boot();
    (new Admin_Settings())->boot();
    (new Comment_Sync())->boot();
}
add_action('plugins_loaded', 'hello_boot');

function hello_activate(): void
{
    if (! get_option('hello_bot_secret')) {
        update_option('hello_bot_secret', wp_generate_password(48, false, false));
    }

    if (! get_option('hello_site_id')) {
        update_option('hello_site_id', wp_generate_uuid4());
    }

    update_option('hello_bridge_url', HELLO_DEFAULT_BRIDGE_URL);
    add_option('hello_bridge_token', '');
    add_option('hello_room_alias_prefix', 'post-');
    add_option('hello_gravatar_fallback', 'matrix_display_name');
    add_option('hello_sync_direction', 'both');
    add_option('hello_redact_on_moderation', '1');
}
register_activation_hook(__FILE__, 'hello_activate');
