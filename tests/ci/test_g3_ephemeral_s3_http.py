"""G3-R32: ephemeral S3-compatible HTTP behavior trial, NOT AWS S3 attestation.

Only run on a disposable localhost emulator in GitHub Actions.
Every test writes short synthetic bytes; NO DeleteObject, no cloud credentials,
no IAM mutations, no real bucket access, no production operations.
"""
from __future__ import annotations

from concurrent.futures import ThreadPoolExecutor
import os
import uuid
import unittest

import boto3
from botocore.config import Config

ENDPOINT = "http://127.0.0.1:5000"
ACCESS = "g3_emulator_only"
SECRET = "g3_fake_not_a_secret"


def require_isolation(env=None):
    env = os.environ if env is None else env
    if (
        env.get("CI") != "true"
        or env.get("G3_EPHEMERAL_S3_EMULATOR_ONLY") != "true"
        or env.get("G3_S3_ENDPOINT") != ENDPOINT
        or env.get("AWS_ACCESS_KEY_ID") != ACCESS
        or env.get("AWS_SECRET_ACCESS_KEY") != SECRET
        or env.get("AWS_SESSION_TOKEN") is not None
        or env.get("AWS_PROFILE") is not None
    ):
        raise RuntimeError("G3 isolated emulator requirements not met")


def client():
    require_isolation()
    return boto3.client(
        "s3",
        region_name="us-east-1",
        endpoint_url=ENDPOINT,
        aws_access_key_id=ACCESS,
        aws_secret_access_key=SECRET,
        config=Config(
            s3={"addressing_style": "path"},
            signature_version="s3v4",
            retries={"max_attempts": 1},
            connect_timeout=2, read_timeout=5,
        ),
    )


def synthetic_bucket():
    return "g3-r32-ephemeral-" + uuid.uuid4().hex[:18]


class EphemeralS3HttpTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        require_isolation()
        cls.s3 = client()
        cls.bucket = synthetic_bucket()
        cls.s3.create_bucket(Bucket=cls.bucket)
        cls.s3.put_bucket_versioning(
            Bucket=cls.bucket,
            VersioningConfiguration={"Status": "Enabled"},
        )
        status = cls.s3.get_bucket_versioning(Bucket=cls.bucket).get("Status")
        if status != "Enabled":
            raise AssertionError("Emulator versioning was not enabled")

    def test_versions_a_b_a_are_distinct_and_originals_remain_readable(self):
        key = "fixture/aba-replacement"
        ids = []
        for data in (b"A", b"B", b"A"):
            response = self.s3.put_object(Bucket=self.bucket, Key=key, Body=data)
            self.assertEqual(response["ResponseMetadata"]["HTTPStatusCode"], 200)
            self.assertTrue(response.get("VersionId"))
            ids.append(response["VersionId"])
        self.assertEqual(len(set(ids)), 3, "ABA must not collapse distinct versions")
        latest = self.s3.head_object(Bucket=self.bucket, Key=key)
        self.assertEqual(latest["VersionId"], ids[-1])
        for version, expected in zip(ids, (b"A", b"B", b"A")):
            observed = self.s3.get_object(
                Bucket=self.bucket, Key=key, VersionId=version,
            )
            self.assertEqual(observed["Body"].read(), expected)
        self.assertEqual(len(self.s3.list_object_versions(
            Bucket=self.bucket, Prefix=key,
        )["Versions"]), 3)

    def test_parallel_writers_observe_distinct_version_ids(self):
        key = "fixture/concurrent-writers"
        payloads = [("writer-%d" % i).encode() for i in range(4)]
        def write(payload):
            return client().put_object(
                Bucket=self.bucket, Key=key, Body=payload,
            )["VersionId"]
        with ThreadPoolExecutor(max_workers=4) as pool:
            versions = list(pool.map(write, payloads))
        self.assertEqual(len(set(versions)), len(payloads))
        entries = self.s3.list_object_versions(
            Bucket=self.bucket, Prefix=key,
        ).get("Versions", [])
        self.assertEqual({x["VersionId"] for x in entries}, set(versions))
        self.assertIn(
            self.s3.head_object(Bucket=self.bucket, Key=key)["VersionId"],
            versions,
        )

    def test_lost_application_ack_is_ambiguous_and_retry_creates_new_version(self):
        key = "fixture/ambiguous-ack"
        # The mock "transport loss" occurs AFTER an actual HTTP PUT succeeded:
        # the application intentionally discards its response. This is NOT a
        # physical network drop or real server crash.
        _ignored = self.s3.put_object(Bucket=self.bucket, Key=key, Body=b"same")
        observed = self.s3.head_object(Bucket=self.bucket, Key=key)
        self.assertIsNotNone(observed.get("VersionId"))
        retry = self.s3.put_object(Bucket=self.bucket, Key=key, Body=b"same")
        self.assertNotEqual(observed["VersionId"], retry["VersionId"])
        self.assertEqual(len(self.s3.list_object_versions(
            Bucket=self.bucket, Prefix=key,
        ).get("Versions", [])), 2)
        # A HEAD observation alone cannot tell which write the client ACKed.
        # It MUST NOT grant a cleanup/delete/retry authorization.

    def test_client_reconnect_reads_data_but_is_not_server_crash_recovery(self):
        key = "fixture/reconnect"
        first = self.s3.put_object(Bucket=self.bucket, Key=key, Body=b"ok")
        later_client = client()
        head = later_client.head_object(Bucket=self.bucket, Key=key)
        self.assertEqual(first["VersionId"], head["VersionId"])
        self.assertEqual(
            later_client.get_object(
                Bucket=self.bucket, Key=key,
                VersionId=first["VersionId"],
            )["Body"].read(),
            b"ok",
        )

    def test_unversioned_bucket_does_not_provide_proven_generation(self):
        other = synthetic_bucket()
        self.s3.create_bucket(Bucket=other)
        self.assertNotEqual(
            self.s3.get_bucket_versioning(Bucket=other).get("Status"),
            "Enabled",
        )
        put = self.s3.put_object(Bucket=other, Key="fixture/no-version", Body=b"v")
        self.assertFalse(put.get("VersionId"))
        # Versioning absent => generation-based cleanup must stay blocked.

    def test_response_header_is_not_independent_provider_attestation(self):
        key = "fixture/untrusted-metadata"
        received = self.s3.put_object(Bucket=self.bucket, Key=key, Body=b"v")
        self.assertTrue(received.get("VersionId"))
        self.assertTrue(received["ResponseMetadata"].get("RequestId"))
        authority = {
            "provider_authenticated": False,
            "generation_write_bound": False,
            "writer_exclusivity_verified": False,
            "retry_authorized": False,
            "delete_authorized": False,
        }
        self.assertFalse(any(authority.values()))

    def test_emulator_gate_rejects_real_endpoint_and_credential_scope(self):
        safe = dict(os.environ)
        for override in (
            {"G3_S3_ENDPOINT": "https://s3.amazonaws.com"},
            {"G3_S3_ENDPOINT": "http://localhost:5000"},
            {"AWS_ACCESS_KEY_ID": "actual_credential"},
            {"AWS_SECRET_ACCESS_KEY": "unexpected_secret"},
            {"AWS_SESSION_TOKEN": "forbidden_token"},
            {"AWS_PROFILE": "production"},
            {"CI": "false"},
            {"G3_EPHEMERAL_S3_EMULATOR_ONLY": "false"},
        ):
            unsafe = dict(safe, **override)
            with self.assertRaises(RuntimeError):
                require_isolation(unsafe)


if __name__ == "__main__":
    unittest.main()
