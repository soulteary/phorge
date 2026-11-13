package main

import (
	"net/http"
	"net/url"
	"testing"
)

func TestNativeFormHiddenFieldsAndEntityDecoding(t *testing.T) {
	base, _ := url.Parse("https://app.media.localhost:8443/auth/login/password:test/")
	raw := []byte(`<form method="GET"><input name="username"></form><form method="POST" action="/auth/login/password:test/"><input type="hidden" name="__csrf__" value="synthetic&amp;token"><input type="hidden" name="__form__" value="1"><input type="text" name="username"></form>`)
	target, values, err := form(raw, base, true)
	if err != nil || target.Host != base.Host || values.Get("__csrf__") != "synthetic&token" || values.Get("__form__") != "1" {
		t.Fatal("native form structure not preserved")
	}
}
func TestFormCannotRedirectCredentialsOrSelectAmbiguousCSRF(t *testing.T) {
	base, _ := url.Parse("https://app.media.localhost:8443/auth/login/password:test/")
	for _, raw := range []string{
		`<form method="POST" action="https://files.media.localhost:8443/auth/login/"><input type="hidden" name="__csrf__" value="synthetic"><input type="hidden" name="__form__" value="1"><input name="username"></form>`,
		`<form method="POST"><input type="hidden" name="__csrf__" value="one"><input type="hidden" name="__csrf__" value="two"><input type="hidden" name="__form__" value="1"><input name="username"></form>`,
		`<form method="POST"><input type="hidden" name="__csrf__" value="synthetic"><input name="username"></form>`,
	} {
		if _, _, err := form([]byte(raw), base, true); err == nil {
			t.Fatal("unsafe native form accepted")
		}
	}
}
func TestHostCookieRequiresExactWireSecurityAttributes(t *testing.T) {
	value := "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
	safe := "__Host-phorge-media=" + value + "; Path=/; Secure; HttpOnly; SameSite=Strict"
	if !hostCookie(http.Header{"Set-Cookie": {safe}}, "__Host-phorge-media") {
		t.Fatal("safe exact cookie refused")
	}
	for _, cookie := range []string{
		"__Host-phorge-media=" + value + "; Path=/; Secure; HttpOnly; SameSite=Strict; Domain=media.localhost",
		"__Host-phorge-media=" + value + "; Path=/; HttpOnly; SameSite=Strict",
		"__Host-phorge-media=" + value + "; Path=/; Secure; SameSite=Strict",
		"__Host-phorge-media=" + value + "; Path=/; Secure; HttpOnly; SameSite=Lax",
	} {
		if hostCookie(http.Header{"Set-Cookie": {cookie}}, "__Host-phorge-media") {
			t.Fatal("unsafe cookie accepted")
		}
	}
	if hostCookie(http.Header{"Set-Cookie": {safe, safe}}, "__Host-phorge-media") {
		t.Fatal("duplicate credential cookie accepted")
	}
}

func TestBridgeAllPoliciesMustPermitPinnedParent(t *testing.T) {
	app := "https://app.media.localhost:8443"
	if !bridgeAllowsApp(http.Header{"Content-Security-Policy": {"default-src 'none'; frame-ancestors " + app, "default-src 'none'"}}, app) {
		t.Fatal("explicit pinned parent denied by non-framing directive")
	}
	for _, h := range []http.Header{
		{"Content-Security-Policy": {"frame-ancestors " + app, "frame-ancestors 'self'"}},
		{"Content-Security-Policy": {"frame-ancestors " + app + ", frame-ancestors 'none'"}},
		{"Content-Security-Policy": {"frame-ancestors 'self'; frame-ancestors " + app}},
		{"Content-Security-Policy": {"frame-ancestors " + app}, "X-Frame-Options": {"SAMEORIGIN"}},
	} {
		if bridgeAllowsApp(h, app) {
			t.Fatal("intersecting or ambiguous native framing policy ignored")
		}
	}
}
func TestBridgeScriptConfigurationDecodesEscapedSlashes(t *testing.T) {
	var value struct {
		App   string `json:"app"`
		Files string `json:"files"`
	}
	if err := decode([]byte(`{"app":"https:\/\/app.media.localhost:8443","files":"https:\/\/files.media.localhost:8443"}`), &value); err != nil || value.App != "https://app.media.localhost:8443" {
		t.Fatal("native script JSON URL escaping not decoded")
	}
}

