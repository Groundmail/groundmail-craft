#!/usr/bin/env python3
"""End-to-end test of the plugin in a real Craft CMS 5 site.

Needs a Craft 5 project with this plugin installed (composer require groundmail/craft-plugin,
then php craft plugin/install groundmail) and PHP:

    CRAFT_PATH=/path/to/project CRAFT_URL=http://127.0.0.1:8096 PHP=php \
    CRAFT_ADMIN=admin CRAFT_PASSWORD=... GROUNDMAIL_API_KEY=gm_... \
    FROM_EMAIL=craft@yourdomain.com python3 tests/e2e/run.py

Craft's email settings are set through the project config, with the API key as the
environment variable $GROUNDMAIL_API_KEY. Sends go through a local proxy that forwards to
the Groundmail API and records each request (never the Authorization header). With MOCK=1
the proxy answers like the API instead and nothing is sent. Recipients are Groundmail
simulator addresses only.

It checks:
1. Settings > Email offers Groundmail, and its settings show the API key and category fields.
2. php craft mailer/test sends one email through Groundmail as `transactional`.
3. A password reset email (a Craft system message) goes out the same way.
4. With the category set to product, email goes out as `product`.
"""
import http.cookiejar
import json
import os
import re
import shlex
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
CRAFT_PATH = os.environ["CRAFT_PATH"]
CRAFT_URL = os.environ.get("CRAFT_URL", "http://127.0.0.1:8096").rstrip("/")
PHP = shlex.split(os.environ.get("PHP", "php"))
ADMIN = os.environ.get("CRAFT_ADMIN", "admin")
PASSWORD = os.environ["CRAFT_PASSWORD"]
API_KEY = os.environ.get("GROUNDMAIL_API_KEY", "gm_mock_key")
FROM_EMAIL = os.environ["FROM_EMAIL"]
MOCK = os.environ.get("MOCK") == "1"
PROXY_PORT = int(os.environ.get("PROXY_PORT", "8098"))
RUN = int(time.time())
DELIVERED = "delivered@simulator.groundmail.dev"
ADAPTER = "groundmail\\craft\\mail\\GroundmailAdapter"

FAILURES = []
LOG = tempfile.NamedTemporaryFile(prefix="gm-craft-", suffix=".jsonl", delete=False).name
ENV = dict(os.environ, GROUNDMAIL_API_KEY=API_KEY, GROUNDMAIL_BASE_URL="http://127.0.0.1:%d" % PROXY_PORT)


def check(ok, label):
    print(("PASS " if ok else "FAIL ") + label)
    if not ok:
        FAILURES.append(label)


def craft(*args):
    cmd = PHP + ["-d", "error_reporting=24575", os.path.join(CRAFT_PATH, "craft"), *args]
    return subprocess.run(cmd, cwd=CRAFT_PATH, capture_output=True, text=True, timeout=300, env=ENV)


def configure(category="transactional"):
    for path, value in [
        ("email.fromEmail", FROM_EMAIL),
        ("email.fromName", "Craft E2E"),
        ("email.transportType", ADAPTER),
        ("email.transportSettings.apiKey", "$GROUNDMAIL_API_KEY"),
        ("email.transportSettings.baseUrl", "$GROUNDMAIL_BASE_URL"),
        ("email.transportSettings.category", category),
    ]:
        out = craft("project-config/set", path, value)
        if out.returncode != 0:
            check(False, "project-config/set %s (%s)" % (path, (out.stdout + out.stderr)[-300:]))


def records():
    with open(LOG) as f:
        return [json.loads(line) for line in f if line.strip()]


def sends_since(n):
    return [r for r in records()[n:] if r["method"] == "POST" and r["path"] == "/emails"]


def accepted(record):
    return record["status"] == 200 and str((record["response"] or {}).get("id", "")).startswith("msg_")


def control_panel_settings():
    jar = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    html = opener.open(CRAFT_URL + "/admin/login", timeout=120).read().decode()
    csrf = re.search(r'csrfTokenValue["\']?\s*[:=]\s*"([^"]+)"', html)
    form = {"loginName": ADMIN, "password": PASSWORD}
    if csrf:
        form["CRAFT_CSRF_TOKEN"] = csrf.group(1)
    req = urllib.request.Request(CRAFT_URL + "/admin/actions/users/login", data=urllib.parse.urlencode(form).encode(), method="POST")
    req.add_header("Accept", "application/json")
    opener.open(req, timeout=120).read()
    return opener.open(CRAFT_URL + "/admin/settings/email", timeout=120).read().decode()


