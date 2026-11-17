#!/usr/bin/env python3
"""One-command Central-first identity-only Production onboarding.

Runs independently pinned Host Site Profile enrollment, then exact Central
readback/registration. No remote WordPress impersonation, OAuth, generic
executor, or write grant. Requires root and trusted first-party executables.
"""
from __future__ import annotations
import argparse,json,os,pathlib,re,stat,subprocess
HOST=pathlib.Path('/usr/local/libexec/mad4b-central-native-site-profile-enroll.py')
REGISTER=pathlib.Path('/usr/local/libexec/mad4b-central-native-site-profile-register.py')
PYTHON='/usr/bin/python3'
COMPANIES=('egypt-tour-gates','tourism-almallahgroup')
HOST_STATES=frozenset((
    'CENTRAL_PINNED_SSH_READONLY_SITE_PROFILE_ENROLLED',
    'ALREADY_ENROLLED_NO_DUPLICATE_WRITE',
))
REGISTER_STATES=frozenset((
    'CENTRAL_READ_ONLY_IDENTITY_REGISTERED_AND_VERIFIED',
    'ALREADY_REGISTERED_EXACT_IDENTITY_VERIFIED',
))
UUID=re.compile(r'^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$')

def exact_root_owned_program(path):
    if path.is_symlink() or not path.is_file():
        raise RuntimeError('CENTRAL_IDENTITY_EXECUTOR_MISSING_OR_SYMLINK')
    mode=path.stat()
    if mode.st_uid!=0 or not stat.S_ISREG(mode.st_mode) or mode.st_mode & 0o022:
        raise RuntimeError('CENTRAL_IDENTITY_EXECUTOR_NOT_ROOT_TRUSTED')

def run_one(args,stage):
    proc=subprocess.run(args,capture_output=True,text=True,timeout=105,check=False)
    if proc.returncode!=0:
        raise RuntimeError(stage.upper()+'_NATIVE_STAGE_REFUSED')
    lines=proc.stdout.splitlines()
    if len(lines)!=1:
        raise RuntimeError(stage.upper()+'_NATIVE_STAGE_UNEXPECTED_OUTPUT')
    data=json.loads(lines[0])
    if not isinstance(data,dict):
        raise RuntimeError(stage.upper()+'_NATIVE_RECEIPT_INVALID')
    return data

def orchestrate(company):
    if os.geteuid()!=0:
        raise RuntimeError('CENTRAL_NATIVE_ROOT_REQUIRED')
    if company not in COMPANIES:
        raise RuntimeError('CENTRAL_IDENTITY_COMPANY_OUTSIDE_ENROLLED_HOST_POLICY')
    exact_root_owned_program(HOST)
    exact_root_owned_program(REGISTER)
    first=run_one([PYTHON,'-I',str(HOST),'--company',company,'--mode','enroll'],'host')
    if (first.get('company')!=company or first.get('state') not in HOST_STATES
        or not isinstance(first.get('site_uuid'),str) or not UUID.fullmatch(first['site_uuid'])
        or first.get('site_write_enabled',first.get('governed_writes_enabled')) is not False
        or first.get('signed_original313') is not False):
        raise RuntimeError('CENTRAL_HOST_NATIVE_RECEIPT_AUTHORITY_INVALID')
    second=run_one([PYTHON,'-I',str(REGISTER),company],'central')
    if (second.get('company')!=company or second.get('state') not in REGISTER_STATES
        or second.get('site_uuid')!=first['site_uuid']
        or second.get('write_authorized',second.get('site_write_authorized')) is not False):
        raise RuntimeError('CENTRAL_REGISTRATION_NOT_BOUND_TO_HOST_SITE_UUID')
    check=run_one([PYTHON,'-I',str(HOST),'--company',company,'--mode','status'],'verify')
    if (check.get('state')!='ALREADY_ENROLLED_NO_DUPLICATE_WRITE'
        or check.get('site_uuid')!=first['site_uuid']
        or check.get('site_write_enabled') is not False):
        raise RuntimeError('CENTRAL_INDEPENDENT_VERIFIED_SITE_PROFILE_CHANGED')
    return {
        'contract':'mad4b.central.native-site-profile.default-ssh.v1',
        'state':'CONNECTED_READ_ONLY_CENTRAL_NATIVE_SSH',
        'company':company,'site_uuid':first['site_uuid'],
        'preferred_provisioner':'central_native_pinned_ssh',
        'wordpress_administrator_required':False,
        'site_profile_recreated':False if first['state']=='ALREADY_ENROLLED_NO_DUPLICATE_WRITE' else True,
        'write_authorized':False,'original313_signed':False,
    }

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--company',required=True,choices=COMPANIES)
    args=ap.parse_args()
    try:
        print(json.dumps(orchestrate(args.company),sort_keys=True))
    except (ValueError,RuntimeError,TypeError,FileNotFoundError,subprocess.TimeoutExpired,json.JSONDecodeError) as exc:
        print(json.dumps({'contract':'mad4b.central.native-site-profile.default-ssh.v1',
            'state':'HOLD','reason':str(exc)[:126],
            'new_write_grants_created':False,'original313_signed':False}))
        raise SystemExit(2)
if __name__=='__main__':main()
