<?php

namespace Dbvc\ConnectedProtocol;

/**
 * Raised when a value cannot be canonically encoded (invalid UTF-8, non-finite
 * floats, unsupported types). Callers report the object as incomplete rather
 * than substituting a lossy encoding.
 */
final class CanonicalizationException extends \RuntimeException
{
    /**
     * @var string
     */
    private $reason;

    /**
     * @param string $reason Stable machine-readable reason code.
     * @param string $message Human-readable detail.
     */
    public function __construct($reason, $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
        $this->reason = (string) $reason;
    }

    /**
     * @return string
     */
    public function getReason()
    {
        return $this->reason;
    }
}
