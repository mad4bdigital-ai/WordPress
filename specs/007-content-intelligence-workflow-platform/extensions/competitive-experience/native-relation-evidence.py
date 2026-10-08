#!/usr/bin/env python3
"""Strict, bounded, non-authorizing WordPress relation evidence inspection.

Only inspects an *untrusted* supplied snapshot. It never authenticates the
snapshot, resolves WPML identity, certifies semantics or permits mutation.
"""
from __future__ import annotations

import re
from urllib.parse import urlsplit

INPUT = 'mad4b.native-relation-evidence.v2'
OUTPUT = 'mad4b.native-relation-inspection.v2'
MAX_ID = 2**63 - 1
MAX_EDGES = 2000


def identifier(raw):
    if isinstance(raw, bool):
        return None
    if isinstance(raw, int):
        return raw if 0 < raw <= MAX_ID else None
    if isinstance(raw, str) and len(raw) <= 19 and re.fullmatch(r'[1-9][0-9]*', raw):
        number = int(raw)
        return number if number <= MAX_ID else None
    return None


def require(test, reason):
    if not test:
        raise ValueError(reason)


def slug(value):
    return isinstance(value, str) and re.fullmatch(r'[A-Za-z][A-Za-z0-9_-]{0,90}', value) is not None


def scope_check(scope):
    require(isinstance(scope, dict), 'scope_required')
    site = scope.get('site_uuid')
    origin = scope.get('origin')
    generation = scope.get('runtime_generation')
    require(isinstance(site, str) and re.fullmatch(r'[0-9a-fA-F-]{36}', site), 'site_uuid_required')
    require(isinstance(origin, str) and len(origin) <= 256, 'origin_required')
    url = urlsplit(origin)
    require(url.scheme == 'https' and url.hostname and not url.username and not url.password
            and not url.path.strip('/') and not url.query and not url.fragment, 'origin_invalid')
    require(isinstance(generation, str) and re.fullmatch(r'[0-9a-f]{64}', generation), 'runtime_generation_required')
    return (site.lower(), origin.rstrip('/'))


