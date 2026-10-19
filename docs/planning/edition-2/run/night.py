#!/usr/bin/env python3
"""Ночной прогон AIQME: задачи tasks.json по порядку, одна сессия Claude Code на задачу.

Устроен как ночь Flexiron (D-39, вариант «г»): учётная запись и вход Claude пользователя,
основной checkout на ветке auto/night-*, коммит оркестратора на каждую принятую задачу.
Запускается из night-run.sh, который создаёт ветку и отключает push; напрямую — только --dry-run.

Задача принята, если агент вернул status=done, ветка и HEAD не тронуты, изменены только
разрешённые пути и aiqme-site/bin/check зелёный. Иначе работа уходит в git stash
«night-blocked W-xx», а зависящие от неё задачи пропускаются (rules[0]).
Выполненной считается задача, у которой в истории HEAD есть коммит «night: W-xx».
"""
import argparse
import json
import os
import re
import signal
import subprocess
import sys
import time
from datetime import datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
TASKS = ROOT / "docs/planning/edition-2/tasks.json"
ALLOWED = ("aiqme-site/", "docs/requirements.md")
PROGRESS = "aiqme-site/docs/progress.md"
FORBIDDEN = ("aiqme-site/experiments/",)
# Запреты действуют и в bypassPermissions (проверено 19.10). Коммит, ветки и stash — дело оркестратора.
DENY = [
    "Bash(git push:*)", "Bash(git commit:*)", "Bash(git switch:*)", "Bash(git checkout:*)",
    "Bash(git stash:*)", "Bash(git reset:*)", "Bash(git rebase:*)", "Bash(git merge:*)",
    "Bash(git remote:*)", "Bash(git config:*)", "Bash(gh:*)",
    "Bash(python3 docs/customer-prototype/tools/*)", "Bash(google-chrome:*)",
    "Read(./legacy-landing/instance/**)", "Read(./aiqme-site/experiments/**/.env)",
    "Edit(./legacy-landing/**)", "Edit(./aiqme-site/experiments/**)", "Edit(./AGENTS.md)",
    "Edit(./docs/planning/**)", "WebFetch", "WebSearch",
]
RESULT_RE = re.compile(r'\{[^{}]*"status"\s*:\s*"(done|blocked)"[^{}]*\}', re.S)

PROMPT = """Ты — ночной агент проекта AIQME. Человека рядом нет, вопросы задавать некому.

Выполни задачу {id} «{title}» из docs/planning/edition-2/tasks.json.
Сначала прочитай AGENTS.md, все rules в tasks.json, саму задачу {id} целиком и то, на что она ссылается
(разделы edition-2, decisions.md). Ход работы и итог веди в aiqme-site/docs/progress.md (rules[13]).
{extra}
Ограничения этой ночи:
- Не делай git commit, push, stash, reset, checkout и не меняй ветку: коммит после проверки сделает оркестратор.
- Меняй только aiqme-site/ (кроме experiments/) и, если это задача W-21, строку «Новый сайт» в docs/requirements.md.
- Если план противоречив или задачу нельзя выполнить без догадки — не гадай: запиши вопрос и причину
  в progress.md и заверши задачу со статусом blocked.
- Перед завершением прогони aiqme-site/bin/check (если он уже есть) и проверки из checks задачи.

Последней строкой ответа выведи ровно один JSON-объект без разметки:
{{"status": "done" или "blocked", "summary": "что сделано, 1–3 предложения", "reason": "почему blocked, иначе пусто"}}
"""
W01_EXTRA = "Первый шаг W-01 — bash docs/planning/edition-2/run/preflight.sh (без перенаправления). При FAIL — status blocked.\n"


def git(*args, check=True):
    return subprocess.run(["git", "-C", str(ROOT), *args], check=check, text=True,
                          capture_output=True).stdout.strip()


def newest_claude():
    if os.environ.get("AIQME_CLAUDE"):  # подмена для тестов оркестратора
        return os.environ["AIQME_CLAUDE"]
    found = []
    for binary in (Path.home() / ".vscode/extensions").glob("anthropic.claude-code-*/resources/native-binary/claude"):
        m = re.match(r"anthropic\.claude-code-(\d+(?:\.\d+)*)", binary.parents[2].name)
        if m and os.access(binary, os.X_OK):
            found.append((tuple(int(p) for p in m.group(1).split(".")), str(binary)))
    return max(found)[1] if found else "claude"


def done_tasks():
    log = git("log", "--format=%s", check=False)
    return set(re.findall(r"^night: (W-\d+)", log, re.M))


