#!/usr/bin/env python3
"""Serve archived web resources locally. Network is disabled unless --fetch is given."""
import argparse
import hashlib
import html
import json
import mimetypes
import re
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import urlsplit, urlunsplit, urljoin, unquote, parse_qsl, urlencode

ROOT = Path(__file__).resolve().parent
SITES = json.loads((ROOT / 'sites.json').read_text())
BLOCKED = ('google-analytics.com', 'googletagmanager.com', 'doubleclick.net', 'facebook.net', 'facebook.com', 'hotjar.com', 'clarity.ms', 'cookiebot.com', 'cookielaw.org', 'onetrust.com', 'trustarc.com', 'chatbase.co', 'recaptcha.net', 'google.com/recaptcha', 'challenges.cloudflare.com', 'cookieyes.com', 'usercentrics.eu', 'm.stripe.com', '/cdn-cgi/challenge-platform/', 'segment.io', 'segment.com', 'sentry.io', 'analytics.google.com', 'adsrvr.org', 'api.clerk.io/v2/log/', '/cdn-cgi/rum', 'jnn-pa.googleapis.com', '/youtubei/v1/log_event', 'va.tawk.to')
LOCK = threading.Lock()
LOCKS = {}

def blocked(url):
    return any(x in url for x in BLOCKED)

def paths(site, url):
    original, separator, post = url.partition('\nPOST\n')
    parts=urlsplit(original)
    if parts.hostname=='api.storyblok.com':
        query=urlencode([(k,v) for k,v in parse_qsl(parts.query,keep_blank_values=True) if k!='cv'])
        original=urlunsplit((parts.scheme,parts.netloc,parts.path,query,parts.fragment))
    elif parts.hostname=='www.metals4u.co.uk' and parts.path=='/banner/ajax/load/':
        query=urlencode([(k,v) for k,v in parse_qsl(parts.query,keep_blank_values=True) if k!='_'])
        original=urlunsplit((parts.scheme,parts.netloc,parts.path,query,parts.fragment))
    elif parts.hostname=='api.clerk.io' and parts.path=='/v2/undefined':
        params=[]
        for k,v in parse_qsl(parts.query,keep_blank_values=True):
            if k in ('callback','_'):continue
            if k=='payload':
                payload=json.loads(v);payload.pop('visitor',None)
                v=json.dumps(payload,sort_keys=True,separators=(',',':'))
            params.append((k,v))
        original=urlunsplit(('https',parts.netloc,parts.path,urlencode(sorted(params)),parts.fragment))
    normalized=original+(separator+post if separator else '')
    key = hashlib.sha256(normalized.encode()).hexdigest()
    directory = ROOT / 'cache' / site['id']
    directory.mkdir(parents=True, exist_ok=True)
    return directory / (key + '.bin'), directory / (key + '.json')

def local_url(url, base, site):
    if url.startswith(('data:', 'blob:', '#', 'mailto:', 'tel:', 'javascript:')):
        return url
    if url.startswith(('http://www.w3.org', 'https://www.w3.org', 'http://www.w3.org/1999', 'http://purl.org', 'https://schema.org', 'http://schema.org')):
        return url
    absolute = urljoin(base, url)
    parsed = urlsplit(absolute)
    if parsed.scheme not in ('http', 'https'):
        return url
    if parsed.hostname in ('127.0.0.1','localhost'):
        return url
    main = urlsplit(site['url'])
    if parsed.netloc == main.netloc:
        return 'http://127.0.0.1:'+str(site['port'])+urlunsplit(('', '', parsed.path or '/', parsed.query, parsed.fragment))
    return 'http://127.0.0.1:'+str(site['port'])+'/__remote/' + parsed.scheme + '/' + parsed.netloc + (parsed.path or '/') + ('?' + parsed.query if parsed.query else '') + ('#' + parsed.fragment if parsed.fragment else '')

