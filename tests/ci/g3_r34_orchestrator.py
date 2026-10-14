#!/usr/bin/env python3
"""G3-R34: kill real disposable Laravel PHP processes and restart local S3."""
import os
import re
import select
import signal
import socket
import subprocess
import time
import uuid

import boto3
from botocore.config import Config

CASES = ("prewrite", "midtransaction", "postcommit", "success")
PHP = "tests/ci/g3_r34_laravel_crash.php"


def guard():
    expected = {
        "CI": "true", "G3_R34_EPHEMERAL_ONLY": "true",
        "APP_ENV": "testing", "DB_HOST": "127.0.0.1",
        "DB_CONNECTION": "mysql", "DB_DATABASE": "canovia_g3_r34_ci",
        "FILESYSTEM_DISK": "s3", "AWS_ENDPOINT": "http://127.0.0.1:9000",
        "AWS_ACCESS_KEY_ID": "g3_r34_emulator_only",
        "AWS_SECRET_ACCESS_KEY": "g3_r34_dummy_no_cloud",
    }
    if any(os.getenv(k) != v for k, v in expected.items()):
        raise RuntimeError("R34 CI boundary denied")
    if not re.fullmatch(r"g3-r34-ci-[0-9]+-[0-9]+", os.getenv("AWS_BUCKET", "")):
        raise RuntimeError("R34 bucket namespace denied")
    if any(os.getenv(k) for k in (
        "AWS_PROFILE", "AWS_SESSION_TOKEN", "AWS_ROLE_ARN",
        "AWS_WEB_IDENTITY_TOKEN_FILE", "AWS_CONTAINER_CREDENTIALS_FULL_URI",
        "AWS_CONTAINER_CREDENTIALS_RELATIVE_URI",
    )):
        raise RuntimeError("R34 credential boundary denied")


def client():
    guard()
    return boto3.client(
        "s3", endpoint_url="http://127.0.0.1:9000",
        aws_access_key_id="g3_r34_emulator_only",
        aws_secret_access_key="g3_r34_dummy_no_cloud", region_name="us-east-1",
        config=Config(s3={"addressing_style": "path"}, retries={"max_attempts": 1},
                      connect_timeout=1, read_timeout=3),
    )


def wait_for_storage():
    for _ in range(60):
        try:
            client().list_buckets()
            return
        except Exception:
            time.sleep(0.5)
    raise RuntimeError("R34 disposable object store unavailable")


def execute_case(env, case):
    process = subprocess.Popen(
        ["php", PHP, "run", case], env=env, stdout=subprocess.PIPE,
        stderr=subprocess.PIPE, text=True,
    )
    expected = "SUCCESS" if case == "success" else "CRASH_READY"
    try:
        deadline = time.monotonic() + 45
        while time.monotonic() < deadline:
            if process.poll() is not None:
                raise RuntimeError("PHP exited before R34 checkpoint: " + case)
            readable, _, _ = select.select([process.stdout], [], [], 0.5)
            if readable:
                if process.stdout.readline().strip() != expected:
                    raise RuntimeError("Wrong R34 checkpoint: " + case)
                break
        else:
            raise RuntimeError("R34 PHP checkpoint timeout: " + case)
        if case == "success":
            if process.wait(timeout=10) != 0:
                raise RuntimeError("R34 success process failed")
        else:
            process.kill()
            if process.wait(timeout=10) != -signal.SIGKILL:
                raise RuntimeError("R34 process was not SIGKILLed")
        print("R34 PHP PASS:", case, expected, flush=True)
    finally:
        if process.poll() is None:
            process.kill()
            process.wait(timeout=10)
        process.stdout.close()
        process.stderr.close()


def inspect(env, case):
    check = subprocess.run(["php", PHP, "verify", case], env=env,
                           capture_output=True, text=True, timeout=30)
    if check.returncode != 0 or check.stdout.strip() != "R34_VERIFY_PASS:" + case:
        raise RuntimeError("R34 cross-layer inspection failed: " + case)
    print(check.stdout.strip(), flush=True)


def main():
    guard()
    run_id = uuid.uuid4().hex
    env = dict(os.environ, G3_R34_RUN_ID=run_id)
    bucket = env["AWS_BUCKET"]
    wait_for_storage()
    s3 = client()
    s3.create_bucket(Bucket=bucket)
    s3.put_bucket_versioning(
        Bucket=bucket, VersioningConfiguration={"Status": "Enabled"})
    if s3.get_bucket_versioning(Bucket=bucket).get("Status") != "Enabled":
        raise RuntimeError("R34 bucket versioning not enabled")
    for case in CASES:
        execute_case(env, case)
    subprocess.run(["docker", "kill", "--signal=KILL", "g3-r34-minio"],
                   check=True, stdout=subprocess.DEVNULL)
    try:
        socket.create_connection(("127.0.0.1", 9000), timeout=1).close()
    except OSError:
        pass
    else:
        raise RuntimeError("S3 service remained reachable")
    subprocess.run(["docker", "start", "g3-r34-minio"], check=True,
                   stdout=subprocess.DEVNULL)
    wait_for_storage()
    for case in CASES:
        inspect(env, case)
    versions = client().list_object_versions(Bucket=bucket)
    if versions.get("DeleteMarkers") or len(versions.get("Versions", [])) != 3:
        raise RuntimeError("R34 generations or delete markers invalid")
    found = set()
    for version in versions["Versions"]:
        key = version["Key"]
        match = re.fullmatch(
            r"inbox/g3-r34/([a-f0-9]{32})/([a-z]+)\.txt", key)
        if not match or match.group(1) != run_id:
            raise RuntimeError("R34 unexpected S3 key")
        case = match.group(2)
        if case not in CASES or case == "prewrite" or case in found:
            raise RuntimeError("R34 duplicate or unexpected S3 generation")
        found.add(case)
        data = client().get_object(
            Bucket=bucket, Key=key, VersionId=version["VersionId"])["Body"].read()
        if data != ("r34-integration-fixture-" + case).encode():
            raise RuntimeError("R34 object changed after restart")
    if found != {"midtransaction", "postcommit", "success"}:
        raise RuntimeError("R34 missing object version")
    print("R34 CROSS-LAYER PASS: four Laravel states, three retained object"
          " generations after storage SIGKILL, no delete markers", flush=True)
    print("R34 GATES: provider_attested=false delete_authorized=false G3_OPEN")


if __name__ == "__main__":
    main()