def changed_paths():
    out = subprocess.run(["git", "-C", str(ROOT), "status", "--porcelain", "-z", "--untracked-files=all"],
                         text=True, capture_output=True, check=True).stdout
    paths, items = [], out.split("\0")
    i = 0
    while i < len(items):
        entry = items[i]
        if not entry:
            i += 1
            continue
        status, path = entry[:2], entry[3:]
        paths.append(path)
        if "R" in status or "C" in status:
            i += 1  # у переименования следом идёт старый путь
        i += 1
    return paths


def allowed(path):
    return path.startswith(ALLOWED) and not path.startswith(FORBIDDEN)


def run_limited(argv, timeout, stdin_text=None, log=None, cwd=ROOT):
    """Запуск с таймаутом; по истечении убивается вся группа процессов."""
    with open(log, "w") if log else open(os.devnull, "w") as out:
        proc = subprocess.Popen(argv, cwd=cwd, stdin=subprocess.PIPE, stdout=out, stderr=subprocess.STDOUT,
                                text=True, start_new_session=True)
        try:
            proc.communicate(stdin_text, timeout=timeout)
            return proc.returncode
        except subprocess.TimeoutExpired:
            os.killpg(proc.pid, signal.SIGKILL)
            proc.wait()
            return None


class Night:
    def __init__(self, out, hours, task_minutes, model, only):
        self.out = out
        self.deadline = time.time() + hours * 3600
        self.task_seconds = task_minutes * 60
        self.model = model
        self.only = only
        self.journal = out / "journal.jsonl"
        self.blocked = set()

    def note(self, **record):
        record["time"] = datetime.now().isoformat(timespec="seconds")
        with open(self.journal, "a") as f:
            f.write(json.dumps(record, ensure_ascii=False) + "\n")
        print(f"[{record['time']}] {record.get('task', '')} {record['event']}: {record.get('detail', '')}", flush=True)

    def stash(self, task, why):
        """Работа задачи — в stash; заметки progress.md остаются в ветке отдельным коммитом."""
        progress = ROOT / PROGRESS
        notes = progress.read_text() if progress.exists() else ""
        if changed_paths():
            git("stash", "push", "-u", "-m", f"night-blocked {task}")
        progress.parent.mkdir(parents=True, exist_ok=True)
        stamp = datetime.now().strftime("%Y-%m-%d %H:%M")
        progress.write_text(notes + f"\n\n## {task}: заблокирована ночным прогоном ({stamp})\n\n"
                            f"Причина (оркестратор): {why}. Работа — в git stash «night-blocked {task}».\n")
        git("add", "--", PROGRESS)
        git("commit", "-q", "-m", f"night-note: {task} заблокирована")
        self.blocked.add(task)
        self.note(task=task, event="blocked", detail=why)

    def agent(self, task):
        prompt = PROMPT.format(id=task["id"], title=task["title"],
                               extra=W01_EXTRA if task["id"] == "W-01" else "")
        (self.out / f"{task['id']}.prompt.txt").write_text(prompt)
        argv = [newest_claude(), "--print", "--output-format", "json", "--add-dir", str(ROOT),
                "--permission-mode", "bypassPermissions", "--disallowedTools", *DENY]
        if self.model:
            argv += ["--model", self.model]
        timeout = min(self.task_seconds, max(60, self.deadline - time.time()))
        log = self.out / f"{task['id']}.stdout.json"
        code = run_limited(argv, timeout, prompt, log)
        if code is None:
            return None, f"таймаут сессии ({int(timeout // 60)} мин)"
        try:
            printed = json.loads(log.read_text())
        except json.JSONDecodeError:
            return None, f"нет JSON сессии (код {code}), см. {log.name}"
        if printed.get("is_error"):
            return "provider", str(printed.get("result", ""))[:300]
        matches = list(RESULT_RE.finditer(printed.get("result", "")))
        if not matches:
            return None, "агент не вернул JSON-итог"
        try:
            result = json.loads(matches[-1].group(0))
        except json.JSONDecodeError:
            return None, "JSON-итог агента не читается"
        return result, printed.get("modelUsage")

    def check(self, task):
        check = ROOT / "aiqme-site/bin/check"
        if not check.exists():
            return False, "aiqme-site/bin/check нет (его создаёт W-01)"
        code = run_limited([str(check)], 30 * 60, log=self.out / f"{task['id']}.check.log", cwd=ROOT / "aiqme-site")
        return code == 0, f"bin/check: {'зелёный' if code == 0 else 'красный' if code is not None else 'таймаут'}"

    def run(self, tasks):
        done = done_tasks()
        branch, head = git("branch", "--show-current"), git("rev-parse", "HEAD")
        for task in tasks:
            tid = task["id"]
            if self.only and tid not in self.only:
                continue
            if tid in done:
                continue
            if task["track"] != "L":
                self.note(task=tid, event="stop", detail=f"уровень {task['track']} — только с человеком (rules[1])")
                return 0
            bad = [d for d in task["depends"] if d in self.blocked or (d not in done and not self.only)]
            if bad:
                self.blocked.add(tid)
                self.note(task=tid, event="skipped", detail=f"пропущено: зависит от заблокированной {', '.join(bad)}")
                continue
            if self.deadline - time.time() < 20 * 60:
                self.note(task=tid, event="stop", detail="до конца ночи меньше 20 минут")
                return 0
            self.note(task=tid, event="start", detail=task["title"])
            result, info = self.agent(task)
            if result == "provider":
                self.stash(tid, f"ошибка Claude: {info}")
                self.note(task=tid, event="stop", detail="ошибка сервиса — ночь остановлена")
                return 1
            if git("branch", "--show-current") != branch or git("rev-parse", "HEAD") != head:
                self.note(task=tid, event="stop", detail="агент сменил ветку или HEAD — ночь остановлена, смотрите вручную")
                return 1
            if result is None:
                self.stash(tid, info)
                head = git("rev-parse", "HEAD")
                continue
            self.note(task=tid, event="agent", detail=f"{result.get('status')}: {result.get('summary', '')}",
                      reason=result.get("reason", ""), usage=info)
            outside = [p for p in changed_paths() if not allowed(p)]
            if outside:
                self.stash(tid, "изменены пути вне aiqme-site/: " + ", ".join(outside[:10]))
                head = git("rev-parse", "HEAD")
                continue
            if result.get("status") != "done":
                self.stash(tid, result.get("reason") or "агент вернул blocked")
                head = git("rev-parse", "HEAD")
                continue
            ok, detail = self.check(task)
            if not ok:
                self.stash(tid, detail)
                head = git("rev-parse", "HEAD")
                continue
            git("add", "-A", "--", *ALLOWED)
            git("commit", "-q", "-m", f"night: {tid} {task['title']}\n\n{result.get('summary', '')}")
            head = git("rev-parse", "HEAD")
            done.add(tid)
            self.note(task=tid, event="committed", detail=f"{detail}; {head[:8]}")
        return 0

    def report(self):
        lines = [f"# Ночь {self.out.name}", "", f"Ветка: `{git('branch', '--show-current')}`", ""]
        if self.journal.exists():
            for line in self.journal.read_text().splitlines():
                r = json.loads(line)
                if r["event"] in ("committed", "blocked", "skipped", "stop"):
                    lines.append(f"- **{r.get('task', '')}** {r['event']}: {r.get('detail', '')}")
        lines += ["", "Заблокированная работа — в `git stash list` (night-blocked W-xx); подробности — progress.md и логи рядом."]
        (self.out / "СВОДКА.md").write_text("\n".join(lines) + "\n")