def audit(bundle):
    require(isinstance(bundle, dict) and bundle.get('contract') == INPUT, 'contract_invalid')
    site, origin = scope_check(bundle.get('scope'))
    scope = bundle['scope']
    fields, records, identities = (bundle.get(key) for key in ('fields', 'records', 'identities'))
    require(isinstance(fields, list) and 0 < len(fields) <= 30, 'field_coverage_required')
    require(isinstance(records, list) and len(records) <= 200, 'records_invalid')
    require(isinstance(identities, list) and len(identities) <= 2000, 'identities_invalid')
    expected = scope.get('expected_source_ids')
    require(isinstance(expected, list) and 0 < len(expected) <= 200, 'expected_source_ids_required')
    expected_ids = [identifier(v) for v in expected]
    require(all(v is not None for v in expected_ids) and len(set(expected_ids)) == len(expected_ids), 'expected_source_ids_invalid')
    schema = {}
    for field in fields:
        require(isinstance(field, dict) and slug(field.get('key')), 'field_invalid')
        name = field['key']
        require(name not in schema, 'field_duplicate')
        require(field.get('kind') in ('post', 'term'), 'entity_kind_invalid')
        require(field.get('cardinality') in ('one', 'many'), 'cardinality_invalid')
        require(field.get('locale_policy') in ('same_locale', 'shared', 'review'), 'locale_policy_invalid')
        if field['kind'] == 'post':
            require(slug(field.get('post_type')), 'post_type_required')
            require('taxonomy' not in field and 'id_scheme' not in field, 'post_field_namespace_invalid')
        else:
            require(slug(field.get('taxonomy')), 'taxonomy_required')
            require(field.get('id_scheme') in ('term_id', 'term_taxonomy_id'), 'term_id_scheme_required')
            require('post_type' not in field, 'term_field_namespace_invalid')
        schema[name] = field
    findings = []

    def finding(state, reason, **info):
        findings.append(dict(state=state, reason=reason, **info))

    known = {}
    for entry in identities:
        require(isinstance(entry, dict), 'identity_invalid')
        id_ = identifier(entry.get('id'))
        kind = entry.get('kind')
        require(id_ is not None and kind in ('post', 'term'), 'identity_namespace_invalid')
        require(entry.get('site_uuid') == site and entry.get('origin') == origin, 'cross_site_identity_denied')
        require(entry.get('exists') in (True, False) and isinstance(entry.get('exists'), bool), 'identity_existence_unknown')
        if kind == 'post':
            require(slug(entry.get('post_type')), 'identity_post_type_invalid')
            namespace = ('post', '', '', id_)
        else:
            require(slug(entry.get('taxonomy')), 'identity_taxonomy_invalid')
            require(entry.get('id_scheme') in ('term_id', 'term_taxonomy_id'), 'identity_term_scheme_invalid')
            namespace = ('term', entry['taxonomy'], entry['id_scheme'], id_)
        require(namespace not in known, 'identity_duplicate')
        known[namespace] = entry

    record_ids, group_locales, edges = set(), set(), 0
    for record in records:
        require(isinstance(record, dict) and isinstance(record.get('meta'), dict), 'record_invalid')
        source_id = identifier(record.get('id'))
        require(source_id is not None and source_id not in record_ids, 'source_identity_duplicate_or_invalid')
        record_ids.add(source_id)
        require(record.get('site_uuid') == site and record.get('origin') == origin, 'cross_site_record_denied')
        locale = record.get('locale')
        require(isinstance(locale, str) and re.fullmatch(r'[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})?', locale), 'source_locale_invalid')
        group = record.get('translation_group')
        require(isinstance(group, str) and 0 < len(group) <= 128, 'translation_group_required')
        key = (group, locale, record.get('post_type'))
        if key in group_locales:
            finding('ISSUE', 'duplicate_translation_group_locale', source_id=source_id)
        group_locales.add(key)
        source = known.get(('post', '', '', source_id))
        if not source:
            finding('UNRESOLVED', 'source_identity_not_observed', source_id=source_id)
        elif not source['exists'] or source.get('post_type') != record.get('post_type'):
            finding('ISSUE', 'source_identity_mismatch', source_id=source_id)
        elif source.get('locale') != locale or source.get('translation_group') != group:
            finding('UNRESOLVED', 'source_locale_or_group_not_verified', source_id=source_id)
        require(set(record['meta']).issubset(schema), 'unexpected_meta_field_denied')
        for field_name, spec in schema.items():
            if field_name not in record['meta']:
                finding('UNRESOLVED', 'field_not_observed', source_id=source_id, field=field_name)
                continue
            raw = record['meta'][field_name]
            is_many = spec['cardinality'] == 'many'
            if is_many != isinstance(raw, list):
                finding('ISSUE', 'cardinality_mismatch', source_id=source_id, field=field_name)
                continue
            values = raw if is_many else [raw]
            if not values:
                finding('UNRESOLVED', 'empty_relation_unverified', source_id=source_id, field=field_name)
            edges += len(values)
            require(edges <= MAX_EDGES, 'relation_edge_budget_exceeded')
            seen = set()
            for raw_target in values:
                target_id = identifier(raw_target)
                ctx = dict(source_id=source_id, field=field_name, target_id=target_id)
                if target_id is None:
                    finding('ISSUE', 'target_id_invalid', **ctx)
                    continue
                if target_id in seen:
                    finding('ISSUE', 'target_duplicate', **ctx)
                    continue
                seen.add(target_id)
                if spec['kind'] == 'post':
                    namespace = ('post', '', '', target_id)
                else:
                    namespace = ('term', spec['taxonomy'], spec['id_scheme'], target_id)
                target = known.get(namespace)
                if target is None:
                    finding('UNRESOLVED', 'target_namespace_identity_missing', **ctx)
                elif not target['exists']:
                    finding('ISSUE', 'target_missing', **ctx)
                elif spec['kind'] == 'post' and target['post_type'] != spec['post_type']:
                    finding('ISSUE', 'target_type_mismatch', **ctx)
                elif spec['locale_policy'] == 'review':
                    finding('UNRESOLVED', 'locale_policy_requires_review', **ctx)
                elif spec['locale_policy'] == 'same_locale' and target.get('locale') != locale:
                    finding('UNRESOLVED', 'locale_mismatch_or_unverified', **ctx)
                elif spec['locale_policy'] == 'shared':
                    finding('STRUCTURAL', 'shared_policy_snapshot_only', **ctx)
                else:
                    finding('STRUCTURAL', 'locale_matches_snapshot_only', **ctx)

    missing_ids = sorted(set(expected_ids) - record_ids)
    extra_ids = sorted(record_ids - set(expected_ids))
    for id_ in missing_ids:
        finding('UNRESOLVED', 'expected_source_not_observed', source_id=id_)
    for id_ in extra_ids:
        finding('ISSUE', 'source_outside_batch_scope', source_id=id_)
    batch_sealed = scope.get('batch_sealed') is True and scope.get('next_cursor') is None
    if not batch_sealed:
        finding('UNRESOLVED', 'batch_coverage_unsealed')
    coverage_complete = batch_sealed and not missing_ids and not extra_ids and bool(records)
    summary = {state: sum(f['state'] == state for f in findings)
               for state in ('STRUCTURAL', 'UNRESOLVED', 'ISSUE')}
    return {'contract': OUTPUT, 'site_uuid': site, 'scope_origin': origin,
            'coverage_complete': coverage_complete, 'snapshot_authenticity_verified': False,
            'semantic_verification_complete': False, 'complete': False,
            'eligible_for_mutation': False, 'authorizing': False, 'read_only': True,
            'mutation_performed': False, 'summary': summary, 'findings': findings}
