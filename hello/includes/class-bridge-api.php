<?php

declare(strict_types=1);

namespace Hello;

use WP_Error;
use WP_Post;

class Bridge_API
{
    private string $bridge_url;
    private string $bridge_token;

    public function __construct()
    {
        $this->bridge_url = rtrim((string) get_option('hello_bridge_url', ''), '/');
        $this->bridge_token = (string) get_option('hello_bridge_token', '');
    }

    public function is_configured(): bool
    {
        return $this->bridge_url !== '' && $this->bridge_token !== '';
    }

    /**
     * @return array{room_id: string, room_alias?: string}|WP_Error
     */
    public function create_room_for_post(WP_Post $post)
    {
        if (! $this->is_configured()) {
            return new WP_Error('hello_bridge_not_configured', __('Bridge URL and token are required.', 'hello'));
        }

        $response = $this->request('POST', '/v1/rooms', [
            'site' => $this->site_payload(),
            'post' => [
                'id' => (int) $post->ID,
                'title' => get_the_title($post),
                'url' => get_permalink($post),
            ],
            'room' => [
                'alias_name' => $this->room_alias_name($post),
                'name' => sprintf(__('Comments: %s', 'hello'), get_the_title($post)),
                'topic' => sprintf(__('Discussion thread for: %s', 'hello'), get_permalink($post)),
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        if (empty($response['room_id']) || ! is_string($response['room_id'])) {
            return new WP_Error('hello_bridge_missing_room_id', __('Bridge did not return a room ID.', 'hello'));
        }

        $result = [
            'room_id' => $response['room_id'],
        ];

        if (! empty($response['room_alias']) && is_string($response['room_alias'])) {
            $result['room_alias'] = $response['room_alias'];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function send_room_message(string $room_id, string $message, string $transaction_id, int $post_id = 0, int $comment_id = 0, string $author = '')
    {
        if (! $this->is_configured()) {
            return new WP_Error('hello_bridge_not_configured', __('Bridge URL and token are required.', 'hello'));
        }

        return $this->request('POST', '/v1/comments', [
            'site' => $this->site_payload(),
            'room_id' => $room_id,
            'post_id' => $post_id,
            'comment_id' => $comment_id,
            'author' => $author,
            'message' => $message,
            'transaction_id' => $transaction_id,
        ]);
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function redact_event(string $room_id, string $event_id, string $reason, string $transaction_id)
    {
        if (! $this->is_configured()) {
            return new WP_Error('hello_bridge_not_configured', __('Bridge URL and token are required.', 'hello'));
        }

        return $this->request('POST', '/v1/redactions', [
            'site' => $this->site_payload(),
            'room_id' => $room_id,
            'event_id' => $event_id,
            'reason' => $reason,
            'transaction_id' => $transaction_id,
        ]);
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function get_status()
    {
        if (! $this->is_configured()) {
            return new WP_Error('hello_bridge_not_configured', __('Bridge URL and token are required.', 'hello'));
        }

        return $this->request('POST', '/v1/health', [
            'site' => $this->site_payload(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function site_payload(): array
    {
        return [
            'url' => home_url('/'),
            'name' => get_bloginfo('name'),
            'rest_url' => rest_url('hello/v1/'),
            'incoming_url' => rest_url('hello/v1/incoming'),
            'webhook_secret' => (string) get_option('hello_bot_secret', ''),
        ];
    }

    private function room_alias_name(WP_Post $post): string
    {
        $prefix = preg_replace('/[^a-z0-9._=-]/', '', strtolower((string) get_option('hello_room_alias_prefix', 'post-'))) ?: 'post-';
        $site_hash = substr(hash('sha256', home_url('/')), 0, 10);

        return $prefix . $site_hash . '-' . (int) $post->ID;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>|WP_Error
     */
    private function request(string $method, string $path, array $body = [])
    {
        $response = wp_remote_request($this->bridge_url . $path, [
            'method' => $method,
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->bridge_token,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
        $decoded = json_decode($raw_body, true);

        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) && isset($decoded['error']) ? (string) $decoded['error'] : $raw_body;
            return new WP_Error('hello_bridge_error', $message, ['status' => $status]);
        }

        return is_array($decoded) ? $decoded : [];
    }
}
