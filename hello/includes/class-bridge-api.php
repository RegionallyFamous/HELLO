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
        $bridge_url = defined('HELLO_BRIDGE_URL') ? (string) constant('HELLO_BRIDGE_URL') : (string) get_option('hello_bridge_url', '');
        $this->bridge_url = rtrim($bridge_url ?: HELLO_DEFAULT_BRIDGE_URL, '/');
        $this->bridge_token = (string) get_option('hello_bridge_token', '');
    }

    public function is_configured(): bool
    {
        return $this->bridge_url !== '';
    }

    /**
     * @return array{site_id: string, bridge_token: string}|WP_Error
     */
    public function register_site()
    {
        if (! $this->is_configured()) {
            return new WP_Error('hello_bridge_not_configured', __('HELLO Bridge URL is required.', 'hello'));
        }

        $response = $this->request('POST', '/v1/sites', [
            'site' => $this->site_payload(),
        ], false);

        if (is_wp_error($response)) {
            return $response;
        }

        if (empty($response['bridge_token']) || ! is_string($response['bridge_token'])) {
            return new WP_Error('hello_bridge_missing_site_token', __('Bridge did not return a site token.', 'hello'));
        }

        $this->bridge_token = sanitize_text_field($response['bridge_token']);
        update_option('hello_bridge_token', $this->bridge_token);

        return [
            'site_id' => isset($response['site_id']) && is_string($response['site_id'])
                ? sanitize_text_field($response['site_id'])
                : $this->site_id(),
            'bridge_token' => $this->bridge_token,
        ];
    }

    /**
     * @return array{room_id: string, room_alias?: string}|WP_Error
     */
    public function create_room_for_post(WP_Post $post)
    {
        $registered = $this->ensure_registered();
        if (is_wp_error($registered)) {
            return $registered;
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
        $registered = $this->ensure_registered();
        if (is_wp_error($registered)) {
            return $registered;
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
        $registered = $this->ensure_registered();
        if (is_wp_error($registered)) {
            return $registered;
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
        $registered = $this->ensure_registered();
        if (is_wp_error($registered)) {
            return $registered;
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
            'site_id' => $this->site_id(),
            'url' => home_url('/'),
            'name' => get_bloginfo('name'),
            'rest_url' => rest_url('hello/v1/'),
            'incoming_url' => rest_url('hello/v1/incoming'),
            'webhook_secret' => (string) get_option('hello_bot_secret', ''),
        ];
    }

    /**
     * @return true|WP_Error
     */
    private function ensure_registered()
    {
        if ($this->bridge_token !== '') {
            return true;
        }

        $registered = $this->register_site();
        return is_wp_error($registered) ? $registered : true;
    }

    private function site_id(): string
    {
        $site_id = (string) get_option('hello_site_id', '');
        if ($site_id === '') {
            $site_id = wp_generate_uuid4();
            update_option('hello_site_id', $site_id);
        }

        return $site_id;
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
    private function request(string $method, string $path, array $body = [], bool $authenticated = true)
    {
        $headers = [
            'Content-Type' => 'application/json',
        ];

        if ($authenticated) {
            if ($this->bridge_token === '') {
                return new WP_Error('hello_bridge_not_registered', __('HELLO site registration is required.', 'hello'));
            }

            $headers['Authorization'] = 'Bearer ' . $this->bridge_token;
        }

        $response = wp_remote_request($this->bridge_url . $path, [
            'method' => $method,
            'timeout' => 15,
            'headers' => $headers,
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
