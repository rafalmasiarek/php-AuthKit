<?php

namespace AuthKit;

/**
 * Class User
 * Represents a user in the authentication system.
 * Provides methods to access user attributes and serialize to JSON.
 */
class User implements \JsonSerializable
{
    /** @var array<string,mixed> */
    private array $attributes;

    /**
     * Default sensitive keys that must never be exposed by getters/serializers.
     * @var string[]
     */
    private const DEFAULT_HIDDEN_KEYS = [
        'password_hash',
        'totp_secret',
        'recovery_codes',
        'reset_token',
        'reset_token_expires_at',
    ];

    /**
     * Extra keys provided by the application to hide in addition to defaults.
     * @var string[]
     */
    private static array $extraHiddenKeys = [];

    /**
     * User constructor.
     * Initializes the user with an array of attributes.
     *
     * @param array<string,mixed> $attributes Associative array of user attributes.
     */
    public function __construct(array $attributes)
    {
        $this->attributes = $attributes;
    }

    /**
     * Configure additional keys to hide (replaces previously set extra keys).
     * Usage: User::setHiddenKeys(['api_key', 'private_notes']);
     *
     * @param string[] $keys
     */
    public static function setHiddenKeys(array $keys): void
    {
        // Normalize to unique string keys
        self::$extraHiddenKeys = array_values(array_unique(array_map('strval', $keys)));
    }

    /**
     * Add more keys to hide (keeps any previously configured extras).
     *
     * @param string[] $keys
     */
    public static function addHiddenKeys(array $keys): void
    {
        self::$extraHiddenKeys = array_values(array_unique(array_merge(
            self::$extraHiddenKeys,
            array_map('strval', $keys)
        )));
    }

    /**
     * Returns the complete effective hidden-keys list (defaults + extras).
     *
     * @return string[]
     */
    public static function getHiddenKeys(): array
    {
        return array_values(array_unique(array_merge(
            self::DEFAULT_HIDDEN_KEYS,
            self::$extraHiddenKeys
        )));
    }

    /**
     * Return a copy of attributes with hidden keys removed.
     *
     * @return array<string,mixed>
     */
    private function filteredAttributes(): array
    {
        $data = $this->attributes;
        foreach (self::getHiddenKeys() as $key) {
            if (array_key_exists($key, $data)) {
                unset($data[$key]);
            }
        }
        return $data;
    }

    /**
     * Returns one field or multiple fields by key(s).
     * Example: get('email') or get(['email', 'name'])
     *
     * @param array<string>|string $fields
     * @return mixed
     */
    public function get(array|string $fields): mixed
    {
        if (is_string($fields)) {
            return $this->attributes[$fields] ?? null;
        }

        $result = [];
        foreach ($fields as $key) {
            $result[$key] = $this->attributes[$key] ?? null;
        }
        return $result;
    }

    /**
     * Returns all available user data EXCEPT hidden keys.
     *
     * @return array<string,mixed>
     */
    public function getAll(): array
    {
        return $this->filteredAttributes();
    }

    /**
     * Compatibility helpers.
     */
    public function getId(): ?int
    {
        $id = $this->get('id');
        return is_int($id) ? $id : null;
    }

    /**
     * Get the user's email address.
     * Returns null if the email is not set.
     */
    public function getEmail(): ?string
    {
        $email = $this->get('email');
        return is_string($email) ? $email : null;
    }

    /**
     * Support for json_encode($user)
     * Returns attributes without hidden keys.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->filteredAttributes();
    }

    /**
     * Convert to JSON string manually (without hidden keys).
     * @param bool $pretty Whether to pretty-print the JSON.
     */
    public function toJson(bool $pretty = false): string
    {
        return json_encode(
            $this->jsonSerialize(),
            $pretty ? JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE : 0
        ) ?: 'null';
    }

    /**
     * Internal-only raw accessor to full attributes including hidden keys.
     * Prefer NOT to use this in application code. Keep it private/protected if undesired.
     *
     * @return array<string,mixed>
     */
    public function _getAllRaw(): array
    {
        return $this->attributes;
    }
}
