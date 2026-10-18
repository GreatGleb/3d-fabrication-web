"""AIQME client portal and static site server.

Run ``python3 server/app.py init`` first, then create clients and projects with
the CLI commands documented in README.md. The database and files live outside
the published build/server directory.
"""

import argparse
from datetime import datetime, timezone
from decimal import Decimal, InvalidOperation
from getpass import getpass
import os
from pathlib import Path
import re
import secrets
import shutil
import sqlite3
from uuid import uuid4

from flask import Flask, abort, g, jsonify, request, send_from_directory, session
from werkzeug.security import check_password_hash, generate_password_hash


ROOT = Path(__file__).resolve().parents[1]
STATUSES = ("Новый", "В работе", "На согласовании", "Готов", "Завершён")
MAX_ATTACHMENT = 100 * 1024 * 1024
MAX_LEAD_FILE = 25 * 1024 * 1024
LEAD_SERVICES = {"scan", "model", "polymer", "metal", "cnc", "unsure"}
LEAD_EXTENSIONS = {"jpg", "jpeg", "png", "webp", "pdf", "stl", "obj", "step", "stp"}


def utc_now():
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


def connect(path):
    db = sqlite3.connect(path)
    db.row_factory = sqlite3.Row
    db.execute("PRAGMA foreign_keys = ON")
    return db


def init_db(path):
    with connect(path) as db:
        db.executescript("""
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY,
                email TEXT NOT NULL UNIQUE COLLATE NOCASE,
                password_hash TEXT NOT NULL,
                session_version INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS projects (
                id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL REFERENCES users(id),
                title TEXT NOT NULL,
                status TEXT NOT NULL,
                summary TEXT NOT NULL DEFAULT '',
                updated_at TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS project_files (
                id INTEGER PRIMARY KEY,
                project_id INTEGER NOT NULL REFERENCES projects(id),
                original_name TEXT NOT NULL,
                stored_name TEXT NOT NULL UNIQUE,
                size INTEGER NOT NULL,
                created_at TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS login_attempts (
                id INTEGER PRIMARY KEY,
                identity TEXT NOT NULL,
                happened_at INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS login_attempts_identity ON login_attempts(identity, happened_at);
            CREATE TABLE IF NOT EXISTS leads (
                id INTEGER PRIMARY KEY,
                service TEXT NOT NULL,
                message TEXT NOT NULL,
                quantity INTEGER,
                name TEXT NOT NULL,
                email TEXT NOT NULL,
                phone TEXT NOT NULL DEFAULT '',
                attachment_name TEXT,
                stored_name TEXT,
                created_at TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS lead_attempts (
                id INTEGER PRIMARY KEY,
                identity TEXT NOT NULL,
                happened_at INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS lead_attempts_identity ON lead_attempts(identity, happened_at);
        """)


