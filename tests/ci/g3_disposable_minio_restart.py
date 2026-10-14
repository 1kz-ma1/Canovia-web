#!/usr/bin/env python3
"""G3 R33: ephemeral MinIO S3-compatible *process restart* evidence only.

No AWS, Render, Aiven, production access, conditional delete or DeleteObject.
Only a GitHub-hosted runner with a localhost endpoint and dummy credentials.
"""
from __future__ import annotations

from concurrent.futures import ThreadPoolExecutor
import argparse
import hashlib
import json
import os
from pathlib import Path
import socket
import sys
import uuid

import boto3
from botocore.config import Config

ENDPOINT = "http://127.0.0.1:9000"
ACCESS = "g3_r33_emulator_only"
SECRET = "g3_dummy_credential_no_cloud"
BUCKET_PREFIX = "g3-r33-throwaway-"


def isolated(env=None):
    env = os.environ if env is None else env
    if not (
        env.get("CI") == "true"
        and env.get("G3_R33_EPHEMERAL_MINIO_ONLY") == "true"
        and env.get("G3_R33_ENDPOINT") == ENDPOINT
        and env.get("AWS_ACCESS_KEY_ID") == ACCESS
        and env.get("AWS_SECRET_ACCESS_KEY") == SECRET
        and env.get("AWS_EC2_METADATA_DISABLED") == "true"
        and env.get("AWS_SESSION_TOKEN") is None
        and env.get("AWS_PROFILE") is None
        and env.get("AWS_WEB_IDENTITY_TOKEN_FILE") is None
        and env.get("AWS_CONTAINER_CREDENTIALS_RELATIVE_URI") is None
        and env.get("AWS_CONTAINER_CREDENTIALS_FULL_URI") is None
    ):
        raise RuntimeError("G3 R33 emulator isolation gate denied")


def client():
    isolated()
    return boto3.client(
        "s3", region_name="us-east-1", endpoint_url=ENDPOINT,
        aws_access_key_id=ACCESS, aws_secret_access_key=SECRET,
        config=Config(
            s3={"addressing_style": "path"},
            signature_version="s3v4",
            retries={"max_attempts": 1},
            connect_timeout=1,
            read_timeout=4,
        ),
    )


def check_local_gate():
    safe = dict(os.environ)
    isolated(safe)
    for override in (
        {"CI": "false"},
        {"G3_R33_EPHEMERAL_MINIO_ONLY": "false"},
        {"G3_R33_ENDPOINT": "https://s3.amazonaws.com"},
        {"G3_R33_ENDPOINT": "http://localhost:9000"},
        {"AWS_ACCESS_KEY_ID": "not-a-dummy"},
        {"AWS_SECRET_ACCESS_KEY": "not-a-dummy"},
        {"AWS_SESSION_TOKEN": "not-allowed"},
        {"AWS_PROFILE": "production"},
        {"AWS_WEB_IDENTITY_TOKEN_FILE": "/tmp/token"},
        {"AWS_CONTAINER_CREDENTIALS_FULL_URI": "http://169.254.170.2"},
    ):
        unsafe = dict(safe, **override)
        try:
            isolated(unsafe)
        except RuntimeError:
            continue
        raise AssertionError("Isolation gate accepted forbidden configuration")
    return True


def object_bytes(value):
    return ("g3-r33-fixture:" + value).encode("ascii")


def record(key, versions, payloads):
    return {
        "key": key,
        "version_ids": versions,
        "sha256": [hashlib.sha256(p).hexdigest() for p in payloads],
    }


def verify_versions(s3, bucket, item):
    key = item["key"]
    listed = s3.list_object_versions(Bucket=bucket, Prefix=key)
    versions = listed.get("Versions", [])
    seen = {v["VersionId"] for v in versions if v["Key"] == key}
    expected = set(item["version_ids"])
    if seen != expected or len(expected) != len(item["version_ids"]):
        raise AssertionError("A version is missing, duplicated, or unexpected")
    if any(m["Key"] == key for m in listed.get("DeleteMarkers", [])):
        raise AssertionError("Unexpected delete marker")
    latest = s3.head_object(Bucket=bucket, Key=key)["VersionId"]
    if latest not in expected:
        raise AssertionError("HEAD points at unexpected generation")
    for version, digest in zip(item["version_ids"], item["sha256"]):
        data = s3.get_object(
            Bucket=bucket, Key=key, VersionId=version,
        )["Body"].read()
        if hashlib.sha256(data).hexdigest() != digest:
            raise AssertionError("Version content digest mismatch")
    return latest