def password_reset(login_name):
    jar = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    html = opener.open(CRAFT_URL + "/admin/login", timeout=120).read().decode()
    csrf = re.search(r'csrfTokenValue["\']?\s*[:=]\s*"([^"]+)"', html)
    form = {"loginName": login_name}
    if csrf:
        form["CRAFT_CSRF_TOKEN"] = csrf.group(1)
    req = urllib.request.Request(CRAFT_URL + "/admin/actions/users/send-password-reset-email", data=urllib.parse.urlencode(form).encode(), method="POST")
    req.add_header("Accept", "application/json")
    try:
        with opener.open(req, timeout=120) as resp:
            return resp.status
    except urllib.error.HTTPError as e:
        return e.code


def main():
    open(LOG, "w").close()
    proxy_cmd = [sys.executable, os.path.join(HERE, "recording_proxy.py"), "--port", str(PROXY_PORT), "--log", LOG]
    if MOCK:
        proxy_cmd.append("--mock")
    proxy = subprocess.Popen(proxy_cmd)
    web = os.path.join(CRAFT_PATH, "web")
    host = urllib.parse.urlparse(CRAFT_URL).netloc
    server = subprocess.Popen(PHP + ["-d", "error_reporting=24575", "-S", host, "-t", web, os.path.join(web, "index.php")],
                              cwd=web, env=ENV, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        time.sleep(1)
        configure()

        # 1. The control panel shows the transport and its fields.
        html = control_panel_settings()
        check(ADAPTER.replace("\\", "\\\\") in html or "GroundmailAdapter" in html, "Settings > Email lists the Groundmail transport type")
        check('name="transportTypes[groundmail-craft-mail-GroundmailAdapter][apiKey]"' in html or "[apiKey]" in html, "Groundmail settings show the API key field")
        check("$GROUNDMAIL_API_KEY" in html, "API key field shows the environment variable, not the key")
        check(API_KEY not in html, "the API key itself is not in the page")
        check("[category]" in html, "Groundmail settings show the email category field")

        # 2. Craft's test email.
        n = len(records())
        out = craft("mailer/test", "--to=" + DELIVERED)
        sent = sends_since(n)
        check(out.returncode == 0, "mailer/test succeeded (%s)" % (out.stdout + out.stderr).strip()[-300:])
        check(len(sent) == 1, "mailer/test sent one email through Groundmail")
        if sent:
            body = sent[0]["body"]
            check(body.get("category") == "transactional", "test email goes out as transactional (got %r)" % body.get("category"))
            check(FROM_EMAIL in body.get("from", "") and DELIVERED in str(body.get("to")), "test email to/from are right")
            check(accepted(sent[0]), "API accepted the test email (%s %s)" % (sent[0]["status"], sent[0]["response"]))

        # 3. A system message: the admin's password reset email (the admin's address is a simulator address).
        n = len(records())
        status = password_reset(ADMIN)
        sent = sends_since(n)
        check(len(sent) == 1, "password reset email sent through Groundmail (HTTP %s)" % status)
        if sent:
            check(sent[0]["body"].get("category") == "transactional" and DELIVERED in str(sent[0]["body"].get("to")), "password reset is transactional, to the admin")
            check(accepted(sent[0]), "API accepted the password reset (%s %s)" % (sent[0]["status"], sent[0]["response"]))

        # 4. Category: product.
        configure("product")
        n = len(records())
        out = craft("mailer/test", "--to=" + DELIVERED)
        sent = sends_since(n)
        check(len(sent) == 1 and sent[0]["body"].get("category") == "product" and accepted(sent[0]),
              "with category product, email goes out as product (%s)" % ([(s["status"], s["body"].get("category")) for s in sent]))
        configure()
    finally:
        server.terminate()
        proxy.terminate()

    print("\n%d failure(s)" % len(FAILURES))
    print("request log: " + LOG)
    sys.exit(1 if FAILURES else 0)


if __name__ == "__main__":
    main()
