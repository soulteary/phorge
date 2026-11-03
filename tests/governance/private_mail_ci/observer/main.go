// Isolated transport observer. Forward mode never invents an authorization result.
package main

import (
	"bytes"
	"compress/gzip"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/http/httputil"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"sync"
	"time"
)

// Classification is telemetry only: raw native bytes are forwarded unchanged.
func sourcePendingResponse(status int, raw []byte) bool {
 if status != http.StatusOK { return false }
 var native struct {
  ErrorCode *string `json:"error_code"`
  ErrorInfo *string `json:"error_info"`
  Result struct { Allowed *bool `json:"allowed"` } `json:"result"`
 }
 return json.Unmarshal(raw, &native) == nil && native.ErrorCode != nil &&
  *native.ErrorCode == "ERR-CONDUIT-CORE" && native.ErrorInfo != nil &&
  *native.ErrorInfo == "Notification source transaction is still pending." &&
  native.Result.Allowed == nil
}

func run() error {
	listen := flag.String("listen", "127.0.0.1:0", "owned loopback listener")
	upstream := flag.String("upstream", "", "actual native callback origin")
	directory := flag.String("directory", "", "owned private fixture directory")
	owner := flag.String("owner", "", "fixture marker")
	flag.Parse()
	host, _, err := net.SplitHostPort(*listen)
	target, parseErr := url.Parse(*upstream)
	if err != nil || host != "127.0.0.1" || parseErr != nil || target.Scheme != "http" || target.Hostname() != "127.0.0.1" || target.Port() == "" || target.User != nil || target.Path != "" || target.RawQuery != "" || target.Fragment != "" || !regexp.MustCompile(`^[a-f0-9]{64}$`).MatchString(*owner) {
		return errors.New("observer arguments refused")
	}
	info, err := os.Lstat(*directory)
	if err != nil || !info.IsDir() || info.Mode()&os.ModeSymlink != 0 || info.Mode().Perm() != 0700 {
		return errors.New("observer directory refused")
	}
	marker, err := os.Lstat(filepath.Join(*directory, "owner"))
	if err != nil || !marker.Mode().IsRegular() {
		return errors.New("observer marker refused")
	}
	content, err := os.ReadFile(filepath.Join(*directory, "owner"))
	if err != nil || !bytes.Equal(content, []byte(*owner)) {
		return errors.New("observer owner refused")
	}
	audit, err := os.OpenFile(filepath.Join(*directory, "callback-observations.jsonl"), os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0600)
	if err != nil {
		return errors.New("observer audit refused")
	}
	defer audit.Close()
	var mutex sync.Mutex
	record := func(value any) error { mutex.Lock(); defer mutex.Unlock(); return json.NewEncoder(audit).Encode(value) }
	proxy := httputil.NewSingleHostReverseProxy(target)
	director := proxy.Director
	proxy.Director = func(request *http.Request) { director(request); request.Host = target.Host }
	proxy.Transport = &http.Transport{Proxy: nil, ResponseHeaderTimeout: 4 * time.Second}
	proxy.ModifyResponse = func(response *http.Response) error {
		body, err := io.ReadAll(io.LimitReader(response.Body, 4097))
		_ = response.Body.Close()
		if err != nil || len(body) > 4096 {
			return errors.New("observer bounded response refused")
		}
		response.Body = io.NopCloser(bytes.NewReader(body))
		decoded := body
		encoding := "identity"
		if value := response.Header.Get("Content-Encoding"); value != "" {
			encoding = "unsupported"
			if strings.EqualFold(value, "gzip") {
				encoding = "gzip"
				reader, err := gzip.NewReader(bytes.NewReader(body))
				if err != nil {
					return record(map[string]any{"mode": "forward", "http_status": response.StatusCode, "content_encoding": encoding, "native_json_valid": false, "native_allowed": nil})
				}
				decoded, err = io.ReadAll(io.LimitReader(reader, 4097))
				closeErr := reader.Close()
				if err != nil || closeErr != nil || len(decoded) > 4096 {
					return record(map[string]any{"mode": "forward", "http_status": response.StatusCode, "content_encoding": encoding, "native_json_valid": false, "native_allowed": nil})
				}
			}
		}
		var native struct {
			Result struct {
				Allowed *bool `json:"allowed"`
			} `json:"result"`
		}
		valid := encoding != "unsupported" && json.Unmarshal(decoded, &native) == nil
		var allowed *bool
		if valid {
			allowed = native.Result.Allowed
		}
		return record(map[string]any{"mode": "forward", "http_status": response.StatusCode, "content_encoding": encoding, "native_json_valid": valid, "native_allowed": allowed, "native_source_pending": valid && sourcePendingResponse(response.StatusCode, decoded)})
	}
	proxy.ErrorHandler = func(writer http.ResponseWriter, request *http.Request, err error) {
		_ = record(map[string]any{"mode": "forward", "transport_error": true})
		http.Error(writer, "unavailable", http.StatusBadGateway)
	}
	handler := http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		if request.URL.Path == "/unexpected-redirect-target" {
			_ = record(map[string]any{"redirect_target_hit": true})
			writer.WriteHeader(200)
			return
		}
		if request.Method != "POST" || request.URL.Path != "/api/mail.delivery" || request.URL.RawQuery != "" {
			http.NotFound(writer, request)
			return
		}
		mode := "forward"
		if raw, err := os.ReadFile(filepath.Join(*directory, "callback-mode")); err == nil {
			pieces := bytes.Split(raw, []byte("\n"))
			if len(pieces) != 2 || !bytes.Equal(pieces[0], []byte(*owner)) {
				http.Error(writer, "unavailable", 503)
				return
			}
			mode = string(pieces[1])
		} else if !os.IsNotExist(err) {
			http.Error(writer, "unavailable", 503)
			return
		}
		switch mode {
		case "forward":
			proxy.ServeHTTP(writer, request)
		case "redirect":
			_ = record(map[string]any{"mode": mode})
			writer.Header().Set("Location", "http://"+request.Host+"/unexpected-redirect-target")
			writer.WriteHeader(http.StatusTemporaryRedirect)
		case "missing-allowed":
			_ = record(map[string]any{"mode": mode})
			writer.Header().Set("Content-Type", "application/json")
			_, _ = io.WriteString(writer, `{"result":{},"error_code":null,"error_info":null}`)
		case "unavailable":
			_ = record(map[string]any{"mode": mode})
			http.Error(writer, "unavailable", http.StatusServiceUnavailable)
		default:
			http.Error(writer, "unavailable", http.StatusServiceUnavailable)
		}
	})
	listener, err := net.Listen("tcp", *listen)
	if err != nil {
		return errors.New("observer listener failed")
	}
	defer listener.Close()
	if err = json.NewEncoder(os.Stdout).Encode(map[string]any{"ready": true, "address": listener.Addr().String(), "native_origin_fixed": true}); err != nil {
		return err
	}
	server := http.Server{Handler: handler, ReadHeaderTimeout: 3 * time.Second, ReadTimeout: 5 * time.Second, WriteTimeout: 8 * time.Second, MaxHeaderBytes: 8192}
	return server.Serve(listener)
}
func main() {
	if err := run(); err != nil {
		fmt.Fprintln(os.Stderr, "owned callback observer failed")
		os.Exit(1)
	}
}