def runtime(site):
    # Patch URLs assembled at runtime; static URLs are rewritten below.
    return r'''<script>(()=>{
const originalOrigin=ORIGIN;
if(navigator.serviceWorker)navigator.serviceWorker.register=async()=>({active:null,installing:null,waiting:null,update:async()=>{},unregister:async()=>true,addEventListener(){}});
function local(u){try{const a=new URL(u,location.href);if(!/^https?:$/.test(a.protocol)||a.origin===location.origin)return u;return a.origin===originalOrigin?a.pathname+a.search+a.hash:'/__remote/'+a.protocol.slice(0,-1)+'/'+a.host+a.pathname+a.search+a.hash}catch{return u}}
const fetchOriginal=window.fetch;window.fetch=function(u,o){if(u instanceof Request){const v=local(u.url);return fetchOriginal.call(this,v===u.url?u:new Request(new URL(v,location.href),u),o)}return fetchOriginal.call(this,local(String(u)),o)};
const open=XMLHttpRequest.prototype.open;XMLHttpRequest.prototype.open=function(m,u,...a){return open.call(this,m,local(String(u)),...a)};
function srcset(v){return String(v).startsWith('data:')?v:String(v).split(',').map(p=>{const a=p.trim().split(/\s+/);a[0]=local(a[0]);return a.join(' ')}).join(', ')}
const set=Element.prototype.setAttribute;Element.prototype.setAttribute=function(n,v){return set.call(this,n,/^(src|href|poster)$/i.test(n)?local(String(v)):n.toLowerCase()==='srcset'?srcset(v):v)};
for(const [klass,keys] of [[HTMLImageElement,['src','srcset']],[HTMLScriptElement,['src']],[HTMLLinkElement,['href']],[HTMLMediaElement,['src']],[HTMLSourceElement,['src','srcset']],[HTMLIFrameElement,['src']]])for(const key of keys){const d=Object.getOwnPropertyDescriptor(klass.prototype,key);if(d?.set)Object.defineProperty(klass.prototype,key,{...d,set(v){d.set.call(this,key==='srcset'?srcset(v):local(String(v)))}})}
for(const key of ['Worker','SharedWorker'])if(window[key]){const C=window[key];window[key]=class extends C{constructor(u,o){super(local(String(u)),o)}}}
function css(v){return String(v).replace(/url\(\s*(['"]?)(.*?)\1\s*\)/g,(_,q,u)=>'url('+q+local(u)+q+')')}
const prop=CSSStyleDeclaration.prototype.setProperty;CSSStyleDeclaration.prototype.setProperty=function(k,v,p){return prop.call(this,k,css(v),p)};
for(const key of ['backgroundImage','background','cssText']){const d=Object.getOwnPropertyDescriptor(CSSStyleDeclaration.prototype,key);if(d?.set)Object.defineProperty(CSSStyleDeclaration.prototype,key,{...d,set(v){d.set.call(this,css(v))}})}
function fix(e){if(!(e instanceof Element))return;for(const k of ['src','href','poster','srcset','style']){const v=e.getAttribute(k);if(v){const n=k==='style'?css(v):k==='srcset'?srcset(v):local(v);if(n!==v)set.call(e,k,n)}}}
new MutationObserver(ms=>{for(const m of ms){if(m.type==='attributes')fix(m.target);else for(const n of m.addedNodes){fix(n);if(n.querySelectorAll)n.querySelectorAll('[src],[srcset],[style],[poster]').forEach(fix)}}}).observe(document.documentElement,{subtree:true,childList:true,attributes:true,attributeFilter:['src','srcset','style','poster']});
window.__archiveLocalURL=local;
})();</script>'''.replace('ORIGIN', json.dumps(urlsplit(site['url']).scheme+'://'+urlsplit(site['url']).netloc))

