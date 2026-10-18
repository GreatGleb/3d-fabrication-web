#!/usr/bin/env python3
"""Rewrite relative Markdown links after the move.

Usage: rewrite_links.py WORKSPACE MOVES.json OLD_MANIFEST.json REPORT.json [--apply]
A link is rewritten only if it resolved to an existing path in the old layout;
its new target is computed through the move map. Frozen snapshots are skipped.
"""
import json, os, re, sys
from pathlib import PurePosixPath
from urllib.parse import unquote, quote

ws, moves_path, manifest_path, report_path = sys.argv[1:5]
apply = '--apply' in sys.argv
moves = [(m['from'], m['to']) for m in json.load(open(moves_path))]
old_files = set(json.load(open(manifest_path))['files'])
old_dirs = {''}
for f in old_files:
    parts = f.split('/')
    for i in range(1, len(parts)):
        old_dirs.add('/'.join(parts[:i]))

FROZEN = ('docs/planning/run/reconciliations/', 'legacy-landing/archive/project-history/',
          'docs/customer-prototype/evidence/', 'docs/customer-prototype/source-backup-20261016/')
SKIP_PARTS = {'node_modules', 'vendor', '.git', 'sessions', 'cache', 'baseline', 'evidence'}


def remap(path, pairs):
    best = None
    for a, b in pairs:
        if path == a or path.startswith(a + '/'):
            if best is None or len(a) > len(best[0]):
                best = (a, b)
    return path if best is None else best[1] + path[len(best[0]):]


to_new = lambda p: remap(p, moves)
to_old = lambda p: remap(p, [(b, a) for a, b in moves])

LINK = re.compile(r'(\]\(\s*<?)([^)\s>]+)(>?(?:\s+"[^"]*")?\s*\))')
HREF = re.compile(r'(<a\s[^>]*href=")([^"]+)(")', re.I)
REFDEF = re.compile(r'^(\s{0,3}\[[^\]]+\]:\s*<?)(\S+?)(>?(?:\s+.*)?)$', re.M)
FENCE = re.compile(r'^(```|~~~).*?^\1', re.M | re.S)


def norm(p):
    parts = []
    for part in p.split('/'):
        if part in ('', '.'):
            continue
        if part == '..':
            if not parts:
                return None
            parts.pop()
        else:
            parts.append(part)
    return '/'.join(parts)


changes, unresolved = [], []


def fix(md_new, target):
    if re.match(r'^[a-z][a-z0-9+.-]*:', target, re.I) or target.startswith(('#', '//', '/')):
        return target
    path, hashpart = (target.split('#', 1) + [None])[:2]
    path_q = path.split('?')[0]
    raw = unquote(path_q)
    md_old = to_old(md_new)
    old_target = norm(str(PurePosixPath(md_old).parent / raw))
    if old_target is None or (old_target not in old_files and old_target not in old_dirs):
        unresolved.append({'file': md_new, 'target': target})
        return target
    new_target = to_new(old_target)
    rel = os.path.relpath(new_target, str(PurePosixPath(md_new).parent) or '.')
    if raw.endswith('/') and not rel.endswith('/'):
        rel += '/'
    if '%' in path_q:
        rel = quote(rel, safe='/')
    if rel == raw or (rel == path_q):
        return target
    result = rel + (path[len(path_q):]) + ('#' + hashpart if hashpart is not None else '')
    changes.append({'file': md_new, 'from': target, 'to': result})
    return result


for dirpath, dirnames, filenames in os.walk(ws):
    dirnames[:] = [d for d in dirnames if d not in SKIP_PARTS]
    for name in filenames:
        if not name.endswith('.md'):
            continue
        full = os.path.join(dirpath, name)
        rel = os.path.relpath(full, ws)
        if rel.startswith(FROZEN):
            continue
        text = open(full, encoding='utf-8').read()
        # Protect fenced code blocks from rewriting.
        blocks = []
        protected = FENCE.sub(lambda m: blocks.append(m.group(0)) or f'\0{len(blocks)-1}\0', text)
        new = LINK.sub(lambda m: m.group(1) + fix(rel, m.group(2)) + m.group(3), protected)
        new = HREF.sub(lambda m: m.group(1) + fix(rel, m.group(2)) + m.group(3), new)
        new = REFDEF.sub(lambda m: m.group(1) + fix(rel, m.group(2)) + m.group(3), new)
        new = re.sub(r'\0(\d+)\0', lambda m: blocks[int(m.group(1))], new)
        if new != text and apply:
            open(full, 'w', encoding='utf-8').write(new)

json.dump({'changes': changes, 'unresolved_in_old_layout': unresolved}, open(report_path, 'w'), ensure_ascii=False, indent=1)
print('changes', len(changes), 'unresolved', len(unresolved), 'files', len({c['file'] for c in changes}))
for u in unresolved[:50]:
    print('  unresolved', u)
