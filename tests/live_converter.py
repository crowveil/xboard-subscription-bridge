"""Start a local fixture source + verified SCE binary and run PHP integration tests.
All proxy credentials and domains here are synthetic; no proxy connections occur.
Usage: python3 tests/live_converter.py /path/to/SubConverter-Extended /path/to/php
"""

import base64
import http.server
import json
import os
import pathlib
import socket
import subprocess
import sys
import threading
import time
import urllib.request
import urllib.parse

ROOT = pathlib.Path(__file__).resolve().parents[1]
sce = pathlib.Path(sys.argv[1]).resolve()
php = str(pathlib.Path(sys.argv[2]).resolve())
runtime = ROOT / "tests/runtime/live"
runtime.mkdir(parents=True, exist_ok=True)
# The binary changes cwd to the directory of -f. Match Docker's /base/base.
base_link = runtime / "base"
if base_link.is_symlink():
    base_link.unlink()
base_link.symlink_to(sce / "base/base", target_is_directory=True)
nodes = "\n".join(
    [
        "trojan://EXTERNAL-TROJAN-PASSWORD@trojan.example.test:443?sni=tls.example.test#Trojan",
        "vless://11111111-1111-4111-8111-111111111111@vless.example.test:443?encryption=none&security=tls&type=tcp&sni=tls.example.test#VLESS",
        "hysteria2://HY2-PASSWORD@hy2.example.test:443?sni=tls.example.test#HY2",
        "tuic://11111111-1111-4111-8111-111111111111:TUIC-DIFFERENT-PASSWORD@tuic.example.test:443?sni=tls.example.test&congestion_control=bbr&alpn=h3#TUIC",
        "ss://"
        + base64.urlsafe_b64encode(b"aes-128-gcm:SS-PASSWORD").decode()
        + "@ss.example.test:443#SS",
    ]
)


class Fixture(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        data = base64.b64encode(nodes.encode())
        if self.path.endswith("/xhttp"):
            down = {
                "path": "/down",
                "server": "down.example.test",
                "port": 443,
                "servername": "down-tls.example.test",
            }
            proxy = {
                "name": "XHTTP-test",
                "type": "vless",
                "server": "up.example.test",
                "port": 443,
                "uuid": "11111111-1111-4111-8111-111111111111",
                "network": "xhttp",
                "tls": True,
                "skip-cert-verify": True,
                "servername": "up-tls.example.test",
                "xhttp-opts": {
                    "path": "/up",
                    "mode": "stream-up",
                    "download-settings": down,
                },
            }
            if self.headers.get("User-Agent") == "clash.meta":
                data = json.dumps({"proxies": [proxy]}).encode()
            else:
                # Simulate an origin whose generic URI response uses Mihomo keys
                # where the URI converter expects Xray's downloadSettings keys.
                query = urllib.parse.urlencode(
                    {
                        "security": "tls",
                        "sni": "up-tls.example.test",
                        "type": "xhttp",
                        "path": "/up",
                        "mode": "stream-up",
                        "extra": json.dumps({"downloadSettings": down}),
                    }
                )
                data = (
                    "vless://11111111-1111-4111-8111-111111111111@up.example.test:443?"
                    + query
                    + "#XHTTP-test"
                ).encode()
        self.send_response(200)
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def log_message(self, *args):
        pass


server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Fixture)
threading.Thread(target=server.serve_forever, daemon=True).start()
with socket.socket() as probe:
    probe.bind(("127.0.0.1", 0))
    port = probe.getsockname()[1]
config = runtime / "pref.toml"
config.write_text(
    (ROOT / "deploy/pref.toml")
    .read_text()
    .replace('base_path = "base"', f'base_path = "{sce / "base/base"}"')
    .replace('listen = "0.0.0.0"', 'listen = "127.0.0.1"')
    .replace("port = 25500", f"port = {port}")
)
libdirs = [
    sce / p
    for p in (
        "lib/x86_64-linux-gnu",
        "usr/lib/x86_64-linux-gnu",
        "lib64",
        "lib",
        "usr/lib",
    )
]
env = os.environ.copy()
env["PORT"] = str(port)
env["BRIDGE_LIVE_CONVERTER_URL"] = f"http://127.0.0.1:{port}"
env["BRIDGE_LIVE_SOURCE_URL"] = f"http://127.0.0.1:{server.server_port}/subscription"
with (runtime / "converter.log").open("wb") as log:
    proc = subprocess.Popen(
        [
            str(sce / "lib64/ld-linux-x86-64.so.2"),
            "--library-path",
            ":".join(map(str, libdirs)),
            str(sce / "subconverter"),
            "-f",
            str(config),
        ],
        cwd=sce / "base",
        env=env,
        stdout=log,
        stderr=subprocess.STDOUT,
    )
    try:
        for attempt in range(100):
            try:
                with urllib.request.urlopen(
                    env["BRIDGE_LIVE_CONVERTER_URL"] + "/healthz", timeout=1
                ) as r:
                    if r.read().strip() == b"ok":
                        break
            except Exception:
                if proc.poll() is not None:
                    raise RuntimeError(
                        "Converter exited; see tests/runtime/live/converter.log"
                    )
                time.sleep(0.1)
        else:
            raise RuntimeError("Converter did not become ready")
        result = subprocess.run(
            [
                php,
                "vendor/bin/phpunit",
                "--display-warnings",
                "--display-errors",
                "--log-junit",
                "tests/runtime/results.xml",
            ],
            cwd=ROOT,
            env=env,
        )
        sys.exit(result.returncode)
    finally:
        proc.terminate()
        try:
            proc.wait(timeout=3)
        except subprocess.TimeoutExpired:
            proc.kill()
        server.shutdown()
