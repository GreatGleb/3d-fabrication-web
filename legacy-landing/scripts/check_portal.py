"""Check authentication and isolation of client projects and files."""

import json
from io import BytesIO
from pathlib import Path
import sys
from tempfile import TemporaryDirectory
from threading import Thread

from playwright.sync_api import expect, sync_playwright
from werkzeug.security import generate_password_hash
from werkzeug.serving import make_server


ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from server.app import connect, create_app, utc_now  # noqa: E402


def check():
    with TemporaryDirectory(prefix="aiqme-portal-") as temp:
        root = Path(temp)
        app = create_app({
            "TESTING": True,
            "SECRET_KEY": "test-only-secret-key",
            "DATABASE": str(root / "portal.sqlite3"),
            "STORAGE": str(root / "files"),
        })
        with connect(app.config["DATABASE"]) as db:
            for email in ("one@example.test", "two@example.test"):
                db.execute("INSERT INTO users(email, password_hash, created_at) VALUES (?, ?, ?)",
                           (email, generate_password_hash("correct-password"), utc_now()))
            for user_id in (1, 2):
                db.execute("INSERT INTO projects(user_id, title, status, summary, updated_at) VALUES (?, ?, ?, ?, ?)",
                           (user_id, f"Project {user_id}", "В работе", "Summary", utc_now()))
                db.execute("INSERT INTO project_files(project_id, original_name, stored_name, size, created_at) VALUES (?, ?, ?, ?, ?)",
                           (user_id, f"result-{user_id}.stl", f"stored-{user_id}", 4, utc_now()))
                (root / "files" / f"stored-{user_id}").write_bytes(b"data")

        client = app.test_client()
        session = client.get("/api/session").json
        assert not session["authenticated"]
        assert client.get("/api/projects").status_code == 401
        assert client.get("/api/files/1").status_code == 401
        assert client.post("/api/login", json={"email": "one@example.test", "password": "correct-password"}).status_code == 403
        csrf = session["csrf"]
        bad = client.post("/api/login", json={"email": "one@example.test", "password": "wrong"}, headers={"X-CSRF-Token": csrf})
        assert bad.status_code == 401
        logged_in = client.post("/api/login", json={"email": "one@example.test", "password": "correct-password"}, headers={"X-CSRF-Token": csrf})
        assert logged_in.status_code == 200
        csrf = logged_in.json["csrf"]
        assert csrf != session["csrf"]
        projects = client.get("/api/projects").json["projects"]
        assert [project["title"] for project in projects] == ["Project 1"]
        assert client.get("/api/files/1").data == b"data"
        assert client.get("/api/files/2").status_code == 404
        assert client.post("/api/logout").status_code == 403
        assert client.post("/api/logout", headers={"X-CSRF-Token": csrf}).status_code == 200
        assert client.get("/api/projects").status_code == 401
        limited = app.test_client()
        limit_csrf = limited.get("/api/session").json["csrf"]
        for _ in range(5):
            response = limited.post("/api/login", json={"email": "two@example.test", "password": "wrong"}, headers={"X-CSRF-Token": limit_csrf})
            assert response.status_code == 401
        assert limited.post("/api/login", json={"email": "two@example.test", "password": "correct-password"}, headers={"X-CSRF-Token": limit_csrf}).status_code == 429
        assert client.get("/login/").status_code == 200
        assert client.get("/assets/portal.js").status_code == 200
        lead_client = app.test_client()
        lead_csrf = lead_client.get("/api/session").json["csrf"]
        lead_data = {
            "service": "model", "message": "Нужна модель детали по фотографии", "quantity": "2",
            "name": "Тестовый клиент", "email": "lead@example.test", "phone": "+37060000000",
            "attachment": (BytesIO(b"image-bytes"), "part.jpg"),
        }
        assert lead_client.post("/api/leads", data=lead_data, content_type="multipart/form-data").status_code == 403
        lead_data["attachment"] = (BytesIO(b"image-bytes"), "part.jpg")
        saved = lead_client.post("/api/leads", data=lead_data, content_type="multipart/form-data", headers={"X-CSRF-Token": lead_csrf})
        assert saved.status_code == 201
        with connect(app.config["DATABASE"]) as db:
            lead = db.execute("SELECT * FROM leads WHERE id = ?", (saved.json["id"],)).fetchone()
            assert lead["service"] == "model" and lead["quantity"] == 2
            assert (root / "files" / "leads" / lead["stored_name"]).read_bytes() == b"image-bytes"
        server = make_server("127.0.0.1", 0, app, threaded=True)
        thread = Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            with sync_playwright() as playwright:
                chrome = Path("/usr/bin/google-chrome")
                options = {"args": ["--no-sandbox"]}
                if chrome.exists():
                    options["executable_path"] = str(chrome)
                browser = playwright.chromium.launch(**options)
                page = browser.new_page(viewport={"width": 390, "height": 844})
                page.goto(f"http://127.0.0.1:{server.server_port}/login/")
                page.locator("#login-email").fill("one@example.test")
                page.locator("#login-password").fill("correct-password")
                page.locator("#login-form button[type=submit]").click()
                expect(page.get_by_role("heading", name="Мои проекты")).to_be_visible()
                expect(page.get_by_role("heading", name="Project 1")).to_be_visible()
                assert page.get_by_role("heading", name="Project 2").count() == 0
                with page.expect_download() as download:
                    page.get_by_role("link", name="Скачать файл: result-1.stl").click()
                assert download.value.suggested_filename == "result-1.stl"
                page.get_by_role("button", name="Выйти").click()
                expect(page.get_by_role("heading", name="Вход")).to_be_visible()
                page.goto(f"http://127.0.0.1:{server.server_port}/")
                page.locator('input[name="service"][value="model"] + span').click()
                page.locator('.lead-step[data-step="0"] .lead-next').click()
                page.locator('#lead-message').fill('Нужна модель корпуса по фотографии')
                page.locator('.lead-step[data-step="1"] .lead-next').click()
                page.locator('#lead-name').fill('Иван')
                page.locator('#lead-email').fill('ivan@example.test')
                page.locator('#lead-form button[type=submit]').click()
                expect(page.get_by_role('heading', name='Спасибо! Заявка получена.')).to_be_visible()
                browser.close()
        finally:
            server.shutdown()
            server.server_close()
        with connect(app.config["DATABASE"]) as db:
            assert db.execute("SELECT COUNT(*) FROM leads").fetchone()[0] == 2
        print(json.dumps({"status": "passed", "checks": ["CSRF", "login", "rate limit", "logout", "project isolation", "file isolation", "lead storage", "browser cabinet", "browser form"]}))


if __name__ == "__main__":
    check()
