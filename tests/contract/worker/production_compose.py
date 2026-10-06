"""Verify the merged production topology without starting any containers."""
import json
import os
from pathlib import Path
import subprocess

root = Path(__file__).resolve().parents[3]
fixture_env = {"PATH": os.environ["PATH"]}
for service in ("IMAGE", "MAILER", "SEARCH", "TASKQUEUE", "CONDUIT", "MAINTENANCE"):
    fixture_env[f"GORGE_{service}_TOKEN"] = "contract-fixture"
fixture_env["GORGE_MAILER_DELIVERY_DSN"] = "fixture@tcp(mysql:3306)/phabricator_metamta"
for role in ("CACHE", "CONDUIT", "DAEMON"):
    fixture_env[f"GORGE_MAINTENANCE_{role}_DSN"] = (
        f"fixture@tcp(mysql:3306)/phabricator_{role.lower()}"
    )
command = ["docker", "compose", "--env-file", "/dev/null", "-f", "docker-compose.yml",
           "-f", "docker-compose.production.yml", "--profile", "mailer",
           "--profile", "search", "--profile", "maintenance", "config", "--format", "json"]
result = subprocess.run(command, cwd=root, env=fixture_env, text=True, capture_output=True)
if result.returncode:
    raise RuntimeError("Production Compose configuration failed")
services = json.loads(result.stdout)["services"]
mailer = services["gorge-mailer"]["environment"]
worker = services["gorge-worker"]["environment"]
assert worker["GORGE_WORKER_MAIL_OUTBOX_DSN"] == mailer["GORGE_MAILER_DELIVERY_DSN"]
assert worker["GORGE_WORKER_MAILER_TOKEN"] == mailer["GORGE_SERVICE_TOKEN"]
assert worker["GORGE_WORKER_MAILER_URL"] == "http://gorge-mailer:8110"
for name in ("phorge-mailer-config", "phorge-search-config", "phorge", "phorge-daemon"):
    assert services[name]["environment"]["GORGE_MAIL_DELIVERY_MODE"] == "native"
    assert services[name]["environment"]["PHORGE_GORGE_POLICY"] == "required"
assert services["phorge-migrate"]["environment"]["GORGE_MAIL_DELIVERY_MODE"] == "legacy"
assert services["phorge-migrate"]["environment"]["GORGE_IMAGE_MODE"] == "gorge"
assert services["phorge-migrate"]["environment"]["GORGE_CLEANUP_GUARD"] == "true"
assert services["phorge-search-config"]["environment"]["GORGE_SEARCH_KEEP_MYSQL"] == "0"
assert services["phorge-search-config"]["environment"]["GORGE_SEARCH_EXCLUSIVE"] == "true"
assert services["phorge-search-config"]["depends_on"]["phorge-mailer-config"]["required"]
for name in ("phorge-mailer-config", "phorge-search-config", "gorge-maintenance"):
    assert services["phorge"]["depends_on"][name]["required"]
for name in ("mailer", "search", "taskqueue", "worker", "maintenance", "image"):
    assert services[f"gorge-{name}"]["build"]["args"]["SERVICE"] == f"gorge-{name}"
assert "readyz" in str(services["gorge-maintenance"]["healthcheck"]["test"])
# Omitting mandatory profiles must fail instead of silently starting a partial stack.
without_profiles = command[:]
for profile in ("mailer", "search", "maintenance"):
    position = without_profiles.index("--profile")
    del without_profiles[position:position + 2]
result = subprocess.run(without_profiles, cwd=root, env=fixture_env, capture_output=True)
assert result.returncode != 0, "Missing production profiles were accepted"
print("Production Compose contracts passed: modes, shared DSN, auth, build, ordering and profiles.")
