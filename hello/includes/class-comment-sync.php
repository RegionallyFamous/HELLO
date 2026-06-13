<?php

declare(strict_types=1);

namespace Hello;

use WP_Comment;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class Comment_Sync
{
    public const META_ROOM_ID = '_hello_room_id';
    public const META_ROOM_ALIAS = '_hello_room_alias';
    public const META_ORIGIN = '_hello_origin';
    public const META_SYNCED = '_hello_synced';
    public const META_MATRIX_ID = '_hello_matrix_id';
    public const META_EVENT_ID = '_hello_event_id';
    public const META_WP_MATRIX_EVENT_ID = '_hello_wp_matrix_event_id';
    public const META_MATRIX_REDACTED = '_hello_matrix_redacted';
    public const META_LAST_ERROR = '_hello_last_error';

    private bool $importing_matrix_comment = false;

    public function boot(): void
    {
        add_action('transition_post_status', [$this, 'maybe_create_room_on_publish'], 10, 3);
        add_action('comment_post', [$this, 'sync_wordpress_comment_to_matrix'], 20, 3);
        add_action('transition_comment_status', [$this, 'handle_comment_status_transition'], 20, 3);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('comment_form_after', [$this, 'render_join_button']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('add_meta_boxes', [$this, 'register_post_metabox']);
        add_action('admin_post_hello_create_room', [$this, 'handle_admin_create_room']);
    }

    public function enqueue_assets(): void
    {
        if (! is_singular('post')) {
            return;
        }

        wp_enqueue_script(
            'hello-join-button',
            HELLO_URL . 'assets/join-button.js',
            [],
            HELLO_VERSION,
            [
                'in_footer' => true,
                'strategy' => 'defer',
            ]
        );

        wp_enqueue_style(
            'hello-join-button',
            HELLO_URL . 'assets/join-button.css',
            [],
            HELLO_VERSION
        );
    }

    public function maybe_create_room_on_publish(string $new_status, string $old_status, WP_Post $post): void
    {
        if ($new_status !== 'publish' || $old_status === 'publish' || $post->post_type !== 'post') {
            return;
        }

        if (wp_is_post_revision($post->ID) || get_post_meta($post->ID, self::META_ROOM_ID, true)) {
            return;
        }

        $this->create_room_for_post($post);
    }

    /**
     * @return array{room_id: string, room_alias?: string}|WP_Error
     */
    public function create_room_for_post(WP_Post $post)
    {
        $api = $this->transport();
        $room = $api->create_room_for_post($post);

        if (is_wp_error($room)) {
            error_log('[HELLO] Failed to create Matrix room for post ' . $post->ID . ': ' . $room->get_error_message());
            update_post_meta($post->ID, self::META_LAST_ERROR, $room->get_error_message());
            return $room;
        }

        update_post_meta($post->ID, self::META_ROOM_ID, sanitize_text_field($room['room_id']));
        delete_post_meta($post->ID, self::META_LAST_ERROR);

        if (! empty($room['room_alias'])) {
            update_post_meta($post->ID, self::META_ROOM_ALIAS, sanitize_text_field((string) $room['room_alias']));
        }

        return $room;
    }

    public function register_rest_routes(): void
    {
        register_rest_route('hello/v1', '/incoming', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'handle_incoming_matrix_message'],
            'permission_callback' => [$this, 'authorize_rest_request'],
            'args' => [
                'room_id' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => [$this, 'validate_required_string'],
                ],
                'matrix_user_id' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => [$this, 'validate_required_string'],
                ],
                'message' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'wp_kses_post',
                    'validate_callback' => [$this, 'validate_required_string'],
                ],
                'event_id' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => [$this, 'validate_required_string'],
                ],
                'bot_secret' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => [$this, 'validate_required_string'],
                ],
                'author_name' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'author_url' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'esc_url_raw',
                ],
                'author_email_hash' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => [Gravatar::class, 'sanitize_hash'],
                ],
                'author_avatar_url' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'esc_url_raw',
                ],
                'moderation_state' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_key',
                ],
            ],
        ]);

        register_rest_route('hello/v1', '/rooms', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'handle_rooms_request'],
            'permission_callback' => [$this, 'authorize_rest_request'],
            'args' => [
                'bot_secret' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => [$this, 'validate_required_string'],
                ],
            ],
        ]);

        register_rest_route('hello/v1', '/health', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'handle_health_request'],
            'permission_callback' => [$this, 'authorize_rest_request'],
            'args' => [
                'bot_secret' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => [$this, 'validate_required_string'],
                ],
            ],
        ]);
    }

    public function handle_incoming_matrix_message(WP_REST_Request $request): WP_REST_Response
    {
        if (! in_array($this->sync_direction(), ['both', 'matrix_to_wp'], true)) {
            return new WP_REST_Response(['message' => __('Matrix to WordPress sync is disabled.', 'hello')], 202);
        }

        $room_id = sanitize_text_field((string) $request->get_param('room_id'));
        $event_id = sanitize_text_field((string) $request->get_param('event_id'));
        $existing = $this->find_comment_by_event_id($event_id);

        if ($existing instanceof WP_Comment) {
            return new WP_REST_Response([
                'comment_id' => (int) $existing->comment_ID,
                'status' => wp_get_comment_status($existing),
                'deduped' => true,
            ], 200);
        }

        $post_id = $this->find_post_id_by_room($room_id);
        if (! $post_id) {
            return new WP_REST_Response(['message' => __('No WordPress post is mapped to this Matrix room.', 'hello')], 404);
        }

        $matrix_user_id = sanitize_text_field((string) $request->get_param('matrix_user_id'));
        $author_name = sanitize_text_field((string) ($request->get_param('author_name') ?: $matrix_user_id));
        $author_url = esc_url_raw((string) $request->get_param('author_url'));
        $message = wp_kses_post((string) $request->get_param('message'));
        $moderation_state = sanitize_key((string) $request->get_param('moderation_state'));
        $status = $moderation_state === 'hold' || get_option('comment_moderation') ? '0' : '1';

        $comment_meta = [
            self::META_ORIGIN => 'matrix',
            self::META_SYNCED => '1',
            self::META_MATRIX_ID => $matrix_user_id,
            self::META_EVENT_ID => $event_id,
        ];

        $hash = Gravatar::sanitize_hash((string) $request->get_param('author_email_hash'));
        if ($hash !== '') {
            $comment_meta['_hello_gravatar_hash'] = $hash;
        }

        $avatar_url = esc_url_raw((string) $request->get_param('author_avatar_url'));
        if ($avatar_url !== '') {
            $comment_meta['_hello_gravatar_avatar_url'] = $avatar_url;
        }

        $this->importing_matrix_comment = true;
        try {
            $comment_id = wp_new_comment(wp_slash([
                'comment_post_ID' => $post_id,
                'comment_author' => $author_name,
                'comment_author_email' => '',
                'comment_author_url' => $author_url,
                'comment_content' => $message,
                'comment_type' => 'comment',
                'comment_approved' => $status,
                'comment_agent' => 'HELLO Matrix Bot',
                'comment_date' => current_time('mysql'),
                'comment_date_gmt' => current_time('mysql', true),
                'comment_meta' => $comment_meta,
            ]), true);
        } finally {
            $this->importing_matrix_comment = false;
        }

        if (is_wp_error($comment_id)) {
            return new WP_REST_Response(['message' => $comment_id->get_error_message()], 500);
        }

        if (! $comment_id) {
            return new WP_REST_Response(['message' => __('Unable to create WordPress comment.', 'hello')], 500);
        }

        return new WP_REST_Response([
            'comment_id' => (int) $comment_id,
            'status' => $status === '1' ? 'approved' : 'hold',
        ], 201);
    }

    public function handle_rooms_request(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        return new WP_REST_Response([
            'rooms' => $this->known_rooms(),
        ], 200);
    }

    public function handle_health_request(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        $api = $this->transport();
        $account = $api->get_status();

        return new WP_REST_Response([
            'plugin_version' => HELLO_VERSION,
            'wordpress_url' => home_url('/'),
            'connection_mode' => 'hosted_bridge',
            'transport_configured' => $api->is_configured(),
            'transport_ok' => ! is_wp_error($account),
            'transport_status' => is_wp_error($account) ? null : $account,
            'transport_error' => is_wp_error($account) ? $account->get_error_message() : '',
            'known_room_count' => count($this->known_rooms()),
            'sync_direction' => $this->sync_direction(),
        ], 200);
    }

    public function sync_wordpress_comment_to_matrix(int $comment_id, $comment_approved, array $comment_data): void
    {
        unset($comment_data);

        if ($this->importing_matrix_comment) {
            return;
        }

        if (! in_array($this->sync_direction(), ['both', 'wp_to_matrix'], true)) {
            return;
        }

        if ((string) $comment_approved !== '1') {
            return;
        }

        $this->sync_comment_to_matrix($comment_id);
    }

    public function handle_comment_status_transition(string $new_status, string $old_status, WP_Comment $comment): void
    {
        if ($new_status === $old_status) {
            return;
        }

        if ($new_status === 'approved') {
            $this->sync_comment_to_matrix((int) $comment->comment_ID);
            return;
        }

        if (in_array($new_status, ['spam', 'trash'], true)) {
            $this->redact_matrix_event_for_comment($comment, $new_status);
        }
    }

    public function register_post_metabox(): void
    {
        add_meta_box(
            'hello-room',
            __('HELLO', 'hello'),
            [$this, 'render_post_metabox'],
            'post',
            'side',
            'default'
        );
    }

    public function render_post_metabox(WP_Post $post): void
    {
        $room_id = (string) get_post_meta($post->ID, self::META_ROOM_ID, true);
        $room_alias = (string) get_post_meta($post->ID, self::META_ROOM_ALIAS, true);
        $last_error = (string) get_post_meta($post->ID, self::META_LAST_ERROR, true);
        $create_url = wp_nonce_url(
            admin_url('admin-post.php?action=hello_create_room&post_id=' . $post->ID),
            'hello_create_room_' . $post->ID
        );

        if ($room_id !== '') {
            echo '<p><strong>' . esc_html__('Room ID', 'hello') . '</strong><br><code>' . esc_html($room_id) . '</code></p>';
        } else {
            echo '<p>' . esc_html__('No Matrix room is stored for this post yet.', 'hello') . '</p>';
        }

        if ($room_alias !== '') {
            echo '<p><strong>' . esc_html__('Alias', 'hello') . '</strong><br><code>' . esc_html($room_alias) . '</code></p>';
        }

        if ($last_error !== '') {
            echo '<p><strong>' . esc_html__('Last error', 'hello') . '</strong><br>' . esc_html($last_error) . '</p>';
        }

        echo '<p><a class="button" href="' . esc_url($create_url) . '">' . esc_html__('Create or repair room', 'hello') . '</a></p>';
    }

    public function handle_admin_create_room(): void
    {
        $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;

        if (! $post_id || ! current_user_can('edit_post', $post_id)) {
            wp_die(esc_html__('You are not allowed to create a Matrix room for this post.', 'hello'));
        }

        check_admin_referer('hello_create_room_' . $post_id);

        $post = get_post($post_id);
        if (! $post instanceof WP_Post || $post->post_type !== 'post') {
            wp_die(esc_html__('Invalid post.', 'hello'));
        }

        $room = $this->create_room_for_post($post);
        $redirect = get_edit_post_link($post_id, 'raw') ?: admin_url('edit.php');

        wp_safe_redirect(add_query_arg(
            'hello_room',
            is_wp_error($room) ? 'error' : 'created',
            $redirect
        ));
        exit;
    }

    private function sync_comment_to_matrix(int $comment_id): void
    {
        if (! in_array($this->sync_direction(), ['both', 'wp_to_matrix'], true)) {
            return;
        }

        if (get_comment_meta($comment_id, self::META_SYNCED, true) === '1') {
            return;
        }

        if (get_comment_meta($comment_id, self::META_ORIGIN, true) === 'matrix') {
            return;
        }

        $comment = get_comment($comment_id);
        if (! $comment instanceof WP_Comment) {
            return;
        }

        $post_id = (int) $comment->comment_post_ID;
        $room_id = (string) get_post_meta($post_id, self::META_ROOM_ID, true);

        if ($room_id === '') {
            $post = get_post($post_id);
            if ($post instanceof WP_Post) {
                $room = $this->create_room_for_post($post);
                $room_id = is_wp_error($room) ? '' : $room['room_id'];
            }
        }

        if ($room_id === '') {
            return;
        }

        $author = $comment->comment_author ?: __('WordPress commenter', 'hello');
        $content = wp_strip_all_tags((string) $comment->comment_content);
        $message = sprintf("%s via WordPress:\n\n%s", $author, $content);
        $transaction_id = 'wp-comment-' . $comment_id;

        $result = $this->transport()->send_room_message($room_id, $message, $transaction_id, $post_id, $comment_id, $author);
        if (is_wp_error($result)) {
            error_log('[HELLO] Failed to send comment ' . $comment_id . ' to Matrix: ' . $result->get_error_message());
            update_post_meta($post_id, self::META_LAST_ERROR, $result->get_error_message());
            return;
        }

        add_comment_meta($comment_id, self::META_SYNCED, '1', true);

        if (! empty($result['event_id']) && is_string($result['event_id'])) {
            add_comment_meta($comment_id, self::META_WP_MATRIX_EVENT_ID, sanitize_text_field($result['event_id']), true);
        }
    }

    public function render_join_button(): void
    {
        if (! is_singular('post')) {
            return;
        }

        $post_id = get_the_ID();
        if (! $post_id) {
            return;
        }

        $room_id = (string) get_post_meta($post_id, self::META_ROOM_ID, true);
        $room_alias = (string) get_post_meta($post_id, self::META_ROOM_ALIAS, true);

        if ($room_id === '') {
            return;
        }

        $matrix_target = $room_alias !== '' ? $room_alias : $room_id;
        $matrix_uri = 'matrix:r/' . ltrim($matrix_target, '#!');
        $web_uri = 'https://matrix.to/#/' . rawurlencode($matrix_target);
        ?>
        <div class="hello-join">
            <a
                href="<?php echo esc_url($web_uri); ?>"
                class="hello-join-btn"
                target="_blank"
                rel="noopener"
                data-matrix-uri="<?php echo esc_attr($matrix_uri); ?>"
                data-web-uri="<?php echo esc_url($web_uri); ?>"
            ><?php esc_html_e('Join the discussion in Beeper', 'hello'); ?></a>
            <p class="hello-hint">
                <?php esc_html_e('Messages sent in Beeper appear here as comments.', 'hello'); ?>
            </p>
            <div class="hello-copy-row">
                <code class="hello-room-address"><?php echo esc_html($matrix_target); ?></code>
                <button
                    type="button"
                    class="hello-copy-btn"
                    data-copy-value="<?php echo esc_attr($matrix_target); ?>"
                    data-copy-label="<?php echo esc_attr__('Copy room address', 'hello'); ?>"
                    data-copied-label="<?php echo esc_attr__('Copied', 'hello'); ?>"
                ><?php esc_html_e('Copy room address', 'hello'); ?></button>
            </div>
            <p class="hello-fallback">
                <?php esc_html_e('In Beeper, use Join Matrix room and paste this address if the button does not open it.', 'hello'); ?>
            </p>
            <p class="hello-copy-status" aria-live="polite"></p>
        </div>
        <?php
    }

    private function sync_direction(): string
    {
        $direction = (string) get_option('hello_sync_direction', 'both');
        return in_array($direction, ['both', 'matrix_to_wp', 'wp_to_matrix'], true) ? $direction : 'both';
    }

    private function transport(): Bridge_API
    {
        return new Bridge_API();
    }

    /**
     * @return true|WP_Error
     */
    public function authorize_rest_request(WP_REST_Request $request)
    {
        $secret = (string) get_option('hello_bot_secret', '');
        if ($secret === '' || ! hash_equals($secret, (string) $request->get_param('bot_secret'))) {
            return new WP_Error('hello_invalid_bot_secret', __('Invalid bot secret.', 'hello'), ['status' => 403]);
        }

        return true;
    }

    public function validate_required_string($value, ?WP_REST_Request $request = null, string $param = ''): bool
    {
        unset($request, $param);

        return is_string($value) && trim($value) !== '';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function known_rooms(): array
    {
        $posts = get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'fields' => 'ids',
            'numberposts' => -1,
            'meta_query' => [
                [
                    'key' => self::META_ROOM_ID,
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        $rooms = [];
        foreach ($posts as $post_id) {
            $room_id = (string) get_post_meta((int) $post_id, self::META_ROOM_ID, true);
            if ($room_id === '') {
                continue;
            }

            $rooms[] = [
                'post_id' => (int) $post_id,
                'room_id' => $room_id,
                'room_alias' => (string) get_post_meta((int) $post_id, self::META_ROOM_ALIAS, true),
                'title' => get_the_title((int) $post_id),
                'url' => get_permalink((int) $post_id),
            ];
        }

        return $rooms;
    }

    private function redact_matrix_event_for_comment(WP_Comment $comment, string $status): void
    {
        if ((string) get_option('hello_redact_on_moderation', '1') !== '1') {
            return;
        }

        if (get_comment_meta((int) $comment->comment_ID, self::META_MATRIX_REDACTED, true) === '1') {
            return;
        }

        $event_id = (string) get_comment_meta((int) $comment->comment_ID, self::META_EVENT_ID, true);
        if ($event_id === '') {
            $event_id = (string) get_comment_meta((int) $comment->comment_ID, self::META_WP_MATRIX_EVENT_ID, true);
        }

        if ($event_id === '') {
            return;
        }

        $room_id = (string) get_post_meta((int) $comment->comment_post_ID, self::META_ROOM_ID, true);
        if ($room_id === '') {
            return;
        }

        $reason = sprintf(__('WordPress comment marked as %s.', 'hello'), $status);
        $result = $this->transport()->redact_event($room_id, $event_id, $reason, 'wp-redact-comment-' . (int) $comment->comment_ID);

        if (is_wp_error($result)) {
            error_log('[HELLO] Failed to redact Matrix event for comment ' . (int) $comment->comment_ID . ': ' . $result->get_error_message());
            update_post_meta((int) $comment->comment_post_ID, self::META_LAST_ERROR, $result->get_error_message());
            return;
        }

        add_comment_meta((int) $comment->comment_ID, self::META_MATRIX_REDACTED, '1', true);
    }

    private function find_post_id_by_room(string $room_id): int
    {
        $posts = get_posts([
            'post_type' => 'post',
            'post_status' => 'any',
            'fields' => 'ids',
            'numberposts' => 1,
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => self::META_ROOM_ID,
                    'value' => $room_id,
                ],
                [
                    'key' => self::META_ROOM_ALIAS,
                    'value' => $room_id,
                ],
            ],
        ]);

        return isset($posts[0]) ? (int) $posts[0] : 0;
    }

    private function find_comment_by_event_id(string $event_id): ?WP_Comment
    {
        if ($event_id === '') {
            return null;
        }

        $comments = get_comments([
            'number' => 1,
            'status' => 'all',
            'meta_key' => self::META_EVENT_ID,
            'meta_value' => $event_id,
        ]);

        return isset($comments[0]) && $comments[0] instanceof WP_Comment ? $comments[0] : null;
    }
}
