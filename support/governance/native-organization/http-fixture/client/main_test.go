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
