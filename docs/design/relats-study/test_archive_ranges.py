"""HTTP contract required for seeking the archived videos."""
import importlib.util
from http.client import HTTPConnection
from pathlib import Path
from threading import Thread
import unittest

ROOT=Path(__file__).resolve().parents[3]
spec=importlib.util.spec_from_file_location('archive',ROOT/'legacy-landing/references/relats/serve.py')
archive=importlib.util.module_from_spec(spec);spec.loader.exec_module(archive)
PAYLOAD=bytes(range(256))*4
class Handler(archive.Handler):
 def do_GET(self,head=False):self.reply(200,'video/mp4',PAYLOAD,head)

class VideoRanges(unittest.TestCase):
 @classmethod
 def setUpClass(cls):
  cls.server=archive.Server(('127.0.0.1',0),Handler)
  Thread(target=cls.server.serve_forever,daemon=True).start()
 @classmethod
 def tearDownClass(cls):cls.server.shutdown();cls.server.server_close()
 def request(self,range_header=None,method='GET'):
  c=HTTPConnection('127.0.0.1',self.server.server_port)
  c.request(method,'/',headers={'Range':range_header} if range_header else {})
  r=c.getresponse();result=(r.status,dict(r.getheaders()),r.read());c.close();return result
 def test_full_file(self):
  status,h,b=self.request();self.assertEqual((status,b),(200,PAYLOAD));self.assertEqual(h['Accept-Ranges'],'bytes')
 def test_bounded_open_and_suffix(self):
  for value,start,end in [('bytes=10-29',10,29),('bytes=1000-',1000,1023),('bytes=-20',1004,1023),('bytes=1000-2000',1000,1023),('bytes=-2000',0,1023)]:
   with self.subTest(value=value):
    status,h,b=self.request(value);self.assertEqual(status,206);self.assertEqual(b,PAYLOAD[start:end+1]);self.assertEqual(h['Content-Range'],f'bytes {start}-{end}/1024');self.assertEqual(int(h['Content-Length']),len(b))
 def test_unsatisfiable(self):
  for value in ['bytes=1024-','bytes=50-20','bytes=-0']:
   with self.subTest(value=value):
    status,h,b=self.request(value);self.assertEqual((status,b),(416,b''));self.assertEqual(h['Content-Range'],'bytes */1024')
 def test_head_has_no_body(self):
  status,h,b=self.request('bytes=0-3','HEAD');self.assertEqual((status,b),(200,b''));self.assertEqual(h['Content-Length'],'1024')
 def test_unsupported_range_is_ignored(self):
  for value in ['bytes=0-2,5-7','invalid','bytes=-']:
   with self.subTest(value=value):self.assertEqual(self.request(value)[::2],(200,PAYLOAD))

if __name__=='__main__':unittest.main()
