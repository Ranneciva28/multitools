#!/usr/bin/env python3
"""Private, full-length password verifier for x11vnc -unixpw_cmd."""
import hashlib
import hmac
import json
import os
import secrets
import sys
import tempfile

AUTH_FILE = '/etc/threads-tools/vnc-auth.json'
USERNAME = b'owner'


def reset():
    password = sys.stdin.buffer.read()
    if not password:
        raise SystemExit('Password cannot be empty')
    salt = secrets.token_bytes(32)
    digest = hashlib.pbkdf2_hmac('sha256', password, salt, 310_000)
    record = {'salt': salt.hex(), 'digest': digest.hex()}
    with tempfile.NamedTemporaryFile(mode='w', encoding='utf-8', dir=os.path.dirname(AUTH_FILE), delete=False) as tmp:
        json.dump(record, tmp)
        tmp.write('\n')
        temp_path = tmp.name
    os.chmod(temp_path, 0o600)
    os.replace(temp_path, AUTH_FILE)


def verify():
    username = sys.stdin.buffer.readline().rstrip(b'\r\n')
    password = sys.stdin.buffer.readline().rstrip(b'\r\n')
    if username != USERNAME or not password:
        raise SystemExit(1)
    try:
        with open(AUTH_FILE, encoding='utf-8') as source:
            record = json.load(source)
        actual = hashlib.pbkdf2_hmac('sha256', password, bytes.fromhex(record['salt']), 310_000)
        if hmac.compare_digest(actual, bytes.fromhex(record['digest'])):
            return
    except (OSError, ValueError, KeyError, TypeError):
        pass
    raise SystemExit(1)


if __name__ == '__main__':
    if sys.argv[1:] == ['set']:
        reset()
    elif not sys.argv[1:]:
        verify()
    else:
        raise SystemExit('Usage: vnc-auth.py [set]')
