<?php

declare(strict_types=1);

namespace BeeperComments;

class Gravatar
{
    public static function boot(): void
    {
        add_filter('get_avatar_data', [self::class, 'filter_avatar_data'], 10, 2);
    }

    /**
     * @param array<string, mixed> $args
     * @param mixed $id_or_email
     * @return array<string, mixed>
     */
    public static function filter_avatar_data(array $args, $id_or_email): array
    {
        $comment = null;

        if ($id_or_email instanceof \WP_Comment) {
            $comment = $id_or_email;
        } elseif (is_object($id_or_email) && isset($id_or_email->comment_ID)) {
            $comment = get_comment((int) $id_or_email->comment_ID);
        }

        if (! $comment instanceof \WP_Comment) {
            return $args;
        }

        $avatar_url = (string) get_comment_meta((int) $comment->comment_ID, '_beeper_comments_gravatar_avatar_url', true);
        if ($avatar_url !== '') {
            $args['url'] = esc_url_raw($avatar_url);
            $args['found_avatar'] = true;
            return $args;
        }

        $hash = self::sanitize_hash((string) get_comment_meta((int) $comment->comment_ID, '_beeper_comments_gravatar_hash', true));
        if ($hash === '') {
            return $args;
        }

        $size = isset($args['size']) ? (int) $args['size'] : 96;
        $args['url'] = sprintf('https://www.gravatar.com/avatar/%s?s=%d&d=identicon', $hash, $size);
        $args['found_avatar'] = true;

        return $args;
    }

    public static function sanitize_hash(string $hash): string
    {
        $hash = strtolower(trim($hash));
        return preg_match('/^[a-f0-9]{32}$/', $hash) ? $hash : '';
    }
}
