#!/usr/bin/env python3
"""Ночной прогон AIQME: задачи tasks.json по порядку, автор и приёмщик Claude Code на каждую задачу.

Устроен как ночь Flexiron (D-39, вариант «г»): учётная запись и вход Claude пользователя,
основной checkout на ветке auto/night-*, коммит оркестратора на каждую принятую задачу.
Запускается из night-run.sh, который создаёт ветку и отключает push; напрямую — только --dry-run.

Задача принята, если автор вернул status=done, ветка и HEAD не тронуты, изменены только
разрешённые пути, aiqme-site/bin/check зелёный и приёмщик (отдельная сессия только для чтения)
подтвердил критерии «готово». Замечания приёмщика уходят автору на доработку (--fix-rounds);
не принятая работа — в git stash «night-blocked W-xx», зависящие задачи пропускаются (rules[0]).
Выполненной считается задача, у которой в истории HEAD есть коммит «night: W-xx».

Потолок токенов ночи — доля недельного лимита (по умолчанию 15 %). Считаются вход, выход и запись
кэша, без чтения кэша: так считает недельный лимит (замер Flexiron 23.09: 2 % ≈ 2,4 млн из 120 млн).
Каждый вердикт приёмщика пишется в review-stats.jsonl рядом с ночами — по нему решаем, где приёмка нужна.
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
# Запреты автора действуют и в bypassPermissions (проверено 19.10). Коммит, ветки и stash — дело оркестратора.
DENY = [
    "Bash(git push:*)", "Bash(git commit:*)", "Bash(git switch:*)", "Bash(git checkout:*)",
    "Bash(git stash:*)", "Bash(git reset:*)", "Bash(git rebase:*)", "Bash(git merge:*)",
    "Bash(git remote:*)", "Bash(git config:*)", "Bash(gh:*)",
    "Bash(python3 docs/customer-prototype/tools/*)", "Bash(google-chrome:*)",
    "Read(./legacy-landing/instance/**)", "Read(./aiqme-site/experiments/**/.env)",
    "Edit(./legacy-landing/**)", "Edit(./aiqme-site/experiments/**)", "Edit(./AGENTS.md)",
    "Edit(./docs/planning/**)", "WebFetch", "WebSearch",
]
# Приёмщик: --restricted убирает Bash и прочий запуск кода; запись запрещена явно.
REVIEW_DENY = ["Write", "Edit", "NotebookEdit", "Read(./legacy-landing/instance/**)",
               "Read(./aiqme-site/experiments/**/.env)", "WebFetch", "WebSearch"]

WORK_RE = re.compile(r'\{[^{}]*"status"\s*:\s*"(done|blocked)"[^{}]*\}', re.S)
REVIEW_RE = re.compile(r'\{(?:[^{}]|\{[^{}]*\})*"verdict"\s*:\s*"(accept|reject)"(?:[^{}]|\{[^{}]*\})*\}', re.S)

WORK_PROMPT = """Ты — ночной агент проекта AIQME. Человека рядом нет, вопросы задавать некому.

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
- После тебя работу проверит отдельный приёмщик по критериям done задачи: тесты должны проверять поведение,
  а не только проходить; непроверенное честно отметь в progress.md.

Последней строкой ответа выведи ровно один JSON-объект без разметки:
{{"status": "done" или "blocked", "summary": "что сделано, 1–3 предложения", "reason": "почему blocked, иначе пусто"}}
"""
W01_EXTRA = "Первый шаг W-01 — bash docs/planning/edition-2/run/preflight.sh (без перенаправления). При FAIL — status blocked.\n"
FIX_EXTRA = """
Это доработка: работа над {id} уже лежит в дереве (не начинай заново). Приёмщик её не принял.
Устрани каждое замечание ниже или, если замечание неверно, объясни это в progress.md со ссылкой на план:
{findings}
"""

REVIEW_PROMPT = """Ты — приёмщик ночного прогона AIQME. Ты только читаешь: файлы не меняешь, команды не запускаешь.

Автор выполнил задачу {id} «{title}» из docs/planning/edition-2/tasks.json. Реши, принять ли работу.
Прочитай: rules в tasks.json, задачу {id} целиком (особенно done и checks) и то, на что она ссылается;
изменения задачи — {diff} (git diff); вывод aiqme-site/bin/check — {check}; заметки автора — aiqme-site/docs/progress.md.
Читай изменённые файлы в дереве, если diff недостаточно.

