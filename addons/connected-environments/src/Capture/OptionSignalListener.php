<?php

namespace Dbvc\Connected\Capture;

use Dbvc\Connected\Adapters\DomainRegistry;

/**
 * Listens to WordPress core option hooks for the watched Bricks options.
 *
 * These hooks fire on every persisted change regardless of DBVC's automatic
 * export settings, the `dbvc_should_export_on_option_update` filter, the
 * PHPUnit guard in includes/hooks.php, or Visual Editor activation. The
 * callback cost for unrelated options is one array lookup.
 */
final class OptionSignalListener
{
    /**
     * @var DirtyCapture
     */
    private $capture;

    /**
     * @var array<string, bool>
     */
    private $watched;

    /**
     * @param DirtyCapture $capture
     */
    public function __construct(DirtyCapture $capture)
    {
        $this->capture = $capture;
        $this->watched = array_fill_keys(DomainRegistry::watched_options(), true);
    }

    /**
     * @return void
     */
    public function register()
    {
        add_action('updated_option', [$this, 'on_updated_option'], 10, 3);
        add_action('added_option', [$this, 'on_added_option'], 10, 2);
        add_action('deleted_option', [$this, 'on_deleted_option'], 10, 1);
    }

    /**
     * @return void
     */
    public function unregister()
    {
        remove_action('updated_option', [$this, 'on_updated_option'], 10);
        remove_action('added_option', [$this, 'on_added_option'], 10);
        remove_action('deleted_option', [$this, 'on_deleted_option'], 10);
    }

    /**
     * @param string $option
     * @param mixed  $old_value
     * @param mixed  $value
     * @return void
     */
    public function on_updated_option($option, $old_value = null, $value = null)
    {
        unset($old_value, $value);
        $this->handle($option, 'updated_option');
    }

    /**
     * @param string $option
     * @param mixed  $value
     * @return void
     */
    public function on_added_option($option, $value = null)
    {
        unset($value);
        $this->handle($option, 'added_option');
    }

    /**
     * @param string $option
     * @return void
     */
    public function on_deleted_option($option)
    {
        $this->handle($option, 'deleted_option');
    }

    /**
     * @param string $option
     * @param string $source_hook
     * @return void
     */
    private function handle($option, $source_hook)
    {
        $option = (string) $option;
        if (! isset($this->watched[$option])) {
            return;
        }

        $signal = DomainRegistry::signal_for_option($option);
        if ($signal === null) {
            return;
        }

        $this->capture->signal($signal['domain'], $signal['object_key'], [
            'origin' => 'human',
            'source_hook' => $source_hook,
        ]);
    }
}