def create_app(config=None):
    app = Flask(__name__, static_folder=None)
    config = config or {}
    instance = Path(os.environ.get("AIQME_DATA_DIR", Path(config["DATABASE"]).parent if "DATABASE" in config else ROOT / "instance")).resolve()
    instance.mkdir(parents=True, exist_ok=True)
    secret_path = instance / "secret_key"
    if "SECRET_KEY" not in config and not secret_path.exists():
        try:
            with secret_path.open("x") as secret_file:
                os.chmod(secret_path, 0o600)
                secret_file.write(secrets.token_hex(32))
        except FileExistsError:
            pass
    app.config.update(
        SECRET_KEY=config.get("SECRET_KEY") or secret_path.read_text().strip(),
        DATABASE=str(instance / "portal.sqlite3"),
        STORAGE=str(instance / "files"),
        SITE_DIR=str(ROOT / "build/server"),
        SESSION_COOKIE_HTTPONLY=True,
        SESSION_COOKIE_SAMESITE="Lax",
        SESSION_COOKIE_SECURE=os.environ.get("AIQME_HTTPS") == "1",
        MAX_CONTENT_LENGTH=26 * 1024 * 1024,
    )
    if config:
        app.config.update(config)
    Path(app.config["STORAGE"]).mkdir(parents=True, exist_ok=True)
    init_db(app.config["DATABASE"])

    def db():
        if "db" not in g:
            g.db = connect(app.config["DATABASE"])
        return g.db

    @app.teardown_appcontext
    def close_db(_error):
        connection = g.pop("db", None)
        if connection is not None:
            connection.close()

    @app.after_request
    def headers(response):
        response.headers["X-Content-Type-Options"] = "nosniff"
        response.headers["Referrer-Policy"] = "strict-origin-when-cross-origin"
        response.headers["X-Frame-Options"] = "DENY"
        response.headers["Content-Security-Policy"] = "default-src 'self'; img-src 'self' data:; font-src 'self'; style-src 'self'; script-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'"
        if request.path.startswith("/api/"):
            response.headers["Cache-Control"] = "no-store"
        return response

    def csrf_token():
        if "csrf" not in session:
            session["csrf"] = secrets.token_urlsafe(32)
        return session["csrf"]

    def require_csrf():
        token = request.headers.get("X-CSRF-Token", "")
        if not token or not secrets.compare_digest(token, csrf_token()):
            abort(403)

    def current_user():
        user_id = session.get("user_id")
        if not isinstance(user_id, int):
            return None
        user = db().execute("SELECT id, email, session_version FROM users WHERE id = ?", (user_id,)).fetchone()
        return user if user and session.get("session_version") == user["session_version"] else None

    @app.get("/api/session")
    def api_session():
        user = current_user()
        return jsonify(authenticated=bool(user), email=user["email"] if user else None, csrf=csrf_token())

    @app.post("/api/login")
    def api_login():
        require_csrf()
        data = request.get_json(silent=True) or {}
        if not isinstance(data, dict):
            return jsonify(error="invalid_request"), 400
        email = str(data.get("email", "")).strip().lower()[:254]
        password = str(data.get("password", ""))
        identity = f"{request.remote_addr}:{email}"
        now = int(datetime.now(timezone.utc).timestamp())
        db().execute("DELETE FROM login_attempts WHERE happened_at < ?", (now - 900,))
        attempts = db().execute("SELECT COUNT(*) FROM login_attempts WHERE identity = ?", (identity,)).fetchone()[0]
        if attempts >= 5:
            db().commit()
            return jsonify(error="rate_limited"), 429
        user = db().execute("SELECT id, email, password_hash, session_version FROM users WHERE email = ?", (email,)).fetchone() if email else None
        if not user or not check_password_hash(user["password_hash"], password):
            db().execute("INSERT INTO login_attempts(identity, happened_at) VALUES (?, ?)", (identity, now))
            db().commit()
            return jsonify(error="invalid_credentials"), 401
        db().execute("DELETE FROM login_attempts WHERE identity = ?", (identity,))
        db().commit()
        session.clear()
        session["user_id"] = user["id"]
        session["session_version"] = user["session_version"]
        return jsonify(authenticated=True, email=user["email"], csrf=csrf_token())

    @app.post("/api/logout")
    def api_logout():
        require_csrf()
        session.clear()
        return jsonify(authenticated=False, csrf=csrf_token())

    @app.get("/api/projects")
    def api_projects():
        user = current_user()
        if not user:
            return jsonify(error="unauthorized"), 401
        projects = db().execute(
            "SELECT id, title, status, summary, updated_at FROM projects WHERE user_id = ? ORDER BY updated_at DESC, id DESC",
            (user["id"],),
        ).fetchall()
        result = []
        for project in projects:
            files = db().execute(
                "SELECT id, original_name, size FROM project_files WHERE project_id = ? ORDER BY id",
                (project["id"],),
            ).fetchall()
            result.append({**dict(project), "files": [dict(file) for file in files]})
        return jsonify(projects=result)

    @app.post("/api/leads")
    def api_leads():
        require_csrf()
        if request.form.get("website", "").strip():
            return jsonify(received=True), 201
        service = request.form.get("service", "").strip()
        message = request.form.get("message", "").strip()
        name = request.form.get("name", "").strip()
        email = request.form.get("email", "").strip().lower()
        phone = request.form.get("phone", "").strip()
        quantity_text = request.form.get("quantity", "").strip()
        if service not in LEAD_SERVICES or not 10 <= len(message) <= 3000 or not 1 <= len(name) <= 120 or not re.fullmatch(r"[^\s@]+@[^\s@]+\.[^\s@]+", email) or len(email) > 254 or len(phone) > 80:
            return jsonify(error="invalid_fields"), 400
        quantity = None
        if quantity_text:
            try:
                value = Decimal(quantity_text)
                if not value.is_finite() or not 1 <= value <= 10000 or value != value.to_integral_value():
                    raise ValueError("invalid quantity")
                quantity = int(value)
            except (InvalidOperation, ValueError):
                return jsonify(error="invalid_fields"), 400
        attachment = request.files.get("attachment")
        attachment_name = None
        contents = None
        if attachment and attachment.filename:
            attachment_name = Path(attachment.filename).name[:255]
            extension = attachment_name.rsplit(".", 1)[-1].lower() if "." in attachment_name else ""
            if extension not in LEAD_EXTENSIONS:
                return jsonify(error="invalid_file"), 400
            contents = attachment.stream.read(MAX_LEAD_FILE + 1)
            if len(contents) > MAX_LEAD_FILE:
                return jsonify(error="invalid_file"), 413
        identity = request.remote_addr or "unknown"
        now = int(datetime.now(timezone.utc).timestamp())
        db().execute("DELETE FROM lead_attempts WHERE happened_at < ?", (now - 3600,))
        attempts = db().execute("SELECT COUNT(*) FROM lead_attempts WHERE identity = ?", (identity,)).fetchone()[0]
        if attempts >= 5:
            db().commit()
            return jsonify(error="rate_limited"), 429
        stored_name = None
        if contents is not None:
            stored_name = uuid4().hex
            lead_directory = Path(app.config["STORAGE"]) / "leads"
            lead_directory.mkdir(parents=True, exist_ok=True)
            (lead_directory / stored_name).write_bytes(contents)
        try:
            cursor = db().execute("""
                INSERT INTO leads(service, message, quantity, name, email, phone, attachment_name, stored_name, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            """, (service, message, quantity, name, email, phone, attachment_name, stored_name, utc_now()))
            db().execute("INSERT INTO lead_attempts(identity, happened_at) VALUES (?, ?)", (identity, now))
            db().commit()
        except Exception:
            if stored_name:
                (Path(app.config["STORAGE"]) / "leads" / stored_name).unlink(missing_ok=True)
            raise
        return jsonify(received=True, id=cursor.lastrowid), 201

    @app.get("/api/files/<int:file_id>")
    def api_file(file_id):
        user = current_user()
        if not user:
            abort(401)
        file = db().execute("""
            SELECT f.original_name, f.stored_name FROM project_files f
            JOIN projects p ON p.id = f.project_id
            WHERE f.id = ? AND p.user_id = ?
        """, (file_id, user["id"])).fetchone()
        if not file:
            abort(404)
        response = send_from_directory(app.config["STORAGE"], file["stored_name"], as_attachment=True, download_name=file["original_name"])
        response.headers["Cache-Control"] = "private, no-store"
        return response

    @app.get("/")
    @app.get("/<path:page>")
    def static_site(page=""):
        site = Path(app.config["SITE_DIR"])
        target = site / page
        if target.is_dir():
            page = (Path(page) / "index.html").as_posix()
        return send_from_directory(site, page or "index.html")

    return app