Проверь по каждому критерию done: выполнен ли он на самом деле. Особенно ищи:
- ложно-зелёные проверки: тест проходит и при неработающей функции, проверка заглушена или пропущена;
- критерий объявлен выполненным в progress.md, но в коде его нет;
- отступления от плана и решений D-xx, выдуманные тексты и значения вместо указанных планом;
- нарушения rules (публичный репозиторий, секреты, медиа, демо-надписи).
Не придирайся к стилю и к тому, чего задача не требует. Замечание — только с доказательством (файл и место).

Последней строкой ответа выведи ровно один JSON-объект без разметки:
{{"verdict": "accept" или "reject", "findings": [{{"criterion": "done[N] или rules[N]", "problem": "что не так", "where": "файл:строка"}}], "summary": "1–2 предложения"}}
При accept findings — пустой список или только мелочи, которые не мешают принять.
"""


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
    paths, items, i = [], out.split("\0"), 0
    while i < len(items):
        entry = items[i]
        if entry:
            paths.append(entry[3:])
            if "R" in entry[:2] or "C" in entry[:2]:
                i += 1  # у переименования следом идёт старый путь
        i += 1
    return paths


def tree_fingerprint():
    """Снимок рабочего дерева: приёмщик не должен его менять."""
    return git("status", "--porcelain", "--untracked-files=all") + git("diff", "HEAD")


def allowed(path):
    return path.startswith(ALLOWED) and not path.startswith(FORBIDDEN)


def counted_tokens(usage):
    """Вход, выход и запись кэша — то, что засчитывает недельный лимит; чтение кэша не считается."""
    return sum(int(m.get("inputTokens", 0)) + int(m.get("outputTokens", 0))
               + int(m.get("cacheCreationInputTokens", 0)) for m in usage.values())


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


class ProviderError(Exception):
    """Сервис Claude отказал (лимит, сеть, вход) — ночь останавливается."""


class BudgetUnknown(Exception):
    """Сессия не сообщила расход токенов — потолок не проверить, ночь останавливается."""


class Night:
    def __init__(self, out, args):
        self.out = out
        self.deadline = time.time() + args.hours * 3600
        self.task_seconds = args.task_minutes * 60
        self.model = args.model
        self.only = set(args.only)
        self.budget = int(args.weekly_tokens * args.night_share)
        self.fix_rounds = args.fix_rounds
        self.review = args.review
        self.journal = out / "journal.jsonl"
        self.stats = out.parent / "review-stats.jsonl"
        self.blocked = set()
        self.spent = 0
        self.task_costs = []

    def note(self, **record):
        record["time"] = datetime.now().isoformat(timespec="seconds")
        with open(self.journal, "a") as f:
            f.write(json.dumps(record, ensure_ascii=False) + "\n")
        print(f"[{record['time']}] {record.get('task', '')} {record['event']}: {record.get('detail', '')}", flush=True)

    def session(self, task, name, argv, prompt):
        """Одна сессия Claude; возвращает (текст результата, учтённые токены)."""
        (self.out / f"{name}.prompt.txt").write_text(prompt)
        if self.model:
            argv += ["--model", self.model]
        timeout = min(self.task_seconds, max(60, self.deadline - time.time()))
        log = self.out / f"{name}.stdout.json"
        code = run_limited(argv, timeout, prompt, log)
        if code is None:
            return None, 0, f"таймаут сессии ({int(timeout // 60)} мин)"
        try:
            printed = json.loads(log.read_text())
        except json.JSONDecodeError:
            return None, 0, f"нет JSON сессии (код {code}), см. {log.name}"
        usage = printed.get("modelUsage")
        if not isinstance(usage, dict) or not usage:
            raise BudgetUnknown(f"{name}: нет modelUsage — расход не учесть")
        tokens = counted_tokens(usage)
        self.spent += tokens
        if printed.get("is_error"):
            raise ProviderError(str(printed.get("result", ""))[:300])
        return printed.get("result", ""), tokens, ""

    def work(self, task, rnd, findings):
        extra = W01_EXTRA if task["id"] == "W-01" else ""
        if findings:
            extra += FIX_EXTRA.format(id=task["id"], findings=json.dumps(findings, ensure_ascii=False, indent=1))
        prompt = WORK_PROMPT.format(id=task["id"], title=task["title"], extra=extra)
        argv = [newest_claude(), "--print", "--output-format", "json", "--add-dir", str(ROOT),
                "--permission-mode", "bypassPermissions", "--disallowedTools", *DENY]
        text, tokens, err = self.session(task, f"{task['id']}.work{rnd}", argv, prompt)
        if text is None:
            return None, tokens, err
        matches = list(WORK_RE.finditer(text))
        if not matches:
            return None, tokens, "автор не вернул JSON-итог"
        try:
            return json.loads(matches[-1].group(0)), tokens, ""
        except json.JSONDecodeError:
            return None, tokens, "JSON-итог автора не читается"

    def judge(self, task, rnd, check_log):
        """Приёмщик только читает; вердикт accept/reject с замечаниями."""
        git("add", "-A", "--", *ALLOWED)  # новые файлы попадают в diff; коммит или stash — после
        diff = self.out / f"{task['id']}.review{rnd}.diff"
        diff.write_text(git("diff", "--cached", "HEAD"))
        before = tree_fingerprint()
        prompt = REVIEW_PROMPT.format(id=task["id"], title=task["title"], diff=diff, check=check_log)
        argv = [newest_claude(), "--print", "--output-format", "json", "--add-dir", str(ROOT),
                "--add-dir", str(self.out), "--restricted", "--disallowedTools", *REVIEW_DENY]
        text, tokens, err = self.session(task, f"{task['id']}.review{rnd}", argv, prompt)
        if tree_fingerprint() != before:
            return {"verdict": "reject", "findings": [], "summary": "приёмщик изменил дерево"}, tokens, "tampered"
        if text is None:
            return None, tokens, err
        matches = list(REVIEW_RE.finditer(text))
        try:
            return json.loads(matches[-1].group(0)), tokens, ""
        except (IndexError, json.JSONDecodeError):
            return None, tokens, "приёмщик не вернул JSON-вердикт"

    def check(self, task):
        check = ROOT / "aiqme-site/bin/check"
        log = self.out / f"{task['id']}.check.log"
        if not check.exists():
            log.write_text("aiqme-site/bin/check нет\n")
            return False, "aiqme-site/bin/check нет (его создаёт W-01)", log
        code = run_limited([str(check)], 30 * 60, log=log, cwd=ROOT / "aiqme-site")
        return code == 0, f"bin/check: {'зелёный' if code == 0 else 'красный' if code is not None else 'таймаут'}", log

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

    def stat(self, task, rnd, verdict, tokens, outcome=None):
        with open(self.stats, "a") as f:
            f.write(json.dumps({"night": self.out.name, "task": task, "round": rnd, "verdict": verdict.get("verdict"),
                                "findings": verdict.get("findings", []), "summary": verdict.get("summary", ""),
                                "review_tokens": tokens, "outcome": outcome}, ensure_ascii=False) + "\n")

    def reviewed(self, task):
        return self.review == "all" or (self.review != "off" and task["id"] in self.review.split(","))

    def one_task(self, task, branch):
        """Автор → проверки → приёмщик → доработка; возвращает итог для журнала."""
        tid, findings, cost = task["id"], None, {"work": 0, "review": 0}
        for rnd in range(self.fix_rounds + 1):
            head = git("rev-parse", "HEAD")
            result, tokens, err = self.work(task, rnd, findings)
            cost["work"] += tokens
            if git("branch", "--show-current") != branch or git("rev-parse", "HEAD") != head:
                raise ProviderError("автор сменил ветку или HEAD — смотрите вручную")
            if result is None:
                return "blocked", err, cost
            self.note(task=tid, event=f"work{rnd}", detail=f"{result.get('status')}: {result.get('summary', '')}",
                      reason=result.get("reason", ""), tokens=tokens)
            outside = [p for p in changed_paths() if not allowed(p)]
            if outside:
                return "blocked", "изменены пути вне aiqme-site/: " + ", ".join(outside[:10]), cost
            if result.get("status") != "done":
                return "blocked", result.get("reason") or "автор вернул blocked", cost
            ok, detail, check_log = self.check(task)
            if not ok:
                return "blocked", detail, cost
            if not self.reviewed(task):
                return "accepted", detail + "; без приёмки", cost
            verdict, tokens, err = self.judge(task, rnd, check_log)
            cost["review"] += tokens
            if verdict is None:
                return "blocked", err, cost
            final = verdict["verdict"] == "accept" or rnd == self.fix_rounds
            self.stat(tid, rnd, verdict, tokens,
                      outcome=("accepted" if verdict["verdict"] == "accept" else "blocked") if final else "fix")
            self.note(task=tid, event=f"review{rnd}", detail=f"{verdict['verdict']}: {verdict.get('summary', '')}",
                      findings=len(verdict.get("findings", [])), tokens=tokens)
            if err == "tampered":
                return "blocked", "приёмщик изменил дерево", cost
            if verdict["verdict"] == "accept":
                return "accepted", detail + f"; приёмка с раунда {rnd}", cost
            findings = verdict.get("findings", [])
        return "blocked", f"приёмщик не принял после {self.fix_rounds} доработок: " + \
            "; ".join(f.get("problem", "") for f in (findings or [])[:5]), cost

    def run(self, tasks):
        done = done_tasks()
        branch = git("branch", "--show-current")
        self.note(event="budget", detail=f"потолок ночи {self.budget:_} токенов".replace("_", " "))
        for task in tasks:
            tid = task["id"]
            if (self.only and tid not in self.only) or tid in done:
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
            expected = max(self.task_costs) if self.task_costs else 0
            if self.spent + expected > self.budget:
                self.note(task=tid, event="stop", detail=(f"потолок токенов: потрачено {self.spent:_}, "
                          f"следующая задача ≈ {expected:_}, потолок {self.budget:_}").replace("_", " "))
                return 0
            self.note(task=tid, event="start", detail=task["title"])
            try:
                outcome, detail, cost = self.one_task(task, branch)
            except (ProviderError, BudgetUnknown) as error:
                self.stash(tid, f"ночь остановлена: {error}")
                return 1
            self.task_costs.append(cost["work"] + cost["review"])
            if outcome == "accepted":
                git("add", "-A", "--", *ALLOWED)
                git("commit", "-q", "-m", f"night: {tid} {task['title']}\n\n{detail}")
                done.add(tid)
                self.note(task=tid, event="committed", detail=f"{detail}; {git('rev-parse', '--short', 'HEAD')}", cost=cost)
            else:
                self.stash(tid, detail)
                self.note(task=tid, event="cost", detail="", cost=cost)
        return 0

    def report(self):
        lines = [f"# Ночь {self.out.name}", "", f"Ветка: `{git('branch', '--show-current')}`",
                 f"Токены (вход + выход + запись кэша): {self.spent:_} из потолка {self.budget:_}".replace("_", " "), ""]
        if self.journal.exists():
            for line in self.journal.read_text().splitlines():
                r = json.loads(line)
                if r["event"] in ("committed", "blocked", "skipped", "stop") or r["event"].startswith("review"):
                    extra = f" (токены: {r['cost']})" if "cost" in r else ""
                    lines.append(f"- **{r.get('task', '')}** {r['event']}: {r.get('detail', '')}{extra}")
        lines += ["", "Заблокированная работа — в `git stash list` (night-blocked W-xx); подробности — progress.md и логи рядом.",
                  f"Вердикты приёмщика по всем ночам — `{self.stats}`."]
        (self.out / "СВОДКА.md").write_text("\n".join(lines) + "\n")


def main():
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    ap.add_argument("--out", type=Path, help="каталог ночи (журнал, логи сессий, сводка)")
    ap.add_argument("--hours", type=float, default=7)
    ap.add_argument("--task-minutes", type=int, default=120, help="предел одной сессии (rules[2])")
    ap.add_argument("--model", default="", help="модель Claude; по умолчанию — модель аккаунта")
    ap.add_argument("--weekly-tokens", type=int, default=120_000_000, help="недельный лимит в учитываемых токенах")
    ap.add_argument("--night-share", type=float, default=0.15, help="доля недельного лимита на ночь")
    ap.add_argument("--review", default="all", help="приёмка: all, off или список задач через запятую")
    ap.add_argument("--fix-rounds", type=int, default=1, help="доработок автора по замечаниям приёмщика")
    ap.add_argument("--only", nargs="*", default=[], help="только эти задачи (пробный запуск, например W-01)")
    ap.add_argument("--dry-run", action="store_true", help="показать, что будет выполнено, без запуска")
    args = ap.parse_args()

    tasks = json.loads(TASKS.read_text())["tasks"]
    if args.dry_run:
        done = done_tasks()
        print("claude:", newest_claude())
        print(f"потолок ночи: {int(args.weekly_tokens * args.night_share):_} токенов; приёмка: {args.review}".replace("_", " "))
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
    night = Night(args.out, args)
    try:
        return night.run(tasks)
    finally:
        night.report()


if __name__ == "__main__":
    sys.exit(main())
