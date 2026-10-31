import copy
import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location("atomic_release", Path(__file__).with_name("check_release.py"))
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)


class ReleaseInventoryTest(unittest.TestCase):
    def plan(self):
        return {"schemaVersion": 1, "targetReaderVersion": 2, "targetSchemaVersion": 2,
                "appliedSchemaVersion": 2, "enableWrites": True,
                "expectedNodeIds": ["web-a", "worker-a"], "nodes": [
                    {"id": "web-a", "role": "web", "state": "serving",
                     "revision": "a" * 40, "readerVersion": 2},
                    {"id": "worker-a", "role": "worker", "state": "serving",
                     "revision": "b" * 40, "readerVersion": 2}]}

    def test_compatible_inventory_allows_activation(self):
        self.assertIn("passed", release.validate(self.plan()))

    def test_legacy_web_and_worker_each_block_new_writes(self):
        for index in (0, 1):
            plan = self.plan()
            plan["nodes"][index]["readerVersion"] = 1
            with self.subTest(index=index), self.assertRaises(ValueError):
                release.validate(plan)

    def test_explicit_stop_allows_legacy_node_but_cannot_hide_missing_inventory(self):
        plan = self.plan()
        plan["nodes"][1].update(readerVersion=1, state="stopped")
        self.assertIn("passed", release.validate(plan))
        plan["nodes"].pop()
        with self.assertRaises(ValueError):
            release.validate(plan)

    def test_schema_and_unknown_versions_fail_closed(self):
        for key, value in (("appliedSchemaVersion", 1), ("targetSchemaVersion", True),
                           ("schemaVersion", 2), ("schemaVersion", True), ("enableWrites", "yes")):
            plan = self.plan()
            plan[key] = value
            with self.subTest(key=key), self.assertRaises(ValueError):
                release.validate(plan)

    def test_duplicate_nodes_and_nonimmutable_revisions_fail(self):
        plan = self.plan()
        plan["nodes"].append(copy.deepcopy(plan["nodes"][0]))
        with self.assertRaises(ValueError):
            release.validate(plan)
        plan = self.plan()
        plan["nodes"][0]["revision"] = "main"
        with self.assertRaises(ValueError):
            release.validate(plan)

    def test_disabling_writes_does_not_require_new_readers_or_schema(self):
        plan = self.plan()
        plan.update(enableWrites=False, appliedSchemaVersion=1)
        plan["nodes"][1]["readerVersion"] = 1
        self.assertIn("disabled", release.validate(plan))


if __name__ == "__main__":
    unittest.main()
