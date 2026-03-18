📥 Tax Rate Import Feature — ZIP + CSV Workflow Specification
This feature allows users to import complete tax‑rate data for a state by uploading:
- A ZIP file containing:
- FIPS County Reference File (TXT, pipe‑delimited)
- Boundary Files (TXT, ZIP+4 → jurisdiction mappings)
- A separate SSTGB Rate File (CSV, no headers)
Project Alpha (PA) must extract, parse, validate, and combine all three data sources to build a complete and accurate tax‑jurisdiction dataset.

🎯 Purpose
- Allow users to import state tax rates, county tax rates, city tax rates, and special district rates.
- Support the official formats published by state revenue departments and the Streamlined Sales Tax Governing Board (SSTGB).
- Provide a single import workflow that handles:
- FIPS county names
- Jurisdiction rates
- ZIP+4 boundary mappings
- Produce a detailed summary report showing:
- How many records were imported
- How many were updated
- How many were skipped
- Any mismatches or missing FIPS codes
- Ensure the system is robust, fault‑tolerant, and repeatable.

📁 Files the User Uploads
1. ZIP File (Required)
The ZIP file must contain:
A. FIPS County Reference File (TXT)
Format (pipe‑delimited):
STATE|STATEFP|COUNTYFP|COUNTYNS|COUNTYNAME|CLASSFP|FUNCSTAT
WI|55|001|01581060|Adams County|H1|A


Used to map:
- STATEFP + COUNTYFP → County Name
B. Boundary Files (TXT)
These map ZIP+4 codes to:
- County FIPS
- City FIPS
- Special district codes
These files determine which jurisdictions apply to a given address.

2. SSTGB Rate File (CSV, Required)
Downloaded separately from the state website.
Format (no headers):
55,00,079,0.009,0.009,0.009,0.009,20240101,99991231


Columns represent:
- State FIPS
- County FIPS
- Jurisdiction code
- State rate
- County rate
- City rate
- Special district rate
- Start date
- End date

🧩 Import Workflow
✔️ Step 1 — User Uploads ZIP + CSV
PA should present a form requiring:
- ZIP file (FIPS + boundary files)
- CSV file (SSTGB rates)
Both must be present to continue.

✔️ Step 2 — Extract ZIP
PA extracts the ZIP into a temporary directory and verifies:
- FIPS file exists
- Boundary files exist
- File formats are correct
If anything is missing → show a validation error.

✔️ Step 3 — Parse FIPS County File
PA reads the pipe‑delimited TXT and builds a lookup:
fips[state_fips][county_fips] = county_name


Store in:
- A DB table (fips_counties)
- Or a cached JSON file
This lookup is required for interpreting the CSV.

✔️ Step 4 — Parse Boundary Files
PA reads each boundary row and inserts into:
tax_boundaries
- zip9
- state_fips
- county_fips
- city_fips
- special_district_code
This table determines which jurisdictions apply to a given address.

✔️ Step 5 — Parse SSTGB Rate CSV
For each row:
- Extract:
- state_fips
- county_fips
- jurisdiction_code
- four rate columns
- start/end dates
- Compute:
local_rate = state_rate + county_rate + city_rate + special_rate
- Determine jurisdiction type:
- If county_fips ≠ "00" → county
- If city_fips appears in boundary files → city
- If special district code appears → special
- Lookup county name using FIPS file
- Insert/update tax_jurisdictions table

✔️ Step 6 — Summary Report
After import, PA must display:
Import Summary
--------------
FIPS counties imported: X
Boundary rows imported: Y
Jurisdictions imported: Z

County mismatches: A
City mismatches: B
Special district mismatches: C

Skipped rows (inactive date ranges): D
Errors: E


If mismatches exist, list them:
Unknown FIPS Codes:
- State 55, County 999
- State 55, County 142



📅 Recommended Import Frequency
Most states update tax rates:
- Once per year (January)
- Occasionally mid‑year for special districts
PA should recommend:
🎯 The Goal
Make PA smart enough to:
- Use simple county tax for 95% of clients
- Use boundary tables only when needed
- Automatically detect when a ZIP code is in a “complex jurisdiction”
- Avoid forcing users to enter ZIP+4 unless required
- Keep the workflow simple for most users
- Still be 100% accurate for Milwaukee‑style cases

🧠 The Key Insight
Boundary tables are only needed when city‑level or special‑district taxes exist.
So PA should:
- Identify which counties or ZIP codes have city/special taxes
- Flag those ZIP codes as “complex”
- Require ZIP+4 only for those ZIP codes
- Use county‑only tax for all others
This gives you the best of both worlds:
- Simplicity
- Accuracy
- Performance
- User‑friendly workflow

🧱 How to Implement This (Clean & Robust)
✔️ Step 1 — During Import, Build a “Complex ZIP” List
When parsing the boundary files:
- For each ZIP9, check if:
- city_fips is not null
- OR special_district_code is not null
- OR the city spans multiple counties (Milwaukee case)
If yes → mark the ZIP5 as complex.
Store this in a new table:
tax_zip_complexity
|  |  |  | 
|  |  |  | 
|  |  |  | 
|  |  |  | 
|  |  |  | 


This table is tiny and fast.

✔️ Step 2 — At Client Creation, Check ZIP5
When the user enters a ZIP code:
If ZIP5 is not complex:
- Only require ZIP5
- Apply county‑level tax
- Skip boundary lookup
- No ZIP+4 needed
- No city tax applied
If ZIP5 is complex:
- Ask the user for ZIP+4
- Or run a USPS lookup
- Use boundary tables to determine:
- county
- city
- special district
This ensures accuracy where it matters.

✔️ Step 3 — At Invoice Time, Apply the Correct Logic
Simple ZIP (not complex)
Use:
- state rate
- county rate
Ignore:
- city
- special district
Complex ZIP
Use:
- state rate
- county rate
- city rate
- special district rate
This is how you correctly handle Milwaukee’s 2% city tax.

🧨 Why This Is the Best Approach
✔️ 95% of clients get a simple workflow
No ZIP+4.
No boundary lookup.
No complexity.
✔️ Only “interesting” ZIP codes trigger advanced logic
Milwaukee
Madison (if they ever add city tax)
Premier resort areas
Special districts
✔️ You avoid unnecessary database lookups
Boundary tables can be millions of rows.
You don’t want to query them unless needed.
✔️ You maintain full accuracy
City‑level taxes are applied only when appropriate.
✔️ You future‑proof the system
If a new city adds a tax, the next import will automatically mark its ZIPs as complex.

🧩 Add This to Your Import Prompt (Suggested Wording)
Here’s the exact text you can add to your tax‑import spec:

ZIP Complexity Detection
During import, the system must analyze the boundary files to determine which ZIP codes require city‑level or special‑district tax calculations. For each ZIP9 entry:
- If the boundary row contains a non‑null city_fips or special_district_code, or if the city spans multiple counties, the system must mark the corresponding ZIP5 as complex.
- Complex ZIP codes require ZIP+4 input and full boundary lookup during tax calculation.
- Non‑complex ZIP codes may use county‑level tax only and do not require ZIP+4.
The system must store this in a tax_zip_complexity table and use it during client creation and invoice tax calculation.


