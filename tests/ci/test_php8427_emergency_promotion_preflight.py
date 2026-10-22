"""Synthetic negative controls for the no-GO emergency promotion preflight."""
import unittest

from scripts.ops.php8427_emergency_promotion_preflight import (
    CANDIDATE_SHA,
    EXPECTED_CHANGED_PATHS,
    EXPECTED_PROOF_BLOBS,
    EXPECTED_RUNTIME_BLOBS,
    LIVE_SHA,
    offline_decision,
    validate_source,
)

SAFE_DOCKER = """FROM php:8.4.27-fpm-bookworm AS app
RUN php -r 'if (PHP_VERSION_ID < 80427 || PHP_VERSION_ID >= 80500) { exit(1); }'
"""
SAFE_START = """if [ "${APP_ENV:-}" != "production" ]; then
    exit 42
fi
if [ "${CANOVIA_STARTUP_MIGRATIONS:-check}" != "check" ] || [ -n "${CANOVIA_APPROVED_MIGRATION_SHA:-}" ]; then
    exit 42
fi
"""


class EmergencyPromotionPreflightTest(unittest.TestCase):
    def sources(self, **overrides):
        fields = {
            "live_sha": LIVE_SHA,
            "candidate_sha": CANDIDATE_SHA,
            "changed_paths": EXPECTED_CHANGED_PATHS,
            "blobs": {**EXPECTED_RUNTIME_BLOBS, **EXPECTED_PROOF_BLOBS},
            "live_start": "php artisan migrate --force",
            "candidate_start": SAFE_START,
            "candidate_dockerfile": SAFE_DOCKER,
        }
        fields.update(overrides)
        return validate_source(**fields)

    def test_only_exact_pinned_source_passes(self):
        self.assertEqual(self.sources(), [])

    def test_foreign_path_fails(self):
        changes = set(EXPECTED_CHANGED_PATHS)
        changes.add("database/migrations/evil.php")
        self.assertIn("CHANGED_PATH_SET_MISMATCH", self.sources(changed_paths=changes))

    def test_missing_expected_path_fails(self):
        self.assertIn(
            "CHANGED_PATH_SET_MISMATCH",
            self.sources(changed_paths={"Dockerfile"}),
        )

    def test_runtime_or_evidence_blob_drift_fails(self):
        for name in (*EXPECTED_RUNTIME_BLOBS, *EXPECTED_PROOF_BLOBS):
            with self.subTest(name=name):
                blobs = {**EXPECTED_RUNTIME_BLOBS, **EXPECTED_PROOF_BLOBS}
                blobs[name] = "0" * 40
                self.assertIn("BLOB_MISMATCH:" + name, self.sources(blobs=blobs))

    def test_stale_live_or_candidate_fails(self):
        self.assertIn("LIVE_SHA_CHANGED", self.sources(live_sha="f" * 40))
        self.assertIn("CANDIDATE_SHA_CHANGED", self.sources(candidate_sha="f" * 40))

    def test_legacy_migration_and_missing_guard_fails(self):
        self.assertIn(
            "CANDIDATE_AUTO_MIGRATION_FOUND",
            self.sources(candidate_start=SAFE_START + "php artisan migrate --force"),
        )
        self.assertIn(
            "CANDIDATE_STARTUP_GUARD_MISSING",
            self.sources(candidate_start="echo start"),
        )

    def test_unpatched_php_image_fails(self):
        self.assertIn(
            "PATCHED_PHP_IMAGE_NOT_PINNED",
            self.sources(candidate_dockerfile="FROM php:8.4-fpm-bookworm AS app"),
        )
        self.assertIn(
            "PHP_VERSION_FLOOR_MISSING",
            self.sources(candidate_dockerfile="FROM php:8.4.27-fpm-bookworm AS app"),
        )

    def test_good_source_always_refuses_production_go(self):
        for production_policy_present in (True, False):
            with self.subTest(policy=production_policy_present):
                v = offline_decision([], production_policy_present)
                self.assertEqual(v["verdict"], "NO_GO")
                self.assertEqual(v["source_integrity"], "PASS")
                self.assertIn("OWNER_PRODUCTION_GO_NOT_GRANTED", v["reason_codes"])
                self.assertIn("CURRENT_AIVEN_PHYSICAL_SCHEMA_AND_LEDGER_NOT_ATTESTED", v["reason_codes"])
                self.assertEqual(v["real_database_queries"], 0)

    def test_missing_required_workflow_is_explicit(self):
        v = offline_decision([], False)
        self.assertIn("PRODUCTION_REQUIRED_PROMOTION_WORKFLOW_ABSENT", v["reason_codes"])

    def test_invalid_source_never_looks_safe(self):
        v = offline_decision(["BLOB_MISMATCH:Dockerfile"], True)
        self.assertEqual(v["source_integrity"], "BLOCK")
        self.assertEqual(v["verdict"], "NO_GO")


if __name__ == "__main__":
    unittest.main()
