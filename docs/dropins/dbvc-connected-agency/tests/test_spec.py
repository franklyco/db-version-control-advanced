import copy
import json
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'scripts'))
from spec_model import DirtyModel, Ledger, compare, fingerprint, framework_status, route, validate_event


def fixture(name):
    return json.loads((ROOT / 'contracts/examples' / name).read_text())


class ComparisonTests(unittest.TestCase):
    def test_three_way_truth_table(self):
        for base, source, target, expected in [
            ('a', 'a', 'a', 'synchronized'), ('a', 'b', 'a', 'outgoing'),
            ('a', 'a', 'b', 'incoming'), ('a', 'b', 'b', 'converged'),
            ('a', 'b', 'c', 'conflict'), (None, 'a', 'a', 'baseline_required')]:
            with self.subTest(expected=expected):
                self.assertEqual(compare(base, source, target), expected)

    def test_partial_stale_or_incompatible_never_clean(self):
        for kw in [{'complete': False}, {'fresh': False}, {'profiles': ('v1', 'v2', 'v1')},
                   {'profiles': ('v1',)}, {'profiles': ('', '', '')}]:
            with self.subTest(kw=kw):
                self.assertEqual(compare('a', 'a', 'a', **kw), 'unknown')

    def test_missing_source_unknown(self):
        self.assertEqual(compare('a', None, 'a'), 'unknown')

    def test_ordered_list_change_detected(self):
        self.assertNotEqual(fingerprint([1, 2]), fingerprint([2, 1]))

    def test_unordered_map_key_noise_ignored(self):
        self.assertEqual(fingerprint({'a': 1, 'b': 2}), fingerprint({'b': 2, 'a': 1}))

    def test_missing_null_empty_and_deleted_differ(self):
        hashes = [fingerprint(x) for x in [None, '', [], {}, {'a': None}, {'a': ''}]]
        hashes.append(fingerprint(exists=False))
        self.assertEqual(len(set(hashes)), len(hashes))

    def test_meaningful_time_preserved(self):
        self.assertNotEqual(fingerprint({'time': 'morning'}), fingerprint({'time': 'evening'}))

    def test_version_difference_is_not_drift(self):
        self.assertEqual(framework_status('a', 'a', 'v1', 'v2'), {'drift': 'clean', 'version': 'version_differs'})

    def test_version_difference_does_not_invent_order(self):
        self.assertEqual(framework_status('a', 'a', 'v3', 'v2')['version'], 'version_differs')

    def test_override_then_new_local_change(self):
        self.assertEqual(framework_status('b', 'a', 'v1', 'v1', 'b')['drift'], 'approved_override')
        self.assertEqual(framework_status('c', 'a', 'v1', 'v1', 'b')['drift'], 'override_changed')


class RoutingTests(unittest.TestCase):
    def setUp(self):
        self.event = fixture('class-observation.json')
        self.registry = fixture('registry.json')

    def test_example_outcomes(self):
        for name, expected in fixture('expected-routing.json').items():
            with self.subTest(name=name):
                self.assertEqual(route(fixture(name), 'a-prod', self.registry), expected)

    def test_offline_target_retains_delivery(self):
        self.assertFalse(self.registry['environments']['a-local']['online'])
        self.assertIn('a-local', route(self.event, 'a-prod', self.registry)['client_targets'])

    def test_disabled_target_not_notified(self):
        self.registry['environments']['a-stage']['enabled'] = False
        self.assertEqual(route(self.event, 'a-prod', self.registry)['client_targets'], ['a-local'])

    def test_disabled_subscription_not_notified(self):
        self.registry['client_subscriptions'][0]['enabled'] = False
        self.assertNotIn('a-stage', route(self.event, 'a-prod', self.registry)['client_targets'])

    def test_cross_client_subscription_rejected(self):
        sub = copy.deepcopy(self.registry['client_subscriptions'][0])
        sub['target'] = 'b-stage'
        self.registry['client_subscriptions'].append(sub)
        self.assertNotIn('b-stage', route(self.event, 'a-prod', self.registry)['client_targets'])

    def test_cross_agency_subscription_rejected(self):
        self.registry['environments']['a-stage']['agency'] = 'another-studio'
        self.assertNotIn('a-stage', route(self.event, 'a-prod', self.registry)['client_targets'])

    def test_unsubscribed_bricks_class_not_framework_managed(self):
        self.event['object']['instance_uid'] = 'client-only-class'
        self.assertEqual(route(self.event, 'a-prod', self.registry)['framework_review'], [])

    def test_framework_mapping_is_environment_scoped(self):
        self.registry['framework_subscriptions'][0]['environment'] = 'a-stage'
        self.assertEqual(route(self.event, 'a-prod', self.registry)['framework_review'], [])

    def test_forged_sender_scope_rejected(self):
        for field in ['agency_id', 'client_id', 'recipients', 'audience']:
            event = copy.deepcopy(self.event)
            event[field] = 'attacker-chosen'
            with self.subTest(field=field), self.assertRaises(ValueError):
                route(event, 'a-prod', self.registry)

    def test_wrong_enrollment_and_epoch_rejected(self):
        with self.assertRaises(ValueError):
            route(self.event, 'b-prod', self.registry)
        self.event['installation_epoch'] = 'old-epoch'
        with self.assertRaises(ValueError):
            route(self.event, 'a-prod', self.registry)

    def test_revoked_source_cannot_report(self):
        self.registry['environments']['a-prod']['revoked'] = True
        with self.assertRaises(ValueError):
            route(self.event, 'a-prod', self.registry)

    def test_forged_apply_origin_does_not_suppress_review(self):
        self.event.update(origin='apply', causation_id='made-up-operation')
        self.assertEqual(route(self.event, 'a-prod', self.registry)['framework_review'], ['framework-button'])

    def test_trusted_expected_operation_updates_client_without_new_proposal(self):
        result = route(self.event, 'a-prod', self.registry, verified_expected_operation=True)
        self.assertEqual(result['framework_review'], [])
        self.assertEqual(result['client_targets'], ['a-local', 'a-stage'])


