#!/usr/bin/env python3
"""Local-only TCP forwarder — works around this project's `internal: true` Compose
network not exposing its published host ports on this host, without touching the
network config, containers, volumes, or egress rules in any way.

Docker still writes the `127.0.0.1:8088:80` / `127.0.0.1:18025:8025` bindings from
docker-compose.yml into each container's HostConfig, but because the `internal`
network has no gateway, those bindings are not reachable from this host. This
script does not change that: it only reads (via `docker compose ps` + `docker
inspect`, both read-only) the current container IPs on the `internal` network, and
relays two fixed host:port -> container:port pairs on top of that:

    127.0.0.1:<WEB_PORT>         -> <web container IP>:80
    127.0.0.1:<MAILPIT_UI_PORT>  -> <mailpit container IP>:8025

It binds strictly to 127.0.0.1, never proxies any destination other than the two
resolved container IPs above, and does not create, join, modify, or route through
any Docker network itself — it is a host-local relay of bytes already received on
localhost, not a general-purpose proxy. It grants no additional egress to the
containers: `web` and `mailpit` still cannot initiate outbound connections, and
this script never talks to anything but those two containers on those two ports.

Run via scripts/start.sh / scripts/restart.sh (which manage it as a background
process), or directly in the foreground for debugging:

    scripts/host-proxy.py

Ctrl-C (SIGINT) or SIGTERM stops it cleanly.
"""
from __future__ import annotations

import os
import re
import signal
import socket
import subprocess
import sys
import threading

PROJECT_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


def load_env() -> dict:
    env = {}
    env_path = os.path.join(PROJECT_ROOT, ".env")
    if os.path.isfile(env_path):
        with open(env_path) as f:
            for line in f:
                line = line.strip()
                if not line or line.startswith("#") or "=" not in line:
                    continue
                key, _, value = line.partition("=")
                env[key.strip()] = value.strip().strip('"').strip("'")
    return env


def container_id(service: str) -> str:
    result = subprocess.run(
        ["docker", "compose", "ps", "-q", service],
        cwd=PROJECT_ROOT,
        capture_output=True,
        text=True,
        check=True,
    )
    cid = result.stdout.strip()
    if not cid:
        raise RuntimeError(
            f"No running container for service '{service}' — start the stack first "
            f"(./scripts/start.sh) before running the host proxy."
        )
    return cid


def container_ip(cid: str) -> str:
    result = subprocess.run(
        [
            "docker",
            "inspect",
            "-f",
            "{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}",
            cid,
        ],
        capture_output=True,
        text=True,
        check=True,
    )
    ip = result.stdout.strip()
    if not re.match(r"^\d+\.\d+\.\d+\.\d+$", ip):
        raise RuntimeError(f"Could not resolve an IPv4 address for container {cid} (got {ip!r})")
    return ip


def pump(src: socket.socket, dst: socket.socket) -> None:
    try:
        while True:
            data = src.recv(65536)
            if not data:
                break
            dst.sendall(data)
    except OSError:
        pass
    finally:
        for s in (src, dst):
            try:
                s.shutdown(socket.SHUT_RDWR)
            except OSError:
                pass
            s.close()


def handle_connection(client: socket.socket, dest_host: str, dest_port: int) -> None:
    try:
        upstream = socket.create_connection((dest_host, dest_port), timeout=5)
    except OSError as exc:
        print(f"[host-proxy] failed to connect to {dest_host}:{dest_port}: {exc}", file=sys.stderr)
        client.close()
        return
    threading.Thread(target=pump, args=(client, upstream), daemon=True).start()
    threading.Thread(target=pump, args=(upstream, client), daemon=True).start()


def serve(listen_port: int, dest_host: str, dest_port: int, label: str) -> socket.socket:
    server = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    server.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    server.bind(("127.0.0.1", listen_port))
    server.listen(128)
    print(f"[host-proxy] 127.0.0.1:{listen_port} -> {label} {dest_host}:{dest_port}")

    def accept_loop() -> None:
        while True:
            try:
                client, _ = server.accept()
            except OSError:
                break
            handle_connection(client, dest_host, dest_port)

    threading.Thread(target=accept_loop, daemon=True).start()
    return server


def main() -> int:
    env = load_env()
    web_port = int(env.get("WEB_PORT", "8088"))
    mailpit_port = int(env.get("MAILPIT_UI_PORT", "18025"))

    web_ip = container_ip(container_id("web"))
    mailpit_ip = container_ip(container_id("mailpit"))

    servers = [
        serve(web_port, web_ip, 80, "web"),
        serve(mailpit_port, mailpit_ip, 8025, "mailpit"),
    ]

    stop_event = threading.Event()

    def handle_signal(signum, frame) -> None:
        stop_event.set()

    signal.signal(signal.SIGINT, handle_signal)
    signal.signal(signal.SIGTERM, handle_signal)

    print("[host-proxy] running (Ctrl-C or SIGTERM to stop)")
    stop_event.wait()

    print("[host-proxy] stopping")
    for s in servers:
        s.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
