import csv, json
from collections import defaultdict
from pathlib import Path

fips = {}
with Path('/tmp/st55_wi_cou2020.txt').open() as f:
    next(f)
    for line in f:
        p = line.strip().split('|')
        if len(p) >= 5:
            fips[p[1] + p[2].zfill(3)] = p[4].replace(' County', '')

agg = defaultdict(float)
with Path('/tmp/WIR062026.csv').open() as f:
    for row in csv.reader(f):
        if len(row) < 9:
            continue
        if row[1].strip() != '00':
            continue
        key = row[0].zfill(2) + row[2].zfill(3)
        # WI DOR rates are expressed as four quarterly/spread columns.
        # The published local rate is the average (sum * 100 / 4).
        local = round(sum(float(c) for c in row[3:7]) * 100 / 4, 4)
        agg[key] = max(agg[key], local)

expected = {fips[k]: round(v + 5.0, 4) for k, v in agg.items() if k in fips}

out = Path('/home/bkoltz/Project-Alpha/tests/fixtures/wi_county_rates_expected.json')
out.write_text(json.dumps(expected, indent=2, sort_keys=True))

print(f"Wrote {len(expected)} counties to {out}")
