<?php

declare(strict_types=1);

namespace BeeperComments;

use WP_Comment;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

class Comment_Sync
{
    public const META_ROOM_ID = '_beeper_comments_room_id';
    public const META_ROOM_ALIAS = '_beeper_comments_room_alias';
    public const META_ORIGIN = '_beeper_comments_origin';
    public const META_SYNCED = '_beeper_comments_synced';
    public const META_MATRIX_ID = '_beeper_comments_matrix_id';
    public const META_EVENT_ID = '_beeper_comments_event_id';

    public function boot(): void
    {
        add_action('transition_post_status', [$this, 'maybe_create_room_on_publish'], 10, 3);
        add_action('comment_post', [$this, 'sync_wordpress_comment_to_matrix'], 20, 3);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('comment_form_after', [$this, 'render_join_button']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function enqueue_assets(): void
    {
        if (! is_singular('post')) {
            return;
        }

        wp_enqueue_script(
            'beeper-comments-join-button',
            BEEPER_COMMENTS_URL . 'assets/join-button.js',
            [],
            BEEPER_COMMENTS_VERSION,
            true
        );

        wp_enqueue_style(
            'beeper-comments-join-button',
            BEEPER_COMMENTS_URL . 'assets/join-button.css',
            [],
            BEEPER_COMMENTS_VERSION
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
        $api = new Matrix_API();
        $room = $api->create_room_for_post($post);

        if (is_wp_error($room)) {
            error_log('[Beeper Comments] Failed to create Matrix room for post ' . $post->ID . ': ' . $room->get_error_message());
            return $room;
        }

        update_post_meta($post->ID, self::META_ROOM_ID, sanitize_text_field($room['room_id']));

        if (! empty($room['room_alias'])) {
            update_post_meta($post->ID, self::META_ROOM_ALIAS, sanitize_text_field((string) $room['room_alias']));
        }

        return $room;
    }

    public function register_rest_routes(): void
    {
        register_rest_route('beeper-comments/v1', '/incoming', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_incoming_matrix_message'],
            'permission_callback' => '__return_true',
            'args' => [
                'room_id' => [
                    'required' => true,
                    'type' => 'string',
                ],
                'matrix_user_id' => [
                    'required' => true,
                    'type' => 'string',
                ],
                'message' => [
                    'required' => true,
                    'type' => 'string',
                ],
                'event_id' => [
                    'required' => true,
                    'type' => 'string',
                ],
                'bot_secret' => [
                    'required' => true,
                    'type' => 'string',
                ],
            ],
        ]);
    }

    public function handle_incoming_matrix_message(WP_REST_Request $request): WP_REST_Response
    {
        $secret = (string) get_option('beeper_comments_bot_secret', '');
        if ($secret === '' || ! hash_equals($secret, (string) $request->get_param('bot_secret'))) {
            return new WP_REST_Response(['message' => __('Invalid bot secret.', 'beeper-comments')], 403);
        }

        if (! in_array($this->sync_direction(), ['both', 'matrix_to_wp'], true)) {
            return new WP_REST_Response(['message' => __('Matrix to WordPress sync is disabled.', 'beeper-comments')], 202);
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
            return new WP_REST_Response(['message' => __('No WordPress post is mapped to this Matrix room.', 'beeper-comments')], 404);
        }

        $matrix_user_id = sanitize_text_field((string) $request->get_param('matrix_user_id'));
        $author_name = sanitize_text_field((string) ($request->get_param('author_name') ?: $matrix_user_id));
        $author_url = esc_url_raw((string) $request->get_param('author_url'));
        $message = wp_kses_post((string) $request->get_param('message'));
        $status = get_option('comment_moderation') ? '0' : '1';

        $comment_id = wp_insert_comment(wp_slash([
            'comment_post_ID' => $post_id,
            'comment_author' => $author_name,
            'comment_author_email' => '',
            'comment_author_url' => $author_url,
            'comment_content' => $message,
            'comment_type' => 'comment',
            'comment_approved' => $status,
            'comment_agent' => 'Beeper Comments Matrix Bot',
            'comment_date' => current_time('mysql'),
            'comment_date_gmt' => current_time('mysql', true),
        ]));

        if (! $comment_id) {
            return new WP_REST_Response(['message' => __('Unable to create WordPress comment.', 'beeper-comments')], 500);
        }

        add_comment_meta($comment_id, self::META_ORIGIN, 'matrix', true);
        add_comment_meta($comment_id, self::META_SYNCED, '1', true);
        add_comment_meta($comment_id, self::META_MATRIX_ID, $matrix_user_id, true);
        add_comment_meta($comment_id, self::META_EVENT_ID, $event_id, true);

        $hash = Gravatar::sanitize_hash((string) $request->get_param('author_email_hash'));
        if ($hash !== '') {
            add_comment_meta($comment_id, '_beeper_comments_gravatar_hash', $hash, true);
        }

        $avatar_url = esc_url_raw((string) $request->get_param('author_avatar_url'));
        if ($avatar_url !== '') {
            add_comment_meta($comment_id, '_beeper_comments_gravatar_avatar_url', $avatar_url, true);
        }

        return new WP_REST_Response([
            'comment_id' => (int) $comment_id,
            'status' => $status === '1' ? 'approved' : 'hold',
        ], 201);
    }

    public function sync_wordpress_comment_to_matrix(int $comment_id, $comment_approved, array $comment_data): void
    {
        if (! in_array($this->sync_direction(), ['both', 'wp_to_matrix'], true)) {
            return;
        }

        if ((string) $comment_approved !== '1') {
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

        $author = $comment->comment_author ?: __('WordPress commenter', 'beeper-comments');
        $content = wp_strip_all_tags((string) $comment->comment_content);
        $message = sprintf("%s via WordPress:\n\n%s", $author, $content);
        $transaction_id = 'wp-comment-' . $comment_id;

        $result = (new Matrix_API())->send_room_message($room_id, $message, $transaction_id);
        if (is_wp_error($result)) {
            error_log('[Beeper Comments] Failed to send comment ' . $comment_id . ' to Matrix: ' . $result->get_error_message());
            return;
        }

        add_comment_meta($comment_id, self::META_SYNCED, '1', true);
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
        <div class="beeper-comments-join">
            <a
                href="<?php echo esc_url($matrix_uri, ['matrix']); ?>"
                class="beeper-join-btn"
                data-matrix-uri="<?php echo esc_attr($matrix_uri); ?>"
                data-web-uri="<?php echo esc_url($web_uri); ?>"
            ><?php esc_html_e('Join the discussion in Beeper', 'beeper-comments'); ?></a>
            <p class="beeper-comments-hint">
                <?php esc_html_e('Opens in Beeper or any Matrix client. Messages appear here as comments.', 'beeper-comments'); ?>
                <a href="<?php echo esc_url($web_uri); ?>"><?php esc_html_e('Open on matrix.to', 'beeper-comments'); ?></a>
            </p>
        </div>
        <?php
    }

    private function sync_direction(): string
    {
        $direction = (string) get_option('beeper_comments_sync_direction', 'both');
        return in_array($direction, ['both', 'matrix_to_wp', 'wp_to_matrix'], true) ? $direction : 'both';
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
