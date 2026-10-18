#!/usr/bin/env python3
"""Render docs/planning/edition-2/tasks.md from tasks.json (source of truth).

Usage: python3 docs/planning/workflow/render_tasks.py [--check]
--check exits 1 if tasks.md differs from the rendered output.
"""
import json
import sys
from pathlib import Path

EDITION = Path(__file__).resolve().parents[1] / 'edition-2'
TRACKS = {'L': 'L — локально', 'T': 'T — тестовые подключения', 'P': 'P — рабочее подключение'}
SLICES = {
    'S1': 'Публичный сайт и контент из БД',
    'S2': 'Вход, калькулятор, заявки, заказы, кабинет, админка заказов; демо-сборка для GitHub Pages',
    'S3': 'Оплата, счета, Google Таблицы, Telegram (локально)',
    'S4': 'Эксплуатационный пакет и итоговая локальная проверка',
    'S5': 'Тестовые и рабочие подключения (нужны доступы)',
}


def decisions(ids):
    return ', '.join(f'[{d}](decisions.md#{d.lower()})' for d in ids) if ids else 'нет'


def render(data):
    tasks = data['tasks']
    lines = [
        '# Задачи для workflow реализации', '',
        '[← План](README.md). Источник истины — [tasks.json](tasks.json); этот файл сгенерирован из него '
        '(`python3 docs/planning/workflow/render_tasks.py`). Задачи последовательные, каждая рассчитана '
        'на одну рабочую сессию Codex. Workflow реализации **не запущен**.', '',
        '## Общие правила', '',
    ]
    lines += [f'- {rule}' for rule in data['rules']]
    lines += ['', '## Срезы поставки', '',
              'Каждый завершённый срез — пригодное приращение. Желаемый ориентир MVP 19.10.2026 не доказан '
              'оценкой; если к нему успевается только часть, выпускается завершённый префикс срезов, '
              'а не половина задачи.', '',
              '| Срез | Содержание | Задачи |', '|---|---|---|']
    for key, title in SLICES.items():
        ids = ', '.join(t['id'] for t in tasks if t['slice'] == key)
        lines.append(f'| {key} | {title} | {ids} |')
    lines += ['', '## Порядок и зависимости', '',
              '| Задача | Уровень | Зависит от | Открытые решения |', '|---|---|---|---|']
    for t in tasks:
        lines.append(f"| [{t['id']}](#{t['id'].lower()}) {t['title']} | {t['track']} | "
                     f"{', '.join(t['depends']) or '—'} | {', '.join(t['unknowns']) or '—'} |")
    for t in tasks:
        lines += ['', f"## {t['id']}", '',
                  f"**{t['title']}** · {TRACKS[t['track']]} · срез {t['slice']} · "
                  f"зависит от: {', '.join(t['depends']) or '—'}", '',
                  f"**Результат.** {t['result']}", '',
                  '**Входы:** ' + '; '.join(f'`{x}`' for x in t['inputs']), '',
                  '**Затрагивает:** ' + '; '.join(f'`{x}`' for x in t['touches']), '',
                  '**Критерии завершения:**', '']
        lines += [f'- {x}' for x in t['done']]
        lines += ['', '**Проверки:**', '']
        lines += [f'- {x}' for x in t['checks']]
        lines += ['', '**Ограничения:**', '']
        lines += [f'- {x}' for x in t['constraints']]
        lines += ['', f"**Неизвестные параметры:** {decisions(t['unknowns'])}", '',
                  '**Трассировка:** ' + '; '.join(f"{k}: {', '.join(v)}" for k, v in t['trace'].items() if v)]
    return '\n'.join(lines) + '\n'


def main():
    data = json.loads((EDITION / 'tasks.json').read_text())
    text = render(data)
    target = EDITION / 'tasks.md'
    if '--check' in sys.argv:
        same = target.read_text() == text
        print('tasks.md is up to date' if same else 'tasks.md differs from tasks.json')
        return 0 if same else 1
    target.write_text(text)
    print(f'Rendered {len(data["tasks"])} tasks into {target}')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
