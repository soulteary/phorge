package main

import (
	"bytes"
	"encoding/json"
	"errors"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"syscall"
)

type target struct {
	Binary string   `json:"binary"`
	Args   []string `json:"args"`
}
type request struct {
	Action    string   `json:"action"`
	Directory string   `json:"directory"`
	Owner     string   `json:"owner"`
	Dev       uint64   `json:"dev"`
	Ino       uint64   `json:"ino"`
	Targets   []target `json:"targets"`
}

func exactObject(raw []byte, expected map[string]bool) error {
	d := json.NewDecoder(bytes.NewReader(raw))
	t, e := d.Token()
	if e != nil || t != json.Delim('{') {
		return errors.New("object refused")
	}
	seen := map[string]bool{}
	for d.More() {
		t, e = d.Token()
		k, ok := t.(string)
		if e != nil || !ok || !expected[k] || seen[k] {
			return errors.New("key refused")
		}
		seen[k] = true
		var v json.RawMessage
		if e = d.Decode(&v); e != nil {
			return e
		}
	}
	if _, e = d.Token(); e != nil {
		return e
	}
	if len(seen) != len(expected) {
		return errors.New("missing key")
	}
	var extra any
	if d.Decode(&extra) != io.EOF {
		return errors.New("extra input")
	}
	return nil
}
func decodeRequest(r io.Reader) (request, error) {
	var v request
	b, e := io.ReadAll(io.LimitReader(r, 8193))
	if e != nil || len(b) > 8192 {
		return v, errors.New("budget refused")
	}
	if e = exactObject(b, map[string]bool{"action": true, "directory": true, "owner": true, "dev": true, "ino": true, "targets": true}); e != nil {
		return v, e
	}
	d := json.NewDecoder(bytes.NewReader(b))
	d.DisallowUnknownFields()
	if e = d.Decode(&v); e != nil {
		return v, e
	}
	var raw map[string]json.RawMessage
	if e = json.Unmarshal(b, &raw); e != nil {
		return v, e
	}
	for _, key := range []string{"dev", "ino"} {
		if !regexp.MustCompile(`^(0|[1-9][0-9]{0,19})$`).Match(raw[key]) {
			return v, errors.New("number refused")
		}
	}
	if len(raw["targets"]) == 0 || raw["targets"][0] != '[' {
		return v, errors.New("targets refused")
	}
	var rows []json.RawMessage
	if e = json.Unmarshal(raw["targets"], &rows); e != nil {
		return v, e
	}
	for _, row := range rows {
		if e = exactObject(row, map[string]bool{"binary": true, "args": true}); e != nil {
			return v, e
		}
	}
	return v, nil
}
func validRequest(v request) error {
	if v.Action != "probe" && v.Action != "stop" {
		return errors.New("action refused")
	}
	if filepath.Dir(v.Directory) != filepath.Clean(os.TempDir()) || filepath.Clean(v.Directory) != v.Directory || !regexp.MustCompile(`^phorge-workbench-http-[a-f0-9]{24}$`).MatchString(filepath.Base(v.Directory)) || !regexp.MustCompile(`^[a-f0-9]{64}$`).MatchString(v.Owner) {
		return errors.New("scope refused")
	}
	s, e := os.Lstat(v.Directory)
	if e != nil || !s.IsDir() || s.Mode().Perm() != 0700 {
		return errors.New("directory refused")
	}
	st, ok := s.Sys().(*syscall.Stat_t)
	if !ok || uint64(st.Dev) != v.Dev || uint64(st.Ino) != v.Ino || int(st.Uid) != os.Getuid() {
		return errors.New("inode refused")
	}
	p := filepath.Join(v.Directory, "owner")
	s, e = os.Lstat(p)
	if e != nil || !s.Mode().IsRegular() || s.Mode().Perm() != 0600 {
		return errors.New("marker refused")
	}
	st, ok = s.Sys().(*syscall.Stat_t)
	if !ok || st.Nlink != 1 || int(st.Uid) != os.Getuid() {
		return errors.New("marker refused")
	}
	b, e := os.ReadFile(p)
	if e != nil || !bytes.Equal(b, []byte(v.Owner)) {
		return errors.New("owner refused")
	}
	if v.Action == "probe" {
		if len(v.Targets) != 0 {
			return errors.New("probe target refused")
		}
		return nil
	}
	if len(v.Targets) < 1 || len(v.Targets) > 2 {
		return errors.New("target budget")
	}
	seen := map[string]bool{}
	for _, t := range v.Targets {
		if t.Binary != "/usr/local/bin/php" && t.Binary != "/usr/bin/php" {
			return errors.New("binary refused")
		}
		key := ""
		if len(t.Args) == 3 && t.Args[0] == "-S" && t.Args[1] == "127.0.0.1:18981" && t.Args[2] == "/tmp/governance-src/support/governance/organization/workbench-http-router.php" {
			key = "server"
		}
		if len(t.Args) == 2 && t.Args[0] == "/tmp/governance-src/support/governance/organization/workbench-http-bridge.php" && t.Args[1] == v.Directory {
			key = "bridge"
		}
		if key == "" || seen[key] {
			return errors.New("target refused")
		}
		seen[key] = true
	}
	return nil
}
