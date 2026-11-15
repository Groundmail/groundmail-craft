#!/usr/bin/env python3
"""A tiny HTTP proxy for end-to-end tests: forwards every request to the Groundmail API
(or answers like it, with --mock) and appends one JSON line per request to a log file.

The log keeps the method, path, JSON body, status and response body. It never keeps the
Authorization header, so the API key does not end up on disk.

    python3 recording_proxy.py --port 8097 --log /tmp/requests.jsonl [--upstream https://api.groundmail.dev] [--mock]
"""
import argparse
import http.server
import json
import urllib.error
import urllib.request

FORWARDED_HEADERS = ("authorization", "content-type", "idempotency-key", "user-agent")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--port", type=int, required=True)
    parser.add_argument("--log", required=True)
    parser.add_argument("--upstream", default="https://api.groundmail.dev")
    parser.add_argument("--mock", action="store_true", help="answer like the API instead of forwarding")
    args = parser.parse_args()

    class Handler(http.server.BaseHTTPRequestHandler):
        def log_message(self, *a):
            pass

        def _handle(self):
            length = int(self.headers.get("Content-Length") or 0)
            raw = self.rfile.read(length) if length else b""
            if args.mock:
                status, body = 200, json.dumps({"id": "msg_mock_%d" % self.server.counter, "status": "queued"}).encode()
                self.server.counter += 1
            else:
                req = urllib.request.Request(args.upstream.rstrip("/") + self.path, data=raw or None, method=self.command)
                for name in FORWARDED_HEADERS:
                    if self.headers.get(name):
                        req.add_header(name, self.headers[name])
                try:
                    with urllib.request.urlopen(req, timeout=30) as resp:
                        status, body = resp.status, resp.read()
                except urllib.error.HTTPError as e:
                    status, body = e.code, e.read()
            try:
                request_json = json.loads(raw) if raw else None
            except ValueError:
                request_json = None
            try:
                response_json = json.loads(body) if body else None
            except ValueError:
                response_json = body.decode("utf-8", "replace")[:500]
            with open(args.log, "a") as f:
                f.write(json.dumps({"method": self.command, "path": self.path, "body": request_json, "status": status, "response": response_json}) + "\n")
            self.send_response(status)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

        do_GET = do_POST = do_DELETE = do_PATCH = do_PUT = _handle

    server = http.server.ThreadingHTTPServer(("127.0.0.1", args.port), Handler)
    server.counter = 1
    server.serve_forever()


if __name__ == "__main__":
    main()
