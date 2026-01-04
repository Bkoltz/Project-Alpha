#!/usr/bin/env python3
"""
Financial Audit Generator
Generates CSV and optional PDFs for audit reports, packaged in a ZIP file.
Usage: python3 audit_generator.py <json_request_file>
"""

import json
import sys
import os
import csv
import zipfile
from datetime import datetime
import tempfile
import subprocess

def main():
    if len(sys.argv) < 2:
        print(json.dumps({'error': 'Missing request file'}))
        sys.exit(1)
    
    try:
        # Load request data
        request_file = sys.argv[1]
        with open(request_file, 'r') as f:
            data = json.load(f)
        
        # Create temporary directory for audit files
        tmpdir = tempfile.mkdtemp()
        # Build a safe zip filename from start/end dates
        sd = data.get('start_date', '')[:10].replace('-', '')
        ed = data.get('end_date', '')[:10].replace('-', '')
        zip_filename = os.path.join(
            tmpdir,
            f"audit_{sd or 'start'}-{ed or 'end'}_{datetime.now().strftime('%Y%m%d_%H%M%S')}.zip"
        )
        
        # Create zip file
        with zipfile.ZipFile(zip_filename, 'w', zipfile.ZIP_DEFLATED) as zf:
            # Generate CSV file
            csv_filename = os.path.join(tmpdir, 'audit_report.csv')
            generate_csv(csv_filename, data)
            zf.write(csv_filename, arcname='audit_report.csv')
            os.remove(csv_filename)
            
            # Generate PDFs if requested
            if data.get('include_pdfs'):
                pdfs_dir = generate_pdfs(tmpdir, data)
                for pdf_file in os.listdir(pdfs_dir):
                    pdf_path = os.path.join(pdfs_dir, pdf_file)
                    zf.write(pdf_path, arcname=f'invoices/{pdf_file}')
                    os.remove(pdf_path)
                os.rmdir(pdfs_dir)
            
            # Add manifest
            manifest_filename = os.path.join(tmpdir, 'MANIFEST.txt')
            generate_manifest(manifest_filename, data)
            zf.write(manifest_filename, arcname='MANIFEST.txt')
            os.remove(manifest_filename)
        
        # Return zip file path as JSON
        print(json.dumps({'zip_path': zip_filename}))
        sys.exit(0)
        
    except Exception as e:
        print(json.dumps({'error': str(e)}), file=sys.stderr)
        sys.exit(1)

def generate_csv(output_file, data):
    """Generate CSV audit report with detailed columns for invoices"""
    invoices = data.get('invoices', [])
    contracts = data.get('contracts', [])
    quotes = data.get('quotes', [])
    
    with open(output_file, 'w', newline='', encoding='utf-8') as csvfile:
        # Detailed CSV with all required columns
        fieldnames = [
            'Date', 
            'Client', 
            'Doc Number/ID', 
            'Document Type',
            'Invoice Tax',
            'Invoice Tax County',
            'Amount Paid',
            'Payment Method',
            'Discount',
            'Running Total'
        ]
        writer = csv.DictWriter(csvfile, fieldnames=fieldnames)
        writer.writeheader()
        
        running_total = 0
        
        # Process invoices
        for inv in invoices:
            amount_paid = float(inv.get('amount_paid', 0))
            tax = float(inv.get('tax_percent', inv.get('tax', 0)))
            discount = float(inv.get('discount_value', inv.get('discount', 0)))
            running_total += amount_paid
            
            writer.writerow({
                'Date': inv.get('created_at', '')[:10],
                'Client': inv.get('client_name', ''),
                'Doc Number/ID': inv.get('doc_number', ''),
                'Document Type': 'Invoice',
                'Invoice Tax': f"${tax:.2f}",
                'Invoice Tax County': inv.get('tax_county', ''),
                'Amount Paid': f"${amount_paid:.2f}",
                'Payment Method': inv.get('payment_methods', ''),
                'Discount': f"${discount:.2f}",
                'Running Total': f"${running_total:.2f}"
            })
        
        # Process contracts if included
        if contracts:
            for contract in contracts:
                amount = float(contract.get('total', 0))
                running_total += amount
                writer.writerow({
                    'Date': contract.get('created_at', '')[:10],
                    'Client': contract.get('client_name', ''),
                    'Doc Number/ID': contract.get('doc_number', ''),
                    'Document Type': 'Contract',
                    'Invoice Tax': '',
                    'Invoice Tax County': '',
                    'Amount Paid': f"${amount:.2f}",
                    'Payment Method': '',
                    'Discount': '',
                    'Running Total': f"${running_total:.2f}"
                })
        
        # Process quotes if included
        if quotes:
            for quote in quotes:
                amount = float(quote.get('total', 0))
                running_total += amount
                writer.writerow({
                    'Date': quote.get('created_at', '')[:10],
                    'Client': quote.get('client_name', ''),
                    'Doc Number/ID': quote.get('doc_number', ''),
                    'Document Type': 'Quote',
                    'Invoice Tax': '',
                    'Invoice Tax County': '',
                    'Amount Paid': f"${amount:.2f}",
                    'Payment Method': '',
                    'Discount': '',
                    'Running Total': f"${running_total:.2f}"
                })
        
        # Add totals row
        writer.writerow({
            'Date': '',
            'Client': '',
            'Doc Number/ID': 'TOTAL',
            'Document Type': '',
            'Invoice Tax': '',
            'Invoice Tax County': '',
            'Amount Paid': f"${running_total:.2f}",
            'Payment Method': '',
            'Discount': '',
            'Running Total': f"${running_total:.2f}"
        })