def prepare(path: Path):
    check_local_gate()
    s3 = client()
    bucket = BUCKET_PREFIX + uuid.uuid4().hex[:20]
    s3.create_bucket(Bucket=bucket)
    s3.put_bucket_versioning(
        Bucket=bucket, VersioningConfiguration={"Status": "Enabled"},
    )
    if s3.get_bucket_versioning(Bucket=bucket).get("Status") != "Enabled":
        raise AssertionError("MinIO failed to enable versioning")

    aba_key = "synthetic/aba"
    aba_payloads = [object_bytes(x) for x in ("A", "B", "A")]
    aba_versions = [
        s3.put_object(Bucket=bucket, Key=aba_key, Body=p)["VersionId"]
        for p in aba_payloads
    ]
    if len(set(aba_versions)) != 3:
        raise AssertionError("ABA produced nonunique generation")

    race_key = "synthetic/parallel"
    race_payloads = [object_bytes("writer" + str(i)) for i in range(4)]

    def write(payload):
        return client().put_object(Bucket=bucket, Key=race_key, Body=payload)["VersionId"]

    with ThreadPoolExecutor(max_workers=4) as executor:
        race_versions = list(executor.map(write, race_payloads))
    if len(set(race_versions)) != 4:
        raise AssertionError("Concurrent writes produced nonunique versions")

    ack_key = "synthetic/ack-ambiguity"
    ack_payloads = [object_bytes("same"), object_bytes("same")]
    first = s3.put_object(Bucket=bucket, Key=ack_key, Body=ack_payloads[0])
    # Deliberately lose the application's ACK, not the real HTTP response.
    _ignored_application_ack = first
    second = s3.put_object(Bucket=bucket, Key=ack_key, Body=ack_payloads[1])
    if first["VersionId"] == second["VersionId"]:
        raise AssertionError("Repeated PUT reused generation")

    items = [
        record(aba_key, aba_versions, aba_payloads),
        record(race_key, race_versions, race_payloads),
        record(ack_key, [first["VersionId"], second["VersionId"]], ack_payloads),
    ]
    for item in items:
        verify_versions(s3, bucket, item)

    # Manifest contains only disposable fixture names, generations and hashes.
    # It is kept in RUNNER_TEMP, not pushed or published as a job artifact.
    state = {
        "schema": "canovia.g3.r33.disposable_restart.v1",
        "bucket": bucket, "items": items,
        "provider_authenticated": False, "safe_delete_authorized": False,
        "crash_consistency_proven": False,
    }
    path.write_text(json.dumps(state, sort_keys=True), encoding="utf-8")
    print("R33 PREPARE PASS: synthetic versions written and re-read via HTTP")


def outage():
    check_local_gate()
    # The Docker container is SIGKILLed in CI before this phase.
    # A fresh TCP connect to the loopback service must fail.
    try:
        with socket.create_connection(("127.0.0.1", 9000), timeout=1):
            pass
    except OSError:
        print("R33 OUTAGE PASS: process endpoint unavailable after SIGKILL")
        return
    raise AssertionError("S3-compatible service unexpectedly remained reachable")


def verify(path: Path):
    check_local_gate()
    state = json.loads(path.read_text(encoding="utf-8"))
    if (
        type(state) is not dict
        or set(state) != {
            "schema", "bucket", "items", "provider_authenticated",
            "safe_delete_authorized", "crash_consistency_proven",
        }
        or state["schema"] != "canovia.g3.r33.disposable_restart.v1"
        or type(state["bucket"]) is not str
        or not state["bucket"].startswith(BUCKET_PREFIX)
        or state["provider_authenticated"] is not False
        or state["safe_delete_authorized"] is not False
        or state["crash_consistency_proven"] is not False
        or type(state["items"]) is not list
        or len(state["items"]) != 3
    ):
        raise AssertionError("Untrusted or malformed restart fixture")
    s3 = client()
    if s3.get_bucket_versioning(Bucket=state["bucket"]).get("Status") != "Enabled":
        raise AssertionError("Versioning state lost after process restart")
    for item in state["items"]:
        verify_versions(s3, state["bucket"], item)

    # The same persistent directory survives a real container process kill,
    # but this does not prove a machine power failure or fsync durability.
    last = object_bytes("post-restart")
    new = s3.put_object(
        Bucket=state["bucket"], Key="synthetic/post-restart", Body=last,
    )
    read = s3.get_object(
        Bucket=state["bucket"], Key="synthetic/post-restart",
        VersionId=new["VersionId"],
    )["Body"].read()
    if read != last:
        raise AssertionError("Post-restart PUT/GET failed")
    print("R33 RESTART PASS: process SIGKILL/restart retained 9 versioned writes "
          "and accepted one new write; no DeleteObject")
    print("R33 AUTHORITY: provider_attested=false, delete_authorized=false, G3_OPEN")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("phase", choices=("prepare", "outage", "verify", "gate"))
    parser.add_argument("--manifest", type=Path)
    args = parser.parse_args()
    if args.phase in ("prepare", "verify") and args.manifest is None:
        parser.error("--manifest required for prepare/verify")
    if args.phase == "prepare":
        prepare(args.manifest)
    elif args.phase == "outage":
        outage()
    elif args.phase == "verify":
        verify(args.manifest)
    else:
        check_local_gate()
        print("R33 SCOPE GATE PASS: only CI/local emulator and fixed dummy credentials")
    return 0


if __name__ == "__main__":
    sys.exit(main())
