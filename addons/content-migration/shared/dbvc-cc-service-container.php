<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_Service_Container
{
    /**
     * @var array<string, callable>
     */
    private $definitions = [];

    /**
     * @var array<string, mixed>
     */
    private $instances = [];

    /**
     * @param string $service_id
     * @param callable $factory
     * @return void
     */
    public function set($service_id, callable $factory)
    {
        $this->definitions[(string) $service_id] = $factory;
    }

    /**
     * @param string $service_id
     * @return bool
     */
    public function has($service_id)
    {
        return isset($this->definitions[(string) $service_id]);
    }

    /**
     * @param string $service_id
     * @return mixed|null
     */
    public function get($service_id)
    {
        $service_id = (string) $service_id;

        if (array_key_exists($service_id, $this->instances)) {
            return $this->instances[$service_id];
        }

        if (! $this->has($service_id)) {
            return null;
        }

        $instance = call_user_func($this->definitions[$service_id], $this);
        $this->instances[$service_id] = $instance;

        return $instance;
    }

    /**
     * @return array<int, string>
     */
    public function ids()
    {
        return array_keys($this->definitions);
    }
}