def cli():
    parser = argparse.ArgumentParser(description="AIQME site and client portal")
    commands = parser.add_subparsers(dest="command", required=True)
    commands.add_parser("init", help="initialize private database")
    serve = commands.add_parser("serve", help="serve site and portal")
    serve.add_argument("--host", default="127.0.0.1")
    serve.add_argument("--port", type=int, default=8820)
    client = commands.add_parser("create-client", help="create a client login")
    client.add_argument("email")
    reset = commands.add_parser("set-password", help="replace a client password")
    reset.add_argument("email")
    project = commands.add_parser("add-project", help="add a project to a client")
    project.add_argument("email")
    project.add_argument("title")
    project.add_argument("--summary", default="")
    status = commands.add_parser("set-status", help="update project status")
    status.add_argument("project_id", type=int)
    status.add_argument("status", choices=STATUSES)
    attach = commands.add_parser("attach-file", help="attach a local file to a project")
    attach.add_argument("project_id", type=int)
    attach.add_argument("path", type=Path)
    commands.add_parser("list-leads", help="list incoming requests")
    show_lead = commands.add_parser("show-lead", help="show one request and its private attachment path")
    show_lead.add_argument("lead_id", type=int)
    args = parser.parse_args()
    app = create_app()
    if args.command == "serve":
        app.run(host=args.host, port=args.port, debug=False)
        return
    if args.command == "init":
        print(f"Database ready: {app.config['DATABASE']}")
        return
    with connect(app.config["DATABASE"]) as db:
        if args.command == "list-leads":
            for lead in db.execute("SELECT id, created_at, service, name, email FROM leads ORDER BY id DESC"):
                print(f"{lead['id']}  {lead['created_at']}  {lead['service']}  {lead['name']}  {lead['email']}")
            return
        if args.command == "show-lead":
            lead = db.execute("SELECT * FROM leads WHERE id = ?", (args.lead_id,)).fetchone()
            if not lead:
                parser.error("Заявка не найдена")
            for field in ("id", "created_at", "service", "name", "email", "phone", "quantity", "message", "attachment_name"):
                print(f"{field}: {lead[field] or '—'}")
            if lead["stored_name"]:
                print(f"attachment_path: {Path(app.config['STORAGE']) / 'leads' / lead['stored_name']}")
            return
        if args.command in ("create-client", "set-password"):
            password = getpass("Пароль клиента (минимум 12 символов): ")
            if len(password) < 12 or password != getpass("Повторите пароль: "):
                parser.error("Пароль слишком короткий или не совпадает")
            if args.command == "create-client":
                db.execute("INSERT INTO users(email, password_hash, created_at) VALUES (?, ?, ?)",
                           (args.email.strip().lower(), generate_password_hash(password), utc_now()))
                print("Клиент создан")
            else:
                cursor = db.execute("UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE email = ?",
                                    (generate_password_hash(password), args.email.strip().lower()))
                if not cursor.rowcount:
                    parser.error("Клиент не найден")
                print("Пароль обновлён")
        elif args.command == "add-project":
            user = db.execute("SELECT id FROM users WHERE email = ?", (args.email.strip().lower(),)).fetchone()
            if not user:
                parser.error("Клиент не найден")
            cursor = db.execute("INSERT INTO projects(user_id, title, status, summary, updated_at) VALUES (?, ?, ?, ?, ?)",
                                (user["id"], args.title, "Новый", args.summary, utc_now()))
            print(f"Проект создан: ID {cursor.lastrowid}")
        elif args.command == "set-status":
            cursor = db.execute("UPDATE projects SET status = ?, updated_at = ? WHERE id = ?",
                                (args.status, utc_now(), args.project_id))
            if not cursor.rowcount:
                parser.error("Проект не найден")
            print("Статус обновлён")
        elif args.command == "attach-file":
            project = db.execute("SELECT id FROM projects WHERE id = ?", (args.project_id,)).fetchone()
            source = args.path.resolve()
            if not project:
                parser.error("Проект не найден")
            if not source.is_file() or source.stat().st_size > MAX_ATTACHMENT:
                parser.error("Нужен существующий файл не больше 100 МБ")
            name = uuid4().hex
            destination = Path(app.config["STORAGE"]) / name
            shutil.copyfile(source, destination)
            db.execute("INSERT INTO project_files(project_id, original_name, stored_name, size, created_at) VALUES (?, ?, ?, ?, ?)",
                       (args.project_id, source.name, name, source.stat().st_size, utc_now()))
            db.execute("UPDATE projects SET updated_at = ? WHERE id = ?", (utc_now(), args.project_id))
            print("Файл прикреплён")


if __name__ == "__main__":
    cli()
