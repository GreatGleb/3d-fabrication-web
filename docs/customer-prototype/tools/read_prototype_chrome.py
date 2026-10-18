#!/usr/bin/env python3
"""Open/read the AIQME prototype in the user's running Chrome via AT-SPI.

Requires system Python with gi/Atspi and a logged-in desktop session.
Does not extract cookies or inspect unrelated tabs.
"""
import argparse
import json
import subprocess

import gi

gi.require_version("Atspi", "2.0")
from gi.repository import Atspi, GLib

URL = "https://aiqme-engineering-prototype.aiqme-9193.chatgpt.site/lt/"


def walk(node):
    if node is None:
        return
    yield node
    try:
        children = [node.get_child_at_index(i) for i in range(node.get_child_count())]
    except GLib.Error:
        return
    for child in children:
        yield from walk(child)


def settle():
    loop = GLib.MainLoop()
    GLib.timeout_add(1200, lambda: (loop.quit(), False)[1])
    loop.run()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--open", action="store_true", help="Open the prototype in Chrome")
    args = parser.parse_args()
    # Never enable system accessibility or the screen reader automatically.
    if args.open:
        subprocess.run(["google-chrome", "--profile-directory=Default", URL],
                       check=True, stdout=subprocess.DEVNULL)
    settle()
    desktop = Atspi.get_desktop(0)
    apps = [desktop.get_child_at_index(i) for i in range(desktop.get_child_count())]
    chrome = next((a for a in apps if a and a.get_name() == "Google Chrome"), None)
    if chrome is None:
        raise SystemExit("Chrome is not available through AT-SPI.")
    pages = []
    for frame in [chrome.get_child_at_index(i) for i in range(chrome.get_child_count())]:
        if frame is None or "AIQME" not in frame.get_name():
            continue
        for doc in walk(frame):
            if doc.get_role_name() != "document web":
                continue
            if "AIQME" not in doc.get_name() and doc.get_name() != "DevTools":
                continue
            records = []
            for node in walk(doc):
                role, name = node.get_role_name(), node.get_name()
                if name and role in {"heading", "static", "link", "push button", "tree item"}:
                    records.append({"role": role, "text": name})
            pages.append({"title": doc.get_name(), "content": records})
    if not pages:
        raise SystemExit("No accessible AIQME document. Activate its tab and retry; a reload may be needed after enabling accessibility.")
    print(json.dumps(pages, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
