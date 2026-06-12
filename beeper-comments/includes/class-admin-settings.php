<?php

declare(strict_types=1);

namespace BeeperComments;

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
    ];

    public function boot(): void
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function register_menu(): void
    {
        add_options_page(
            __('Beeper Comments', 'beeper-comments'),
            __('Beeper Comments', 'beeper-comments'),
            'manage_options',
            'beeper-comments',
            [$this, 'render_page']
        );
    }

    public function register_settings(): void
    {
        foreach ($this->settings as $option => $type) {
            register_setting('beeper_comments', $option, [
                'type' => 'string',
                'sanitize_callback' => fn ($value) => $this->sanitize_setting((string) $value, $option),
                'default' => $this->default_for($option),
            ]);
        }

        add_settings_section(
            'beeper_comments_matrix',
            __('Matrix Connection', 'beeper-comments'),
            fn () => print '<p>' . esc_html__('Use a Matrix bot account with permission to create rooms and send messages.', 'beeper-comments') . '</p>',
            'beeper-comments'
        );

        $this->add_field('beeper_comments_homeserver', __('Homeserver URL', 'beeper-comments'), 'url');
        $this->add_field('beeper_comments_bot_token', __('Bot access token', 'beeper-comments'), 'password');
        $this->add_field('beeper_comments_bot_user', __('Bot user ID', 'beeper-comments'), 'text');
        $this->add_field('beeper_comments_bot_secret', __('Webhook shared secret', 'beeper-comments'), 'password');
        $this->add_field('beeper_comments_room_alias_prefix', __('Room alias prefix', 'beeper-comments'), 'text');

        add_settings_section(
            'beeper_comments_behavior',
            __('Sync Behavior', 'beeper-comments'),
            '__return_empty_string',
            'beeper-comments'
        );

        $this->add_field('beeper_comments_sync_direction', __('Sync direction', 'beeper-comments'), 'select');
        $this->add_field('beeper_comments_gravatar_fallback', __('Gravatar fallback', 'beeper-comments'), 'select');
    }

    public function render_page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Beeper Comments', 'beeper-comments'); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('beeper_comments');
                do_settings_sections('beeper-comments');
                submit_button();
                ?>
            </form>
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

        return sanitize_text_field($value);
    }

    private function add_field(string $option, string $label, string $input_type): void
    {
        add_settings_field(
            $option,
            $label,
            fn () => $this->render_field($option, $input_type),
            'beeper-comments',
            str_contains($option, 'sync') || str_contains($option, 'gravatar') ? 'beeper_comments_behavior' : 'beeper_comments_matrix'
        );
    }

    private function render_field(string $option, string $input_type): void
    {
        $value = (string) get_option($option, $this->default_for($option));

        if ($option === 'beeper_comments_sync_direction') {
            $choices = [
                'both' => __('Both directions', 'beeper-comments'),
                'matrix_to_wp' => __('Matrix to WordPress only', 'beeper-comments'),
                'wp_to_matrix' => __('WordPress to Matrix only', 'beeper-comments'),
            ];
            $this->render_select($option, $value, $choices);
            return;
        }

        if ($option === 'beeper_comments_gravatar_fallback') {
            $choices = [
                'matrix_display_name' => __('Use Matrix display name', 'beeper-comments'),
                'anonymous' => __('Use Anonymous', 'beeper-comments'),
            ];
            $this->render_select($option, $value, $choices);
            return;
        }

        printf(
            '<input type="%1$s" class="regular-text" name="%2$s" id="%2$s" value="%3$s" autocomplete="off" />',
            esc_attr($input_type),
            esc_attr($option),
            esc_attr($value)
        );

        if ($option === 'beeper_comments_bot_secret') {
            echo '<p class="description">' . esc_html__('Use the same value in the bot WORDPRESS_BOT_SECRET environment variable.', 'beeper-comments') . '</p>';
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
        ];

        return $defaults[$option] ?? '';
    }
}
