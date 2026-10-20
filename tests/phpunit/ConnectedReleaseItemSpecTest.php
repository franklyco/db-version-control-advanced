<?php

/**
 * M9 follow-up — CLI item-spec parsing for release-create (--items / --delete)
 * and framework-publish (--items). A domain may itself contain a colon
 * (wp.bricks:components, wp.post:page), so the parsers must not split on the
 * FIRST colon: for a release selection the instance_uid is the final segment and
 * the domain is everything before it; for a framework object the definition_uid
 * is the first segment, the profile the last, the instance_uid next-to-last, and
 * the domain everything in between. Regression for the 6e live-verify workaround,
 * where `release-create --items wp.bricks:components:<uid>` mis-parsed the domain
 * as `wp.bricks` and leaked `components:` into the instance_uid.
 *
 * @group connected-environments
 */
class ConnectedReleaseItemSpecTest extends WP_UnitTestCase
{
    /**
     * @param string               $method
     * @param array<string, mixed> $assoc_args
     * @return mixed
     */
    private function invoke(string $method, array $assoc_args)
    {
        $ref = new ReflectionMethod('DBVC_Agency_CLI_Inspector', $method);
        $ref->setAccessible(true);

        return $ref->invoke(null, $assoc_args);
    }

    public function test_release_selections_keep_colon_bearing_domains(): void
    {
        $selections = $this->invoke('parse_release_selections', [
            'items' => 'bricks.global_class:bricks-global-class-abcd1234,wp.bricks:components:wp-bricks-components-b57bd0faff562da3,wp.post:page:wp-post-page-42',
            'delete' => 'wp.bricks:theme_styles:singleton',
        ]);

        $this->assertIsArray($selections, is_wp_error($selections) ? $selections->get_error_message() : '');
        $this->assertCount(4, $selections);

        // Plain domain (no colon) still works.
        $this->assertSame(['domain' => 'bricks.global_class', 'instance_uid' => 'bricks-global-class-abcd1234', 'operation' => 'replace'], $selections[0]);
        // Colon-bearing domain: split on the LAST colon, not the first.
        $this->assertSame(['domain' => 'wp.bricks:components', 'instance_uid' => 'wp-bricks-components-b57bd0faff562da3', 'operation' => 'replace'], $selections[1]);
        $this->assertSame(['domain' => 'wp.post:page', 'instance_uid' => 'wp-post-page-42', 'operation' => 'replace'], $selections[2]);
        // --delete rows carry the delete operation and the same parsing.
        $this->assertSame(['domain' => 'wp.bricks:theme_styles', 'instance_uid' => 'singleton', 'operation' => 'delete'], $selections[3]);
    }

    public function test_release_selections_reject_malformed_specs(): void
    {
        foreach (['no-colon-here', 'wp.bricks:components:', ':only-instance', 'wp.bricks::'] as $bad) {
            $result = $this->invoke('parse_release_selections', ['items' => $bad]);
            $this->assertWPError($result, "spec '$bad' should be rejected");
            $this->assertSame('dbvc_agency_invalid_selection', $result->get_error_code());
        }

        // Empty lists are valid (no selections).
        $this->assertSame([], $this->invoke('parse_release_selections', []));
    }

    public function test_framework_objects_keep_colon_bearing_domains(): void
    {
        $objects = $this->invoke('parse_framework_objects', [
            'items' => 'def-1:bricks.global_class:bricks-global-class-abcd1234:default,def-2:wp.bricks:components:wp-bricks-components-b57bd0faff562da3:default',
        ]);

        $this->assertIsArray($objects, is_wp_error($objects) ? $objects->get_error_message() : '');
        $this->assertCount(2, $objects);
        $this->assertSame(['definition_uid' => 'def-1', 'domain' => 'bricks.global_class', 'instance_uid' => 'bricks-global-class-abcd1234', 'profile' => 'default'], $objects[0]);
        $this->assertSame(['definition_uid' => 'def-2', 'domain' => 'wp.bricks:components', 'instance_uid' => 'wp-bricks-components-b57bd0faff562da3', 'profile' => 'default'], $objects[1]);
    }

    public function test_framework_objects_reject_too_few_fields_and_honor_json_passthrough(): void
    {
        $result = $this->invoke('parse_framework_objects', ['items' => 'def-1:bricks.global_class:only-three']);
        $this->assertWPError($result);
        $this->assertSame('dbvc_agency_invalid_objects', $result->get_error_code());

        // A structured --objects array wins over --items and is returned untouched.
        $passthrough = $this->invoke('parse_framework_objects', [
            'objects' => [['definition_uid' => 'd', 'domain' => 'wp.bricks:components', 'instance_uid' => 'i', 'profile' => 'p']],
            'items' => 'ignored:because:objects:wins',
        ]);
        $this->assertIsArray($passthrough);
        $this->assertSame('wp.bricks:components', $passthrough[0]['domain']);
    }
}
