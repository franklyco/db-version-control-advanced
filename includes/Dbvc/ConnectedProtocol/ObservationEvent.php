<?php

namespace Dbvc\ConnectedProtocol;

/**
 * Draft `agency-control.observation.v0.2` wire event.
 *
 * An event is an immutable observation of one logical object at a source
 * sequence. It carries identity, profile, hash, existence and completeness;
 * never raw content, recipients, client or agency membership. The validator
 * implements the explicit example contract from
 * `docs/dropins/dbvc-connected-agency/contracts/observation.schema.json`
 * (required fields, closed property sets, patterns, enums and bounds). It is
 * not a general JSON Schema engine.
 */
final class ObservationEvent
{
    public const SCHEMA_VERSION = 'agency-control.observation.v0.2';
    public const MAX_SEQUENCE = 9007199254740991;
    public const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/';
    public const HASH_PATTERN = '/^[a-f0-9]{64}$/';
    public const DATE_TIME_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/';

    public const DOMAINS = ['bricks.global_class', 'bricks.variable', 'wp.service'];
    public const ORIGINS = ['human', 'apply', 'rollback', 'reconciliation'];

    private const TOP_LEVEL_KEYS = [
        'schema_version', 'event_id', 'environment_id', 'installation_epoch', 'sequence',
        'observed_at', 'object', 'projection', 'origin', 'causation_id',
    ];
    private const OBJECT_KEYS = ['domain', 'instance_uid'];
    private const PROJECTION_KEYS = ['profile', 'hash', 'exists', 'complete'];

    /**
     * Build a body in the schema's key order. Missing fields are left absent so
     * validate() reports them; nothing is defaulted silently except
     * `schema_version`, `origin` (human) and `causation_id` (null).
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function build(array $fields)
    {
        $object = isset($fields['object']) && is_array($fields['object']) ? $fields['object'] : [];
        $projection = isset($fields['projection']) && is_array($fields['projection']) ? $fields['projection'] : [];

        $body = [
            'schema_version' => self::SCHEMA_VERSION,
        ];
        foreach (['event_id', 'environment_id', 'installation_epoch', 'sequence', 'observed_at'] as $key) {
            if (array_key_exists($key, $fields)) {
                $body[$key] = $fields[$key];
            }
        }
        $body['object'] = [];
        foreach (self::OBJECT_KEYS as $key) {
            if (array_key_exists($key, $object)) {
                $body['object'][$key] = $object[$key];
            }
        }
        $body['projection'] = [];
        foreach (self::PROJECTION_KEYS as $key) {
            if (array_key_exists($key, $projection)) {
                $body['projection'][$key] = $projection[$key];
            }
        }
        $body['origin'] = array_key_exists('origin', $fields) ? $fields['origin'] : 'human';
        $body['causation_id'] = array_key_exists('causation_id', $fields) ? $fields['causation_id'] : null;

        return $body;
    }

    /**
     * @param mixed $body
     * @return array<int, string> Error codes; empty when the body is valid.
     */
    public static function validate($body)
    {
        $errors = [];
        if (! is_array($body)) {
            return ['body_not_object'];
        }

        foreach (self::TOP_LEVEL_KEYS as $key) {
            if (! array_key_exists($key, $body)) {
                $errors[] = 'missing:' . $key;
            }
        }
        foreach (array_keys($body) as $key) {
            if (! in_array((string) $key, self::TOP_LEVEL_KEYS, true)) {
                $errors[] = 'unknown:' . $key;
            }
        }

        if (array_key_exists('schema_version', $body) && $body['schema_version'] !== self::SCHEMA_VERSION) {
            $errors[] = 'invalid:schema_version';
        }
        foreach (['event_id', 'environment_id', 'installation_epoch'] as $key) {
            if (array_key_exists($key, $body) && ! self::isIdentifier($body[$key])) {
                $errors[] = 'invalid:' . $key;
            }
        }
        if (array_key_exists('sequence', $body)) {
            $sequence = $body['sequence'];
            if (! is_int($sequence) || $sequence < 1 || $sequence > self::MAX_SEQUENCE) {
                $errors[] = 'invalid:sequence';
            }
        }
        if (array_key_exists('observed_at', $body)) {
            if (! is_string($body['observed_at']) || ! preg_match(self::DATE_TIME_PATTERN, $body['observed_at'])) {
                $errors[] = 'invalid:observed_at';
            }
        }

        if (array_key_exists('object', $body)) {
            $object = $body['object'];
            if (! is_array($object)) {
                $errors[] = 'invalid:object';
            } else {
                foreach (self::OBJECT_KEYS as $key) {
                    if (! array_key_exists($key, $object)) {
                        $errors[] = 'missing:object.' . $key;
                    }
                }
                foreach (array_keys($object) as $key) {
                    if (! in_array((string) $key, self::OBJECT_KEYS, true)) {
                        $errors[] = 'unknown:object.' . $key;
                    }
                }
                if (array_key_exists('domain', $object) && ! in_array($object['domain'], self::DOMAINS, true)) {
                    $errors[] = 'invalid:object.domain';
                }
                if (array_key_exists('instance_uid', $object) && ! self::isIdentifier($object['instance_uid'])) {
                    $errors[] = 'invalid:object.instance_uid';
                }
            }
        }

        if (array_key_exists('projection', $body)) {
            $projection = $body['projection'];
            if (! is_array($projection)) {
                $errors[] = 'invalid:projection';
            } else {
                foreach (self::PROJECTION_KEYS as $key) {
                    if (! array_key_exists($key, $projection)) {
                        $errors[] = 'missing:projection.' . $key;
                    }
                }
                foreach (array_keys($projection) as $key) {
                    if (! in_array((string) $key, self::PROJECTION_KEYS, true)) {
                        $errors[] = 'unknown:projection.' . $key;
                    }
                }
                if (array_key_exists('profile', $projection) && ! self::isIdentifier($projection['profile'])) {
                    $errors[] = 'invalid:projection.profile';
                }
                if (array_key_exists('hash', $projection) && (! is_string($projection['hash']) || ! preg_match(self::HASH_PATTERN, $projection['hash']))) {
                    $errors[] = 'invalid:projection.hash';
                }
                foreach (['exists', 'complete'] as $key) {
                    if (array_key_exists($key, $projection) && ! is_bool($projection[$key])) {
                        $errors[] = 'invalid:projection.' . $key;
                    }
                }
            }
        }

        if (array_key_exists('origin', $body) && ! in_array($body['origin'], self::ORIGINS, true)) {
            $errors[] = 'invalid:origin';
        }
        if (array_key_exists('causation_id', $body) && $body['causation_id'] !== null && ! self::isIdentifier($body['causation_id'])) {
            $errors[] = 'invalid:causation_id';
        }

        return $errors;
    }

    /**
     * Canonical encoding of a body (sorted keys, version-1 canonicalizer).
     *
     * @param array<string, mixed> $body
     * @return string
     * @throws CanonicalizationException
     */
    public static function encode(array $body)
    {
        return Canonicalizer::encode($body);
    }

    /**
     * @param array<string, mixed> $body
     * @return string SHA-256 of the canonical body; the immutable event digest.
     * @throws CanonicalizationException
     */
    public static function digest(array $body)
    {
        return Canonicalizer::hash(self::encode($body));
    }

    /**
     * @param mixed $value
     * @return bool
     */
    public static function isIdentifier($value)
    {
        return is_string($value)
            && $value !== ''
            && strlen($value) <= 128
            && preg_match(self::ID_PATTERN, $value) === 1;
    }
}