def main():
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    ap.add_argument("--out", type=Path, help="каталог ночи (журнал, логи сессий, сводка)")
    ap.add_argument("--hours", type=float, default=7)
    ap.add_argument("--task-minutes", type=int, default=120, help="предел одной сессии (rules[2]: час без прогресса — стоп)")
    ap.add_argument("--model", default="", help="модель Claude; по умолчанию — модель аккаунта")
    ap.add_argument("--only", nargs="*", default=[], help="только эти задачи (пробный запуск, например W-01)")
    ap.add_argument("--dry-run", action="store_true", help="показать, что будет выполнено, без запуска")
    args = ap.parse_args()

    tasks = json.loads(TASKS.read_text())["tasks"]
    if args.dry_run:
        done = done_tasks()
        print("claude:", newest_claude())
        for t in tasks:
            if args.only and t["id"] not in args.only:
                continue
            state = "выполнена" if t["id"] in done else "к выполнению" if t["track"] == "L" else f"стоп ({t['track']})"
            print(f"{t['id']:5} {state:13} {t['title']}")
        return 0
    if not args.out:
        ap.error("--out обязателен (его задаёт night-run.sh)")
    if not git("branch", "--show-current").startswith("auto/night-"):
        sys.exit("Ночь идёт только на ветке auto/night-* (её создаёт night-run.sh)")
    args.out.mkdir(parents=True, exist_ok=True)
    night = Night(args.out, args.hours, args.task_minutes, args.model, set(args.only))
    try:
        return night.run(tasks)
    finally:
        night.report()


if __name__ == "__main__":
    sys.exit(main())
