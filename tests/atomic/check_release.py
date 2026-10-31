#!/usr/bin/env python3
"""Validate an operator-supplied complete release inventory before enabling writes."""

import argparse
import json
from pathlib import Path
import re
import sys


def validate(plan):
    if (not isinstance(plan, dict) or type(plan.get("schemaVersion")) is not int or
            plan["schemaVersion"] != 1):
        raise ValueError("Unknown release-inventory format.")
    for name in ("targetReaderVersion", "targetSchemaVersion", "appliedSchemaVersion"):
        if type(plan.get(name)) is not int or plan[name] < 1:
            raise ValueError(f"Invalid {name}.")
    if type(plan.get("enableWrites")) is not bool:
        raise ValueError("enableWrites must be an explicit boolean.")
    expected = plan.get("expectedNodeIds")
    nodes = plan.get("nodes")
    if (not isinstance(expected, list) or not expected or
            any(not isinstance(name, str) or not name for name in expected) or
            len(set(expected)) != len(expected) or not isinstance(nodes, list)):
        raise ValueError("A complete nonempty expected-node inventory is required.")
    seen = set()
    roles = set()
    for node in nodes:
        if (not isinstance(node, dict) or not isinstance(node.get("id"), str) or
                node["id"] not in expected or node["id"] in seen):
            raise ValueError("Unexpected or duplicate node.")
        seen.add(node["id"])
        if node.get("role") not in ("web", "worker"):
            raise ValueError("Each node must identify its web/worker role.")
        roles.add(node["role"])
        if (node.get("state") not in ("serving", "stopped") or
                not isinstance(node.get("revision"), str) or
                not re.fullmatch(r"[0-9a-f]{40}", node.get("revision", "")) or
                type(node.get("readerVersion")) is not int or node["readerVersion"] < 1):
            raise ValueError("Invalid node state, immutable revision, or reader version.")
        if (plan["enableWrites"] and node["state"] == "serving" and
                node["readerVersion"] < plan["targetReaderVersion"]):
            raise ValueError("Serving legacy reader/worker blocks new writes: " + node["id"])
    if seen != set(expected) or roles != {"web", "worker"}:
        raise ValueError("Inventory must account for every web and worker node.")
    if plan["enableWrites"] and plan["appliedSchemaVersion"] < plan["targetSchemaVersion"]:
        raise ValueError("The required schema has not been applied.")
    return "Writer activation preflight passed." if plan["enableWrites"] else "Writers remain disabled."


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("inventory", type=Path)
    args = parser.parse_args()
    try:
        print(validate(json.loads(args.inventory.read_text())))
    except (ValueError, OSError) as error:
        print(f"Release preflight failed: {error}", file=sys.stderr)
        sys.exit(1)
