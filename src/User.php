<?php

namespace AuthKit;

/**
 * Class User
 * Represents a user in the authentication system.
 * Provides methods to access user attributes and serialize to JSON.
 */
class User implements \JsonSerializable
{
    private array $attributes;

    /**
     * User constructor.
     * Initializes the user with an array of attributes.
     *
     * @param array $attributes Associative array of user attributes.
     */
    // The attributes array can contain any user-related data, such as 'id', 'email', 'name', etc.
    // This allows for flexibility in what data is stored and accessed.
    public function __construct(array $attributes)
    {
        $this->attributes = $attributes;
    }

    /**
     * Returns one field or multiple fields by key(s).
     * Example: get('email') or get(['email', 'name'])
     */
    // This method allows you to retrieve user attributes by their keys.
    // If a single string is passed, it returns the value of that attribute.
    // If an array of strings is passed, it returns an associative array with the requested attributes.
    // If an attribute does not exist, it returns null for that key.
    // This is useful for accessing user data in a flexible way, allowing you to get either
    // a single value or multiple values at once without needing to know the exact structure of the
    // user data beforehand.
    // @param array|string $fields A single field name or an array of field names to retrieve.
    // @return mixed The value of the requested field(s) or an associative array of values.
    // If the field does not exist, it returns null for that field.
    // @throws \InvalidArgumentException If $fields is neither a string nor an array.
    // This method is designed to be flexible, allowing you to retrieve user data in a way
    // that suits your needs. It can handle both single field requests and multiple field requests,
    // making it easy to work with user attributes without needing to know the exact structure of the
    // user data.
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
     * Returns all available user data.
     */
    // This method returns the entire attributes array, which contains all the user data.
    // It is useful when you need to access all user attributes at once, for example,
    // when displaying user information or when you need to serialize the user data for storage
    // or transmission.
    // The returned array will include all attributes that were set when the User object was created.
    // This method does not modify the attributes in any way; it simply returns a copy of
    // the attributes array as it currently exists in the User object.
    public function getAll(): array
    {
        return $this->attributes;
    }

    /**
     * Compatibility helpers.
     */
    // These methods provide a way to access user attributes using common names.
    // They are useful for backward compatibility or when the attribute names are known.
    public function getId(): ?int
    {
        return $this->get('id');
    }

    /**
     * Get the user's email address.
     * Returns null if the email is not set.
     */
    // This method is useful for retrieving the user's email address, which is often used for login
    // and communication purposes. It returns null if the email attribute is not set, allowing for
    // graceful handling of cases where the email is not available.
    public function getEmail(): ?string
    {
        return $this->get('email');
    }

    /**
     * Support for json_encode($user)
     */
    // This method allows the User object to be serialized to JSON format.
    // It implements the JsonSerializable interface, which is a standard way in PHP to define how
    // an object should be serialized when using json_encode().
    // When json_encode() is called on a User object, it will return the attributes array,
    // which contains all the user data. This makes it easy to convert a User object to
    // a JSON representation, suitable for APIs or other data interchange formats.
    public function jsonSerialize(): array
    {
        return $this->attributes;
    }

    /**
     * Convert to JSON string manually.
     * @param bool $pretty Whether to pretty-print the JSON.
     */
    // This method converts the User object to a JSON string.
    // It uses json_encode() to serialize the attributes array.
    // The $pretty parameter allows for pretty-printing the JSON output, which can be useful
    // for debugging or logging purposes. If $pretty is true, the JSON will be formatted
    // with indentation and newlines for better readability. If false, it will be a compact
    // representation without extra whitespace.
    // The method returns the JSON string representation of the user attributes.
    // It can be used when you need a JSON representation of the user data, for example
    // when sending user information in a web API response or storing it in a file.
    public function toJson(bool $pretty = false): string
    {
        return json_encode(
            $this->attributes,
            $pretty ? JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE : 0
        );
    }
}
