#!/usr/bin/env python3
"""Bounded, deterministic non-mutating JetEngine schema audit for original 313.

A schema readback is evidence of CURRENT definitions, not historical deploy authority.
"""
import hashlib,json
COMMAND=['option','get','jet_engine_meta_boxes','--format=json']
DIGEST=hashlib.sha256(json.dumps(COMMAND,ensure_ascii=False,separators=(',',':')).encode()).hexdigest()
EXPECTED={'meta-2':('tax','season'), 'meta-3':('post','tour-rates'),
          'mad4b_tour_layout_page_options':('post','tours-and-activities')}

def canonical_payload(boxes):
    if not isinstance(boxes,dict) or set(boxes)!=set(EXPECTED):
        raise RuntimeError('NATIVE_SCHEMA_EXPECTED_BOXES_MISMATCH')
    total=0
    for key,(obj,scope) in EXPECTED.items():
        box=boxes[key]
        if not isinstance(box,dict) or box.get('id')!=key:
            raise RuntimeError('NATIVE_SCHEMA_BOX_ID_INVALID')
        args=box.get('args')
        fields=box.get('meta_fields')
        if not isinstance(args,dict) or not isinstance(fields,list) or len(fields)>200:
            raise RuntimeError('NATIVE_SCHEMA_BOX_SHAPE_INVALID')
        if args.get('object_type')!=obj:
            raise RuntimeError('NATIVE_SCHEMA_BOX_OBJECT_SCOPE_INVALID')
        relation='allowed_tax' if obj=='tax' else 'allowed_post_type'
        if scope not in (args.get(relation) or []):
            raise RuntimeError('NATIVE_SCHEMA_BOX_RELATION_MISSING')
        names=[]
        for field in fields:
            if not isinstance(field,dict) or not isinstance(field.get('name'),str):
                raise RuntimeError('NATIVE_SCHEMA_FIELD_SHAPE_INVALID')
            names.append(field['name'])
        if len(names)!=len(set(names)):
            raise RuntimeError('NATIVE_SCHEMA_DUPLICATE_FIELD')
        total+=len(fields)
    if total<19 or total>160:
        raise RuntimeError('NATIVE_SCHEMA_FIELD_COUNT_INVALID')
    canon=json.dumps(boxes,ensure_ascii=False,sort_keys=True,separators=(',',':'),allow_nan=False).encode('utf-8')
    if len(canon)>120000:
        raise RuntimeError('NATIVE_SCHEMA_BYTES_UNBOUNDED')
    return canon

def schema_hash(boxes):
    return hashlib.sha256(canonical_payload(boxes)).hexdigest()
