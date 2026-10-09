#!/usr/bin/env python3
"""Fail-closed grant guard tests. Synthetic values; no Aiven access."""
import unittest
from scripts.ci.p0_mysql_metadata_grants_guard import verify_grants

DB = "canovia_p0_ci"
ACCOUNT = "`p0_ro_ci`@`%`"
USAGE = f"GRANT USAGE ON *.* TO {ACCOUNT}\n"
META = f"GRANT REFERENCES ON `{DB}`.* TO {ACCOUNT}\n"
LEDGER = f"GRANT SELECT ON `{DB}`.`migrations` TO {ACCOUNT}\n"


class TestMetadataGrants(unittest.TestCase):
    def test_minimal_permissions_allow_metadata_and_ledger(self):
        self.assertTrue(verify_grants(USAGE + META + LEDGER, DB))
        self.assertTrue(verify_grants(META + LEDGER, DB))

    def test_schema_only_select_does_not_hide_missing_ledger_permission(self):
        self.assertFalse(verify_grants(USAGE + META, DB))
        self.assertFalse(verify_grants(USAGE + LEDGER, DB))

    def test_reject_broad_select_even_when_other_requirements_met(self):
        self.assertFalse(verify_grants(META + LEDGER + f"GRANT SELECT ON `{DB}`.* TO {ACCOUNT}\n", DB))
        self.assertFalse(verify_grants(META + LEDGER + f"GRANT SELECT ON *.* TO {ACCOUNT}\n", DB))

    def test_wrong_database_and_other_tables_denied(self):
        self.assertFalse(verify_grants(META + LEDGER, "differentdb"))
        self.assertFalse(verify_grants(META + f"GRANT SELECT ON `{DB}`.`users` TO {ACCOUNT}\n", DB))
        self.assertFalse(verify_grants(META + LEDGER + f"GRANT SELECT ON `{DB}`.`users` TO {ACCOUNT}\n", DB))
        self.assertFalse(verify_grants(f"GRANT REFERENCES ON `otherdb`.* TO {ACCOUNT}\n" + LEDGER, DB))

    def test_reject_mutations_grant_options_admin_and_roles(self):
        for bad in (
            f"GRANT UPDATE ON `{DB}`.* TO {ACCOUNT}\n",
            f"GRANT ALL PRIVILEGES ON `{DB}`.* TO {ACCOUNT}\n",
            f"GRANT SELECT, INSERT ON `{DB}`.* TO {ACCOUNT}\n",
            f"GRANT REFERENCES ON `{DB}`.* TO {ACCOUNT} WITH GRANT OPTION\n",
            "GRANT `some_role`@`%` TO `p0_ro_ci`@`%`\n",
        ):
            with self.subTest(bad=bad[:32]):
                self.assertFalse(verify_grants(USAGE + META + LEDGER + bad, DB))

    def test_reject_untrusted_format_or_duplicate_grants(self):
        self.assertFalse(verify_grants(META + LEDGER + LEDGER, DB))
        self.assertFalse(verify_grants(META + LEDGER + "a", DB))
        self.assertFalse(verify_grants(META + LEDGER.replace("\n", "\r\n"), DB))
        self.assertFalse(verify_grants(META + LEDGER + "\x00", DB))
        self.assertFalse(verify_grants(META + LEDGER, "wrong db;"))
        self.assertFalse(verify_grants(META + LEDGER + "x" * 17000, DB))


if __name__ == "__main__":
    unittest.main()
