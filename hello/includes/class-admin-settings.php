<?php

declare(strict_types=1);

namespace Hello;

class Admin_Settings
{
    /** @var array<string, string> */
    private array $settings = [
        'beeper_comments_homeserver' => 'url',
        'beeper_comments_bot_token' => 'token',
        'beeper_comments_bot_user' => 'text',
        'beeper_comments_bot_secret' => 'secret',
        'beeper_comments_room_alias_prefix' => 'text',
        'beeper_comments_gravatar_fallback' => 'select',
        'beeper_comments_sync_direction' => 'select',
        'beeper_comments_redact_on_moderation' => 'checkbox',
    ];

    public function boot(): void
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function register_menu(): void
    {
        add_options_page(
            __('HELLO', 'hello'),
            __('HELLO', 'hello'),
            'manage_options',
            'hello',
            [$this, 'render_page']
        );
    }

    public function register_settings(): void
    {
        foreach ($this->settings as $option => $type) {
            register_setting('hello', $option, [
                'type' => 'string',
                'sanitize_callback' => fn ($value) => $this->sanitize_setting((string) $value, $option),
                'default' => $this->default_for($option),
            ]);
        }

        add_settings_section(
            'hello_matrix',
            __('Matrix Connection', 'hello'),
            fn () => print '<p>' . esc_html__('Use a Matrix bot account with permission to create rooms and send messages.', 'hello') . '</p>',
            'hello'
        );

        $this->add_field('beeper_comments_homeserver', __('Homeserver URL', 'hello'), 'url');
        $this->add_field('beeper_comments_bot_token', __('Bot access token', 'hello'), 'password');
        $this->add_field('beeper_comments_bot_user', __('Bot user ID', 'hello'), 'text');
        $this->add_field('beeper_comments_bot_secret', __('Webhook shared secret', 'hello'), 'password');
        $this->add_field('beeper_comments_room_alias_prefix', __('Room alias prefix', 'hello'), 'text');

        add_settings_section(
            'hello_behavior',
            __('Sync Behavior', 'hello'),
            '__return_empty_string',
            'hello'
        );

        $this->add_field('beeper_comments_sync_direction', __('Sync direction', 'hello'), 'select');
        $this->add_field('beeper_comments_gravatar_fallback', __('Gravatar fallback', 'hello'), 'select');
        $this->add_field('beeper_comments_redact_on_moderation', __('Redact moderated Matrix messages', 'hello'), 'checkbox');
    }

