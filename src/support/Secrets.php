<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\support;

/**
 * Which config values a read must not hand back, and what it says instead.
 *
 * A withheld value is replaced rather than dropped: a caller that sees no key
 * concludes the setting is unset. The placeholder says the setting exists,
 * holds a value, and that the value was withheld.
 *
 * Ported from stimmt/craft-mcp (MIT).
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Secrets {
    /**
     * What a withheld value reads as.
     */
    public const string PLACEHOLDER = '[redacted: this setting holds a credential]';

    /**
     * The last word of a key that names a credential.
     *
     * The last word rather than a substring: setPasswordPath, csrfTokenName,
     * tokenParam, invalidUserTokenPath and defaultTokenDuration all contain a
     * credential word and none of them is a secret.
     */
    private const array SECRET_WORDS = [
        'password',
        'secret',
        'token',
        'key',
        'dsn',
        'credentials',
        'user',
        'username',
    ];

    /**
     * Keys whose name does not say credential while the value is one. db.url
     * is a connection URL that carries the user and the password.
     */
    private const array SECRET_PATHS = ['db.url'];

    /**
     * Keys that end in a credential word while naming a setting rather than
     * holding one. general.siteToken is the name of a query string parameter.
     */
    private const array PUBLIC_PATHS = ['general.siteToken'];

    /**
     * Replace every credential reachable under this key with the placeholder.
     *
     * Walks into arrays, so a whole category or config file can be handed over
     * at once and each setting is judged on its own full key path.
     */
    public static function conceal(string $key, mixed $value): mixed {
        if (self::withholds($key)) {
            return self::isCredential($value) ? self::PLACEHOLDER : $value;
        }

        if (!is_array($value)) {
            return $value;
        }

        $concealed = [];

        foreach ($value as $name => $nested) {
            $concealed[$name] = self::conceal($key . '.' . $name, $nested);
        }

        return $concealed;
    }

    /**
     * Whether a key path names a credential.
     */
    private static function withholds(string $key): bool {
        if (self::isListed($key, self::PUBLIC_PATHS)) {
            return false;
        }

        if (self::isListed($key, self::SECRET_PATHS)) {
            return true;
        }

        return in_array(self::finalWord($key), self::SECRET_WORDS, true);
    }

    /**
     * A credential is non-empty text, or a structure holding it.
     *
     * The value type matters as well as the key: deferPublicRegistrationPassword
     * is a boolean toggle, and an empty string is a credential that was never
     * configured. Withholding either would report a secret that is not there.
     */
    private static function isCredential(mixed $value): bool {
        return is_string($value) ? $value !== '' : is_array($value);
    }

    /**
     * The last word of the final path segment, reading camelCase, snake_case
     * and kebab-case the same way.
     */
    private static function finalWord(string $key): string {
        $segments = explode('.', $key);
        $spaced = (string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', end($segments));
        $words = preg_split('/[\s_-]+/', strtolower($spaced), -1, PREG_SPLIT_NO_EMPTY);

        if ($words === false || $words === []) {
            return '';
        }

        return end($words);
    }

    /**
     * @param string[] $paths
     */
    private static function isListed(string $key, array $paths): bool {
        return in_array(strtolower($key), array_map(strtolower(...), $paths), true);
    }
}
