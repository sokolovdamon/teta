#!/usr/bin/env python3
"""Compact summary of `php artisan test` JSON output (laravel/pao): counts and first line of each failure."""
import json, re, sys

raw = sys.stdin.read()
m = re.search(r'\{"tool":"phpunit".*\}', raw, re.S)
if not m:
    print(raw[-4000:])
    sys.exit(1)
data = json.loads(m.group(0))
print(f"{data['result']}: {data.get('passed', 0)}/{data.get('tests', 0)} passed, {data.get('assertions', 0)} assertions, {data.get('duration_ms', 0)} ms")
for f in data.get('failures', []) + data.get('errors', []):
    msg = f.get('message', '')
    exc = re.search(r'The following exception occurred during the last request:\s*\n\s*\n(.+?)(?: in /|\n)', msg)
    first = exc.group(1) if exc else msg.split('\n')[0]
    print(f"- {f['test']}\n    {first[:400]}")
sys.exit(0 if data['result'] == 'passed' else 1)
