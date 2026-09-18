"""Offline executable design examples. Not production auth, SQL, sync or hashing."""
import copy
import hashlib
import json
import re
from datetime import datetime

DOMAINS = {'bricks.global_class', 'bricks.variable', 'wp.service'}
ID = re.compile(r'^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$')


def fingerprint(value=None, *, exists=True):
    # Illustrative Python bytes only; PHP/domain-specific canonicalization is separate.
    projection = {'exists': exists}
    if exists:
        projection['value'] = value
    raw = json.dumps(projection, sort_keys=True, ensure_ascii=False,
                     separators=(',', ':'), allow_nan=False)
    return hashlib.sha256(raw.encode()).hexdigest()


def validate_event(event):
    if not isinstance(event, dict):
        raise ValueError('event_object_required')
    required = {'schema_version', 'event_id', 'environment_id', 'installation_epoch',
                'sequence', 'observed_at', 'object', 'projection', 'origin', 'causation_id'}
    if set(event) != required:
        raise ValueError('event_fields')
    if event['schema_version'] != 'agency-control.observation.v0.2':
        raise ValueError('schema_version')
    for key in ('event_id', 'environment_id', 'installation_epoch'):
        if not isinstance(event[key], str) or not ID.fullmatch(event[key]):
            raise ValueError('identity_format')
    if type(event['sequence']) is not int or not 1 <= event['sequence'] <= 9007199254740991:
        raise ValueError('sequence_invalid')
    stamp = event['observed_at']
    if not isinstance(stamp, str) or not re.fullmatch(
            r'\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})', stamp):
        raise ValueError('timestamp_format')
    try:
        datetime.fromisoformat(stamp.replace('Z', '+00:00'))
    except ValueError as exc:
        raise ValueError('timestamp_invalid') from exc
    obj, projection = event['object'], event['projection']
    if not isinstance(obj, dict) or set(obj) != {'domain', 'instance_uid'}:
        raise ValueError('object_fields')
    if not isinstance(obj['domain'], str) or obj['domain'] not in DOMAINS:
        raise ValueError('unsupported_domain')
    if not isinstance(obj['instance_uid'], str) or not ID.fullmatch(obj['instance_uid']):
        raise ValueError('object_identity')
    if not isinstance(projection, dict) or set(projection) != {'profile', 'hash', 'exists', 'complete'}:
        raise ValueError('projection_fields')
    if not isinstance(projection['profile'], str) or not ID.fullmatch(projection['profile']):
        raise ValueError('profile_invalid')
    if not isinstance(projection['hash'], str) or not re.fullmatch('[a-f0-9]{64}', projection['hash']):
        raise ValueError('hash_invalid')
    if type(projection['exists']) is not bool or type(projection['complete']) is not bool:
        raise ValueError('projection_flags')
    if not isinstance(event['origin'], str) or event['origin'] not in {'human', 'apply', 'rollback', 'reconciliation'}:
        raise ValueError('origin_invalid')
    cause = event['causation_id']
    if cause is not None and (not isinstance(cause, str) or not ID.fullmatch(cause)):
        raise ValueError('causation_invalid')
    return event


def compare(base, source, target, *, complete=True, fresh=True, profiles=('v1', 'v1', 'v1')):
    if not complete or not fresh or len(profiles) != 3 or len(set(profiles)) != 1 or not profiles[0] or source is None or target is None:
        return 'unknown'
    if base is None:
        return 'baseline_required'
    if source == target:
        return 'synchronized' if source == base else 'converged'
    if target == base:
        return 'outgoing'
    if source == base:
        return 'incoming'
    return 'conflict'


def framework_status(actual, adopted_hash, adopted_version, desired_version, override_hash=None):
    version = ('unknown' if adopted_version is None or desired_version is None else
               'current' if adopted_version == desired_version else 'version_differs')
    if actual is None or adopted_hash is None:
        drift = 'unknown'
    elif override_hash is not None:
        drift = 'approved_override' if actual == override_hash else 'override_changed'
    else:
        drift = 'clean' if actual == adopted_hash else 'local_drift'
    return {'drift': drift, 'version': version}


def route(event, principal_environment, registry, *, verified_expected_operation=False):
    validate_event(event)
    envs = registry['environments']
    source = envs.get(principal_environment)
    if (not source or not source['enabled'] or source['revoked'] or
            event['environment_id'] != principal_environment or
            event['installation_epoch'] != source['epoch']):
        raise ValueError('enrollment_mismatch')
    obj = event['object']
    targets = set()
    for sub in registry['client_subscriptions']:
        target = envs.get(sub['target'])
        if (sub['enabled'] and sub['source'] == principal_environment and target and
                target['enabled'] and not target['revoked'] and
                target['agency'] == source['agency'] and target['client'] == source['client'] and
                sub['target'] != principal_environment and obj['domain'] in sub['domains']):
            targets.add(sub['target'])
    review = set()
    if not verified_expected_operation:
        for sub in registry['framework_subscriptions']:
            if (sub['enabled'] and sub['agency'] == source['agency'] and sub['client'] == source['client'] and
                    sub['environment'] == principal_environment and sub['domain'] == obj['domain'] and
                    sub['instance_uid'] == obj['instance_uid']):
                review.add(sub['definition_uid'])
    return {'client_targets': sorted(targets), 'framework_review': sorted(review)}


class Ledger:
    """In-memory ordering/replay illustration; no SQL atomicity or retention claims."""
    def __init__(self, environment, epoch):
        self.environment, self.epoch = environment, epoch
        self.events, self.sequences, self.projections = {}, {}, {}
        self.highest = self.contiguous = 0

    def accept(self, event):
        validate_event(event)
        if event['environment_id'] != self.environment or event['installation_epoch'] != self.epoch:
            raise ValueError('identity_or_epoch_mismatch')
        seq, event_id, digest = event['sequence'], event['event_id'], fingerprint(event)
        if event_id in self.events:
            if self.events[event_id] != digest:
                raise ValueError('event_id_reused')
            return {'receipt': 'duplicate', 'projection_updated': False, 'has_gap': self.contiguous < self.highest}
        if seq in self.sequences:
            raise ValueError('sequence_reused')
        self.events[event_id], self.sequences[seq] = digest, event_id
        self.highest = max(self.highest, seq)
        while self.contiguous + 1 in self.sequences:
            self.contiguous += 1
        key = (event['object']['domain'], event['object']['instance_uid'], event['projection']['profile'])
        prior = self.projections.get(key)
        updated = prior is None or seq > prior['sequence']
        if updated:
            self.projections[key] = copy.deepcopy(event)
        return {'receipt': 'accepted', 'projection_updated': updated, 'has_gap': self.contiguous < self.highest}


class DirtyModel:
    """Sequential reference for generation/lease fencing. Not a concurrent SQL store."""
    def __init__(self):
        self.generation = self.acked = 0
        self.lease = None

    def signal(self):
        self.generation += 1

    def claim(self, token, now, ttl=10):
        if self.generation == self.acked or (self.lease and now < self.lease['expires']):
            return None
        self.lease = {'token': token, 'expires': now + ttl, 'generation': self.generation}
        return copy.deepcopy(self.lease)

    def finish(self, claim, now):
        if not self.lease or claim != self.lease or now >= self.lease['expires']:
            return False
        self.acked = max(self.acked, claim['generation'])
        self.lease = None
        return True

    @property
    def pending(self):
        return self.generation > self.acked
