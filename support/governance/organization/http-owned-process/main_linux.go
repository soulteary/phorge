//go:build linux

// Linux test-only stable-handle cleanup. Never signal a numeric PID.
package main

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"golang.org/x/sys/unix"
	"os"
	"strconv"
	"strings"
	"time"
)

type held struct{ fd int }

var errGone = errors.New("process disappeared")

func alive(fd int) (bool, error) {
	p := []unix.PollFd{{Fd: int32(fd), Events: unix.POLLIN}}
	n, e := unix.Poll(p, 0)
	if e != nil {
		return false, e
	}
	if n == 0 {
		return true, nil
	}
	if p[0].Revents&(unix.POLLERR|unix.POLLNVAL) != 0 {
		return false, errors.New("pidfd poll unavailable")
	}
	return false, nil
}
func procStart(pid int) (string, error) {
	b, e := os.ReadFile(fmt.Sprintf("/proc/%d/stat", pid))
	if e != nil {
		return "", e
	}
	i := bytes.LastIndexByte(b, ')')
	if i < 0 {
		return "", errors.New("stat refused")
	}
	f := strings.Fields(string(b[i+1:]))
	if len(f) <= 19 {
		return "", errors.New("stat refused")
	}
	return f[19], nil
}
func matches(pid int, v request) (bool, error) {
	dir := fmt.Sprintf("/proc/%d/", pid)
	before, e := procStart(pid)
	if e != nil {
		return false, e
	}
	b, e := os.ReadFile(dir + "cmdline")
	if e != nil {
		return false, e
	}
	args := bytes.Split(bytes.TrimSuffix(b, []byte{0}), []byte{0})
	if len(args) == 0 {
		return false, nil
	}
	candidate := false
	for _, t := range v.Targets {
		if string(args[0]) != t.Binary || len(args) != len(t.Args)+1 {
			continue
		}
		same := true
		for i, x := range t.Args {
			if string(args[i+1]) != x {
				same = false
			}
		}
		if same {
			candidate = true
			break
		}
	}
	if !candidate {
		return false, nil
	}
	cwd, e := os.Readlink(dir + "cwd")
	if e != nil {
		return false, e
	}
	if cwd != v.Directory {
		return false, nil
	}
	env, e := os.ReadFile(dir + "environ")
	if e != nil {
		return false, e
	}
	key := []byte("PHORGE_GOVERNANCE_WORKBENCH_HTTP_OWNER=" + v.Owner)
	count := 0
	for _, entry := range bytes.Split(env, []byte{0}) {
		if bytes.Equal(entry, key) {
			count++
		}
	}
	if count != 1 {
		return false, nil
	}
	after, e := procStart(pid)
	if e != nil {
		return false, e
	}
	if before != after {
		return false, errGone
	}
	return true, nil
}
func scan(v request) ([]held, error) {
	entries, e := os.ReadDir("/proc")
	if e != nil {
		return nil, e
	}
	result := []held{}
	fail := func(err error) ([]held, error) {
		for _, h := range result {
			unix.Close(h.fd)
		}
		return nil, err
	}
	for _, entry := range entries {
		pid, e := strconv.Atoi(entry.Name())
		if e != nil || pid < 1 {
			continue
		}
		// The stable handle exists before any mutable /proc identity is inspected.
		fd, e := unix.PidfdOpen(pid, 0)
		if errors.Is(e, unix.ESRCH) {
			continue
		}
		if e != nil {
			return fail(e)
		}
		okay, e := matches(pid, v)
		live, pollErr := alive(fd)
		if pollErr != nil {
			unix.Close(fd)
			return fail(pollErr)
		}
		if !live {
			unix.Close(fd)
			continue
		}
		if e != nil {
			unix.Close(fd)
			return fail(errors.New("live identity unverified"))
		}
		if !okay {
			unix.Close(fd)
			continue
		}
		result = append(result, held{fd})
	}
	return result, nil
}
func wait(handles []held, timeout time.Duration) (int, error) {
	deadline := time.Now().Add(timeout)
	for {
		left := 0
		for _, h := range handles {
			live, e := alive(h.fd)
			if e != nil {
				return 0, e
			}
			if live {
				left++
			}
		}
		if left == 0 || time.Now().After(deadline) {
			return left, nil
		}
		time.Sleep(25 * time.Millisecond)
	}
}
func run() error {
	v, e := decodeRequest(os.Stdin)
	if e != nil {
		return e
	}
	if e := validRequest(v); e != nil {
		return e
	}
	if v.Action == "probe" {
		fd, e := unix.PidfdOpen(os.Getpid(), 0)
		if e != nil {
			return e
		}
		defer unix.Close(fd)
		if e = unix.PidfdSendSignal(fd, 0, nil, 0); e != nil {
			return e
		}
		return json.NewEncoder(os.Stdout).Encode(map[string]any{"passed": true, "pidfd_supported": true})
	}
	handles, e := scan(v)
	if e != nil {
		return e
	}
	defer func() {
		for _, h := range handles {
			unix.Close(h.fd)
		}
	}()
	term, killed := 0, 0
	for _, h := range handles {
		if e := unix.PidfdSendSignal(h.fd, unix.SIGTERM, nil, 0); e != nil && !errors.Is(e, unix.ESRCH) {
			return e
		}
		term++
	}
	left, e := wait(handles, 12*time.Second)
	if e != nil {
		return e
	}
	if left > 0 {
		for _, h := range handles {
			live, e := alive(h.fd)
			if e != nil {
				return e
			}
			if live {
				if e = unix.PidfdSendSignal(h.fd, unix.SIGKILL, nil, 0); e != nil && !errors.Is(e, unix.ESRCH) {
					return e
				}
				killed++
			}
		}
		left, e = wait(handles, 3*time.Second)
		if e != nil {
			return e
		}
	}
	if left != 0 {
		return errors.New("owned handles still live")
	}
	remaining, e := scan(v)
	if e != nil {
		return e
	}
	for _, h := range remaining {
		unix.Close(h.fd)
	}
	if len(remaining) != 0 {
		return errors.New("new owned processes remain")
	}
	return json.NewEncoder(os.Stdout).Encode(map[string]any{"passed": true, "matched_handles": len(handles), "signaled_term": term, "signaled_kill": killed, "remaining_owned": 0})
}
func main() {
	if e := run(); e != nil {
		fmt.Fprintln(os.Stderr, "owned pidfd cleanup unavailable")
		os.Exit(1)
	}
}
