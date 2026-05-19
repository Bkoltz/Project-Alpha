ROBUST GAMEPLAN FOR IMPORTING WI TAXES
(This is the architecture you want — simple, layered, and guaranteed to work.)

PHASE 1 — Normalize the Data Sources (3 separate importers)
You should never try to import all three files in one pass.
Instead, create three independent importers, each responsible for one file type.
This makes debugging, scaling, and maintenance dramatically easier.

✅ Importer A — County & County‑Subdivision FIPS (TXT)
This file gives you:
- County FIPS
- County names
- City/town/village names
- City FIPS (COUSUBFP)
- Functional status (active/inactive)
Store into table: fips_places
|  |  | 
|  |  | 
|  |  | 
|  |  | 
|  |  | 
|  |  | 
|  |  | 
|  |  | 


This table is static and rarely changes.

✅ Importer B — SSTGB Rate CSV
This file gives you:
- State rate
- County rate
- City rate
- Special district rate
- Jurisdiction code
- Start/end dates
Store into table: tax_jurisdictions
|  |  | 
|  |  | 
|  |  | 
|  |  | 
|  |  | 
|  |  | 
|  |  | 
|  |  | 
|  |  | 
|  |  | 
|  |  | 
|  |  | 


Important:
Wisconsin repeats the same rate in all 4 columns.
You sum them to get the local rate.

✅ Importer C — Boundary Files (ZIP+4 ranges)
This file gives you:
- ZIP5
- ZIP4 low
- ZIP4 high
- County FIPS
- City FIPS
- Special district code
- Effective dates
Store into table: tax_boundaries
|  |  | 
|  |  | 
|  |  | 
|  |  | 
|  |  | 
|  |  | 
|  |  | 
|  |  | 


This table is huge.
You must stream it line‑by‑line.

PHASE 2 — Build a “ZIP Complexity” Table
This is the key to making your system fast and user‑friendly.
During boundary import:
If a ZIP5 has:
- A city_fips
- A special district
- Multiple counties
- Multiple city jurisdictions
→ mark ZIP5 as complex.
Store into table: tax_zip_complexity
|  |  |  | 
|  |  |  | 
|  |  |  | 


This lets you avoid ZIP+4 lookups for 95% of clients.

PHASE 3 — Tax Calculation Logic (The Heart of the System)
When creating an invoice:

STEP 1 — User enters ZIP5 (and ZIP+4 if needed)
If ZIP5 is not complex:
- Only apply state + county tax
- No boundary lookup
- No city tax
- No special district tax
If ZIP5 is complex:
- Require ZIP+4
- Lookup ZIP9 in tax_boundaries
- Determine:
- county_fips
- city_fips
- special_code

STEP 2 — Lookup Jurisdiction Rates
County rate
Find active row in tax_jurisdictions where:
- county_fips matches
- city_fips is NULL
- special_code is NULL
City rate
Find active row where:
- city_fips matches
Special district rate
Find active row where:
- special_code matches
State rate
Static (5% for WI).

STEP 3 — Combine Rates
total_rate =
    state_rate
  + county_rate
  + city_rate
  + special_rate


This gives you the correct tax for any address.

PHASE 4 — Performance & Reliability
1. Stream all large files
Use SplFileObject or a generator.
2. Batch database inserts
500–2000 rows per batch.
3. Use background jobs
Never import in a web request.
4. Log everything
- Rows processed
- Rows skipped
- Mismatches
- Errors
5. Allow re‑imports
Overwrite old data cleanly.

PHASE 5 — Summary of the Full Gameplan
Here’s the complete architecture in one list:
1. Import FIPS places (TXT)
→ Build county + city lookup table.
2. Import SSTGB rates (CSV)
→ Build jurisdiction rate table.
3. Import boundary files (TXT)
→ Build ZIP9 → jurisdiction mapping.
4. Build ZIP complexity table
→ Only require ZIP+4 when needed.
5. At invoice time
→ Determine jurisdictions
→ Lookup rates
→ Combine
→ Apply tax
6. Use streaming + batching
→ Avoid memory issues.
7. Use background jobs
→ Avoid timeouts.
8. Provide import summary
→ Rows imported
→ Rows updated
→ Mismatches
→ Errors