def rewrite(body, mime, url, site):
    if not any(t in mime for t in ('html', 'css')):
        return body
    text = body.decode('utf-8', errors='replace')
    def css(text):
        def cssurl(m):
            return 'url('+m[1]+local_url(m[2].strip(),url,site)+m[1]+')'
        return re.sub(r"url\(\s*([\"']?)(.*?)\1\s*\)",cssurl,text)
    if 'css' in mime:
        return css(text).encode()
    from bs4 import BeautifulSoup
    soup=BeautifulSoup(text,'html.parser')
    base_tag=soup.find('base',href=True)
    document_base=urljoin(url,base_tag['href']) if base_tag else url
    for tag in soup.find_all(True):
        tag.attrs.pop('integrity',None)
        if tag.name=='meta' and str(tag.get('http-equiv','')).lower()=='content-security-policy':
            tag.decompose();continue
        if tag.name=='base':
            # Preserve the document base required by Angular routing.
            if tag.get('href'):tag['href']=local_url(tag['href'],url,site)
        for key in ('src','href','poster','action'):
            if tag.get(key):tag[key]=local_url(tag[key],url if tag.name=='base' else document_base,site)
        if tag.get('srcset') and not tag['srcset'].startswith('data:'):
            tag['srcset']=', '.join(local_url(part.strip().split()[0],document_base,site)+(' '+part.strip().split()[1] if len(part.strip().split())>1 else '') for part in tag['srcset'].split(',') if part.strip())
        if tag.get('style'):tag['style']=css(tag['style'])
        if tag.name=='style' and tag.string:tag.string=css(str(tag.string))
    injection=BeautifulSoup(runtime(site),'html.parser')
    if soup.head:soup.head.insert(0,injection)
    return str(soup).encode()

class Server(ThreadingHTTPServer):
    daemon_threads = True

