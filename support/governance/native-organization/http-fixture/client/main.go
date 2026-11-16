// Strict TLS wire proof for disposable synthetic media fixtures; not browser proof.
package main

import (
	"bytes"
	"context"
	"crypto/rand"
	"crypto/sha256"
	"crypto/tls"
	"crypto/x509"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	"errors"
	"fmt"
	"html"
	"io"
	"net"
	"net/http"
	"net/http/cookiejar"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"syscall"
	"time"
)

type fixture struct {
	Scope       string `json:"scope"`
	Owner       string `json:"owner"`
	App         string `json:"app_origin"`
	Files       string `json:"files_origin"`
	Login       string `json:"login_uri"`
	Username    string `json:"login_username"`
	Password    string `json:"login_password"`
	Authorize   string `json:"manual_authorize_uri"`
	Image       string `json:"native_image_page_uri"`
	Private     string `json:"private_file_uri"`
	Second      string `json:"private_second_uri"`
	HTML        string `json:"active_html_uri"`
	SVG         string `json:"active_svg_uri"`
	ContentHash string `json:"expected_content_sha256"`
	Marker      string `json:"private_content_marker"`
	Preseeded   bool   `json:"browser_session_preseeded"`
	OTP         bool   `json:"otp_tested"`
}
type proxyReady struct {
	Port        int    `json:"tls_port"`
	BrowserPort int    `json:"browser_port"`
	Pin         string `json:"certificate_sha256"`
	Public      bool   `json:"public_bind"`
}
type receipt struct {
	LastCSP    []map[string][]string `json:"last_csp_directive_sets,omitempty"`
	LastXFrame []string              `json:"last_xframe_options,omitempty"`
	BridgeCSP  []map[string][]string `json:"bridge_csp_directive_sets,omitempty"`

	ErrorClass            string `json:"error_class,omitempty"`
	ErrorReason           string `json:"error_reason,omitempty"`
	LastHTTPStatus        int    `json:"last_http_status"`
	LastBodySHA256        string `json:"last_body_sha256,omitempty"`
	LastBodyBytes         int    `json:"last_body_bytes"`
	LastResponseHTML      bool   `json:"last_response_html"`
	LastResponseJSON      bool   `json:"last_response_json"`
	NativeLoginFormParsed bool   `json:"native_login_form_parsed"`
	NativeSessionRotated  bool   `json:"native_session_rotated"`
	NativeValidateVisited bool   `json:"native_validate_visited"`
	NativeFinishVisited   bool   `json:"native_finish_visited"`

	Passed          bool     `json:"passed"`
	Stage           string   `json:"stage"`
	Checks          []string `json:"checks"`
	Count           int      `json:"check_count"`
	Pin             string   `json:"public_leaf_sha256,omitempty"`
	Browser         bool     `json:"browser_proof"`
	BrowserRequired bool     `json:"trusted_domain_browser_acceptance_still_required"`
	Preseeded       bool     `json:"session_preseeded"`
	OTP             bool     `json:"otp_tested"`
	Deploy          bool     `json:"production_deployment"`
}

var hex64 = regexp.MustCompile(`\A[a-f0-9]{64}\z`)

