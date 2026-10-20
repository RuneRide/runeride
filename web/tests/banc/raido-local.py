# Mini serveur HTTP local qui joue le rôle de raido.runeride.fr : chaque requête devient un événement Function URL
# rejoué par `bootstrap --evenement`, avec CORS pour la PWA servie sur 127.0.0.1:8080. Essai local seulement.
import json, os, subprocess, sys, tempfile
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
B = sys.argv[1]; PORT = int(sys.argv[2]); ENV = dict(os.environ)
class H(BaseHTTPRequestHandler):
    def cors(self):
        self.send_header('Access-Control-Allow-Origin', 'http://127.0.0.1:8080')
        self.send_header('Access-Control-Allow-Headers', 'authorization, content-type, x-amz-content-sha256, accept')
        self.send_header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
    def do_OPTIONS(self):
        self.send_response(204); self.cors(); self.end_headers()
    def jouer(self, methode):
        n = int(self.headers.get('content-length') or 0)
        corps = self.rfile.read(n).decode() if n else ''
        evt = {"rawPath": self.path.split('?')[0], "requestContext": {"http": {"method": methode}}, "headers": {k.lower(): v for k, v in self.headers.items()}, "body": corps, "isBase64Encoded": False}
        with tempfile.NamedTemporaryFile('w', suffix='.json', delete=False) as f: json.dump(evt, f); chemin = f.name
        p = subprocess.run([B, '--evenement', chemin], capture_output=True, text=True, env=ENV)
        os.unlink(chemin)
        sys.stderr.write(p.stderr)
        try: rep = json.loads(p.stdout)
        except Exception: rep = {"statusCode": 500, "headers": {"content-type": "application/json"}, "body": json.dumps({"erreur": "bootstrap : " + p.stdout[:200]})}
        body = (rep.get('body') or '').encode()
        self.send_response(int(rep.get('statusCode', 500))); self.cors()
        for k, v in (rep.get('headers') or {}).items(): self.send_header(k, v)
        self.send_header('content-length', str(len(body))); self.end_headers(); self.wfile.write(body)
    def do_GET(self): self.jouer('GET')
    def do_POST(self): self.jouer('POST')
    def log_message(self, *a): pass
print(f"raido local sur 127.0.0.1:{PORT}", flush=True)
ThreadingHTTPServer(('127.0.0.1', PORT), H).serve_forever()
