<?php
/**
 * Plugin Name: HELLO
 * Description: Syncs WordPress post comments with Matrix rooms that can be opened in Beeper.
 * Version: 0.1.0
 * Author: HELLO
 * Requires PHP: 8.0
 * Requires at least: 6.2
 * Text Domain: hello
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('HELLO_VERSION', '0.1.0');
define('HELLO_FILE', __FILE__);
define('HELLO_DIR', plugin_dir_path(__FILE__));
define('HELLO_URL', plugin_dir_url(__FILE__));

require_once HELLO_DIR . 'includes/class-matrix-api.php';
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
    if (! get_option('beeper_comments_bot_secret')) {
        update_option('beeper_comments_bot_secret', wp_generate_password(48, false, false));
    }

    add_option('beeper_comments_homeserver', 'https://matrix.org');
    add_option('beeper_comments_room_alias_prefix', 'post-');
    add_option('beeper_comments_gravatar_fallback', 'matrix_display_name');
    add_option('beeper_comments_sync_direction', 'both');
    add_option('beeper_comments_redact_on_moderation', '1');
}
register_activation_hook(__FILE__, 'hello_activate');
