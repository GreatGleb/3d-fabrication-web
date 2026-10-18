#!/usr/bin/env python3
"""Run landing builds and checks; usage: run_checks.py WORKSPACE LANDING_REL OUTDIR.

LANDING_REL is '.' before the move and 'legacy-landing' after it. Commands are
executed from WORKSPACE with paths prefixed by LANDING_REL, so the same logical
set is compared. Motion checks get temporary static servers on 8822/8824.
"""
import json, os, subprocess, sys, time
from pathlib import Path

ws = Path(sys.argv[1]).resolve()
landing = sys.argv[2]
out = Path(sys.argv[3]).resolve()
out.mkdir(parents=True, exist_ok=True)
p = (lambda rel: rel if landing == '.' else f'{landing}/{rel}')

steps = [
    ('build_pages.py', f'python3 {p("scripts/build_pages.py")}'),
    ('build_pages.py-demo', f'python3 {p("scripts/build_pages.py")} --mode demo'),
    ('build_motion_preview.py', f'python3 {p("scripts/build_motion_preview.py")}'),
    ('build_motion_v2.py', f'python3 {p("scripts/build_motion_v2.py")}'),
    ('build_github_pages_v2.py', f'python3 {p("scripts/build_github_pages_v2.py")}'),
    ('check_stl_pricing.py', f'STL_TEST_DIRECTORY=github-pages python3 {p("scripts/check_stl_pricing.py")}'),
    ('check_github_pages_ui.py', f'python3 {p("scripts/check_github_pages_ui.py")}'),
    ('check_pages.py', f'python3 {p("scripts/check_pages.py")}'),
    ('check_demo.py', f'python3 {p("scripts/check_demo.py")}'),
    ('check_portal.py', f'python3 {p("scripts/check_portal.py")}'),
    ('check_motion_preview.py', f'python3 {p("scripts/check_motion_preview.py")}'),
    ('check_motion_v2.py', f'python3 {p("scripts/check_motion_v2.py")}'),
]
servers = []
results = []
try:
    for name, cmd in steps:
        if name == 'check_motion_preview.py' and not servers:
            for port, d in ((8822, p('build/motion-v1')), (8824, p('build/motion-v2'))):
                servers.append(subprocess.Popen(['python3', '-m', 'http.server', str(port), '--bind', '127.0.0.1', '--directory', d],
                                                cwd=ws, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL))
            time.sleep(1.5)
        start = time.monotonic()
        r = subprocess.run(cmd, shell=True, cwd=ws, capture_output=True, text=True, timeout=1800)
        (out / f'{name}.log').write_text(f'$ {cmd}\n' + r.stdout + r.stderr)
        tail = (r.stdout + r.stderr).strip().splitlines()[-1:] or ['']
        results.append({'command': cmd, 'exit': r.returncode, 'seconds': round(time.monotonic() - start, 1),
                        'log': f'{name}.log', 'last_line': tail[0][:300]})
        print(name, r.returncode, tail[0][:160], flush=True)
finally:
    for s in servers:
        s.terminate(); s.wait()
(out / 'results.json').write_text(json.dumps(results, ensure_ascii=False, indent=2))
