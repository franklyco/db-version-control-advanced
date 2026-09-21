<?php

namespace Dbvc\ConnectedProtocol;

/**
 * Shared wire constants for the hub-mediated reporting protocol. Both roles
 * read these; neither may relax them unilaterally. Limits are the package's
 * proposed pilot values, not measured capacities.
 */
final class Protocol
{
    public const REST_NAMESPACE = 'dbvc-agency/v1';
    public const PROTOCOL_VERSION = ObservationEvent::SCHEMA_VERSION;

    public const MAX_BATCH_EVENTS = 50;
    public const MAX_BATCH_BYTES = 262144;
    public const REQUEST_TIMEOUT_SECONDS = 10;
    public const MAX_INBOX_ITEMS = 100;
    /** Release payloads: canonical bodies per item and per upload request. */
    public const MAX_PAYLOAD_BYTES = 1048576;
    public const MAX_PAYLOAD_ITEMS = 25;
    public const MAX_RELEASE_ITEMS = 200;
    /** A prepare receipt describes target state at one moment; it expires and is never permission to write. */
    public const PREPARE_RECEIPT_TTL_SECONDS = 3600;

    public const OUTCOME_ACCEPTED = 'accepted';
    public const OUTCOME_DUPLICATE = 'duplicate';
    public const OUTCOME_CONFLICT = 'conflict';
    public const OUTCOME_REJECTED = 'rejected';

    public const HEADER_SITE_URL = 'X-DBVC-Site-URL';
    public const HEADER_PROTOCOL = 'X-DBVC-Protocol';

    /**
     * @return array<string, mixed>
     */
    public static function describe()
    {
        return [
            'protocol' => self::PROTOCOL_VERSION,
            'namespace' => self::REST_NAMESPACE,
            'limits' => [
                'max_batch_events' => self::MAX_BATCH_EVENTS,
                'max_batch_bytes' => self::MAX_BATCH_BYTES,
                'request_timeout_seconds' => self::REQUEST_TIMEOUT_SECONDS,
                'max_inbox_items' => self::MAX_INBOX_ITEMS,
                'max_payload_bytes' => self::MAX_PAYLOAD_BYTES,
                'max_payload_items' => self::MAX_PAYLOAD_ITEMS,
                'max_release_items' => self::MAX_RELEASE_ITEMS,
                'prepare_receipt_ttl_seconds' => self::PREPARE_RECEIPT_TTL_SECONDS,
            ],
            'domains' => ObservationEvent::DOMAINS,
            'canonicalizer_version' => Canonicalizer::VERSION,
        ];
    }
}
