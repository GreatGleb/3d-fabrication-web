#!/usr/bin/env python3
import json,pathlib,subprocess,difflib
root=pathlib.Path(__file__).resolve().parents[1];prototypes=root.parent;php=prototypes/'lunar-aiqme/bin/php'
groups={'order_rules':['app/Services/OrderWorkflow.php','app/Services/ContentPublisher.php','app/Payments/LocalTestAdapter.php'],'basket':['app/Services/BasketService.php','app/Services/RequestCartValidator.php'],'models':['app/Models/*.php'],'schema':['database/migrations/2026*.php'],'wiring':['app/Providers/AppServiceProvider.php','app/Http/Controllers/ProbeOrderController.php','routes/web.php','config/comparison.php'],'optional_native_admin':['app/Admin/*.php']}
# Lexical tokens exclude whitespace/comments, so running Pint or expanding braces does not change this count.
lexer='''$s=file_get_contents($argv[1]);$code="";$n=0;foreach(token_get_all($s) as $t){if(is_array($t)){if(in_array($t[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT])){continue;}$code.=$t[1];}else{$code.=$t;}$n++;}echo json_encode(["tokens"=>$n,"code_bytes"=>strlen($code),"file_bytes"=>strlen($s),"physical_lines"=>substr_count($s,"\\n")]);'''
results={}
for variant in ['lunar-aiqme','laravel-orders-aiqme']:
 app=prototypes/variant/'app';files={};sums={}
 for group,patterns in groups.items():
  sums[group]={'tokens':0,'code_bytes':0,'files':0}
  for pattern in patterns:
   for path in sorted(app.glob(pattern)):
    stat=json.loads(subprocess.check_output([str(php),'-r',lexer,str(path)],text=True));files[str(path.relative_to(app))]={'group':group,**stat}
    for k in ['tokens','code_bytes']:sums[group][k]+=stat[k]
    sums[group]['files']+=1
 lock=json.loads((app/'composer.lock').read_text());results[variant]={'groups':sums,'files':files,'production_packages':len(lock['packages']),'all_packages':len(lock['packages'])+len(lock['packages-dev']),'laravel_version':next(p['version'] for p in lock['packages'] if p['name']=='laravel/framework')}
 inventory=subprocess.check_output([str(php),'artisan','comparison:inventory'],cwd=app,text=True);(root/f'evidence/{variant}-inventory.json').write_text(inventory)
shared={}
for path in sorted((root/'shared').rglob('*')):
 if path.is_file() and 'Tests' not in path.parts:
  shared[str(path.relative_to(root))]=json.loads(subprocess.check_output([str(php),'-r',lexer,str(path)],text=True))
results['shared_production_files']=shared
left=prototypes/'lunar-aiqme/app/app/Services/OrderWorkflow.php';right=prototypes/'laravel-orders-aiqme/app/app/Services/OrderWorkflow.php'
(root/'evidence/order-service.diff').write_text(''.join(difflib.unified_diff(left.read_text().splitlines(True),right.read_text().splitlines(True),fromfile='Lunar OrderWorkflow',tofile='Plain OrderWorkflow')))
(root/'evidence/source-metrics.json').write_text(json.dumps(results,indent=2))
for variant in ['lunar-aiqme','laravel-orders-aiqme']:print(variant,results[variant]['groups'],results[variant]['production_packages'],flush=True)