func TestLogoutSelectsExactNativeDialogInsteadOfHeaderSearch(t *testing.T) {
	base, _ := url.Parse("https://app.media.localhost:8443/logout/")
	body := []byte(`<form method="POST" action="/search/"><input type="hidden" name="__csrf__" value="search-token"><input type="hidden" name="__form__" value="1"></form><form method="POST" action="/logout/"><input type="hidden" name="__csrf__" value="logout-token"><input type="hidden" name="__form__" value="1"><button type="submit">Log Out</button></form>`)
	action, values, err := form(body, base, false)
	if err != nil || action.Path != "/logout/" || values.Get("__csrf__") != "logout-token" {
		t.Fatal("header search selected instead of native logout dialog")
	}
}

func TestPrivateReadResponseRejectsStatusBytesRangeAndCacheTampering(t *testing.T) {
	good := func() *http.Response {
		return &http.Response{StatusCode: 206, Header: http.Header{"Cache-Control": {"private, no-store"}, "X-Content-Type-Options": {"nosniff"}, "Content-Range": {"bytes 0-1/4"}}}
	}
	if !privateReadMatches(good(), []byte("ab"), []byte("ab"), 206, "bytes 0-1/4") {
		t.Fatal("valid native range refused")
	}
	for _, mutate := range []func(*http.Response){
		func(r *http.Response) { r.StatusCode = 200 },
		func(r *http.Response) { r.StatusCode = 304 },
		func(r *http.Response) { r.Header.Set("Content-Range", "bytes 1-2/4") },
		func(r *http.Response) { r.Header.Set("Cache-Control", "public, max-age=3600") },
		func(r *http.Response) { r.Header.Del("X-Content-Type-Options") },
	} {
		r := good()
		mutate(r)
		if privateReadMatches(r, []byte("ab"), []byte("ab"), 206, "bytes 0-1/4") {
			t.Fatal("unsafe range response accepted")
		}
	}
	if privateReadMatches(good(), []byte("private extra bytes"), []byte("ab"), 206, "bytes 0-1/4") {
		t.Fatal("wrong body accepted")
	}
	if privateReadMatches(nil, nil, nil, 200, "") {
		t.Fatal("missing response accepted")
	}
	head := good()
	if !privateReadMatches(head, nil, nil, 206, "bytes 0-1/4") || privateReadMatches(head, []byte("ab"), nil, 206, "bytes 0-1/4") {
		t.Fatal("HEAD body contract lost")
	}
}

func TestDeniedPrivateReadRejectsSelectedFragmentsAndResponseTampering(t *testing.T) {
	good := func() *http.Response {
		return &http.Response{StatusCode: 403, Header: http.Header{"Cache-Control": {"no-store"}, "X-Content-Type-Options": {"nosniff"}}}
	}
	full := []byte("Synthetic private body with 0123456789abcdef")
	selected := []byte("0123456789abcdef")
	check := func(r *http.Response, b []byte, method string) bool {
		return deniedPrivateReadMatches(r, b, method, "unique-fixture-marker", full, selected)
	}
	if !check(good(), []byte("Access denied"), "GET") || !check(good(), nil, "HEAD") {
		t.Fatal("ordinary denial refused")
	}
	// Two ordinary prefix bytes are not evidence of a private leak.
	if !check(good(), []byte("Syntax error denied"), "GET") {
		t.Fatal("ordinary text mistaken for private bytes")
	}
	for _, leak := range [][]byte{full, selected, selected[:8], selected[8:], []byte("unique-fixture-marker")} {
		body := append([]byte("denied with leaked fragment:"), leak...)
		if check(good(), body, "GET") {
			t.Fatal("private canary or partial body fragment accepted")
		}
	}
	for _, mutate := range []func(*http.Response){
		func(r *http.Response) { r.StatusCode = 200 }, func(r *http.Response) { r.StatusCode = 206 }, func(r *http.Response) { r.StatusCode = 304 },
		func(r *http.Response) { r.Header.Set("Content-Range", "bytes 0-1/44") }, func(r *http.Response) { r.Header.Del("Cache-Control") }, func(r *http.Response) { r.Header.Del("X-Content-Type-Options") },
	} {
		r := good()
		mutate(r)
		if check(r, []byte("denied"), "GET") {
			t.Fatal("unsafe denial accepted")
		}
	}
	if check(good(), []byte("denied"), "HEAD") || check(nil, nil, "GET") {
		t.Fatal("HEAD body or missing response accepted")
	}
}
