#!/usr/bin/env python3
"""R23: disposable V5863 creative-plan test-file A/B/A' and real mutant matrix.

Mutated app source is NEVER staged, committed, released or used outside an
ephemeral PR GitHub Actions runner. All ordinary CI commands remain unchanged.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile

try:
    from .platform_workspace_scenario_equivalence import (
        Inconclusive, ROOT, WORKFLOW, observed_class_order, read_junit_all,
        runner,
    )
    from .platform_workspace_r20_app_mutation import (
        isolated_environment, parse_mutated_junit,
    )
except ImportError:
    from platform_workspace_scenario_equivalence import (
        Inconclusive, ROOT, WORKFLOW, observed_class_order, read_junit_all,
        runner,
    )
    from platform_workspace_r20_app_mutation import (
        isolated_environment, parse_mutated_junit,
    )

SOURCE = ROOT / "app/Http/Controllers/DevelopmentCreativePlanSelectionController.php"
TEST_SOURCE = ROOT / "tests/Feature/DevelopmentCreativePlanRescueV5863Test.php"
TARGET = "DevelopmentCreativePlanRescueV5863Test"
GROUPS = (
    ("V58.63 Legacy Creative Development Rescue", (
        TARGET, "WorkspaceModeResolverV540Test")),
    ("V58.69 Team Task Dependencies", (
        "DevelopmentTeamTaskDependencyV5869Test",
        "DeveloperWorkspaceSurfacesV580Test", TARGET)),
)
METHODS = (
    "test_legacy_hinanex_collaborative_creative_plan_is_discoverable_and_opt_in_is_reversible",
    "test_normal_development_plan_and_unrelated_study_are_not_reclassified",
    "test_other_persons_private_plan_never_appears_and_cannot_be_opted_in",
    "test_shared_viewer_may_open_team_surface_without_becoming_editor_or_owner",
)
GUARD = "        abort_unless($profiles->forPlan($plan)->key === 'creative', 404);"
AUTH_AND_GUARD = "        $ownership->authorizeView($request, $plan);\n" + GUARD
MUTATIONS = (
    (
        "creative_plan_owner_view_fence",
        "test_other_persons_private_plan_never_appears_and_cannot_be_opted_in",
        AUTH_AND_GUARD,
        "        // R23 throwaway simulated absent view permission.\n" + GUARD,
    ),
    (
        "creative_plan_category_fence",
        "test_normal_development_plan_and_unrelated_study_are_not_reclassified",
        GUARD,
        "        // R23 throwaway simulated absent creative category fence.",
    ),
)
PLANNED_PHP_RUNS = 2 * 3 + 2 * 2 * 2  # 6 A/B/A' baselines + 8 app-mutated
MAX_PHP_TEST_SOURCE = 256_000


def manifest(text: str) -> None:
    if not isinstance(text, str) or len(text) > 500_000:
        raise Inconclusive("invalid current Workspace Resume source")
    for name, classes in GROUPS:
        exactly = ("      - name: " + name + "\n"
                   + "        run: php artisan test "
                   + " ".join("tests/Feature/" + c + ".php" for c in classes))
        if text.count(exactly) != 1:
            raise Inconclusive("R23 historical group command drift")
    target_occurrences = sum(
        ("        run: php artisan test " in line
         and "tests/Feature/" + TARGET + ".php" in line.split(" "))
        for line in text.splitlines()
    )
    if target_occurrences != 2:
        raise Inconclusive("R23 target repeat occurrence count drift")
    if (len(GROUPS) != 2 or len(METHODS) != 4 or len(MUTATIONS) != 2
            or PLANNED_PHP_RUNS != 14):
        raise Inconclusive("R23 bounded run matrix drift")


def source_test_contract(data: bytes) -> None:
    if not 0 < len(data) <= MAX_PHP_TEST_SOURCE:
        raise Inconclusive("test source invalid length")
    text = data.decode("utf-8")
    if ("final class DevelopmentCreativePlanRescueV5863Test extends TestCase"
            not in text or "use RefreshDatabase;" not in text):
        raise Inconclusive("R23 Laravel test state baseline drift")
    import re
    got = re.findall(r"^\s*public function (test_[A-Za-z0-9_]+)\(\): void", text, re.M)
    if len(got) != 4 or set(got) != set(METHODS):
        raise Inconclusive("R23 target PHP assertions/method names drift")
    for evidence in ("->assertForbidden()", "->assertNotFound()",
                     "->assertDatabaseCount('intelligence_action_projections', 0)",
                     "$this->assertSame('制作活動', $hinanex->fresh()->category)"):
        if evidence not in text:
            raise Inconclusive("R23 targeted auth/state source guard drift")


def mutate(original: bytes, name: str) -> bytes:
    if not isinstance(original, bytes) or not 0 < len(original) < 100_000:
        raise Inconclusive("unsafe controller bytes")
    text = original.decode("utf-8")
    if (not text.startswith("<?php")
            or "final class DevelopmentCreativePlanSelectionController extends Controller" not in text
            or "R23 throwaway" in text):
        raise Inconclusive("unrecognized or pre-mutated application controller")
    match = [row for row in MUTATIONS if row[0] == name]
    if len(match) != 1:
        raise Inconclusive("unexpected R23 mutant identity")
    _, _, old, new = match[0]
    if text.count(old) != 1 or new in text:
        raise Inconclusive("application mutation anchor drift or duplicate")
    return text.replace(old, new, 1).encode("utf-8")


def command(classes: tuple[str, ...], output: Path) -> list[str]:
    return ["php", "artisan", "test", "--without-tty", "--log-junit",
            str(output), *("tests/Feature/" + c + ".php" for c in classes)]


def class_name(identity: tuple[str, str]) -> str:
    return identity[0].rsplit("\\", 1)[-1]


def inventory_check(records: tuple, classes: tuple[str, ...], shadow: bool) -> None:
    if set(observed_class_order(records)) != set(classes):
        raise Inconclusive("R23 group class inventory changed")
    method_set = {method for cls, method in records if cls.rsplit("\\", 1)[-1] == TARGET}
    expected = set() if shadow else set(METHODS)
    if method_set != expected:
        raise Inconclusive("V5863 method coverage missing or unexpected")


def A_B_A_prime(a: tuple, b: tuple, a2: tuple,
                original: tuple[str, ...], shadow: tuple[str, ...]) -> None:
    inventory_check(a, original, False)
    inventory_check(b, shadow, True)
    inventory_check(a2, original, False)
    if a != a2:
        raise Inconclusive("R23 restored group did not match original case order")
    without_target = tuple((cls, method) for cls, method in a if class_name((cls, method)) != TARGET)
    if sorted(without_target) != sorted(b):
        raise Inconclusive("R23 shadow group changed neighboring case inventory")


def mutated_outcome(classes: tuple[str, ...], path: Path,
                    baseline: tuple, required_target: str | None) -> dict:
    env = os.environ.copy()
    env.update({
        "APP_ENV": "testing", "DB_CONNECTION": "sqlite", "DB_DATABASE": ":memory:",
        "DB_URL": "", "DATABASE_URL": "", "CACHE_STORE": "array",
        "SESSION_DRIVER": "array", "QUEUE_CONNECTION": "sync",
        "MAIL_MAILER": "array", "NATIVE_AI_DRIVER": "disabled",
    })
    try:
        process = subprocess.run(command(classes, path), cwd=ROOT, env=env,
                                 capture_output=True, timeout=120, check=False)
    except (subprocess.TimeoutExpired, OSError) as exc:
        raise Inconclusive("mutated PHP process timed out or unavailable") from exc
    actual, failures = parse_mutated_junit(path)
    if sorted(actual) != sorted(baseline):
        raise Inconclusive("mutated process testcase identity drift")
    if bool(failures) != (process.returncode != 0):
        raise Inconclusive("JUnit failure markers and process status disagree")
    if required_target is not None and not any(
        class_name(identity) == TARGET and identity[1] == required_target
        for identity in failures
    ):
        raise Inconclusive("R23 expected real application regression undetected")
    if required_target is None and any(class_name(identity) == TARGET for identity in actual):
        raise Inconclusive("omitted target unexpectedly executed")
    return {
        "failed_testcases": len(failures),
        "failing_class_names": sorted({class_name(i) for i in failures}),
        "v5863_expected_method_detected": required_target is not None,
    }


def collect() -> dict:
    if not isolated_environment():
        raise Inconclusive("only ephemeral PR-to-main CI checkout is permitted")
    if (WORKFLOW.is_symlink() or SOURCE.is_symlink() or TEST_SOURCE.is_symlink()
            or not WORKFLOW.is_file() or not SOURCE.is_file() or not TEST_SOURCE.is_file()):
        raise Inconclusive("symlinked or absent source path")
    manifest(WORKFLOW.read_text(encoding="utf-8"))
    source_test_contract(TEST_SOURCE.read_bytes())
    original = SOURCE.read_bytes()
    for key, _, _, _ in MUTATIONS:
        mutate(original, key)
    digest = hashlib.sha256(original).hexdigest()
    baselines: list[tuple[tuple, tuple]] = []
    results: list[dict] = []
    count = 0
    try:
        with tempfile.TemporaryDirectory(prefix="canovia-r23-creative-") as td:
            base = Path(td)
            for i, (label, full) in enumerate(GROUPS):
                shadow = tuple(c for c in full if c != TARGET)
                paths = [base / f"baseline_{i}_{name}.xml" for name in ("A", "B", "A2")]
                a_seconds = runner(command(full, paths[0]), paths[0])
                a = read_junit_all(paths[0])
                count += 1
                b_seconds = runner(command(shadow, paths[1]), paths[1])
                b = read_junit_all(paths[1])
                count += 1
                a2_seconds = runner(command(full, paths[2]), paths[2])
                a2 = read_junit_all(paths[2])
                count += 1
                A_B_A_prime(a, b, a2, full, shadow)
                baselines.append((a, b))
                results.append({
                    "step": label,
                    "original_testcases": len(a),
                    "without_v5863_testcases": len(b),
                    "restored_testcases": len(a2),
                    "omitted_v5863_testcases": len(a) - len(b),
                    "whole_group_observed_seconds": {
                        "original": round(a_seconds, 4),
                        "omission": round(b_seconds, 4),
                        "restored": round(a2_seconds, 4),
                    },
                    "testcase_inventory_retained": True,
                    "state_isolation_proven": False,
                    "scope_specific_meaning_reviewed": False,
                    "controller_mutations": [],
                })
            for key, required_method, _, _ in MUTATIONS:
                try:
                    SOURCE.write_bytes(mutate(original, key))
                    for i, (_, full) in enumerate(GROUPS):
                        shadow = tuple(c for c in full if c != TARGET)
                        a, b = baselines[i]
                        full_path = base / f"{key}_{i}_full.xml"
                        full_result = mutated_outcome(full, full_path, a, required_method)
                        count += 1
                        shadow_path = base / f"{key}_{i}_shadow.xml"
                        omitted_result = mutated_outcome(shadow, shadow_path, b, None)
                        count += 1
                        results[i]["controller_mutations"].append({
                            "mutation": key, "full": full_result,
                            "omitting_v5863": omitted_result,
                        })
                finally:
                    SOURCE.write_bytes(original)
                    if hashlib.sha256(SOURCE.read_bytes()).hexdigest() != digest:
                        raise Inconclusive("R23 controller source not restored after mutant")
    finally:
        if SOURCE.read_bytes() != original:
            SOURCE.write_bytes(original)
        if hashlib.sha256(SOURCE.read_bytes()).hexdigest() != digest:
            raise Inconclusive("R23 application controller restoration failed")
    if count != PLANNED_PHP_RUNS or len(results) != 2:
        raise Inconclusive("R23 incomplete process evidence")
    return {
        "result": "OBSERVED_CREATIVE_RESCUE_CONTEXT_MUTATION_SHADOW",
        "selected_repeated_test_file": "tests/Feature/" + TARGET + ".php",
        "runner": "disposable PR-only GitHub Actions / SQLite in memory",
        "historical_named_step_count": len(GROUPS),
        "method_count": len(METHODS),
        "independent_php_processes": count,
        "contexts": results,
        "application_controller_restored_sha256": digest,
        "test_file_source_not_modified": True,
        "saved_runtime_improvement_proven": False,
        "full_workflow_state_equivalence_proven": False,
        "normal_ci_regression_commands_changed": False,
        "permission_to_remove_repeated_invocations": False,
        "production_release_authorized": False,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--run", action="store_true")
    args = parser.parse_args()
    try:
        if not args.run:
            raise Inconclusive("explicit --run required")
        report = collect()
    except (Inconclusive, OSError, ValueError, TypeError, UnicodeError) as exc:
        report = {
            "result": "BLOCK",
            "code": "R23_CREATIVE_RESCUE_EVIDENCE_INCOMPLETE",
            "failure_stage": str(exc)[:120],
            "permission_to_remove_repeated_invocations": False,
            "production_release_authorized": False,
        }
    print(json.dumps(report, ensure_ascii=True, sort_keys=True))
    return 0 if report["result"] == "OBSERVED_CREATIVE_RESCUE_CONTEXT_MUTATION_SHADOW" else 2


if __name__ == "__main__":
    sys.exit(main())
