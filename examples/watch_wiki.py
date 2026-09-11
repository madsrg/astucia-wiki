#!/usr/bin/env python3
"""Echo Astucia Wiki realtime events.

Astucia Wiki - Copyright (C) 2026 Mads Rotwitt
Free software under the GNU GPL v3 or later.

Standard library only: an SSE stream is a long-lived HTTP response with one `data:` line
per event, so no client library is needed.

    WIKI_URL=https://wiki.example.com WIKI_TOKEN=wk_sys_... ./watch_wiki.py

Set WIKI_HUB_URL if the hub is not reverse-proxied under the wiki's own origin.
"""
import json, os, sys, time, urllib.error, urllib.parse, urllib.request

WIKI  = os.environ.get("WIKI_URL", "http://127.0.0.1:8000").rstrip("/")
TOKEN = os.environ.get("WIKI_TOKEN") or sys.exit("set WIKI_TOKEN to a wk_sys_… service token")
HUB   = os.environ.get("WIKI_HUB_URL", "").rstrip("/")


def ticket():
    """A subscriber ticket, scoped to whatever Spaces this token may read."""
    req = urllib.request.Request(f"{WIKI}/api.php?action=realtime_ticket",
                                 headers={"Authorization": f"Bearer {TOKEN}"})
    res = json.load(urllib.request.urlopen(req, timeout=15))
    if not res.get("enabled"):
        sys.exit("realtime is disabled on this wiki (ENABLE_REALTIME=false)")
    # The wiki reports its hub path, which is relative because the hub is normally
    # reverse-proxied under the wiki's own origin.
    return res["token"], HUB or (WIKI + res["url"])


def stream(jwt, url):
    """One SSE connection. Yields each event payload as a dict."""
    # 'wiki/{+rest}' is an RFC 6570 template meaning "everything". Asking broadly is safe:
    # the *ticket* decides what actually arrives, so this never over-delivers.
    q = urllib.parse.urlencode({"topic": "wiki/{+rest}"})
    req = urllib.request.Request(f"{url}?{q}", headers={
        "Authorization": f"Bearer {jwt}",
        "Accept": "text/event-stream",
    })
    res = urllib.request.urlopen(req, timeout=None)
    # Fail loudly on the most common setup mistake. Without this check a wiki that serves
    # its own HTML at that path (no reverse-proxy rule for the hub) looks like a stream
    # that connects and immediately ends, and the script just spins.
    ctype = res.headers.get("Content-Type", "")
    if "text/event-stream" not in ctype:
        raise RuntimeError(
            f"{url} returned '{ctype or 'no content type'}', not an event stream — "
            "is the hub reverse-proxied at that path? (or set WIKI_HUB_URL)")
    for raw in res:                      # SSE is line-oriented
        line = raw.decode("utf-8").rstrip("\n")
        if line.startswith("data:"):
            yield json.loads(line[5:].strip())


def handle(ev):
    """An event is a hint, never the content — re-read what it names.

    Replace this with whatever your bridge does; to fetch the change, call the REST API:
      chat -> api.php?action=chat_messages&file=<path>&space=<space>
      page -> api.php?action=get&file=<path>&space=<space>
    """
    print(json.dumps(ev), flush=True)


def main():
    backoff = 1
    while True:
        try:
            jwt, url = ticket()
            print(f"[connected] {url}", file=sys.stderr, flush=True)
            backoff = 1
            for ev in stream(jwt, url):
                handle(ev)
            print("[stream ended] reconnecting", file=sys.stderr, flush=True)
        except urllib.error.HTTPError as e:
            # 401 means the ticket expired or the token was revoked; mint a fresh one.
            print(f"[http {e.code}] reconnecting", file=sys.stderr, flush=True)
        except Exception as e:
            print(f"[error] {e}", file=sys.stderr, flush=True)
        time.sleep(backoff)
        backoff = min(backoff * 2, 30)    # never hammer a hub that is down


if __name__ == "__main__":
    main()