class LedgerTests(unittest.TestCase):
    def setUp(self):
        self.event = fixture('class-observation.json')
        self.ledger = Ledger('a-prod', 'epoch-a-prod-1')

    def test_duplicate_same_body_once(self):
        self.assertEqual(self.ledger.accept(self.event)['receipt'], 'accepted')
        self.assertEqual(self.ledger.accept(self.event)['receipt'], 'duplicate')
        self.assertEqual(len(self.ledger.events), 1)

    def test_same_id_different_body_conflicts(self):
        self.ledger.accept(self.event)
        self.event['projection']['hash'] = 'f' * 64
        with self.assertRaises(ValueError):
            self.ledger.accept(self.event)

    def test_sequence_reuse_conflicts(self):
        self.ledger.accept(self.event)
        self.event['event_id'] = 'second-id'
        with self.assertRaises(ValueError):
            self.ledger.accept(self.event)

    def test_old_event_for_other_object_still_updates(self):
        later = fixture('service-observation.json')
        self.assertTrue(self.ledger.accept(later)['has_gap'])
        result = self.ledger.accept(self.event)
        self.assertTrue(result['projection_updated'])
        self.assertFalse(result['has_gap'])
        self.assertEqual(len(self.ledger.projections), 2)

    def test_old_event_for_same_object_does_not_regress(self):
        later = copy.deepcopy(self.event)
        later.update(sequence=2, event_id='later-class')
        later['projection']['hash'] = 'f' * 64
        self.ledger.accept(later)
        self.assertFalse(self.ledger.accept(self.event)['projection_updated'])
        self.assertEqual(next(iter(self.ledger.projections.values()))['projection']['hash'], 'f' * 64)

    def test_new_epoch_requires_new_ledger(self):
        self.event['installation_epoch'] = 'restored-epoch'
        with self.assertRaises(ValueError):
            self.ledger.accept(self.event)

    def test_clock_is_not_ordering_authority(self):
        self.ledger.accept(self.event)
        later = copy.deepcopy(self.event)
        later.update(sequence=2, event_id='next-class', observed_at='2020-01-01T00:00:00Z')
        self.assertTrue(self.ledger.accept(later)['projection_updated'])


class WireTests(unittest.TestCase):
    def test_invalid_sequences(self):
        for value in [True, 0, -1, 1.5, '1', 9007199254740992]:
            event = fixture('class-observation.json')
            event['sequence'] = value
            with self.subTest(value=value), self.assertRaises(ValueError):
                validate_event(event)

    def test_invalid_nested_authority_or_payload(self):
        event = fixture('class-observation.json')
        event['projection']['content'] = 'private body'
        with self.assertRaises(ValueError):
            validate_event(event)

    def test_invalid_timestamp_hash_and_flags(self):
        for field, value in [('observed_at', 'yesterday'), ('observed_at', '2026-09-16T12:00:00')]:
            event = fixture('class-observation.json')
            event[field] = value
            with self.assertRaises(ValueError):
                validate_event(event)
        for field, value in [('hash', 'bad'), ('complete', 1), ('exists', None)]:
            event = fixture('class-observation.json')
            event['projection'][field] = value
            with self.assertRaises(ValueError):
                validate_event(event)


class DirtyGenerationTests(unittest.TestCase):
    def test_settled_work_can_be_acknowledged(self):
        state = DirtyModel()
        state.signal()
        claim = state.claim('worker-1', 0)
        self.assertTrue(state.finish(claim, 1))
        self.assertFalse(state.pending)

    def test_new_save_during_work_stays_pending(self):
        state = DirtyModel()
        state.signal()
        claim = state.claim('worker-1', 0)
        state.signal()
        self.assertTrue(state.finish(claim, 1))
        self.assertTrue(state.pending)
        self.assertEqual(state.claim('worker-2', 2)['generation'], 2)

    def test_active_lease_prevents_second_claim(self):
        state = DirtyModel()
        state.signal()
        state.claim('worker-1', 0)
        self.assertIsNone(state.claim('worker-2', 1))

    def test_expired_worker_cannot_acknowledge(self):
        state = DirtyModel()
        state.signal()
        old = state.claim('worker-1', 0)
        new = state.claim('worker-2', 11)
        self.assertFalse(state.finish(old, 12))
        self.assertTrue(state.finish(new, 12))


if __name__ == '__main__':
    unittest.main()
