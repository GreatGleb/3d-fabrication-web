#!/usr/bin/env python3
"""Write a JSON manifest of every file and symlink under ROOT.

Regular files: sha256, size, inode. Files under instance/ are recorded by stat
only (size, mtime_ns, inode): the user asked not to read private data.
Usage: manifest.py ROOT OUT.json [--exclude REL ...]
"""
import hashlib, json, os, sys
from pathlib import Path

root = Path(sys.argv[1]).resolve()
out = Path(sys.argv[2])
excludes = [a for i, a in enumerate(sys.argv) if i > 2 and sys.argv[i - 1] == '--exclude']
entries = {}
for dirpath, dirnames, filenames in os.walk(root):
    dirnames.sort()
    base = Path(dirpath)
    for name in sorted(filenames) + [d for d in dirnames if (base / d).is_symlink()]:
        path = base / name
        rel = path.relative_to(root).as_posix()
        if any(rel == e or rel.startswith(e + '/') for e in excludes):
            continue
        st = path.lstat()
        if path.is_symlink():
            entries[rel] = {'symlink': os.readlink(path)}
        elif rel.startswith('instance/') or '/instance/' in rel and rel.split('/instance/')[0] in ('legacy-landing',):
            entries[rel] = {'size': st.st_size, 'mtime_ns': st.st_mtime_ns, 'inode': st.st_ino, 'stat_only': True}
        else:
            h = hashlib.sha256()
            with path.open('rb') as f:
                for chunk in iter(lambda: f.read(1 << 20), b''):
                    h.update(chunk)
            entries[rel] = {'sha256': h.hexdigest(), 'size': st.st_size, 'inode': st.st_ino}
    dirnames[:] = [d for d in dirnames if not (base / d).is_symlink()]
out.write_text(json.dumps({'root': str(root), 'files': entries}, ensure_ascii=False, indent=0))
print(len(entries), 'entries ->', out)
