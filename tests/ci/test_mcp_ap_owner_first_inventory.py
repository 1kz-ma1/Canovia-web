"""Safe offline checks for the AP-owner-first source inventory.

Runs without GitHub/Aiven/Render credentials, network, Laravel or a DB.
"""
import importlib.util
import pathlib
import unittest

SOURCE = pathlib.Path(__file__).resolve().parents[2] / "scripts/ci/mcp_ap_owner_first_inventory.py"
spec = importlib.util.spec_from_file_location("mcp_ap_owner_first_inventory", SOURCE)
inventory = importlib.util.module_from_spec(spec)
spec.loader.exec_module(inventory)


class OwnerFirstSourceInventoryTest(unittest.TestCase):
    def fixture(self):
        old = {f"database/migrations/old_{i:03}.php" for i in range(94)}
        new = old | {f"database/migrations/new_{i:03}.php" for i in range(20)}
        old |= {
            inventory.HOTSPOTS["account_link_committer"],
            inventory.HOTSPOTS["explicit_consent"],
            inventory.HOTSPOTS["delegated_policy"],
        }
        new |= set(inventory.HOTSPOTS.values())
        old_sources = {
            inventory.HOTSPOTS["account_link_committer"]: "identity_fingerprint",
            inventory.HOTSPOTS["explicit_consent"]: "UPDATE mcp_delegated_grants",
            inventory.HOTSPOTS["delegated_policy"]: "identity_fingerprint",
        }
        new_sources = {
            inventory.HOTSPOTS["account_link_committer"]: "active_identity_fingerprint",
            inventory.HOTSPOTS["explicit_consent"]: "DB::table('mcp_study_result_proposals')",
            inventory.HOTSPOTS["delegated_policy"]: "active_identity_fingerprint",
        }
        return old, new, old_sources, new_sources

    def test_exact_migration_count_is_only_an_inventory_not_release_approval(self):
        old, new, old_sources, new_sources = self.fixture()
        report = inventory.source_inventory(old, new, old_sources, new_sources)
        self.assertEqual(report["live_source_migrations"], 94)
        self.assertEqual(report["reviewed_main_source_migrations"], 114)
        self.assertEqual(report["added_source_migrations"], 20)
        self.assertFalse(report["candidate_zero_ddl_verified"])
        self.assertFalse(report["production_release_authorized"])
        self.assertFalse(report["aiven_queried"])

    def test_missing_live_tool_and_new_schema_dependencies_are_reported(self):
        old, new, old_sources, new_sources = self.fixture()
        report = inventory.source_inventory(old, new, old_sources, new_sources)
        self.assertFalse(report["hotspots"]["study_context"]["present_in_live"])
        self.assertTrue(report["hotspots"]["study_context"]["present_in_reviewed_main"])
        self.assertIn("new_result_proposal_table", report["main_direct_schema_refs"]["explicit_consent"])
        self.assertIn("new_active_identity_column", report["main_direct_schema_refs"]["account_link_committer"])

    def test_old_migration_deletion_and_legacy_refs_are_visible(self):
        old, new, old_sources, new_sources = self.fixture()
        new.discard("database/migrations/old_000.php")
        old_sources[inventory.HOTSPOTS["delegated_policy"]] = "active_identity_fingerprint"
        report = inventory.source_inventory(old, new, old_sources, new_sources)
        self.assertEqual(report["removed_source_migrations"], 1)
        self.assertIn("new_active_identity_column", report["live_direct_schema_refs"]["delegated_policy"])
        self.assertFalse(report["assessed_backport_implementation_complete"])


if __name__ == "__main__":
    unittest.main()