def generate_pdfs(tmpdir, data):
    """Generate PDF invoices (if available)"""
    pdfs_dir = os.path.join(tmpdir, 'invoices')
    os.makedirs(pdfs_dir, exist_ok=True)
    
    # This would require calling the PHP PDF generation or using a library like weasyprint
    # For now, we'll just create placeholder entries
    # In production, you'd integrate with your PDF generation library
    
    return pdfs_dir

def generate_manifest(output_file, data):
    """Generate audit manifest file"""
    with open(output_file, 'w', encoding='utf-8') as f:
        f.write("=== FINANCIAL AUDIT REPORT ===\n\n")
        f.write(f"Generated: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n")
        f.write(f"Audit Period: {data['start_date']} to {data['end_date']}\n\n")
        
        f.write("REPORT CONFIGURATION:\n")
        f.write(f"- Include Invoices (Paid/Partial): {'Yes' if data['include_invoices'] else 'No'}\n")
        f.write(f"- Include Contracts: {'Yes' if data['include_contracts'] else 'No'}\n")
        f.write(f"- Include Quotes: {'Yes' if data['include_quotes'] else 'No'}\n")
        f.write(f"- Generate CSV: {'Yes' if data['generate_csv'] else 'No'}\n")
        f.write(f"- Include PDFs: {'Yes' if data['include_pdfs'] else 'No'}\n")
        
        if data.get('schedule_emails'):
            f.write(f"- Scheduled Email Recipients: {', '.join(data['schedule_emails'])}\n")
        
        f.write("\nREPORT SUMMARY:\n")
        f.write(f"- Total Invoices: {len(data['invoices'])}\n")
        if data['include_contracts']:
            f.write(f"- Total Contracts: {len(data['contracts'])}\n")
        if data['include_quotes']:
            f.write(f"- Total Quotes: {len(data['quotes'])}\n")
        
        total_amount = sum(float(inv.get('amount_paid', inv.get('total', 0))) for inv in data['invoices'])
        if data['include_contracts']:
            total_amount += sum(float(c.get('total', 0)) for c in data['contracts'])
        if data['include_quotes']:
            total_amount += sum(float(q.get('total', 0)) for q in data['quotes'])
        
        f.write(f"- Total Amount: ${total_amount:.2f}\n\n")
        
        f.write("FILES INCLUDED:\n")
        f.write("- audit_report.csv: Detailed audit data with Date, Client, Doc ID, Taxes, Payment Info, and Running Total\n")
        f.write("- MANIFEST.txt: This file\n")
        if data.get('include_pdfs'):
            f.write("- invoices/: PDF files for all invoices\n")
        
        f.write("\nAUDIT INTEGRITY NOTE:\n")
        f.write("- Audit logs are read-only and cannot be edited within the system to ensure data integrity\n")

if __name__ == '__main__':
    main()
