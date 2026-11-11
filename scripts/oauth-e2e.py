# /// script
# dependencies = ["httpx"]
# ///
"""Walks the OAuth + MCP flow the way a Claude custom connector does.

usage: uv run scripts/oauth-e2e.py BASE_URL [EMAIL PASSWORD]
Without credentials it stops after client registration (no login possible).
"""
import base64, hashlib, html, json, re, secrets, sys
from urllib.parse import parse_qs, urlencode, urlparse

import httpx

base = sys.argv[1].rstrip("/")
creds = sys.argv[2:4]
mcp_url = base + "/mcp/content"
CALLBACK = "https://claude.ai/api/mcp/auth_callback"
c = httpx.Client(follow_redirects=False, timeout=30)
MCP_HEADERS = {"Accept": "application/json, text/event-stream", "Content-Type": "application/json"}


def ok(label, cond, detail=""):
    print(("✓ " if cond else "✗ ") + label + (f"  [{detail}]" if detail else ""))
    if not cond:
        sys.exit(1)


def rpc(token, method, params=None, id=1):
    r = c.post(mcp_url, headers={**MCP_HEADERS, "Authorization": f"Bearer {token}"},
               json={"jsonrpc": "2.0", "id": id, "method": method, "params": params or {}})
    body = r.text
    if body.startswith("event:") or "\ndata:" in body or body.startswith("data:"):
        body = [l[5:].strip() for l in body.splitlines() if l.startswith("data:")][-1]
    return r, (json.loads(body) if body.strip() else None)


r = c.post(mcp_url, headers=MCP_HEADERS, json={"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {}})
www = r.headers.get("www-authenticate", "")
ok("unauthenticated MCP call → 401 with resource_metadata", r.status_code == 401 and "resource_metadata=" in www, f"{r.status_code} {www}")
prm_url = re.search(r'resource_metadata="([^"]+)"', www).group(1)
prm = c.get(prm_url).json()
ok("protected resource metadata names the MCP URL", prm["resource"] == mcp_url, prm["resource"])
asm = c.get(prm["authorization_servers"][0].rstrip("/") + "/.well-known/oauth-authorization-server").json()
ok("AS metadata: S256 + registration endpoint", "S256" in asm["code_challenge_methods_supported"] and "registration_endpoint" in asm)

r = c.post(asm["registration_endpoint"], json={
    "client_name": "Claude (e2e check)", "redirect_uris": [CALLBACK],
    "grant_types": ["authorization_code", "refresh_token"], "response_types": ["code"],
    "token_endpoint_auth_method": "none", "scope": "mcp:use"})
ok("dynamic client registration", r.status_code in (200, 201), f"{r.status_code} {r.text[:200]}")
client = r.json()

r = c.post(asm["registration_endpoint"], json={"client_name": "evil", "redirect_uris": ["https://evil.example/cb"],
           "grant_types": ["authorization_code"], "response_types": ["code"], "token_endpoint_auth_method": "none"})
ok("registration with a foreign redirect host is rejected", r.status_code == 400, str(r.status_code))

if not creds:
    print("no credentials given, stopping before login")
    sys.exit(0)

verifier = secrets.token_urlsafe(48)
challenge = base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest()).rstrip(b"=").decode()
state = secrets.token_urlsafe(16)
authorize = asm["authorization_endpoint"] + "?" + urlencode({
    "response_type": "code", "client_id": client["client_id"], "redirect_uri": CALLBACK,
    "code_challenge": challenge, "code_challenge_method": "S256", "state": state, "scope": "mcp:use", "resource": mcp_url})
r = c.get(authorize)
ok("guest is sent to the CP login", r.status_code == 302 and "/cp/auth/login" in r.headers["location"], r.headers.get("location", str(r.status_code)))

login_url = r.headers["location"]
c.get(login_url)
xsrf = httpx.URL("http://x/?" + "t=" + c.cookies.get("XSRF-TOKEN")).params["t"]
r = c.post(login_url, data={"email": creds[0], "password": creds[1]}, headers={"X-XSRF-TOKEN": xsrf, "Referer": login_url})
ok("CP login redirects back to authorize", r.status_code in (302, 303) and "/oauth/authorize" in r.headers.get("location", ""), f'{r.status_code} {r.headers.get("location")}')

r = c.get(r.headers["location"])
ok("consent screen, not frameable, shows claude.ai", r.status_code == 200 and "claude.ai" in r.text and "DENY" in r.headers.get("x-frame-options", "").upper(),
   f'{r.status_code} xfo={r.headers.get("x-frame-options")}')
form = re.search(r'<form[^>]*method="post"[^>]*>(.*?)</form>', r.text, re.S | re.I).group(1)
fields = {m.group(1): html.unescape(m.group(2)) for m in re.finditer(r'name="([^"]+)"\s+value="([^"]*)"', form)}
fields.pop("_method", None)
r = c.post(asm["authorization_endpoint"], data=fields, headers={"Referer": authorize})
loc = r.headers.get("location", "")
q = parse_qs(urlparse(loc).query)
ok("approve redirects to Claude's callback with code + state", loc.startswith(CALLBACK) and q.get("state") == [state] and "code" in q, f"{r.status_code} {loc[:120]}")

r = c.post(asm["token_endpoint"], data={"grant_type": "authorization_code", "code": q["code"][0], "redirect_uri": CALLBACK,
                                        "client_id": client["client_id"], "code_verifier": verifier})
ok("code → tokens (PKCE, public client)", r.status_code == 200 and "refresh_token" in r.json(), f"{r.status_code} {r.text[:200]}")
tok = r.json()
print(f"  expires_in={tok['expires_in']}")

r, body = rpc(tok["access_token"], "initialize", {"protocolVersion": "2025-06-18", "capabilities": {}, "clientInfo": {"name": "e2e", "version": "0"}})
ok("initialize", r.status_code == 200 and "result" in body, f"{r.status_code} {r.text[:200]}")
print("  server:", body["result"]["serverInfo"], "| instructions chars:", len(body["result"].get("instructions", "")))
r, body = rpc(tok["access_token"], "tools/list")
names = [t["name"] for t in body["result"]["tools"]]
ok("tools/list", len(names) == 12, ", ".join(names))
r, body = rpc(tok["access_token"], "tools/call", {"name": "get_overview", "arguments": {}})
ok("get_overview", not body["result"].get("isError"), body["result"]["content"][0]["text"][:600])

r = c.post(asm["token_endpoint"], data={"grant_type": "refresh_token", "refresh_token": tok["refresh_token"], "client_id": client["client_id"]})
ok("refresh rotates", r.status_code == 200 and r.json()["refresh_token"] != tok["refresh_token"], str(r.status_code))
open("/tmp/mcp-token", "w").write(r.json()["access_token"])  # for ad-hoc tool calls with curl
r2 = c.post(asm["token_endpoint"], data={"grant_type": "refresh_token", "refresh_token": tok["refresh_token"], "client_id": client["client_id"]})
ok("old refresh token → invalid_grant", r2.status_code == 400 and r2.json().get("error") == "invalid_grant", f"{r2.status_code} {r2.text[:120]}")
r, body = rpc("garbage", "tools/list")
ok("garbage token → 401", r.status_code == 401)
print("ALL OK")
