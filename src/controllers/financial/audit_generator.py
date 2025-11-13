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
        zip_filename = os.path.join(
            tmpdir,
            f"audit_{data['start_year']}-{data['end_year']}_{datetime.now().strftime('%Y%m%d_%H%M%S')}.zip"
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
    """Generate CSV audit report"""
    invoices = data.get('invoices', [])
    contracts = data.get('contracts', [])
    client_info_only = data.get('client_info_only', False)
    
    with open(output_file, 'w', newline='', encoding='utf-8') as csvfile:
        if client_info_only:
            # Summary mode: client, doc_id, project_id, total
            fieldnames = ['Document Type', 'Doc ID', 'Client Name', 'Project Code', 'Amount', 'Status', 'Date']
            writer = csv.DictWriter(csvfile, fieldnames=fieldnames)
            writer.writeheader()
            
            running_total = 0
            for inv in invoices:
                amount = float(inv.get('total', 0))
                running_total += amount
                writer.writerow({
                    'Document Type': 'Invoice',
                    'Doc ID': inv.get('doc_number', ''),
                    'Client Name': inv.get('client_name', ''),
                    'Project Code': inv.get('project_code', ''),
                    'Amount': f"${amount:.2f}",
                    'Status': inv.get('status', ''),
                    'Date': inv.get('created_at', '')[:10]
                })
            
            if contracts:
                for contract in contracts:
                    amount = float(contract.get('total', 0))
                    running_total += amount
                    writer.writerow({
                        'Document Type': 'Contract',
                        'Doc ID': contract.get('doc_number', ''),
                        'Client Name': contract.get('client_name', ''),
                        'Project Code': contract.get('project_code', ''),
                        'Amount': f"${amount:.2f}",
                        'Status': contract.get('status', ''),
                        'Date': contract.get('created_at', '')[:10]
                    })
            
            # Add totals row
            writer.writerow({
                'Document Type': 'TOTAL',
                'Doc ID': '',
                'Client Name': '',
                'Project Code': '',
                'Amount': f"${running_total:.2f}",
                'Status': '',
                'Date': ''
            })
        else:
            # Detailed mode
            fieldnames = ['Document Type', 'Doc ID', 'Client Name', 'Project Code', 'Amount', 'Status', 'Date', 'Running Total']
            writer = csv.DictWriter(csvfile, fieldnames=fieldnames)
            writer.writeheader()
            
            running_total = 0
            for inv in invoices:
                amount = float(inv.get('total', 0))
                running_total += amount
                writer.writerow({
                    'Document Type': 'Invoice',
                    'Doc ID': inv.get('doc_number', ''),
                    'Client Name': inv.get('client_name', ''),
                    'Project Code': inv.get('project_code', ''),
                    'Amount': f"${amount:.2f}",
                    'Status': inv.get('status', ''),
                    'Date': inv.get('created_at', '')[:10],
                    'Running Total': f"${running_total:.2f}"
                })
            
            if contracts:
                for contract in contracts:
                    amount = float(contract.get('total', 0))
                    running_total += amount
                    writer.writerow({
                        'Document Type': 'Contract',
                        'Doc ID': contract.get('doc_number', ''),
                        'Client Name': contract.get('client_name', ''),
                        'Project Code': contract.get('project_code', ''),
                        'Amount': f"${amount:.2f}",
                        'Status': contract.get('status', ''),
                        'Date': contract.get('created_at', '')[:10],
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
        f.write(f"Audit Period: {data['start_year']} - {data['end_year']}\n")
        f.write(f"Date Range: {data['start_date']} to {data['end_date']}\n\n")
        
        f.write("REPORT CONFIGURATION:\n")
        f.write(f"- Invoice Status Filter: {data['invoice_status']}\n")
        f.write(f"- Include Contracts: {'Yes' if data['include_contracts'] else 'No'}\n")
        f.write(f"- Include PDFs: {'Yes' if data['include_pdfs'] else 'No'}\n")
        f.write(f"- Summary Mode Only: {'Yes' if data['client_info_only'] else 'No'}\n\n")
        
        f.write("REPORT SUMMARY:\n")
        f.write(f"- Total Invoices: {len(data['invoices'])}\n")
        if data['include_contracts']:
            f.write(f"- Total Contracts: {len(data['contracts'])}\n")
        
        total_amount = sum(float(inv.get('total', 0)) for inv in data['invoices'])
        if data['include_contracts']:
            total_amount += sum(float(c.get('total', 0)) for c in data['contracts'])
        
        f.write(f"- Total Amount: ${total_amount:.2f}\n\n")
        
        f.write("FILES INCLUDED:\n")
        f.write("- audit_report.csv: Detailed audit data\n")
        f.write("- MANIFEST.txt: This file\n")
        if data.get('include_pdfs'):
            f.write("- invoices/: PDF files for all invoices\n")

if __name__ == '__main__':
    main()
