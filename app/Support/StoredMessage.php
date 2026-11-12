<?php

namespace App\Support;

/**
 * A sentence that is saved in the database now and shown to a visitor later, in the visitor's language.
 *
 * Instead of a finished sentence we store a translation key with its real values, as JSON:
 * {"key":"repositories.errors.clone_timeout","params":{"minutes":4}}. When the interface shows it,
 * render() turns it into text with __() in the language of the current request.
 *
 * A stored string that is not such JSON (a row written before this class existed, or the raw message of an
 * unexpected exception) is shown unchanged, so old rows keep working.
 */
final class StoredMessage
{
    /**
     * The string to save for a translation key and the values of its :placeholders.
     *
     * @param  array<string, string|int|float>  $params
     */
    public static function make(string $key, array $params = []): string
    {
        return json_encode(['key' => $key, 'params' => (object) $params], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * The text of a stored string in the current language; null stays null.
     */
    public static function render(?string $stored): ?string
    {
        if ($stored === null) {
            return null;
        }

        $decoded = json_decode($stored, true);

        if (! is_array($decoded) || ! is_string($decoded['key'] ?? null)) {
            return $stored;
        }

        $params = is_array($decoded['params'] ?? null) ? $decoded['params'] : [];

        return __($decoded['key'], $params);
    }
}
