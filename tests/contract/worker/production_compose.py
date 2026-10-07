"""Verify the merged production topology without starting any containers."""
import json
import os
from pathlib import Path
import subprocess

root = Path(__file__).resolve().parents[3]
fixture_env = {"PATH": os.environ["PATH"]}
for service in ("IMAGE", "MAILER", "SEARCH", "TASKQUEUE", "CONDUIT", "MAINTENANCE", "FILE", "DB", "RENDER", "WEBHOOK"):
    fixture_env[f"GORGE_{service}_TOKEN"] = "contract-fixture"
fixture_env["GORGE_MAILER_DELIVERY_DSN"] = "fixture@tcp(mysql:3306)/phabricator_metamta"
fixture_env.update(MYSQL_ROOT_PASSWORD="contract-root", MYSQL_PASSWORD="contract-app",
                   GORGE_DB_MYSQL_USER="gorge_dbapi_ro", GORGE_DB_MYSQL_PASS="contract-ro")
for role in ("CACHE", "CONDUIT", "DAEMON", "DIFFERENTIAL", "MULTIMETER"):
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
file_token = services["gorge-file-storage"]["environment"]["GORGE_SERVICE_TOKEN"]
assert file_token == "contract-fixture"
assert services["phorge-migrate"]["environment"]["GORGE_FILE_TOKEN"] == file_token
# Web and daemon consume the migration job deployment config from shared conf.
for name in ("phorge", "phorge-daemon"):
    assert any(v.get("source") == "phorge-conf" for v in services[name]["volumes"])
mailer = services["gorge-mailer"]["environment"]
worker = services["gorge-worker"]["environment"]
assert services["gorge-worker"]["stop_grace_period"] == "45s"
assert worker["GORGE_WORKER_DRAIN_TIMEOUT_SEC"] == "30"
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
for name in ("mailer", "search", "taskqueue", "worker", "maintenance", "image", "file-storage", "render", "webhook", "conduit", "notification", "db-api"):
    assert services[f"gorge-{name}"]["build"]["args"]["SERVICE"] == f"gorge-{name}"
assert "readyz" in str(services["gorge-maintenance"]["healthcheck"]["test"])
db = services["gorge-db-api"]["environment"]
assert db["GORGE_DB_MYSQL_USER"] == "gorge_dbapi_ro"
assert db["GORGE_DB_MYSQL_PASS"] == "contract-ro"
for name in ("gorge-render", "gorge-webhook", "gorge-db-api"):
    assert services[name]["environment"]["GORGE_SERVICE_TOKEN"] == "contract-fixture"
assert all(p["host_ip"] == "127.0.0.1" for p in services["gorge-notification"]["ports"])
# Every new credential is mandatory, including the diagnostic account.
for key in ("GORGE_DB_TOKEN", "GORGE_RENDER_TOKEN", "GORGE_WEBHOOK_TOKEN",
            "GORGE_DB_MYSQL_USER", "GORGE_DB_MYSQL_PASS", "MYSQL_ROOT_PASSWORD", "MYSQL_PASSWORD"):
    missing_env = dict(fixture_env)
    del missing_env[key]
    rejected = subprocess.run(command, cwd=root, env=missing_env, capture_output=True)
    assert rejected.returncode, f"Missing {key} was accepted"
# Omitting mandatory profiles must fail instead of silently starting a partial stack.
without_profiles = command[:]
for profile in ("mailer", "search", "maintenance"):
    position = without_profiles.index("--profile")
    del without_profiles[position:position + 2]
result = subprocess.run(without_profiles, cwd=root, env=fixture_env, capture_output=True)
assert result.returncode != 0, "Missing production profiles were accepted"
print("Production Compose contracts passed: modes, shared DSN, auth, build, ordering and profiles.")

for role in ("CACHE", "CONDUIT", "DAEMON", "DIFFERENTIAL", "MULTIMETER"):
    key = f"GORGE_MAINTENANCE_{role}_DSN"
    assert services["gorge-maintenance"]["environment"][key] == fixture_env[key]
