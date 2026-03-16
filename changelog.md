Twig Implementation - 03/16/2026

General changes
 - Changed twig rendering through index.php
 - Refactored custom fields through twig, however, the old system is still in use and accessable
 - Added Router.php 
 - Moved most routing out of index.php into Router.php
 - Added changelog.md
 - Added known-issues.md

 Object Oriented Files
   - Twig rendering files are rendered through the Render class
   - All quotes files have been made into oop classes 

Quotes changes
 - Refactored quotes details, creations, editing, and pdf display
 - All quote repository, service, and control classes have been condensed, allowing reuse of similar functions
 - Almost all of the logic has been left mostly unchanged, just moved and reused in service classes
 - QuotesFinances added to consolidate redundant finance logic
 - PDF generation for quotes and twig files has been reconfigured

Database changes
 - Added 'document_type' column for 'quotes'. Currently this isn't used for anything, but in the future I hope to merge the 'is_on_demand' and 'is_long_term' columns into one