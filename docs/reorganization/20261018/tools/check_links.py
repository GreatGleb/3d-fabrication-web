#!/usr/bin/env python3
"""Check relative links and anchors in Markdown files and path strings in task JSON.

Usage: check_links.py ROOT [--json OUT]
Markdown: inline links [text](target) and <a href>, reference definitions.
Anchors: GitHub-style heading slugs plus explicit id/name attributes.
JSON: "inputs" of docs/planning/edition-2/tasks.json must exist (first path token).
"""
import json, re, sys, unicodedata
from pathlib import Path
from urllib.parse import unquote

# Byte-preserved snapshot copies: their links refer to the original location; reported separately.
FROZEN = ('docs/planning/run/reconciliations/', 'legacy-landing/archive/project-history/', 'archive/project-history/')
SKIP_PARTS = {'node_modules', 'vendor', '.git', '__pycache__', 'runtime', 'sessions', 'cache'}
LINK = re.compile(r'(?<!!)\[(?:[^\[\]]|\[[^\]]*\])*\]\(\s*<?([^)\s>]+)>?(?:\s+"[^"]*")?\s*\)')
IMG = re.compile(r'!\[[^\]]*\]\(\s*<?([^)\s>]+)>?(?:\s+"[^"]*")?\s*\)')
HREF = re.compile(r'<a\s[^>]*href="([^"]+)"', re.I)
REFDEF = re.compile(r'^\s{0,3}\[[^\]]+\]:\s*<?(\S+?)>?(?:\s+.*)?$', re.M)
FENCE = re.compile(r'^(```|~~~).*?^\1', re.M | re.S)
CODE = re.compile(r'`[^`\n]*`')


def slug(text):
    text = re.sub(r'<[^>]+>', '', text)
    text = re.sub(r'\[([^\]]*)\]\([^)]*\)', r'\1', text)
    text = text.strip().lower()
    out = []
    for ch in text:
        cat = unicodedata.category(ch)
        if ch in ' -':
            out.append('-')
        elif ch == '_' or cat[0] in 'LN' or cat == 'Mn':
            out.append(ch)
    return ''.join(out)


def anchors(path, cache={}):
    if path in cache:
        return cache[path]
    text = path.read_text(errors='replace')
    text_nf = FENCE.sub('', text)
    seen, result = {}, set()
    for m in re.finditer(r'^#{1,6}\s+(.*?)\s*#*\s*$', text_nf, re.M):
        s = slug(m.group(1))
        n = seen.get(s, 0)
        result.add(s if n == 0 else f'{s}-{n}')
        seen[s] = n + 1
    result.update(re.findall(r'(?:id|name)="([^"]+)"', text))
    cache[path] = result
    return result


def targets(text):
    text = FENCE.sub('', text)
    text = CODE.sub('', text)
    for rx in (LINK, IMG, HREF, REFDEF):
        for m in rx.finditer(text):
            yield m.group(1)


def main():
    root = Path(sys.argv[1]).resolve()
    out = sys.argv[sys.argv.index('--json') + 1] if '--json' in sys.argv else None
    broken, checked = [], 0
    files = [p for p in root.rglob('*.md') if not SKIP_PARTS & set(p.relative_to(root).parts)]
    frozen_broken = []
    for md in sorted(files):
        sink = frozen_broken if str(md.relative_to(root)).startswith(FROZEN) else broken
        for target in targets(md.read_text(errors='replace')):
            if re.match(r'^[a-z][a-z0-9+.-]*:', target, re.I) or target.startswith('//'):
                continue
            checked += 1
            path_part, _, frag = target.partition('#')
            path_part = unquote(path_part.split('?')[0])
            dest = md if not path_part else (md.parent / path_part)
            if not dest.exists():
                sink.append({'file': str(md.relative_to(root)), 'target': target, 'reason': 'missing'})
                continue
            if frag and dest.is_file() and dest.suffix == '.md':
                if unquote(frag) not in anchors(dest.resolve()):
                    sink.append({'file': str(md.relative_to(root)), 'target': target, 'reason': 'anchor'})
    tasks = root / 'docs/planning/edition-2/tasks.json'
    if tasks.exists():
        for task in json.loads(tasks.read_text())['tasks']:
            for item in task['inputs']:
                first = item.split(' ')[0]
                path_part, _, frag = first.partition('#')
                if not path_part.startswith(('docs/', 'legacy-landing/', 'aiqme-site/experiments/')):
                    continue  # future application paths (aiqme-site/...) are created by the tasks themselves
                checked += 1
                dest = root / path_part
                if not dest.exists():
                    broken.append({'file': 'docs/planning/edition-2/tasks.json', 'target': f"{task['id']}: {item}", 'reason': 'missing'})
                elif frag and frag not in anchors(dest.resolve()):
                    broken.append({'file': 'docs/planning/edition-2/tasks.json', 'target': f"{task['id']}: {item}", 'reason': 'anchor'})
    report = {'markdown_files': len(files), 'checked': checked, 'broken': broken,
              'frozen_snapshot_prefixes': list(FROZEN), 'frozen_broken': frozen_broken}
    if out:
        Path(out).write_text(json.dumps(report, ensure_ascii=False, indent=1))
    print(f"files={len(files)} checked={checked} broken={len(broken)} frozen_broken={len(frozen_broken)}")
    for b in broken[:200]:
        print(f"  {b['reason']:7} {b['file']} -> {b['target']}")
    return 1 if broken else 0


if __name__ == '__main__':
    raise SystemExit(main())
