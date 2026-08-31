#!/usr/bin/env python3
import datetime as dt
import hashlib
import json
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

REPO = os.environ.get("GITHUB_REPOSITORY", "abaadapp/abaad.Saas")
GITHUB_TOKEN = os.environ.get("GITHUB_TOKEN", "")
NOTION_TOKEN = os.environ.get("NOTION_TOKEN", "")
NOTION_DATA_SOURCE_ID = os.environ.get("NOTION_DATA_SOURCE_ID", "13c08848-1445-4c8e-b209-d6c3202a2e41")
NOTION_VERSION = os.environ.get("NOTION_VERSION", "2025-09-03")
GITHUB_EVENT_PATH = os.environ.get("GITHUB_EVENT_PATH", "")

ALLOWED = {
    "Type": {"Feature", "Fix"},
    "Status": {"Backlog", "Ready", "In Progress", "Blocked", "In Review", "Changes Requested", "Ready to Merge", "Merged", "Done"},
    "Owner": {"Saad", "Udai", "ChatGPT"},
    "Priority": {"P0", "P1", "P2", "P3"},
}
SECTIONS = ["Type", "Status", "Owner", "Priority", "Area / Files", "Branch", "GitHub PR", "Depends On", "Prompt", "Notes"]


def request_json(url, *, method="GET", headers=None, body=None):
    data = None if body is None else json.dumps(body).encode("utf-8")
    req = urllib.request.Request(url, data=data, method=method)
    for k, v in (headers or {}).items():
        req.add_header(k, v)
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            raw = resp.read().decode("utf-8")
            return json.loads(raw) if raw else {}
    except urllib.error.HTTPError as exc:
        detail = exc.read().decode("utf-8", errors="replace")
        raise RuntimeError(f"HTTP {exc.code} {method} {url}: {detail}") from exc


def github_headers():
    return {
        "Authorization": f"Bearer {GITHUB_TOKEN}",
        "Accept": "application/vnd.github+json",
        "X-GitHub-Api-Version": "2022-11-28",
        "User-Agent": "abaad-task-sync",
    }


def notion_headers():
    return {
        "Authorization": f"Bearer {NOTION_TOKEN}",
        "Content-Type": "application/json",
        "Notion-Version": NOTION_VERSION,
        "User-Agent": "abaad-task-sync",
    }


def parse_sections(body):
    body = body or ""
    heading_re = re.compile(r"^###\s+(.+?)\s*$", re.MULTILINE)
    matches = list(heading_re.finditer(body))
    found = {}
    for i, m in enumerate(matches):
        name = m.group(1).strip()
        if name not in SECTIONS:
            continue
        start = m.end()
        end = matches[i + 1].start() if i + 1 < len(matches) else len(body)
        value = body[start:end].strip()
        if value == "_No response_":
            value = ""
        found[name] = value
    return found


def validate_issue(issue):
    sections = parse_sections(issue.get("body"))
    missing = [k for k in ("Type", "Status", "Owner", "Priority", "Prompt") if not sections.get(k)]
    if missing:
        raise ValueError(f"Issue #{issue['number']} missing required sections: {', '.join(missing)}")
    for field, allowed in ALLOWED.items():
        value = sections.get(field, "")
        if value not in allowed:
            raise ValueError(f"Issue #{issue['number']} has invalid {field}: {value!r}. Allowed: {sorted(allowed)}")
    if issue.get("state") == "closed":
        sections["Status"] = "Done"
    return sections


def canonical_payload(issue, sections):
    return {
        "repo": REPO,
        "issue_number": int(issue["number"]),
        "issue_url": issue["html_url"],
        "task": issue["title"].strip(),
        "type": sections["Type"],
        "status": sections["Status"],
        "owner": sections["Owner"],
        "priority": sections["Priority"],
        "area_files": sections.get("Area / Files", ""),
        "branch": sections.get("Branch", ""),
        "github_pr": sections.get("GitHub PR", ""),
        "depends_on": sections.get("Depends On", ""),
        "prompt": sections.get("Prompt", ""),
        "notes": sections.get("Notes", ""),
    }


