<?php

namespace Dbvc\AgencyControl\Storage;

/**
 * Minimal transaction boundary over $wpdb.
 *
 * Strategy `transaction` (default) issues START TRANSACTION / COMMIT /
 * ROLLBACK. Strategy `savepoint` nests inside an already open transaction
 * (the WordPress PHPUnit harness keeps one open per test and rolls it back).
 * Select it with the `dbvc_agency_control_transaction_strategy` filter. On a
 * non-transactional engine the statements are accepted but provide no
 * atomicity; Schema::is_transactional() reports that so status output can
 * label durability as best-effort.
 */
final class Transaction
{
    private const SAVEPOINT = 'dbvc_agency_control_receipt';

    /**
     * @var string
     */
    private $strategy;

    /**
     * @var bool
     */
    private $open = false;

    public function __construct()
    {
        $strategy = apply_filters('dbvc_agency_control_transaction_strategy', 'transaction');
        $this->strategy = $strategy === 'savepoint' ? 'savepoint' : 'transaction';
    }

    /**
     * @return bool
     */
    public function begin()
    {
        global $wpdb;

        if ($this->open) {
            return false;
        }

        $result = $this->strategy === 'savepoint'
            ? $wpdb->query('SAVEPOINT ' . self::SAVEPOINT)
            : $wpdb->query('START TRANSACTION');
        $this->open = $result !== false;

        return $this->open;
    }

    /**
     * @return bool
     */
    public function commit()
    {
        global $wpdb;

        if (! $this->open) {
            return false;
        }
        $this->open = false;

        $result = $this->strategy === 'savepoint'
            ? $wpdb->query('RELEASE SAVEPOINT ' . self::SAVEPOINT)
            : $wpdb->query('COMMIT');

        return $result !== false;
    }

    /**
     * @return bool
     */
    public function rollback()
    {
        global $wpdb;

        if (! $this->open) {
            return false;
        }
        $this->open = false;

        $result = $this->strategy === 'savepoint'
            ? $wpdb->query('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT)
            : $wpdb->query('ROLLBACK');

        return $result !== false;
    }

    /**
     * @return string
     */
    public function strategy()
    {
        return $this->strategy;
    }
}
