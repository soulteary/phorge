package main

import (
	"bytes"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"syscall"
	"testing"
)

func owned(t *testing.T) request {
	t.Helper()
	b := make([]byte, 12)
	if _, e := rand.Read(b); e != nil {
		t.Fatal(e)
	}
	dir := filepath.Join(os.TempDir(), "phorge-workbench-http-"+hex.EncodeToString(b))
	if e := os.Mkdir(dir, 0700); e != nil {
		t.Fatal(e)
	}
	t.Cleanup(func() { os.RemoveAll(dir) })
	owner := strings.Repeat("a", 64)
	if e := os.WriteFile(filepath.Join(dir, "owner"), []byte(owner), 0600); e != nil {
		t.Fatal(e)
	}
	s, _ := os.Lstat(dir)
	st := s.Sys().(*syscall.Stat_t)
	return request{Action: "probe", Directory: dir, Owner: owner, Dev: uint64(st.Dev), Ino: uint64(st.Ino), Targets: []target{}}
}
func encoded(t *testing.T, v request) []byte {
	b, e := json.Marshal(v)
	if e != nil {
		t.Fatal(e)
	}
	return b
}
func TestValidProbeAndFixedTarget(t *testing.T) {
	v := owned(t)
	if e := validRequest(v); e != nil {
		t.Fatal(e)
	}
	v.Action = "stop"
	v.Targets = []target{{Binary: "/usr/local/bin/php", Args: []string{"/tmp/governance-src/support/governance/organization/workbench-http-bridge.php", v.Directory}}}
	if e := validRequest(v); e != nil {
		t.Fatal(e)
	}
	if _, e := decodeRequest(bytes.NewReader(encoded(t, v))); e != nil {
		t.Fatal(e)
	}
}
func TestControlUnknownAliasDuplicateAndNull(t *testing.T) {
	v := owned(t)
	b := string(encoded(t, v))
	for _, bad := range []string{strings.Replace(b, `"action"`, `"Action"`, 1), strings.Replace(b, `"action":"probe"`, `"action":"probe","action":"probe"`, 1), strings.Replace(b, `"targets":[]`, `"targets":null`, 1), strings.Replace(b, `"dev":`+jsonNumber(v.Dev), `"dev":null`, 1), `{}`, b + `{}`} {
		if _, e := decodeRequest(strings.NewReader(bad)); e == nil {
			t.Fatal("malformed control accepted")
		}
	}
}
func jsonNumber(v uint64) string { b, _ := json.Marshal(v); return string(b) }
func TestInodeOwnerAndModes(t *testing.T) {
	v := owned(t)
	bad := v
	bad.Ino++
	if validRequest(bad) == nil {
		t.Fatal("inode")
	}
	bad = v
	bad.Owner = strings.Repeat("b", 64)
	if validRequest(bad) == nil {
		t.Fatal("owner")
	}
	os.Chmod(v.Directory, 0755)
	if validRequest(v) == nil {
		t.Fatal("mode")
	}
}
func TestForeignTargetsAndArgumentBudget(t *testing.T) {
	v := owned(t)
	v.Action = "stop"
	for _, targets := range [][]target{nil, {{Binary: "/bin/sh", Args: []string{"-c", "true"}}}, {{Binary: "/usr/bin/php", Args: []string{"/tmp/governance-src/support/governance/organization/workbench-http-bridge.php", v.Directory, "extra"}}}, {{Binary: "/usr/bin/php", Args: []string{"other.php", v.Directory}}}} {
		v.Targets = targets
		if validRequest(v) == nil {
			t.Fatal("foreign target")
		}
	}
}
func TestSymlinkMarkerAndExtraNestedKey(t *testing.T) {
	v := owned(t)
	marker := filepath.Join(v.Directory, "owner")
	os.Rename(marker, marker+".saved")
	os.Symlink(marker+".saved", marker)
	if validRequest(v) == nil {
		t.Fatal("symlink")
	}
	v.Action = "stop"
	v.Targets = []target{{Binary: "/usr/bin/php", Args: []string{"a", "b"}}}
	b := strings.Replace(string(encoded(t, v)), `"binary":`, `"extra":1,"binary":`, 1)
	if _, e := decodeRequest(strings.NewReader(b)); e == nil {
		t.Fatal("extra nested key")
	}
}

func TestFixedServerAndBridgePair(t *testing.T) {
	v := owned(t)
	v.Action = "stop"
	server := target{Binary: "/usr/local/bin/php", Args: []string{"-S", "127.0.0.1:18981", "/tmp/governance-src/support/governance/organization/workbench-http-router.php"}}
	bridge := target{Binary: "/usr/local/bin/php", Args: []string{"/tmp/governance-src/support/governance/organization/workbench-http-bridge.php", v.Directory}}
	v.Targets = []target{server, bridge}
	if e := validRequest(v); e != nil {
		t.Fatal(e)
	}
	for _, bad := range [][]target{{server, server}, {{Binary: server.Binary, Args: []string{"-S", "0.0.0.0:18981", server.Args[2]}}}, {{Binary: server.Binary, Args: []string{"-S", "127.0.0.1:18982", server.Args[2]}}}, {{Binary: bridge.Binary, Args: []string{bridge.Args[0], v.Directory + "x"}}}} {
		v.Targets = bad
		if validRequest(v) == nil {
			t.Fatal("nonfixed HTTP target accepted")
		}
	}
}
