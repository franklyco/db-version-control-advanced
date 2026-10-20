<?php

use Dbvc\ConnectedProtocol\Canonicalizer;
use Dbvc\ConnectedProtocol\CanonicalizationException;
use Dbvc\ConnectedProtocol\Comparison;
use Dbvc\ConnectedProtocol\ObservationEvent;
use Dbvc\ConnectedProtocol\RoleGate;

/**
 * Pure contract layer: no WordPress storage, network or add-on activation.
 *
 * @group connected-environments
 */
class ConnectedProtocolContractsTest extends WP_UnitTestCase
{
    public function test_lists_preserve_order_and_maps_sort_keys(): void
    {
        $this->assertSame('["b","a","c"]', Canonicalizer::encode(['b', 'a', 'c']));
        $this->assertSame('{"a":1,"b":2}', Canonicalizer::encode(['b' => 2, 'a' => 1]));
        $this->assertNotSame(Canonicalizer::hash(Canonicalizer::encode([1, 2])), Canonicalizer::hash(Canonicalizer::encode([2, 1])));
        $this->assertSame(Canonicalizer::hash(Canonicalizer::encode(['x' => 1, 'y' => 2])), Canonicalizer::hash(Canonicalizer::encode(['y' => 2, 'x' => 1])));
    }

    public function test_nested_list_order_is_meaningful_and_nested_keys_are_sorted(): void
    {
        $left = ['settings' => ['_gradient' => ['colors' => [['id' => 'a'], ['id' => 'b']]]]];
        $right = ['settings' => ['_gradient' => ['colors' => [['id' => 'b'], ['id' => 'a']]]]];
        $this->assertNotSame(Canonicalizer::encode($left), Canonicalizer::encode($right));

        $this->assertSame(
            '{"settings":{"_gradient":{"applyTo":"overlay","colors":[{"color":"x","id":"a"}]}}}',
            Canonicalizer::encode(['settings' => ['_gradient' => ['colors' => [['id' => 'a', 'color' => 'x']], 'applyTo' => 'overlay']]])
        );
    }

    public function test_missing_null_empty_string_empty_list_and_empty_map_are_distinct(): void
    {
        $encodings = [
            'missing' => Canonicalizer::encode(['name' => 'x']),
            'null' => Canonicalizer::encode(['name' => 'x', 'cat' => null]),
            'empty_string' => Canonicalizer::encode(['name' => 'x', 'cat' => '']),
            'empty_list' => Canonicalizer::encode(['name' => 'x', 'cat' => []]),
            'empty_map' => Canonicalizer::encode(['name' => 'x', 'cat' => new stdClass()]),
            'false' => Canonicalizer::encode(['name' => 'x', 'cat' => false]),
        ];
        $this->assertSame('{"name":"x"}', $encodings['missing']);
        $this->assertSame('{"cat":null,"name":"x"}', $encodings['null']);
        $this->assertSame('{"cat":"","name":"x"}', $encodings['empty_string']);
        $this->assertSame('{"cat":[],"name":"x"}', $encodings['empty_list']);
        $this->assertSame('{"cat":{},"name":"x"}', $encodings['empty_map']);
        $this->assertSame('{"cat":false,"name":"x"}', $encodings['false']);
        $this->assertCount(count($encodings), array_unique(array_map([Canonicalizer::class, 'hash'], $encodings)));
    }

    public function test_numbers_and_numeric_strings_are_distinguished(): void
    {
        $this->assertSame('[1,1.0,"1",true]', Canonicalizer::encode([1, 1.0, '1', true]));
        $this->assertSame('0.1', Canonicalizer::encode(0.1));
        $this->assertSame('[0,"0",false,null,""]', Canonicalizer::encode([0, '0', false, null, '']));
    }

    public function test_only_documented_volatile_top_level_keys_are_stripped(): void
    {
        $value = ['id' => 'abc', 'time' => 5, 'settings' => ['time' => 5, 'modified' => 9]];
        $projection = Canonicalizer::project($value, ['time']);
        $this->assertTrue($projection['ok']);
        $this->assertSame(1, $projection['version']);
        $this->assertSame('{"id":"abc","settings":{"modified":9,"time":5}}', $projection['canonical']);
        $this->assertSame(Canonicalizer::hash($projection['canonical']), $projection['hash']);

        $untouched = Canonicalizer::project($value);
        $this->assertSame('{"id":"abc","settings":{"modified":9,"time":5},"time":5}', $untouched['canonical']);
    }

    public function test_invalid_utf8_and_non_finite_floats_fail_closed(): void
    {
        $bad = Canonicalizer::project(['name' => "\xB1\x31"]);
        $this->assertFalse($bad['ok']);
        $this->assertSame('invalid_utf8', $bad['reason']);
        $this->assertArrayNotHasKey('hash', $bad);

        $this->expectException(CanonicalizationException::class);
        Canonicalizer::encode(['value' => INF]);
    }

    public function test_unicode_and_slashes_are_not_escaped(): void
    {
        $this->assertSame('{"value":"var(--gap-l)/2 · ünïcode"}', Canonicalizer::encode(['value' => 'var(--gap-l)/2 · ünïcode']));
    }