    public function render_page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('HELLO', 'hello'); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('hello');
                do_settings_sections('hello');
                submit_button();
                ?>
            </form>
            <?php $this->render_status_panel(); ?>
        </div>
        <?php
    }

    public function sanitize_setting(string $value, string $option): string
    {
        if ($option === 'beeper_comments_homeserver') {
            return esc_url_raw(rtrim($value, '/'));
        }

        if ($option === 'beeper_comments_sync_direction') {
            return in_array($value, ['both', 'matrix_to_wp', 'wp_to_matrix'], true) ? $value : 'both';
        }

        if ($option === 'beeper_comments_gravatar_fallback') {
            return in_array($value, ['matrix_display_name', 'anonymous'], true) ? $value : 'matrix_display_name';
        }

        if ($option === 'beeper_comments_redact_on_moderation') {
            return $value === '1' ? '1' : '0';
        }

        return sanitize_text_field($value);
    }

    private function add_field(string $option, string $label, string $input_type): void
    {
        add_settings_field(
            $option,
            $label,
            fn () => $this->render_field($option, $input_type),
            'hello',
            str_contains($option, 'sync') || str_contains($option, 'gravatar') ? 'hello_behavior' : 'hello_matrix'
        );
    }

    private function render_field(string $option, string $input_type): void
    {
        $value = (string) get_option($option, $this->default_for($option));

        if ($option === 'beeper_comments_sync_direction') {
            $choices = [
                'both' => __('Both directions', 'hello'),
                'matrix_to_wp' => __('Matrix to WordPress only', 'hello'),
                'wp_to_matrix' => __('WordPress to Matrix only', 'hello'),
            ];
            $this->render_select($option, $value, $choices);
            return;
        }

        if ($option === 'beeper_comments_gravatar_fallback') {
            $choices = [
                'matrix_display_name' => __('Use Matrix display name', 'hello'),
                'anonymous' => __('Use Anonymous', 'hello'),
            ];
            $this->render_select($option, $value, $choices);
            return;
        }

        if ($option === 'beeper_comments_redact_on_moderation') {
            printf(
                '<input type="hidden" name="%1$s" value="0" /><label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label>',
                esc_attr($option),
                checked($value, '1', false),
                esc_html__('When a synced WordPress comment is marked spam or trashed, ask Matrix to redact the matching event.', 'hello')
            );
            return;
        }

        printf(
            '<input type="%1$s" class="regular-text" name="%2$s" id="%2$s" value="%3$s" autocomplete="off" />',
            esc_attr($input_type),
            esc_attr($option),
            esc_attr($value)
        );

        if ($option === 'beeper_comments_bot_secret') {
            echo '<p class="description">' . esc_html__('Use the same value in the bot WORDPRESS_BOT_SECRET environment variable.', 'hello') . '</p>';
        }
    }

    /**
     * @param array<string, string> $choices
     */
    private function render_select(string $option, string $value, array $choices): void
    {
        printf('<select name="%1$s" id="%1$s">', esc_attr($option));
        foreach ($choices as $choice_value => $label) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($choice_value),
                selected($value, $choice_value, false),
                esc_html($label)
            );
        }
        echo '</select>';
    }

    private function default_for(string $option): string
    {
        $defaults = [
            'beeper_comments_homeserver' => 'https://matrix.org',
            'beeper_comments_bot_token' => '',
            'beeper_comments_bot_user' => '',
            'beeper_comments_bot_secret' => '',
            'beeper_comments_room_alias_prefix' => 'post-',
            'beeper_comments_gravatar_fallback' => 'matrix_display_name',
            'beeper_comments_sync_direction' => 'both',
            'beeper_comments_redact_on_moderation' => '1',
        ];

        return $defaults[$option] ?? '';
    }

    private function render_status_panel(): void
    {
        $room_count = count(get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'fields' => 'ids',
            'numberposts' => -1,
            'meta_query' => [
                [
                    'key' => Comment_Sync::META_ROOM_ID,
                    'compare' => 'EXISTS',
                ],
            ],
        ]));

        $base = rest_url('hello/v1/');
        $secret = (string) get_option('beeper_comments_bot_secret', '');
        ?>
        <hr>
        <h2><?php esc_html_e('Bot Connection', 'hello'); ?></h2>
        <table class="widefat striped" style="max-width: 760px;">
            <tbody>
                <tr>
                    <th scope="row"><?php esc_html_e('Known post rooms', 'hello'); ?></th>
                    <td><?php echo esc_html((string) $room_count); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Incoming webhook', 'hello'); ?></th>
                    <td><code><?php echo esc_html($base . 'incoming'); ?></code></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Rooms registry', 'hello'); ?></th>
                    <td><code><?php echo esc_html($base . 'rooms'); ?></code></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Health endpoint', 'hello'); ?></th>
                    <td><code><?php echo esc_html($base . 'health'); ?></code></td>
                </tr>
            </tbody>
        </table>
        <h3><?php esc_html_e('Bot environment', 'hello'); ?></h3>
        <textarea readonly rows="7" class="large-text code" style="max-width: 760px;"><?php
        echo esc_textarea(
            'MATRIX_HOMESERVER_URL=' . (string) get_option('beeper_comments_homeserver', 'https://matrix.org') . "\n" .
            'MATRIX_ACCESS_TOKEN=' . (string) get_option('beeper_comments_bot_token', '') . "\n" .
            'MATRIX_USER_ID=' . (string) get_option('beeper_comments_bot_user', '') . "\n" .
            'WORDPRESS_BASE_URL=' . home_url('/') . "\n" .
            'WORDPRESS_BOT_SECRET=' . $secret . "\n" .
            'IDENTITY_STORE_PATH=.data/identities.json' . "\n" .
            'MATRIX_SYNC_STORE_PATH=.data/matrix-sync.json' . "\n" .
            'ROOM_REFRESH_MS=60000'
        );
        ?></textarea>
        <?php
    }
}
