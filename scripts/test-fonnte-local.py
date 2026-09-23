"""Tes Fonnte langsung (tanpa PHP/MySQL). Isi TOKEN + TARGET lalu: python scripts/test-fonnte-local.py"""
import json, urllib.request

TOKEN = ""   # <-- isi dari dashboard Fonnte (Device -> Token). JANGAN commit token asli!
TARGET = ""  # <-- isi ID grup, mis. 120363xxxxxxxxxx@g.us
MESSAGE = "🔔 Tes SIMARS lokal. Jika masuk, token+grup benar."

if not TOKEN or not TARGET:
    print("Isi dulu TOKEN dan TARGET di dalam file ini.")
    raise SystemExit(1)

req = urllib.request.Request(
    "https://api.fonnte.com/send",
    data=urllib.parse.urlencode({"target": TARGET, "message": MESSAGE}).encode(),
    headers={"Authorization": TOKEN},
    method="POST",
)
import urllib.parse
try:
    with urllib.request.urlopen(req, timeout=20) as r:
        body = r.read().decode()[:1000]
        print("HTTP", r.status)
        print(body)
        js = json.loads(body)
        print("status:", js.get("status"), "| reason:", js.get("reason") or js.get("message"))
except Exception as e:
    print("GAGAL:", e)
    try:
        print("detail:", e.read().decode()[:1000])
    except Exception: pass
