"""Mock API lokal utk tes tombol WA (tanpa PHP/MySQL).
Meniru kontrak api-php persis: /api/auth/login, /api/dashboard/stats,
/api/whatsapp/settings (GET+PUT), /api/whatsapp/test (POST -> Fonnte asli).
Pakai: python scripts/mock-api-local.py  (port 8000)
"""
import json, urllib.parse, urllib.request
from http.server import BaseHTTPRequestHandler, HTTPServer

PORT = 8000
STORE = __import__('pathlib').Path(__file__).parent / '.wa-local.json'

def load_settings():
    if STORE.exists():
        try: return json.loads(STORE.read_text())
        except Exception: pass
    # default: ambil dari test-fonnte-local.py yg sudah terbukti masuk
    try:
        t = (__import__('pathlib').Path(__file__).parent / 'test-fonnte-local.py').read_text()
        import re
        tok = re.search(r'TOKEN\s*=\s*"([^"]*)"', t)
        tgt = re.search(r'TARGET\s*=\s*"([^"]*)"', t)
        return {'fonnteToken': tok.group(1) if tok else '',
                'groupTarget': tgt.group(1) if tgt else '',
                'waGroupMarker': '', 'isEnabled': True}
    except Exception:
        return {'fonnteToken': '', 'groupTarget': '', 'waGroupMarker': '', 'isEnabled': True}

def save_settings(s):
    STORE.write_text(json.dumps(s), encoding='utf-8')

class H(BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def _json(self, obj, code=200):
        b = json.dumps(obj).encode()
        self.send_response(code); self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(b))); self.end_headers()
        self.wfile.write(b)
    def _body(self):
        try: return json.loads(self.rfile.read(int(self.headers.get('Content-Length', 0) or 0)) or b'{}')
        except Exception: return {}
    def do_GET(self):
        if self.path.startswith('/api/dashboard/stats'): return self._json({})  # validasi token AuthContext
        if self.path.startswith('/api/whatsapp/settings'): return self._json(load_settings())
        return self._json({'message': 'Not found'}, 404)
    def do_POST(self):
        if self.path.startswith('/api/auth/login'):
            return self._json({'token': 'mock-local-token',
                               'user': {'id': '1', 'username': 'admin', 'name': 'Admin', 'role': 'ADMIN'}})
        if self.path.startswith('/api/whatsapp/test'):
            s = load_settings()
            if not s.get('groupTarget'): return self._json({'success': False, 'message': 'Grup target belum diatur.'})
            try:
                req = urllib.request.Request('https://api.fonnte.com/send',
                    data=urllib.parse.urlencode({'target': s['groupTarget'],
                        'message': '\U0001f514 Pesan tes dari SIMARS (mock lokal). Jika masuk, tombol + jalur /api beres.'}).encode(),
                    headers={'Authorization': s.get('fonnteToken', '')}, method='POST')
                with urllib.request.urlopen(req, timeout=20) as r:
                    raw = r.read().decode()[:500]
                js = json.loads(raw)
                if r.status >= 400 or js.get('status') in (False, 0):
                    return self._json({'success': False, 'message': 'Fonnte menolak (HTTP %d): %s' % (r.status, raw[:300])})
                return self._json({'success': True})
            except Exception as e:
                detail = ''
                try: detail = e.read().decode()[:300]
                except Exception: pass
                return self._json({'success': False, 'message': 'Gagal mengirim pesan tes: %s %s' % (e, detail)})
        return self._json({'message': 'Not found'}, 404)
    def do_PUT(self):
        if self.path.startswith('/api/whatsapp/settings'):
            b = self._body()
            s = {'fonnteToken': b.get('fonnteToken') or None, 'groupTarget': b.get('groupTarget') or None,
                 'waGroupMarker': b.get('waGroupMarker') or None, 'isEnabled': bool(b.get('isEnabled', False))}
            save_settings(s)
            return self._json({k: (v or '') if k != 'isEnabled' else v for k, v in s.items()})
        return self._json({'message': 'Not found'}, 404)
    def do_OPTIONS(self):
        self.send_response(204)
        self.send_header('Access-Control-Allow-Origin', '*')
        self.send_header('Access-Control-Allow-Methods', 'GET, POST, PUT, OPTIONS')
        self.send_header('Access-Control-Allow-Headers', 'Content-Type, Authorization')
        self.end_headers()

print('mock-api lokal: http://127.0.0.1:%d  (Ctrl+C berhenti)' % PORT, flush=True)
HTTPServer(('127.0.0.1', PORT), H).serve_forever()
