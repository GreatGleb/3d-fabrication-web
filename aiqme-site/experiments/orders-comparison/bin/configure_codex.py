#!/usr/bin/env python3
from pathlib import Path
root=Path(__file__).resolve().parents[1]
for variant in ['lunar-aiqme','laravel-orders-aiqme']:
 app=root.parent/variant/'app';path=app/'.codex/config.toml';path.parent.mkdir(exist_ok=True)
 path.write_text('[mcp_servers.laravel-boost]\ncommand = "'+str(root.parent/'lunar-aiqme/bin/php')+'"\nargs = ["artisan", "boost:mcp"]\ncwd = "'+str(app)+'"\n')
