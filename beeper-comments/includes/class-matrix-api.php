<?php

declare(strict_types=1);

namespace BeeperComments;

use WP_Error;
use WP_Post;

class Matrix_API
{
    private string $homeserver;
    private string $access_token;
    private string $bot_user;

    public function __construct()
    {
        $this->homeserver = rtrim((string) get_option('beeper_comments_homeserver', ''), '/');
        $this->access_token = (string) get_option('beeper_comments_bot_token', '');
        $this->bot_user = (string) get_option('beeper_comments_bot_user', '');
    }

    public function is_configured(): bool
    {
        return $this->homeserver !== '' && $this->access_token !== '';
    }

    /**
     * @return array{room_id: string, room_alias?: string}|WP_Error
     */
    public function create_room_for_post(WP_Post $post)
    {
        if (! $this->is_configured()) {
            return new WP_Error('beeper_comments_matrix_not_configured', __('Matrix homeserver and bot token are required.', 'beeper-comments'));
        }

        $alias_prefix = $this->sanitize_alias_prefix((string) get_option('beeper_comments_room_alias_prefix', 'post-'));
        if ($alias_prefix === '') {
            $alias_prefix = 'post-';
        }

        $room_alias_name = $alias_prefix . (string) $post->ID;
        $payload = [
            'name' => sprintf(__('Comments: %s', 'beeper-comments'), get_the_title($post)),
            'topic' => sprintf(__('Discussion thread for: %s', 'beeper-comments'), get_permalink($post)),
            'preset' => 'public_chat',
            'room_alias_name' => $room_alias_name,
            'initial_state' => [
                [
                    'type' => 'm.room.guest_access',
                    'content' => [
                        'guest_access' => 'can_join',
                    ],
                ],
            ],
        ];

        $response = $this->request('POST', '/_matrix/client/v3/createRoom', $payload);
        if (is_wp_error($response)) {
            return $response;
        }

        if (empty($response['room_id']) || ! is_string($response['room_id'])) {
            return new WP_Error('beeper_comments_missing_room_id', __('Matrix did not return a room ID.', 'beeper-comments'));
        }

        $result = [
            'room_id' => $response['room_id'],
        ];

        if (! empty($response['room_alias']) && is_string($response['room_alias'])) {
            $result['room_alias'] = $response['room_alias'];
        } elseif ($this->bot_user !== '' && str_contains($this->bot_user, ':')) {
            $result['room_alias'] = '#' . $room_alias_name . ':' . substr(strstr($this->bot_user, ':'), 1);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function get_account()
    {
        if (! $this->is_configured()) {
            return new WP_Error('beeper_comments_matrix_not_configured', __('Matrix homeserver and bot token are required.', 'beeper-comments'));
        }

        return $this->request('GET', '/_matrix/client/v3/account/whoami');
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function send_room_message(string $room_id, string $message, string $transaction_id)
    {
        if (! $this->is_configured()) {
            return new WP_Error('beeper_comments_matrix_not_configured', __('Matrix homeserver and bot token are required.', 'beeper-comments'));
        }

        $path = sprintf(
            '/_matrix/client/v3/rooms/%s/send/m.room.message/%s',
            rawurlencode($room_id),
            rawurlencode($transaction_id)
        );

        return $this->request('PUT', $path, [
            'msgtype' => 'm.text',
            'body' => $message,
        ]);
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function redact_event(string $room_id, string $event_id, string $reason, string $transaction_id)
    {
        if (! $this->is_configured()) {
            return new WP_Error('beeper_comments_matrix_not_configured', __('Matrix homeserver and bot token are required.', 'beeper-comments'));
        }

        $path = sprintf(
            '/_matrix/client/v3/rooms/%s/redact/%s/%s',
            rawurlencode($room_id),
            rawurlencode($event_id),
            rawurlencode($transaction_id)
        );

        return $this->request('PUT', $path, [
            'reason' => $reason,
        ]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>|WP_Error
     */
    private function request(string $method, string $path, array $body = [])
    {
        $url = $this->homeserver . $path;
        $args = [
            'method' => $method,
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->access_token,
                'Content-Type' => 'application/json',
            ],
        ];

        if ($method !== 'GET') {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
        $decoded = json_decode($raw_body, true);

        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) && isset($decoded['error']) ? (string) $decoded['error'] : $raw_body;
            return new WP_Error('beeper_comments_matrix_error', $message, ['status' => $status]);
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function sanitize_alias_prefix(string $prefix): string
    {
        return preg_replace('/[^a-z0-9._=-]/', '', strtolower($prefix)) ?: '';
    }
}
