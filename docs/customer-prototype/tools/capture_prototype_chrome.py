#!/usr/bin/env python3
"""Capture the prototype using Chrome's user-approved local CDP session.

Enable chrome://inspect/#remote-debugging first. No accessibility settings,
cookies, passwords or request headers are exported. Only this page is captured.
"""
import argparse
import asyncio
import hashlib
import json
from datetime import datetime
from pathlib import Path
from urllib.parse import urlsplit

from playwright.async_api import async_playwright

URL = "https://aiqme-engineering-prototype.aiqme-9193.chatgpt.site/lt/"
ROOT = Path(__file__).resolve().parents[3]


async def capture(output):
    port, endpoint = (Path.home() / ".config/google-chrome/DevToolsActivePort").read_text().splitlines()
    async with async_playwright() as pw:
        browser = await pw.chromium.connect_over_cdp(f"ws://127.0.0.1:{port}{endpoint}", timeout=120000)
        context = next((c for c in browser.contexts if any(
            urlsplit(p.url).hostname == urlsplit(URL).hostname for p in c.pages
        )), None)
        if context is None:
            raise RuntimeError("Open the prototype in the intended Chrome profile first.")
        output.mkdir(parents=True, exist_ok=False)
        (output / "resources").mkdir()
        page = await context.new_page()
        records, tasks = [], []

        async def save_response(response):
            kind = response.request.resource_type
            if kind not in {"document", "stylesheet", "script"}:
                return
            record = {"url": response.url, "type": kind, "status": response.status}
            records.append(record)
            try:
                body = await response.body()
                digest = hashlib.sha256(body).hexdigest()
                extension = {"document": ".html", "stylesheet": ".css", "script": ".js"}[kind]
                name = "resources/" + digest + extension
                (output / name).write_bytes(body)
                record.update(file=name, bytes=len(body), sha256=digest)
            except Exception as exc:
                record["error"] = str(exc)

        page.on("response", lambda response: tasks.append(asyncio.create_task(save_response(response))))
        try:
            await page.set_viewport_size({"width": 1440, "height": 1000})
            response = await page.goto(URL, wait_until="networkidle", timeout=60000)
            if response is None or response.status != 200:
                raise RuntimeError(f"Prototype navigation failed: {response.status if response else 'no response'}")
            await page.evaluate("document.fonts.ready")
            await page.screenshot(path=str(output / "viewport.png"))
            await page.screenshot(path=str(output / "full-page.png"), full_page=True)
            (output / "dom.html").write_text(await page.content())
            info = await page.evaluate("""() => ({
                title: document.title, url: location.href,
                scripts: [...document.scripts].map(s => ({src:s.src, inline:!s.src})),
                stylesheets: [...document.styleSheets].map(s => s.href),
                headingFont: getComputedStyle(document.querySelector('h1') || document.body).fontFamily,
                bodyFont: getComputedStyle(document.body).fontFamily,
                dimensions: {width:innerWidth,height:innerHeight,scrollHeight:document.documentElement.scrollHeight}
            })""")
            await asyncio.gather(*tasks)
            info["resources"] = records
            info["scope"] = "Resources loaded on /lt/ at capture time; inline code is in dom.html. Unvisited routes and unloaded lazy chunks are not included."
            (output / "manifest.json").write_text(json.dumps(info, ensure_ascii=False, indent=2))
            print(json.dumps({"output": str(output), **info}, ensure_ascii=False, indent=2))
        finally:
            await page.close()
        # Do not close the user's browser; Playwright disconnects on context exit.


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path, default=ROOT / "docs" / "customer-prototype" / "evidence" / datetime.now().strftime("%Y%m%d-%H%M%S"))
    args = parser.parse_args()
    asyncio.run(capture(args.output))