class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass
    def do_HEAD(self):
        self.do_GET(head=True)
    def do_POST(self):
        # Replay only an already archived read-only response; never forward POST.
        site=self.server.site
        if not site:return self.send_error(405)
        size=int(self.headers.get('Content-Length','0'))
        if size>1024*1024:return self.send_error(413)
        payload=self.rfile.read(size).decode('utf-8')
        if self.path.startswith('/__remote/'):
            parts=self.path.split('/',4)
            if len(parts)<5:return self.send_error(400)
            url=parts[2]+'://'+parts[3]+'/'+parts[4]
        else:
            origin=urlsplit(site['url']);url=origin.scheme+'://'+origin.netloc+self.path
        if blocked(url):return self.reply(204,'text/plain',b'',False)
        data,meta=paths(site,url+'\nPOST\n'+payload)
        if meta.exists():
            record=json.loads(meta.read_text())
            return self.reply(200,record['type'],data.read_bytes(),False)
        self.send_error(405, 'No archived read-only response; remote writes are disabled.')
    def do_GET(self, head=False):
        site=self.server.site
        if self.path == '/__archive_status':
            return self.reply(200,'application/json',json.dumps({'downloads_enabled':self.server.fetch}).encode(),head)
        if not site:
            links=''.join('<li><a href="http://127.0.0.1:'+str(s['port'])+html.escape(urlsplit(s['url']).path)+'">'+html.escape(s['name'])+'</a></li>' for s in SITES)
            data=('<!doctype html><meta charset="utf-8"><title>Локальные сайты</title><style>body{max-width:850px;margin:60px auto;font:20px/1.7 system-ui}a{color:#155abb}</style><h1>Локальные сайты</h1><p>Скрипты и ресурсы запускаются с локального сервера. Ссылки ведут на сохранённые страницы; серверы оригинальных сайтов и их функции не входят в копии.</p><ul>'+links+'</ul>').encode()
            return self.reply(200,'text/html; charset=utf-8',data,head)
        if self.path.startswith('/__remote/'):
            parts=self.path.split('/',4)
            if len(parts)<5:return self.send_error(400)
            url=parts[2]+'://'+parts[3]+'/'+parts[4]
        else:
            origin=urlsplit(site['url'])
            url=origin.scheme+'://'+origin.netloc+self.path
        if blocked(url):return self.reply(200,'application/javascript',b'',head)
        parsed=urlsplit(url)
        if parsed.scheme not in ('http','https') or parsed.hostname in ('localhost','127.0.0.1','::1'):
            return self.send_error(400)
        datafile,metafile=paths(site,url)
        with LOCK:
            lock=LOCKS.setdefault(str(datafile),threading.Lock())
        with lock:
            if not metafile.exists() and self.server.fetch:
                try:
                    import requests
                    response=requests.get(url,timeout=(12,45),headers={'User-Agent':'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36','Referer':site['url']})
                    is_challenge = 'text/html' in response.headers.get('Content-Type','') and 'Just a moment' in response.text[:2000]
                    if response.status_code < 400 and not is_challenge:
                        datafile.write_bytes(response.content)
                        metafile.write_text(json.dumps({'url':url,'final_url':response.url,'status':response.status_code,'type':response.headers.get('Content-Type',mimetypes.guess_type(parsed.path)[0] or 'application/octet-stream')}))
                    else:
                        return self.missing(url,response.status_code,head)
                except Exception as e:
                    return self.missing(url,str(e),head)
            if not metafile.exists():return self.missing(url,'not archived',head)
            meta=json.loads(metafile.read_text())
            data=rewrite(datafile.read_bytes(),meta['type'],meta.get('final_url',url),site)
        if parsed.hostname=='api.clerk.io' and parsed.path=='/v2/undefined':
            callback=dict(parse_qsl(parsed.query)).get('callback','')
            if re.fullmatch(r'[A-Za-z_$][\w.$]*',callback):
                data=re.sub(rb'^\s*[A-Za-z_$][\w.$]*\s*\(',lambda m:callback.encode()+b'(',data,count=1)
        return self.reply(200,meta['type'],data,head)
    def missing(self,url,reason,head):
        record={'site':self.server.site['id'],'url':url,'reason':reason}
        with LOCK:
            with (ROOT/'missing.jsonl').open('a') as out:out.write(json.dumps(record)+'\n')
        self.reply(404,'text/plain; charset=utf-8',('Resource unavailable in archive: '+url).encode(),head)
    def reply(self,status,mime,data,head):
        # Browser video seeking requires byte ranges, even for an offline archive.
        total = len(data)
        content_range = None
        is_video = status == 200 and mime.split(';')[0].startswith('video/')
        requested = self.headers.get('Range', '') if is_video and not head else ''
        match = re.fullmatch(r'bytes=(\d*)-(\d*)', requested)
        if match and any(match.groups()):
            first, last = match.groups()
            start = int(first) if first else max(0, total - int(last))
            end = min(int(last), total - 1) if first and last else total - 1
            if start >= total or end < start or (not first and int(last) == 0):
                status, data = 416, b''
                content_range = f'bytes */{total}'
            else:
                status, data = 206, data[start:end + 1]
                content_range = f'bytes {start}-{end}/{total}'
        self.send_response(status)
        self.send_header('Content-Type',mime)
        self.send_header('Content-Length',str(len(data)))
        if is_video:
            self.send_header('Accept-Ranges', 'bytes')
        if content_range:
            self.send_header('Content-Range', content_range)
        self.send_header('Access-Control-Allow-Origin','*')
        self.send_header('Cache-Control','no-store')
        self.end_headers()
        if not head:
            try:self.wfile.write(data)
            except (BrokenPipeError,ConnectionResetError):pass

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--fetch',action='store_true',help='Download missing public GET resources during preparation. Omit for offline viewing.')
    parser.add_argument('--site',choices=[s['id'] for s in SITES])
    args=parser.parse_args()
    servers=[]
    for site in [None]+[s for s in SITES if not args.site or s['id']==args.site]:
        server=Server(('127.0.0.1',site['port'] if site else 8810),Handler)
        server.site=site;server.fetch=args.fetch
        threading.Thread(target=server.serve_forever,daemon=True).start();servers.append(server)
    print('http://127.0.0.1:8810/ — '+('preparation (downloads enabled)' if args.fetch else 'offline archive'),flush=True)
    try:threading.Event().wait()
    except KeyboardInterrupt:
        for server in servers:server.shutdown()

if __name__=='__main__':main()
