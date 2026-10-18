"""Inventory and probe the preserved Relats resources; no network access."""
from pathlib import Path
from collections import defaultdict
import hashlib,json,subprocess
from bs4 import BeautifulSoup
OUT=Path(__file__).resolve().parent
ROOT=OUT.parents[2]
CACHE=ROOT/'legacy-landing/references/relats/cache/relats'
assets=[];totals=defaultdict(lambda:{'count':0,'bytes':0})
for meta in sorted(CACHE.glob('*.json')):
 m=json.loads(meta.read_text());f=meta.with_suffix('.bin');kind=m['type'].split(';')[0]
 item={'url':m['url'],'cache':str(f.relative_to(ROOT)),'bytes':f.stat().st_size,'mime':kind,'sha256':hashlib.sha256(f.read_bytes()).hexdigest()}
 totals[kind]['count']+=1;totals[kind]['bytes']+=item['bytes']
 if kind=='video/mp4':
  probe=json.loads(subprocess.check_output(['ffprobe','-v','error','-show_streams','-show_format','-of','json',str(f)]))
  item['media']={'duration':probe['format'].get('duration'),'bit_rate':probe['format'].get('bit_rate'),'streams':[{k:s.get(k) for k in ('codec_type','codec_name','width','height','r_frame_rate','nb_frames','pix_fmt')} for s in probe['streams']]}
  frames=json.loads(subprocess.check_output(['ffprobe','-v','error','-select_streams','v:0','-skip_frame','nokey','-show_frames','-show_entries','frame=best_effort_timestamp_time','-of','json',str(f)]))
  ts=[float(x['best_effort_timestamp_time']) for x in frames['frames'] if 'best_effort_timestamp_time'in x]
  item['media']['keyframes']=len(ts);item['media']['max_keyframe_gap_seconds']=max((b-a for a,b in zip(ts,ts[1:])),default=0)
 assets.append(item)
html=BeautifulSoup((OUT/'sources/index.html').read_text(),'html.parser')
videos=[]
for v in html.select('video'):
 s=v.find_parent('section')
 videos.append({'section':s.get('data-component') if s else None,'attributes':v.attrs,'sources':[x.attrs for x in v.select('source')]})
result={'totalPayloadBytes':sum(x['bytes'] for x in assets),'totals':dict(totals),'assets':assets,'htmlVideos':videos,'htmlCounts':{'images':len(html.select('img')),'videos':len(videos),'canvas':len(html.select('canvas')),'lottie':len(html.select('.lottie')),'preloadMain':len(html.select('[preload-main]')),'preloadSecondary':len(html.select('[preload-secondary]'))},'sourceFiles':{name:next(x['cache'] for x in assets if x['url']==url) for name,url in [('index.html','https://toptier.relats.com/'),('app.js','https://toptier.relats.com/wp-content/themes/relats/js/app.min.js'),('vendor.js','https://toptier.relats.com/wp-content/themes/relats/js/vendor.min.js'),('style.css','https://toptier.relats.com/wp-content/themes/relats/style.css')]}}
(OUT/'asset-inventory.json').write_text(json.dumps(result,ensure_ascii=False,indent=2))
print('Payload MiB',round(result['totalPayloadBytes']/1024**2,2),'HTML',result['htmlCounts'])
for k,v in totals.items():print(k,v['count'],round(v['bytes']/1024**2,2),'MiB')
for a in assets:
 if 'media'in a:print(a['url'].rsplit('/',1)[-1],round(a['bytes']/1024**2,2),a['media'])
