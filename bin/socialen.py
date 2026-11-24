#!/usr/bin/env python3
"""Operate Socialen through its private PHP command over an existing SSH login."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shlex
import subprocess
import sys
import tempfile


def parser():
    p = argparse.ArgumentParser(description="Socialen via SSH. Ändringar förhandsgranskas utan --apply.")
    p.add_argument("--config", type=Path, default=Path(os.environ.get("SOCIALEN_CONFIG", Path(__file__).resolve().parents[1] / "publishing/socialen-terminal.json")))
    subs = p.add_subparsers(dest="command", required=True)
    for name in ("health", "channels", "media"):
        subs.add_parser(name)
    posts = subs.add_parser("posts")
    posts.add_argument("--network", choices=("facebook", "instagram", "youtube"))
    posts.add_argument("--status", choices=("draft", "scheduled", "cancelled"))
    posts.add_argument("--limit", type=int, default=100)
    posts.add_argument("--before-id", type=int)
    show = subs.add_parser("show")
    show.add_argument("id", type=int)
    upload = subs.add_parser("upload")
    upload.add_argument("file", type=Path)
    save = subs.add_parser("save")
    save.add_argument("manifest", type=Path)
    reschedule = subs.add_parser("reschedule")
    reschedule.add_argument("id", type=int)
    reschedule.add_argument("--at", required=True, help="Lokal tid, YYYY-MM-DDTHH:MM")
    reschedule.add_argument("--timezone", default=None)
    reschedule.add_argument("--version", required=True, type=int)
    draft = subs.add_parser("draft")
    draft.add_argument("id", type=int)
    draft.add_argument("--version", required=True, type=int)
    for cmd in (upload, save, reschedule, draft):
        cmd.add_argument("--apply", action="store_true", help="Spara ändringen efter granskning")
    for cmd in (save, reschedule, draft):
        cmd.add_argument("--request-id", help="Stabil nyckel för samma avsikt; beräknas annars från begäran")
    return p


def load_config(path):
    cfg = json.loads(path.read_text())
    for key in ("host", "user", "app_root", "php"):
        if not isinstance(cfg.get(key), str) or not cfg[key] or "\0" in cfg[key] or "\n" in cfg[key]:
            raise ValueError("Ogiltig SSH-inställning: " + key)
    if not cfg["app_root"].startswith("/") or not cfg["php"].startswith("/"):
        raise ValueError("app_root och php behöver vara absoluta serversökvägar.")
    if cfg["host"].startswith("-") or "@" in cfg["host"] or cfg["user"].startswith("-") or "@" in cfg["user"]:
        raise ValueError("Ogiltig SSH-värd/användare.")
    return cfg


def build_request(args, cfg):
    req = {"action": args.command, "actor_id": cfg.get("actor_id", 1)}
    for name in ("id", "network", "status", "limit", "before_id", "version"):
        value = getattr(args, name, None)
        if value is not None:
            req[name] = value
    source = None
    if args.command in ("save", "reschedule", "draft", "upload"):
        req["apply"] = args.apply
    if args.command == "save":
        post = json.loads(args.manifest.read_text())
        if not isinstance(post, dict):
            raise ValueError("Manifestet behöver vara ett JSON-objekt.")
        post.setdefault("timezone", cfg.get("timezone", "Europe/Stockholm"))
        req["post"] = post
    if args.command == "reschedule":
        req["scheduled_at"] = args.at
        if args.timezone:
            req["timezone"] = args.timezone
    if args.command == "upload":
        source = args.file.expanduser().resolve(strict=True)
        if not source.is_file():
            raise ValueError("Välj en vanlig fil.")
        h = hashlib.sha256()
        with source.open("rb") as f:
            for chunk in iter(lambda: f.read(1024 * 1024), b""):
                h.update(chunk)
        req.update(name=source.name, bytes=source.stat().st_size, sha256=h.hexdigest())
    if args.command in ("save", "reschedule", "draft"):
        intent = {k: v for k, v in req.items() if k != "apply"}
        digest = hashlib.sha256(json.dumps(intent, sort_keys=True, ensure_ascii=False, separators=(",", ":")).encode()).hexdigest()
        req["request_id"] = args.request_id or "intent-" + digest
    return req, source


def transport(cfg, request, source=None):
    # All user text travels through stdin, never through a remote shell argument.
    remote = "exec " + shlex.quote(cfg["php"]) + " " + shlex.quote(cfg["app_root"].rstrip("/") + "/bin/admin.php")
    cmd = ["ssh", "-T", "-p", str(int(cfg.get("port", 22))),
           "-o", "BatchMode=yes", "-o", "StrictHostKeyChecking=yes", "-o", "ConnectTimeout=15",
           "-o", "ServerAliveInterval=15", "-o", "ServerAliveCountMax=3"]
    if cfg.get("identity_file"):
        cmd += ["-i", str(Path(cfg["identity_file"]).expanduser()), "-o", "IdentitiesOnly=yes"]
    cmd += [cfg["user"] + "@" + cfg["host"], remote]
    header = (json.dumps(request, ensure_ascii=False, separators=(",", ":")) + "\n").encode()
    if len(header) > 262144:
        raise ValueError("Begäran är för stor.")
    # Temporary output files avoid pipe deadlocks during a streamed upload.
    with tempfile.TemporaryFile() as output, tempfile.TemporaryFile() as errors:
        proc = subprocess.Popen(cmd, stdin=subprocess.PIPE, stdout=output, stderr=errors)
        try:
            try:
                proc.stdin.write(header)
                if source and request.get("apply"):
                    with source.open("rb") as f:
                        for chunk in iter(lambda: f.read(1024 * 1024), b""):
                            proc.stdin.write(chunk)
                proc.stdin.close()
            except BrokenPipeError:
                pass  # Read the server's validation error; never repeat the request automatically.
            proc.wait(timeout=600)
        except BaseException:
            proc.kill()
            proc.wait()
            raise
        output.seek(0)
        raw = output.read()
        errors.seek(0)
        err = errors.read(4096).decode(errors="replace")
        try:
            response = json.loads(raw)
        except (ValueError, UnicodeDecodeError):
            raise RuntimeError("Inget giltigt kvitto från Socialen. Kontrollera SSH och kör health. Ändringsanrop upprepas inte automatiskt. " + err.strip()) from None
        if not isinstance(response, dict) or "ok" not in response:
            raise RuntimeError("Ogiltigt svar från Socialen.")
        return response, proc.returncode


def main(argv=None):
    args = parser().parse_args(argv)
    try:
        cfg = load_config(args.config)
        request, source = build_request(args, cfg)
        response, code = transport(cfg, request, source)
        print(json.dumps(response, ensure_ascii=False, indent=2))
        return 0 if response.get("ok") and code == 0 else 2
    except (OSError, ValueError, RuntimeError, subprocess.TimeoutExpired) as exc:
        print(json.dumps({"ok": False, "error": str(exc)}, ensure_ascii=False), file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())