def sha256_payload(payload):
    encoded = json.dumps(payload, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")
    return hashlib.sha256(encoded).hexdigest()


def rich_text(value):
    value = value or ""
    return {"rich_text": [] if not value else [{"type": "text", "text": {"content": value[:2000]}}]}


def title(value):
    return {"title": [{"type": "text", "text": {"content": value[:2000]}}]}


def select(value):
    return {"select": {"name": value}}


def notion_properties(payload, sync_hash):
    now = dt.datetime.now(dt.timezone.utc).replace(microsecond=0).isoformat()
    sync_key = f"{REPO}#{payload['issue_number']}"
    return {
        "Task": title(payload["task"]),
        "Type": select(payload["type"]),
        "Status": select(payload["status"]),
        "Owner": select(payload["owner"]),
        "Priority": select(payload["priority"]),
        "Area / Files": rich_text(payload["area_files"]),
        "Branch": rich_text(payload["branch"]),
        "GitHub PR": {"url": payload["github_pr"] or None},
        "Depends On": rich_text(payload["depends_on"]),
        "Prompt": rich_text(payload["prompt"]),
        "Notes": rich_text(payload["notes"]),
        "GitHub Issue": {"number": payload["issue_number"]},
        "GitHub Issue URL": {"url": payload["issue_url"]},
        "Sync Key": rich_text(sync_key),
        "Sync Hash": rich_text(sync_hash),
        "Synced At": {"date": {"start": now}},
    }


def query_notion_page(sync_key):
    url = f"https://api.notion.com/v1/data_sources/{NOTION_DATA_SOURCE_ID}/query"
    result = request_json(
        url,
        method="POST",
        headers=notion_headers(),
        body={"filter": {"property": "Sync Key", "rich_text": {"equals": sync_key}}, "page_size": 2},
    )
    rows = result.get("results", [])
    if len(rows) > 1:
        raise RuntimeError(f"Duplicate Notion rows for Sync Key {sync_key}; refusing to guess.")
    return rows[0] if rows else None


def sync_issue(issue):
    if "pull_request" in issue:
        return
    sections = validate_issue(issue)
    payload = canonical_payload(issue, sections)
    sync_hash = sha256_payload(payload)
    sync_key = f"{REPO}#{payload['issue_number']}"
    props = notion_properties(payload, sync_hash)
    existing = query_notion_page(sync_key)
    if existing:
        page_id = existing["id"]
        request_json(
            f"https://api.notion.com/v1/pages/{page_id}",
            method="PATCH",
            headers=notion_headers(),
            body={"properties": props},
        )
        print(f"updated Notion <- GitHub #{payload['issue_number']}: {payload['task']}")
    else:
        request_json(
            "https://api.notion.com/v1/pages",
            method="POST",
            headers=notion_headers(),
            body={
                "parent": {"type": "data_source_id", "data_source_id": NOTION_DATA_SOURCE_ID},
                "properties": props,
            },
        )
        print(f"created Notion <- GitHub #{payload['issue_number']}: {payload['task']}")


def event_issue():
    if not GITHUB_EVENT_PATH or not os.path.exists(GITHUB_EVENT_PATH):
        return None
    with open(GITHUB_EVENT_PATH, "r", encoding="utf-8") as fh:
        event = json.load(fh)
    return event.get("issue")


def list_all_issues():
    owner, repo = REPO.split("/", 1)
    page = 1
    while True:
        query = urllib.parse.urlencode({"state": "all", "per_page": 100, "page": page, "sort": "updated", "direction": "desc"})
        url = f"https://api.github.com/repos/{owner}/{repo}/issues?{query}"
        rows = request_json(url, headers=github_headers())
        if not rows:
            return
        for issue in rows:
            if "pull_request" not in issue:
                yield issue
        if len(rows) < 100:
            return
        page += 1


def main():
    if not NOTION_TOKEN:
        print("NOTION_TOKEN is not configured; sync intentionally skipped.")
        return 0
    if not GITHUB_TOKEN:
        print("GITHUB_TOKEN is not configured.", file=sys.stderr)
        return 2

    issue = event_issue()
    if issue:
        sync_issue(issue)
        return 0

    count = 0
    for item in list_all_issues():
        try:
            sync_issue(item)
            count += 1
        except ValueError as exc:
            print(f"SKIP INVALID: {exc}", file=sys.stderr)
    print(f"reconciliation complete: {count} valid GitHub issues processed")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
