#!/usr/bin/env python3
"""Same physical acceptance tests, separate applications and disposable databases."""
import json,os,pathlib,subprocess,time,xml.etree.ElementTree as ET
import sys;root=pathlib.Path(sys.argv[1]).resolve();prototypes=root.parent;out=pathlib.Path(sys.argv[2]).resolve();out.mkdir(parents=True,exist_ok=True)
php=prototypes/'lunar-aiqme/bin/php';summary={}
for variant,expected in [('lunar-aiqme','aiqme_lunar_comparison_test'),('laravel-orders-aiqme','aiqme_plain_comparison_test')]:
 app=prototypes/variant/'app';config=ET.parse(app/'phpunit.xml').getroot();actual=next(x.attrib['value'] for x in config.findall('./php/env') if x.attrib['name']=='DB_DATABASE')
 assert actual==expected,(variant,actual,expected)
 env=os.environ.copy();env.update(DB_DATABASE=expected,DB_CONNECTION='pgsql',DB_HOST='127.0.0.1',DB_PORT='55439',DB_USERNAME='probe',DB_PASSWORD='synthetic-local-only',APP_ENV='testing')
 start=time.monotonic()
 result=subprocess.run([str(php),'vendor/bin/phpunit',str(root/'shared/Tests'),'--log-junit',str(out/f'{variant}-contract.xml')],cwd=app,env=env,capture_output=True,text=True)
 (out/f'{variant}-contract.log').write_text(result.stdout+result.stderr)
 report=ET.parse(out/f'{variant}-contract.xml').getroot();suite=report.find('testsuite')
 summary[variant]={'exit_code':result.returncode,'database':expected,'test_seconds':round(time.monotonic()-start,3),'tests':int(suite.attrib['tests']),'assertions':int(suite.attrib['assertions']),'failures':int(suite.attrib['failures']),'errors':int(suite.attrib['errors'])}
 print(variant,summary[variant],flush=True)
(out/'comparison-results.json').write_text(json.dumps(summary,indent=2))
raise SystemExit(any(x['exit_code'] for x in summary.values()))
