"""Load actual Compose mailer environments through the paired Go loader.

Does not start Docker services or contact a provider. The Go overlay lives in a
temporary directory and leaves both source checkouts unchanged.
"""
import argparse
import json
import os
from pathlib import Path
import subprocess
import tempfile

parser = argparse.ArgumentParser()
parser.add_argument("--gorge-dir", required=True)
args = parser.parse_args()
root = Path(__file__).resolve().parents[3]
gorge = Path(args.gorge_dir).resolve()
package = gorge / "go/internal/mailer"
if not (package / "config.go").is_file():
    raise RuntimeError("Paired Gorge mailer source is missing")

cases = [
    ("legacy-compose-input", {"MAILER_TYPE": "smtp", "MAILER_KEY": "legacy-provider"}, "smtp", "legacy-provider"),
    ("canonical-input-wins", {"GORGE_MAILER_TYPE": "smtp", "GORGE_MAILER_BACKEND_KEY": "canonical-provider",
                              "MAILER_TYPE": "test", "MAILER_KEY": "legacy-provider"}, "smtp", "canonical-provider"),
    ("json-backends-win", {"GORGE_MAILER_CONFIG": '[{"key":"json-provider","type":"test"}]',
                           "GORGE_MAILER_TYPE": "smtp", "GORGE_MAILER_BACKEND_KEY": "unused-provider"}, "test", "json-provider"),
    ("missing-backend-is-unready", {}, "", ""),
]
fixtures = []
for name, inputs, kind, key in cases:
    env = {"PATH": os.environ["PATH"], "GORGE_MAILER_KEY": "phorge-adapter",
           "SMTP_HOST": "smtp.fixture.invalid", "SMTP_PORT": "587", **inputs}
    command = ["docker", "compose", "--env-file", "/dev/null", "-f", "docker-compose.yml",
               "--profile", "mailer", "config", "--format", "json"]
    result = subprocess.run(command, cwd=root, env=env, capture_output=True, text=True)
    if result.returncode:
        raise RuntimeError("Compose failed for mailer fixture " + name)
    services = json.loads(result.stdout)["services"]
    environment = services["gorge-mailer"]["environment"]
    if services["phorge-mailer-config"]["environment"]["GORGE_MAILER_KEY"] != "phorge-adapter":
        raise RuntimeError("Provider key overwrote the Phorge adapter key")
    fixtures.append({"name": name, "environment": environment, "type": kind, "key": key})

source = r'''package mailer
import (
    "encoding/json"
    "os"
    "testing"
)
func TestPhorgeComposeMailerEnvironment(t *testing.T) {
    var cases []struct {
        Name string `json:"name"`
        Environment map[string]string `json:"environment"`
        Type string `json:"type"`
        Key string `json:"key"`
    }
    raw, err := os.ReadFile(os.Getenv("GORGE_COMPOSE_FIXTURE"))
    if err != nil { t.Fatal(err) }
    if err := json.Unmarshal(raw, &cases); err != nil { t.Fatal(err) }
    if len(cases) != 4 { t.Fatalf("missing fixture cases: %d", len(cases)) }
    for _, fixture := range cases {
        t.Run(fixture.Name, func(t *testing.T) {
            clearMailerEnv(t)
            for key, value := range fixture.Environment { t.Setenv(key, value) }
            cfg := LoadFromEnv()
            if fixture.Type == "" {
                if len(cfg.Mailers) != 0 { t.Fatalf("unexpected backend: %+v", cfg.Mailers) }
                dispatcher, err := NewDispatcher(cfg.Mailers, cfg.RetryPolicy())
                if err != nil { t.Fatal(err) }
                if dispatcher.Ready() == nil { t.Fatal("missing provider is ready") }
                return
            }
            if len(cfg.Mailers) != 1 { t.Fatalf("want one backend, got %+v", cfg.Mailers) }
            backend := cfg.Mailers[0]
            if backend.Type != fixture.Type || backend.Key != fixture.Key {
                t.Fatalf("unexpected backend identity: %+v", backend)
            }
            if backend.Key == "phorge-adapter" { t.Fatal("adapter and provider identities conflated") }
            if backend.Type == "smtp" && backend.Options["host"] != "smtp.fixture.invalid" {
                t.Fatal("provider options did not reach loader")
            }
        })
    }
}
'''
with tempfile.TemporaryDirectory(prefix="phorge-mailer-compose-") as temporary:
    temporary = Path(temporary)
    test = temporary / "compose_environment_test.go"
    test.write_text(source)
    fixture = temporary / "environment.json"
    fixture.write_text(json.dumps(fixtures))
    overlay = temporary / "overlay.json"
    overlay.write_text(json.dumps({"Replace": {str(package / "phorge_compose_environment_test.go"): str(test)}}))
    env = dict(os.environ, GORGE_COMPOSE_FIXTURE=str(fixture))
    result = subprocess.run(["go", "test", "-count=1", "-v", "-overlay", str(overlay),
                             "./internal/mailer", "-run", "^TestPhorgeComposeMailerEnvironment$"],
                            cwd=gorge / "go", env=env, capture_output=True, text=True)
    print(result.stdout, end="")
    if result.returncode:
        print(result.stderr, end="")
        raise RuntimeError("Paired Go loader rejected Compose mailer environments")
print("Compose to paired Go loader contracts passed: aliases, priority and separate identities.")