func privateFile(dir, name string, max int64) ([]byte, error) {
	p := filepath.Join(dir, name)
	s, e := os.Lstat(p)
	if e != nil || !s.Mode().IsRegular() || s.Mode().Perm() != 0600 || s.Size() < 1 || s.Size() > max {
		return nil, errors.New("private_file_refused")
	}
	owner, ok := s.Sys().(*syscall.Stat_t)
	if !ok || int(owner.Uid) != os.Geteuid() {
		return nil, errors.New("private_file_owner_refused")
	}
	return os.ReadFile(p)
}
func decode(raw []byte, value any) error {
	d := json.NewDecoder(bytes.NewReader(raw))
	d.DisallowUnknownFields()
	if e := d.Decode(value); e != nil {
		return errors.New("json_refused")
	}
	var extra any
	if d.Decode(&extra) != io.EOF {
		return errors.New("json_extra_refused")
	}
	return nil
}
func exactKeys(raw []byte, expected []string) bool {
	var value map[string]json.RawMessage
	if json.Unmarshal(raw, &value) != nil {
		return false
	}
	keys := make([]string, 0, len(value))
	for k := range value {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	sort.Strings(expected)
	return strings.Join(keys, "\x00") == strings.Join(expected, "\x00")
}

var exactPinError = errors.New("owned_tls_exact_pin_mismatch")

func ownedLeafVerifier(expectedPin, appHost, fileHost string) func(tls.ConnectionState) error {
	return func(cs tls.ConnectionState) error {
		if len(cs.VerifiedChains) == 0 || len(cs.PeerCertificates) != 1 {
			return errors.New("chain_refused")
		}
		if cs.ServerName != appHost && cs.ServerName != fileHost {
			return errors.New("server_name_refused")
		}
		digest := sha256.Sum256(cs.PeerCertificates[0].Raw)
		if hex.EncodeToString(digest[:]) != expectedPin {
			return exactPinError
		}
		return nil
	}
}
func cookieValue(jar http.CookieJar, u *url.URL, name string) string {
	for _, c := range jar.Cookies(u) {
		if c.Name == name {
			return c.Value
		}
	}
	return ""
}
func safeHeaders(h http.Header) bool {
	return strings.Contains(strings.Join(h.Values("Cache-Control"), ","), "no-store") &&
		strings.EqualFold(h.Get("X-Content-Type-Options"), "nosniff")
}

// Exact response verification only; these unit checks are not a wire receipt.
func privateReadMatches(resp *http.Response, body, expected []byte, status int, contentRange string) bool {
	return resp != nil && resp.StatusCode == status && string(body) == string(expected) &&
		resp.Header.Get("Content-Range") == contentRange && safeHeaders(resp.Header)
}

// Bounded fixture-canary non-disclosure, not proof that every private byte is absent.
func deniedPrivateReadMatches(resp *http.Response, body []byte, method, marker string, full, selected []byte) bool {
	if resp == nil || resp.StatusCode != 403 || resp.Header.Get("Content-Range") != "" || !safeHeaders(resp.Header) ||
		marker == "" || len(full) < 16 || len(selected) != 16 || (method == "HEAD" && len(body) != 0) {
		return false
	}
	text := string(body)
	for _, canary := range []string{marker, string(full), string(selected), string(selected[:8]), string(selected[8:])} {
		if strings.Contains(text, canary) {
			return false
		}
	}
	return true
}
func cspDiagnostic(h http.Header, app, files string) []map[string][]string {
	result := []map[string][]string{}
	for _, header := range h.Values("Content-Security-Policy") {
		for _, policy := range strings.Split(header, ",") {
			if len(result) >= 8 {
				return append(result, map[string][]string{"budget": {"EXCEEDED"}})
			}
			directives := map[string][]string{}
			for _, raw := range strings.Split(policy, ";") {
				parts := strings.Fields(raw)
				if len(parts) == 0 {
					continue
				}
				name := strings.ToLower(parts[0])
				if !regexp.MustCompile(`^[a-z-]{1,64}$`).MatchString(name) {
					name = "redacted-directive"
				}
				if _, duplicate := directives[name]; duplicate {
					directives[name] = append(directives[name], "DUPLICATE")
				}
				if len(parts) > 33 {
					directives[name] = append(directives[name], "BUDGET")
					continue
				}
				for _, value := range parts[1:] {
					kind := "URI_OR_TOKEN_REDACTED"
					switch value {
					case "'none'":
						kind = "NONE"
					case "'self'":
						kind = "SELF"
					case app:
						kind = "APP"
					case files:
						kind = "FILES"
					default:
						if strings.HasPrefix(value, "'nonce-") {
							kind = "NONCE_REDACTED"
						} else if strings.HasPrefix(value, "'sha") {
							kind = "HASH_REDACTED"
						}
					}
					directives[name] = append(directives[name], kind)
				}
			}
			result = append(result, directives)
		}
	}
	return result
}
func xframeDiagnostic(h http.Header) []string {
	out := []string{}
	for _, value := range h.Values("X-Frame-Options") {
		switch strings.ToUpper(strings.TrimSpace(value)) {
		case "DENY":
			out = append(out, "NONE")
		case "SAMEORIGIN":
			out = append(out, "SELF")
		default:
			out = append(out, "REDACTED")
		}
	}
	return out
}
func bridgeAllowsApp(h http.Header, app string) bool {
	if len(h.Values("X-Frame-Options")) != 0 {
		return false
	}
	seen := false
	count := 0
	for _, header := range h.Values("Content-Security-Policy") {
		for _, policy := range strings.Split(header, ",") {
			count++
			if count > 8 {
				return false
			}
			frameSeen := false
			for _, raw := range strings.Split(policy, ";") {
				parts := strings.Fields(raw)
				if len(parts) == 0 || !strings.EqualFold(parts[0], "frame-ancestors") {
					continue
				}
				if frameSeen || len(parts) != 2 || parts[1] != app {
					return false
				}
				frameSeen = true
				seen = true
			}
		}
	}
	return seen
}
func hostCookie(h http.Header, name string) bool {
	seen := 0
	for _, c := range (&http.Response{Header: h}).Cookies() {
		if c.Name == name {
			seen++
			if c.Domain != "" || c.Path != "/" || !c.Secure || !c.HttpOnly || c.SameSite != http.SameSiteStrictMode || !hex64.MatchString(c.Value) {
				return false
			}
		}
	}
	return seen == 1
}

var inputTags = regexp.MustCompile(`(?is)<input\b[^>]*>`)
var formTags = regexp.MustCompile(`(?is)<form\b([^>]*)>(.*?)</form\s*>`)
var attributes = regexp.MustCompile(`(?is)([a-zA-Z_:][a-zA-Z0-9_:.-]*)\s*=\s*(?:"([^"]*)"|'([^']*)')`)

func attrs(tag string) map[string]string {
	m := map[string]string{}
	for _, a := range attributes.FindAllStringSubmatch(tag, -1) {
		v := a[2]
		if v == "" {
			v = a[3]
		}
		m[strings.ToLower(a[1])] = html.UnescapeString(v)
	}
	return m
}
func form(body []byte, base *url.URL, username bool) (*url.URL, url.Values, error) {
	for _, f := range formTags.FindAllStringSubmatch(string(body), 32) {
		if len(f[1]) > 8192 {
			return nil, nil, errors.New("form_attribute_budget_refused")
		}
		a := attrs(f[1])
		if strings.ToLower(a["method"]) != "post" {
			continue
		}
		values := url.Values{}
		hasUser := false
		for _, tag := range inputTags.FindAllString(f[2], 128) {
			if len(tag) > 8192 {
				return nil, nil, errors.New("input_attribute_budget_refused")
			}
			v := attrs(tag)
			name := v["name"]
			if name == "username" {
				hasUser = true
			}
			if name != "" && strings.ToLower(v["type"]) == "hidden" {
				if _, ok := values[name]; ok {
					return nil, nil, errors.New("duplicate_hidden_field")
				}
				values.Set(name, v["value"])
			}
		}
		if username && !hasUser {
			continue
		}
		if values.Get("__csrf__") == "" || values.Get("__form__") == "" {
			continue
		}
		target, e := base.Parse(a["action"])
		if e != nil || target.Scheme != base.Scheme || target.Host != base.Host || target.User != nil || target.Fragment != "" {
			return nil, nil, errors.New("form_origin_refused")
		}
		// Standard native pages may contain a POST search form before the
		// actual dialog. Only the requested native login/logout action is a
		// candidate; never expand the owned router to make a wrong form work.
		if target.Path != base.Path || target.RawQuery != base.RawQuery {
			continue
		}
		return target, values, nil
	}
	return nil, nil, errors.New("native_form_missing")
}
func main() {
	r := receipt{Stage: "preflight", BrowserRequired: true}
	e := run(&r)
	r.Passed = e == nil
	if e != nil {
		r.ErrorClass = fmt.Sprintf("%T", e)
		r.ErrorReason = "bounded_client_refusal"
		switch e.Error() {
		case "native_login_page_unavailable", "native_form_missing", "native_login_redirect_refused", "native_login_redirect_path_refused", "native_login_finish_not_completed", "strict_tls_request_failed", "response_budget_refused", "native_authorize_page_refused", "native_target_binding_refused", "protocol_json_refused", "form_origin_refused", "duplicate_hidden_field", "fixture_shape_refused", "fixture_lineage_refused", "leaf_binding_refused":
			r.ErrorReason = e.Error()
		}
	}
	r.Count = len(r.Checks)
	json.NewEncoder(os.Stdout).Encode(r)
	if e != nil {
		os.Exit(1)
	}
}
func run(r *receipt) error {
	check := func(ok bool, name string) error {
		r.Stage = name
		if !ok {
			return errors.New(name)
		}
		r.Checks = append(r.Checks, name)
		return nil
	}
	if len(os.Args) != 2 || os.Getenv("PHORGE_GOVERNANCE_ISOLATED_TEST") != "1" {
		return errors.New("scope_refused")
	}
	dir := os.Args[1]
	real, e := filepath.EvalSymlinks(dir)
	s, se := os.Lstat(dir)
	if e != nil || se != nil || real != dir || filepath.Dir(dir) != os.TempDir() || !regexp.MustCompile(`\Aphorge-media-wire-[a-f0-9]{24}\z`).MatchString(filepath.Base(dir)) || !s.IsDir() || s.Mode().Perm() != 0700 {
		return errors.New("private_directory_refused")
	}
	ownerStat, ok := s.Sys().(*syscall.Stat_t)
	if !ok || int(ownerStat.Uid) != os.Geteuid() {
		return errors.New("private_owner_refused")
	}
	var f fixture
	var ready proxyReady
	b, e := privateFile(dir, "fixture-ready.json", 65536)
	if e != nil {
		return e
	}
	if !exactKeys(b, []string{"scope", "owner", "app_origin", "files_origin", "login_uri", "login_username", "login_password", "manual_authorize_uri", "native_image_page_uri", "private_file_uri", "private_second_uri", "active_html_uri", "active_svg_uri", "expected_content_sha256", "private_content_marker", "browser_session_preseeded", "otp_tested"}) {
		return errors.New("fixture_shape_refused")
	}
	if e = decode(b, &f); e != nil {
		return e
	}
	owner, e := privateFile(dir, "owner", 64)
	if e != nil || string(owner) != f.Owner || !hex64.MatchString(f.Owner) || f.Preseeded || f.OTP {
		return errors.New("fixture_lineage_refused")
	}
	b, e = privateFile(dir, "proxy-ready.json", 16384)
	if e != nil {
		return e
	}
	if !exactKeys(b, []string{"tls_port", "browser_port", "certificate_sha256", "public_bind"}) {
		return errors.New("ready_shape_refused")
	}
	if e = decode(b, &ready); e != nil {
		return e
	}
	if ready.Public || ready.Port < 1 || ready.Port > 65535 || ready.Port != ready.BrowserPort || !hex64.MatchString(ready.Pin) {
		return errors.New("tls_ready_refused")
	}
	b, e = privateFile(dir, "tls-leaf.pem", 16384)
	if e != nil {
		return e
	}
	block, rest := pem.Decode(b)
	if block == nil || block.Type != "CERTIFICATE" || len(bytes.TrimSpace(rest)) != 0 {
		return errors.New("public_leaf_refused")
	}
	leaf, e := x509.ParseCertificate(block.Bytes)
	if e != nil {
		return e
	}
	sum := sha256.Sum256(leaf.Raw)
	pin := hex.EncodeToString(sum[:])
	r.Pin = pin
	names := append([]string(nil), leaf.DNSNames...)
	sort.Strings(names)
	if pin != ready.Pin || strings.Join(names, ",") != "app.media.localhost,files.media.localhost" || time.Now().Before(leaf.NotBefore) || time.Now().After(leaf.NotAfter) {
		return errors.New("leaf_binding_refused")
	}
	roots, e := x509.SystemCertPool()
	if e != nil || roots == nil {
		return errors.New("system_roots_unavailable")
	}
	systemRoots := roots.Clone()
	roots.AddCert(leaf)
	app, e := url.Parse(f.App)
	if e != nil {
		return e
	}
	files, e := url.Parse(f.Files)
	if e != nil {
		return e
	}
	if app.Scheme != "https" || files.Scheme != "https" || app.Hostname() != "app.media.localhost" || files.Hostname() != "files.media.localhost" ||
		app.Port() != fmt.Sprint(ready.Port) || files.Port() != fmt.Sprint(ready.Port) || app.User != nil || files.User != nil || app.RawQuery != "" || files.RawQuery != "" || app.Fragment != "" || files.Fragment != "" || (app.Path != "" && app.Path != "/") || (files.Path != "" && files.Path != "/") {
		return errors.New("origin_refused")
	}
	if f.Scope != "private-synthetic-native-password-browser-fixture" || f.Username == "" || len(f.Username) > 128 || f.Password == "" || len(f.Password) > 1024 || !hex64.MatchString(f.ContentHash) {
		return errors.New("fixture_identity_refused")
	}
	for _, entry := range []struct {
		raw    string
		host   string
		prefix string
	}{{f.Login, app.Host, "/auth/login/"}, {f.Authorize, app.Host, "/auth/media/authorize/"}, {f.Private, files.Host, "/file/"}, {f.Second, files.Host, "/file/"}, {f.HTML, files.Host, "/file/"}, {f.SVG, files.Host, "/file/"}} {
		u, err := url.Parse(entry.raw)
		if err != nil || u.Scheme != "https" || u.Host != entry.host || u.User != nil || u.Fragment != "" || !strings.HasPrefix(u.Path, entry.prefix) {
			return errors.New("fixture_endpoint_refused")
		}
	}
	config := &tls.Config{RootCAs: roots, MinVersion: tls.VersionTLS12,
		VerifyConnection: ownedLeafVerifier(pin, app.Hostname(), files.Hostname())}

	dial := func(ctx context.Context, network, address string) (net.Conn, error) {
		h, p, e := net.SplitHostPort(address)
		if e != nil || (h != app.Hostname() && h != files.Hostname()) || p != fmt.Sprint(ready.Port) {
			return nil, errors.New("dial_origin_refused")
		}
		return (&net.Dialer{Timeout: 5 * time.Second}).DialContext(ctx, "tcp4", net.JoinHostPort("127.0.0.1", p))
	}
	transport := &http.Transport{Proxy: nil, TLSClientConfig: config, DialContext: dial, ResponseHeaderTimeout: 20 * time.Second, MaxResponseHeaderBytes: 65536, DisableKeepAlives: true}
	defer transport.CloseIdleConnections()
	jar, _ := cookiejar.New(nil)
	client := &http.Client{Transport: transport, Jar: jar, Timeout: 30 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	requestCount := 0
	request := func(method, raw string, values url.Values, extra http.Header) (*http.Response, []byte, error) {
		requestCount++
		r.LastCSP = nil
		r.LastXFrame = nil
		r.LastHTTPStatus = 0
		r.LastBodySHA256 = ""
		r.LastBodyBytes = 0
		r.LastResponseHTML = false
		r.LastResponseJSON = false
		r.Stage = fmt.Sprintf("wire-request-%02d", requestCount)
		u, e := url.Parse(raw)
		if e != nil || u.Scheme != "https" || (u.Host != app.Host && u.Host != files.Host) || u.User != nil || u.Fragment != "" {
			return nil, nil, errors.New("request_origin_refused")
		}
		if u.Host == files.Host {
			appSession := cookieValue(jar, app, "phsid")
			if appSession != "" {
				for _, cookie := range jar.Cookies(files) {
					if cookie.Name == "phsid" && cookie.Value == appSession {
						return nil, nil, errors.New("application_cookie_leaked_to_file_origin")
					}
				}
			}
		}
		var body io.Reader
		if values != nil {
			body = strings.NewReader(values.Encode())
		}
		q, e := http.NewRequest(method, u.String(), body)
		if e != nil {
			return nil, nil, e
		}
		if values != nil {
			q.Header.Set("Content-Type", "application/x-www-form-urlencoded")
			q.Header.Set("Origin", u.Scheme+"://"+u.Host)
			q.Header.Set("Sec-Fetch-Site", "same-origin")
		}
		for k, v := range extra {
			q.Header[k] = v
		}
		resp, e := client.Do(q)
		if e != nil {
			return nil, nil, errors.New("strict_tls_request_failed")
		}
		r.LastCSP = cspDiagnostic(resp.Header, f.App, f.Files)
		r.LastXFrame = xframeDiagnostic(resp.Header)
		r.LastHTTPStatus = resp.StatusCode
		r.LastResponseHTML = strings.Contains(resp.Header.Get("Content-Type"), "text/html")
		r.LastResponseJSON = strings.Contains(resp.Header.Get("Content-Type"), "application/json")
		defer resp.Body.Close()
		data, e := io.ReadAll(io.LimitReader(resp.Body, 2*1024*1024+1))
		if e != nil || len(data) > 2*1024*1024 {
			return nil, nil, errors.New("response_budget_refused")
		}
		hash := sha256.Sum256(data)
		r.LastBodySHA256 = hex.EncodeToString(hash[:])
		r.LastBodyBytes = len(data)
		return resp, data, nil
	}
	// Actual TLS handshakes, before any password or session is transmitted.
	testTLS := func(settings *tls.Config) error {
		tr := &http.Transport{Proxy: nil, TLSClientConfig: settings, DialContext: dial, DisableKeepAlives: true, ResponseHeaderTimeout: 5 * time.Second}
		defer tr.CloseIdleConnections()
		c := &http.Client{Transport: tr, Timeout: 10 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
		response, err := c.Get(f.Files + "/media/session/bridge/")
		if response != nil {
			response.Body.Close()
		}
		return err
	}
	untrusted := config.Clone()
	untrusted.RootCAs = systemRoots
	var unknown x509.UnknownAuthorityError
	e = testTLS(untrusted)
	if err := check(e != nil && errors.As(e, &unknown), "tls-unknown-leaf-without-private-trust-anchor-rejected"); err != nil {
		return err
	}
	wrongName := config.Clone()
	wrongName.ServerName = "invalid.media.localhost"
	var nameError x509.HostnameError
	e = testTLS(wrongName)
	if err := check(e != nil && errors.As(e, &nameError), "tls-wrong-san-rejected-by-standard-verifier"); err != nil {
		return err
	}
	wrongPin := config.Clone()
	expectedWrongPin := strings.Repeat("0", 64)
	if expectedWrongPin == pin {
		expectedWrongPin = strings.Repeat("1", 64)
	}
	wrongPin.VerifyConnection = ownedLeafVerifier(expectedWrongPin, app.Hostname(), files.Hostname())
	e = testTLS(wrongPin)
	if err := check(e != nil && errors.Is(e, exactPinError), "tls-standard-chain-success-with-wrong-exact-pin-still-rejected"); err != nil {
		return err
	}

	resp, body, e := request("GET", f.Private, nil, nil)
	if e != nil {
		return e
	}
	if e = check(resp.StatusCode == 403 && resp.TLS != nil && len(resp.TLS.VerifiedChains) > 0, "tls-anonymous-private-file-denied-over-verified-chain-san-time-and-pin"); e != nil {
		return e
	}
	resp, body, e = request("GET", f.Login, nil, nil)
	if e != nil {
		return e
	}
	if resp.StatusCode != 200 {
		return errors.New("native_login_page_unavailable")
	}
	loginURL, e := url.Parse(f.Login)
	if e != nil {
		return e
	}
	action, values, e := form(body, loginURL, true)
	if e != nil {
		return e
	}
	r.NativeLoginFormParsed = true
	values.Set("username", f.Username)
	values.Set("password", f.Password)
	beforeSession := cookieValue(jar, app, "phsid")
	resp, _, e = request("POST", action.String(), values, nil)
	if e != nil {
		return e
	}
	if e = check(resp.StatusCode >= 300 && resp.StatusCode < 400 && cookieValue(jar, app, "phsid") != "" && cookieValue(jar, app, "phsid") != beforeSession, "tls-real-native-password-login-rotates-anonymous-session"); e != nil {
		return e
	}
	r.NativeSessionRotated = cookieValue(jar, app, "phsid") != "" && cookieValue(jar, app, "phsid") != beforeSession
	// Follow only actual native login validation/finish redirects. The native
	// password controller first issues a partial session; finish must upgrade it.
	visitedValidate, visitedFinish := false, false
	for count := 0; count < 4; count++ {
		location := resp.Header.Get("Location")
		next, err := app.Parse(location)
		if err != nil || location == "" || next.Scheme != "https" || next.Host != app.Host || next.User != nil || next.Fragment != "" {
			return errors.New("native_login_redirect_refused")
		}
		if next.Path == "/" {
			break
		}
		if next.Path == "/auth/validate/" {
			visitedValidate = true
		} else if next.Path == "/auth/finish/" {
			visitedFinish = true
		} else {
			return errors.New("native_login_redirect_path_refused")
		}
		resp, _, e = request("GET", next.String(), nil, nil)
		if e != nil {
			return e
		}
		if resp.StatusCode < 300 || resp.StatusCode >= 400 {
			return errors.New("native_login_finish_not_completed")
		}
	}
	r.NativeValidateVisited = visitedValidate
	r.NativeFinishVisited = visitedFinish
	if e = check(visitedValidate && visitedFinish, "tls-native-cookie-validation-and-partial-session-finish-completed"); e != nil {
		return e
	}
	resp, body, e = request("GET", f.Authorize, nil, nil)
	if e != nil {
		return e
	}
	var authorized struct {
		Files    string `json:"files"`
		Path     string `json:"path"`
		CSRFName string `json:"csrf_name"`
		CSRF     string `json:"csrf"`
	}
	match := regexp.MustCompile(`const config=(\{[^\n]+?\}),frame=`).FindSubmatch(body)
	if resp.StatusCode != 200 || len(match) != 2 || decode(match[1], &authorized) != nil || authorized.Files != f.Files || authorized.CSRFName != "__csrf__" || authorized.CSRF == "" {
		return errors.New("native_authorize_page_refused")
	}
	target, e := url.Parse(f.Private)
	if e != nil || authorized.Path != target.Path {
		return errors.New("native_target_binding_refused")
	}
	if e = check(safeHeaders(resp.Header), "tls-native-authorize-page-real-csrf-and-no-store-headers"); e != nil {
		return e
	}
	resp, body, e = request("GET", f.Files+"/media/session/bridge/", nil, nil)
	if e != nil {
		return e
	}
	var bridgeConfig struct {
		App   string `json:"app"`
		Files string `json:"files"`
	}
	bridgeMatch := regexp.MustCompile(`const config=(\{[^\n]+?\});\s*let stage=`).FindSubmatch(body)
	r.BridgeCSP = cspDiagnostic(resp.Header, f.App, f.Files)
	if e = check(resp.StatusCode == 200 && safeHeaders(resp.Header) && len(bridgeMatch) == 2 && decode(bridgeMatch[1], &bridgeConfig) == nil && bridgeConfig.App == f.App && bridgeConfig.Files == f.Files && bridgeAllowsApp(resp.Header, f.App), "tls-native-file-bridge-pins-application-origin"); e != nil {
		return e
	}
	random := make([]byte, 32)
	if _, e = rand.Read(random); e != nil {
		return e
	}
	nonce := hex.EncodeToString(random)
	post := func(origin, action string, payload map[string]string, csrf bool) (*http.Response, map[string]json.RawMessage, error) {
		raw, _ := json.Marshal(payload)
		v := url.Values{"payload": {string(raw)}}
		if csrf {
			v.Set(authorized.CSRFName, authorized.CSRF)
		}
		response, data, e := request("POST", origin+action, v, nil)
		if e != nil {
			return nil, nil, e
		}
		out := map[string]json.RawMessage{}
		if e = json.Unmarshal(data, &out); e != nil {
			return nil, nil, errors.New("protocol_json_refused")
		}
		return response, out, nil
	}
	text := func(data map[string]json.RawMessage, key string) string {
		var value string
		json.Unmarshal(data[key], &value)
		return value
	}
	resp, data, e := post(f.Files, "/media/session/prepare/", map[string]string{"path": authorized.Path, "client_nonce": nonce}, false)
	if e != nil {
		return e
	}
	transaction := text(data, "transaction")
	if e = check(resp.StatusCode == 200 && hex64.MatchString(transaction) && hostCookie(resp.Header, "__Host-phorge-media-browser"), "tls-prepare-real-transaction-and-hostonly-secure-browser-cookie"); e != nil {
		return e
	}
	badNonce := strings.Repeat("0", 64)
	if nonce == badNonce {
		badNonce = strings.Repeat("1", 64)
	}
	resp, _, e = post(f.App, "/auth/media/issue/", map[string]string{"transaction": transaction, "client_nonce": badNonce, "path": authorized.Path}, true)
	if e != nil {
		return e
	}
	if e = check(resp.StatusCode == 403, "tls-issue-target-transaction-nonce-mismatch-refused"); e != nil {
		return e
	}
	secondTarget, err := url.Parse(f.Second)
	if err != nil || secondTarget.Path == authorized.Path {
		return errors.New("second_target_refused")
	}
	resp, _, e = post(f.App, "/auth/media/issue/", map[string]string{"transaction": transaction, "client_nonce": nonce, "path": secondTarget.Path}, true)
	if e != nil {
		return e
	}
	if e = check(resp.StatusCode == 403, "tls-issue-cannot-substitute-second-valid-native-file-target"); e != nil {
		return e
	}
	resp, data, e = post(f.App, "/auth/media/issue/", map[string]string{"transaction": transaction, "client_nonce": nonce, "path": authorized.Path}, true)
	if e != nil {
		return e
	}
	code := text(data, "code")
	if e = check(resp.StatusCode == 200 && hex64.MatchString(code) && safeHeaders(resp.Header), "tls-issue-native-session-csrf-and-target-bound-oneuse-code"); e != nil {
		return e
	}
	resp, _, e = post(f.Files, "/media/session/redeem/", map[string]string{"transaction": transaction, "code": code, "client_nonce": badNonce, "path": authorized.Path}, false)
	if e != nil {
		return e
	}
	if e = check(resp.StatusCode == 403, "tls-redeem-code-nonce-mismatch-refused"); e != nil {
		return e
	}
	resp, _, e = post(f.Files, "/media/session/redeem/", map[string]string{"transaction": transaction, "code": code, "client_nonce": nonce, "path": secondTarget.Path}, false)
	if e != nil {
		return e
	}
	if e = check(resp.StatusCode == 403, "tls-redeem-cannot-substitute-second-valid-native-file-target"); e != nil {
		return e
	}
	redeem := map[string]string{"transaction": transaction, "code": code, "client_nonce": nonce, "path": authorized.Path}
	resp, _, e = post(f.Files, "/media/session/redeem/", redeem, false)
	if e != nil {
		return e
	}
	if e = check(resp.StatusCode == 200 && hostCookie(resp.Header, "__Host-phorge-media") && cookieValue(jar, app, "__Host-phorge-media") == "", "tls-redeem-hostonly-secure-httponly-credential-not-parent-cookie"); e != nil {
		return e
	}
	resp, _, e = post(f.Files, "/media/session/redeem/", redeem, false)
	if e != nil {
		return e
	}
	if e = check(resp.StatusCode == 403, "tls-exchange-code-is-actually-single-use"); e != nil {
		return e
	}
	resp, data, e = post(f.Files, "/media/session/ready/", map[string]string{"client_nonce": nonce}, false)
	if e != nil {
		return e
	}
	if e = check(resp.StatusCode == 200 && string(data["ready"]) == "true", "tls-six-native-media-protocol-stages-completed"); e != nil {
		return e
	}
	resp, body, e = request("GET", f.Private, nil, nil)
	if e != nil {
		return e
	}
	digest := sha256.Sum256(body)
	if e = check(resp.StatusCode == 200 && hex.EncodeToString(digest[:]) == f.ContentHash && safeHeaders(resp.Header), "tls-authorized-private-file-real-gorge-bytes-and-no-store-nosniff"); e != nil {
		return e
	}
	// Native FileDataController supports a single explicit/open-ended byte range.
	// It currently ignores conditional validators: no-store private reads remain 200.
	fullPrivateBody := append([]byte(nil), body...)
	if len(fullPrivateBody) < 16 {
		return errors.New("private_fixture_too_short")
	}
	privateCanaryRange := fullPrivateBody[len(fullPrivateBody)-16:]
	for _, item := range []struct {
		name, method string
		headers      http.Header
		status       int
		contentRange string
		want         []byte
	}{
		{"range-two-bytes", "GET", http.Header{"Range": {"bytes=0-1"}}, 206, fmt.Sprintf("bytes 0-1/%d", len(fullPrivateBody)), fullPrivateBody[:2]},
		{"range-canary", "GET", http.Header{"Range": {fmt.Sprintf("bytes=%d-%d", len(fullPrivateBody)-16, len(fullPrivateBody)-1)}}, 206, fmt.Sprintf("bytes %d-%d/%d", len(fullPrivateBody)-16, len(fullPrivateBody)-1, len(fullPrivateBody)), privateCanaryRange},
		{"range-open-ended", "GET", http.Header{"Range": {"bytes=0-"}}, 206, fmt.Sprintf("bytes 0-%d/%d", len(fullPrivateBody)-1, len(fullPrivateBody)), fullPrivateBody},
		{"conditional-etag", "GET", http.Header{"If-None-Match": {"*"}}, 200, "", fullPrivateBody},
		{"conditional-date", "GET", http.Header{"If-Modified-Since": {"Wed, 31 Dec 2098 23:59:59 GMT"}}, 200, "", fullPrivateBody},
		{"head-range", "HEAD", http.Header{"Range": {"bytes=0-1"}}, 206, fmt.Sprintf("bytes 0-1/%d", len(fullPrivateBody)), nil},
	} {
		resp, body, e = request(item.method, f.Private, nil, item.headers)
		if e != nil {
			return e
		}
		if e = check(privateReadMatches(resp, body, item.want, item.status, item.contentRange), "tls-authorized-private-"+item.name+"-current-native-contract"); e != nil {
			return e
		}
	}
	resp, body, e = request("HEAD", f.Private, nil, nil)
	if e != nil {
		return e
	}
	if e = check(resp.StatusCode == 200 && len(body) == 0 && safeHeaders(resp.Header), "tls-authorized-head-obeys-current-authentication-and-safe-headers"); e != nil {
		return e
	}
	for i, uri := range []string{f.HTML, f.SVG} {
		resp, _, e = request("GET", uri, nil, nil)
		if e != nil {
			return e
		}
		if e = check(resp.StatusCode == 200 && strings.HasPrefix(resp.Header.Get("Content-Type"), "application/octet-stream") && strings.HasPrefix(resp.Header.Get("Content-Disposition"), "attachment") && strings.Contains(strings.Join(resp.Header.Values("Content-Security-Policy"), ";"), "sandbox") && safeHeaders(resp.Header), fmt.Sprintf("tls-active-upload-%d-forced-attachment-and-sandbox-headers-only", i)); e != nil {
			return e
		}
	}
	retainedMediaCookie := cookieValue(jar, files, "__Host-phorge-media")
	resp, body, e = request("GET", f.App+"/logout/", nil, nil)
	if e != nil {
		return e
	}
	logoutURL, _ := url.Parse(f.App + "/logout/")
	logout, fields, e := form(body, logoutURL, false)
	if e != nil {
		return e
	}
	resp, _, e = request("POST", logout.String(), fields, nil)
	if e != nil {
		return e
	}
	if e = check(resp.StatusCode >= 300 && resp.StatusCode < 400 && retainedMediaCookie != "" && cookieValue(jar, files, "__Host-phorge-media") == retainedMediaCookie, "tls-real-native-logout-controller-completed-with-file-cookie-retained"); e != nil {
		return e
	}
	resp, _, e = request("GET", f.Private, nil, http.Header{"If-None-Match": {"*"}})
	if e != nil {
		return e
	}
	if e = check(resp.StatusCode == 403, "tls-native-logout-revokes-retained-media-cookie-before-conditional-file-access"); e != nil {
		return e
	}
	// Preserve the same jar and its original media credential: no reauthentication.
	for _, item := range []struct {
		name, method string
		headers      http.Header
	}{
		{"get", "GET", nil},
		{"head", "HEAD", nil},
		{"range", "GET", http.Header{"Range": {"bytes=0-1"}}},
		{"head-range", "HEAD", http.Header{"Range": {"bytes=0-1"}}},
		{"range-canary", "GET", http.Header{"Range": {fmt.Sprintf("bytes=%d-%d", len(fullPrivateBody)-16, len(fullPrivateBody)-1)}}},
		{"conditional-date", "GET", http.Header{"If-Modified-Since": {"Wed, 31 Dec 2098 23:59:59 GMT"}}},
	} {
		resp, body, e = request(item.method, f.Private, nil, item.headers)
		if e != nil {
			return e
		}
		if e = check(deniedPrivateReadMatches(resp, body, item.method, f.Marker, fullPrivateBody, privateCanaryRange), "tls-native-logout-denies-"+item.name+"-with-retained-media-cookie"); e != nil {
			return e
		}
	}
	resp, _, e = post(f.Files, "/media/session/ready/", map[string]string{"client_nonce": nonce}, false)
	if e != nil {
		return e
	}
	if e = check(resp.StatusCode == 403, "tls-native-logout-revokes-media-ready-identity"); e != nil {
		return e
	}
	r.Stage = "complete"
	return nil
}
