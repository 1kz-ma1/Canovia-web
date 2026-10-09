#!/usr/bin/env python3
"""No-network negative controls for the Aiven account-creation body."""
import contextlib
import io
import json
import sys
import unittest
from unittest import mock

from scripts.ops.p0_aiven_zero_grants_payload import (
    UnsafeIdentity,
    main,
    zero_grants_payload,
)


class TestZeroGrantCreationBody(unittest.TestCase):
    def test_explicit_empty_grants_never_omitted(self):
        body = zero_grants_payload("canovia_p0_audit")
        self.assertEqual(body, {"username": "canovia_p0_audit", "mysql_grants": []})
        self.assertEqual(set(body), {"username", "mysql_grants"})
        self.assertEqual(json.loads(json.dumps(body))["mysql_grants"], [])

    def test_reject_admin_and_non_audit_identifiers(self):
        for name in ("avnadmin", "root", "admin", "canovia", "canovia_p0_", "canovia_p0_audit;DROP", "Canovia_p0_audit", "", "canovia_p0_" + "x" * 32, "myapp", "canovia_p0_audit@host"):
            with self.subTest(name=name):
                with self.assertRaises(UnsafeIdentity):
                    zero_grants_payload(name)

    def test_cli_outputs_json_body_only_without_creating_user(self):
        out = io.StringIO()
        err = io.StringIO()
        with mock.patch.object(sys, "argv", ["script", "--username", "canovia_p0_audit"]), contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            rc = main()
        self.assertEqual(rc, 0)
        self.assertEqual(json.loads(out.getvalue()), {"username": "canovia_p0_audit", "mysql_grants": []})
        self.assertNotIn("Bearer", out.getvalue() + err.getvalue())
        self.assertIn("OFFLINE_ONLY_NOT_PROVISIONED", err.getvalue())


if __name__ == "__main__":
    unittest.main()
