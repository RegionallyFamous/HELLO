<?php
/**
 * Plugin Name: Beeper Comments
 * Description: Syncs WordPress post comments with Matrix rooms that can be opened in Beeper.
 * Version: 0.1.0
 * Author: HELLO
 * Requires PHP: 8.0
 * Requires at least: 6.2
 * Text Domain: beeper-comments
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('BEEPER_COMMENTS_VERSION', '0.1.0');
define('BEEPER_COMMENTS_FILE', __FILE__);
define('BEEPER_COMMENTS_DIR', plugin_dir_path(__FILE__));
define('BEEPER_COMMENTS_URL', plugin_dir_url(__FILE__));

require_once BEEPER_COMMENTS_DIR . 'includes/class-matrix-api.php';
require_once BEEPER_COMMENTS_DIR . 'includes/class-gravatar.php';
require_once BEEPER_COMMENTS_DIR . 'includes/class-comment-sync.php';
require_once BEEPER_COMMENTS_DIR . 'includes/class-admin-settings.php';

use BeeperComments\Admin_Settings;
use BeeperComments\Comment_Sync;
use BeeperComments\Gravatar;

function beeper_comments_boot(): void
{
    Gravatar::boot();
    (new Admin_Settings())->boot();
    (new Comment_Sync())->boot();
}
add_action('plugins_loaded', 'beeper_comments_boot');

function beeper_comments_activate(): void
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
register_activation_hook(__FILE__, 'beeper_comments_activate');
