<?php

namespace Dbvc\VisualEditor\Bricks;

/**
 * Read-only index of Bricks global classes that bind a dynamic background image.
 *
 * Bricks lets a global class carry `_background.image.useDynamicData`, which it resolves
 * per request into class-scoped CSS (`.{class}.brxe-{element} {background-image: ...}`).
 * The element itself carries no background setting, so the inspector needs this index to
 * see the binding. Only the base breakpoint is indexed; breakpoint and pseudo-state
 * variants (`_background:mobile_portrait`, `_background:hover`) are out of scope.
 */
final class GlobalClassBackgroundIndex
{
    /**
     * @var callable|null
     */
    private $loader;

    /**
     * @var array<string, array{id: string, name: string, expression: string, size: string}>|null
     */
    private $by_id = null;

    /**
     * @param callable|null $loader Returns the raw Bricks global classes list. Defaults to Bricks' loaded data.
     */
    public function __construct(?callable $loader = null)
    {
        $this->loader = $loader;
    }

    /**
     * Dynamic background candidates for an element's global classes, in the element's class order.
     *
     * @param mixed $class_ids Value of the element's `_cssGlobalClasses` setting.
     * @return array<int, array{id: string, name: string, expression: string, size: string}>
     */
    public function candidatesFor($class_ids)
    {
        if (is_string($class_ids)) {
            $class_ids = preg_split('/\s+/', trim($class_ids));
        }

        if (! is_array($class_ids) || empty($class_ids)) {
            return [];
        }

        $index = $this->index();
        if (empty($index)) {
            return [];
        }

        $candidates = [];
        foreach ($class_ids as $class_id) {
            if (! is_scalar($class_id)) {
                continue;
            }

            $class_id = (string) $class_id;
            if (isset($index[$class_id])) {
                $candidates[] = $index[$class_id];
            }
        }

        return $candidates;
    }

    /**
     * @return array<string, array{id: string, name: string, expression: string, size: string}>
     */
    private function index()
    {
        if (is_array($this->by_id)) {
            return $this->by_id;
        }

        $this->by_id = [];

        foreach ($this->loadClasses() as $class) {
            if (! is_array($class)) {
                continue;
            }

            $id = isset($class['id']) && is_scalar($class['id']) ? (string) $class['id'] : '';
            $name = isset($class['name']) && is_scalar($class['name']) ? trim((string) $class['name']) : '';
            $settings = isset($class['settings']) && is_array($class['settings']) ? $class['settings'] : [];
            $background = isset($settings['_background']) && is_array($settings['_background']) ? $settings['_background'] : [];
            $image = isset($background['image']) && is_array($background['image']) ? $background['image'] : [];
            $expression = isset($image['useDynamicData']) && is_scalar($image['useDynamicData'])
                ? trim((string) $image['useDynamicData'])
                : '';

            if ($id === '' || $name === '' || $expression === '') {
                continue;
            }

            $this->by_id[$id] = [
                'id' => $id,
                'name' => $name,
                'expression' => $expression,
                'size' => isset($image['size']) && is_scalar($image['size']) ? sanitize_key((string) $image['size']) : '',
            ];
        }

        return $this->by_id;
    }

    /**
     * @return array<int, mixed>
     */
    private function loadClasses()
    {
        if (is_callable($this->loader)) {
            $classes = call_user_func($this->loader);

            return is_array($classes) ? $classes : [];
        }

        if (class_exists('\\Bricks\\Database')
            && isset(\Bricks\Database::$global_data)
            && is_array(\Bricks\Database::$global_data)
            && isset(\Bricks\Database::$global_data['globalClasses'])
            && is_array(\Bricks\Database::$global_data['globalClasses'])) {
            return \Bricks\Database::$global_data['globalClasses'];
        }

        $classes = get_option('bricks_global_classes', []);

        return is_array($classes) ? $classes : [];
    }
}
