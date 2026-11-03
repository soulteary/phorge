package main

import "testing"

func TestPendingClassificationCannotConfuseOtherFailures(t *testing.T) {
 const pending = `{"result":null,"error_code":"ERR-CONDUIT-CORE","error_info":"Notification source transaction is still pending."}`
 cases := []struct { name string; status int; body string; want bool }{
  {"actual pending", 200, pending, true},
  {"HTTP failure", 500, pending, false},
  {"authentication HTTP failure", 401, pending, false},
  {"unrelated SQL error", 200, `{"result":null,"error_code":"ERR-CONDUIT-CORE","error_info":"Database unavailable."}`, false},
  {"service authentication error", 200, `{"result":null,"error_code":"ERR-MAIL-AUTH","error_info":"Service authentication required."}`, false},
  {"contradictory allowed result", 200, `{"result":{"allowed":true},"error_code":"ERR-CONDUIT-CORE","error_info":"Notification source transaction is still pending."}`, false},
  {"explicit permanent denial", 200, `{"result":{"allowed":false},"error_code":null,"error_info":null}`, false},
  {"malformed response", 200, `{broken`, false},
  {"null response", 200, `null`, false},
  {"non-string error", 200, `{"result":null,"error_code":"ERR-CONDUIT-CORE","error_info":42}`, false},
  {"invented longer error", 200, `{"result":null,"error_code":"ERR-CONDUIT-CORE","error_info":"prefix: Notification source transaction is still pending."}`, false},
 }
 for _, c := range cases { t.Run(c.name, func(t *testing.T) {
  if got := sourcePendingResponse(c.status, []byte(c.body)); got != c.want { t.Fatalf("classification=%v want %v", got, c.want) }
 }) }
}