    public function test_package_observation_examples_validate_and_digest_is_stable(): void
    {
        $directory = DBVC_PLUGIN_PATH . 'docs/dropins/dbvc-connected-agency/contracts/examples/';
        foreach (['class-observation.json', 'variable-observation.json', 'service-observation.json'] as $file) {
            $body = json_decode((string) file_get_contents($directory . $file), true);
            $this->assertIsArray($body, $file);
            $this->assertSame([], ObservationEvent::validate($body), $file);
            $this->assertSame(ObservationEvent::digest($body), ObservationEvent::digest(array_reverse($body, true)), $file . ' digest must not depend on key order');
        }
    }

    public function test_observation_validation_rejects_unknown_fields_bad_ids_and_bounds(): void
    {
        $valid = ObservationEvent::build([
            'event_id' => 'obs-000000000001',
            'environment_id' => 'local-abc',
            'installation_epoch' => 'provisional-1',
            'sequence' => 1,
            'observed_at' => '2026-09-17T12:00:00Z',
            'object' => ['domain' => 'bricks.global_class', 'instance_uid' => 'bricks-global-class-0011223344556677'],
            'projection' => ['profile' => 'bricks-global-class-v1', 'hash' => str_repeat('a', 64), 'exists' => true, 'complete' => true],
        ]);
        $this->assertSame([], ObservationEvent::validate($valid));
        $this->assertSame('human', $valid['origin']);
        $this->assertNull($valid['causation_id']);
        $this->assertSame(array_keys($valid), ['schema_version', 'event_id', 'environment_id', 'installation_epoch', 'sequence', 'observed_at', 'object', 'projection', 'origin', 'causation_id']);

        $unknown = $valid;
        $unknown['recipients'] = ['a-staging'];
        $this->assertContains('unknown:recipients', ObservationEvent::validate($unknown));

        $unknown_object = $valid;
        $unknown_object['object']['client_id'] = 'a';
        $this->assertContains('unknown:object.client_id', ObservationEvent::validate($unknown_object));

        $bad = $valid;
        $bad['event_id'] = '-starts-with-dash';
        $bad['sequence'] = ObservationEvent::MAX_SEQUENCE + 1;
        $bad['projection']['hash'] = 'ABC';
        $bad['object']['domain'] = 'bricks.template';
        $bad['origin'] = 'sender-says-apply';
        $errors = ObservationEvent::validate($bad);
        foreach (['invalid:event_id', 'invalid:sequence', 'invalid:projection.hash', 'invalid:object.domain', 'invalid:origin'] as $expected) {
            $this->assertContains($expected, $errors);
        }

        $missing = $valid;
        unset($missing['projection']['complete'], $missing['causation_id']);
        $errors = ObservationEvent::validate($missing);
        $this->assertContains('missing:projection.complete', $errors);
        $this->assertContains('missing:causation_id', $errors);
    }

    public function test_role_gate_precedence(): void
    {
        $this->assertSame('emergency_disabled', RoleGate::state(true, true, true, true));
        $this->assertSame('disabled', RoleGate::state(false, false, true, true));
        $this->assertSame('unsupported_runtime', RoleGate::state(true, false, true, false));
        $this->assertSame('migration_required', RoleGate::state(true, false, false, true));
        $this->assertSame('ready', RoleGate::state(true, false, true, true));
    }

    public function test_environment_comparison_states(): void
    {
        $profiles = ['p', 'p', 'p'];
        $this->assertSame('synchronized', Comparison::environment('h', 'h', 'h', $profiles));
        $this->assertSame('outgoing', Comparison::environment('h', 'x', 'h', $profiles));
        $this->assertSame('incoming', Comparison::environment('h', 'h', 'x', $profiles));
        $this->assertSame('converged', Comparison::environment('h', 'x', 'x', $profiles));
        $this->assertSame('conflict', Comparison::environment('h', 'x', 'y', $profiles));
        $this->assertSame('baseline_required', Comparison::environment(null, 'x', 'y', $profiles));
        $this->assertSame('unknown', Comparison::environment('h', 'h', 'h', ['p', 'q', 'p']));
        $this->assertSame('unknown', Comparison::environment('h', 'h', 'h', $profiles, false));
        $this->assertSame('unknown', Comparison::environment('h', null, 'h', $profiles));
    }

    public function test_framework_comparison_states(): void
    {
        $this->assertSame(['drift' => 'clean', 'version' => 'current'], Comparison::framework('a', 'a', '1.0', '1.0'));
        $this->assertSame(['drift' => 'local_drift', 'version' => 'version_differs'], Comparison::framework('b', 'a', '1.0', '1.1'));
        $this->assertSame(['drift' => 'approved_override', 'version' => 'current'], Comparison::framework('o', 'a', '1.0', '1.0', 'o'));
        $this->assertSame(['drift' => 'override_changed', 'version' => 'current'], Comparison::framework('z', 'a', '1.0', '1.0', 'o'));
        $this->assertSame(['drift' => 'unknown', 'version' => 'unknown'], Comparison::framework(null, 'a', null, '1.0'));
    }
}
