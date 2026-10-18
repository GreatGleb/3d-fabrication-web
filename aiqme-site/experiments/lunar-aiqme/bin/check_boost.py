#!/usr/bin/env python3
"""Exercise the exact project-local Codex MCP command, without changing global config."""
import json, pathlib, subprocess, tomllib
root = pathlib.Path(__file__).resolve().parents[1]
config = tomllib.loads((root/'app/.codex/config.toml').read_text())['mcp_servers']['laravel-boost']
p = subprocess.Popen([config['command'], *config['args']], cwd=config['cwd'], stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
def call(i,method,params):
 p.stdin.write(json.dumps({'jsonrpc':'2.0','id':i,'method':method,'params':params})+'\n'); p.stdin.flush()
 while True:
  line=p.stdout.readline()
  if not line: raise RuntimeError(p.stderr.read())
  data=json.loads(line)
  if data.get('id')==i: return data
try:
 results={'initialize':call(1,'initialize',{'protocolVersion':'2024-11-05','capabilities':{},'clientInfo':{'name':'aiqme-local-probe','version':'1'}})}
 p.stdin.write(json.dumps({'jsonrpc':'2.0','method':'notifications/initialized'})+'\n'); p.stdin.flush()
 results['tools']=call(2,'tools/list',{})
 (root/'evidence/boost-tools.json').write_text(json.dumps(results,indent=2))
 names=[x['name'] for x in results['tools']['result']['tools']]; print(names)
 for i,(name,args) in enumerate([('application-info',{}),('database-schema',{}),('get-absolute-url',{'path':'/'}),('database-query',{'query':"SELECT count(*) AS table_count FROM information_schema.tables WHERE table_schema='public'"}),('search-docs',{'queries':['database transactions pessimistic locking','http tests authentication'],'packages':['laravel/framework']})],3):
  result=call(i,'tools/call',{'name':name,'arguments':args}); (root/('evidence/boost-'+name+'.json')).write_text(json.dumps(result,indent=2));print(name,'error=',result.get('error',result.get('result',{}).get('isError',False)))
finally:
 p.terminate(); p.wait(timeout=10)
