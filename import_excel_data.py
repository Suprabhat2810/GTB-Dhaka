"""
Import Previous Year Data from Excel Files to MySQL
Requires: pip install pandas openpyxl mysql-connector-python
"""

import os
import glob
import pandas as pd
import mysql.connector
from pathlib import Path
import re

# Database configuration
DB_CONFIG = {
    'host': 'localhost',
    'user': 'root',
    'password': 'sup2005',  # Add your MySQL password if any
    'database': 'gtb_database',
    'port': 3307,
}

def extract_program_and_year(filename):
    """Extract program and year level from filename"""
    # Remove extension
    name = Path(filename).stem
    
    # Extract year level
    year_match = re.search(r'(1st|2nd|3rd|First|Second|Third)\s*Year', name, re.IGNORECASE)
    year_level = year_match.group(0) if year_match else '1 Year'
    
    # Extract program (everything before year level)
    program = re.sub(r'(1st|2nd|3rd|First|Second|Third)\s*Year', '', name, flags=re.IGNORECASE).strip()
    
    return program, year_level

def import_excel_file(file_path, academic_year, status, conn, cursor):
    """Import a single Excel file"""
    try:
        # Read Excel file
        df = pd.read_excel(file_path)
        
        # Extract program and year from filename
        program, year_level = extract_program_and_year(file_path)
        filename = os.path.basename(file_path)
        
        print(f"   📄 {status}: {filename}")
        print(f"      Program: {program} | Year: {year_level}")
        
        # Find columns (case-insensitive)
        columns_lower = {col.lower(): col for col in df.columns}
        
        name_col = next((v for k, v in columns_lower.items() if 'name' in k and 'father' not in k), None)
        roll_col = next((v for k, v in columns_lower.items() if 'roll' in k or 'reg' in k), None)
        father_col = next((v for k, v in columns_lower.items() if 'father' in k), None)
        fee_col = next((v for k, v in columns_lower.items() if 'total' in k and 'fee' in k), None)
        paid_col = next((v for k, v in columns_lower.items() if 'paid' in k or 'deposit' in k), None)
        pending_col = next((v for k, v in columns_lower.items() if 'pending' in k or 'balance' in k), None)
        
        imported = 0
        
        for _, row in df.iterrows():
            # Skip empty rows
            if pd.isna(row.get(name_col)) or str(row.get(name_col)).strip() == '':
                continue
            
            student_name = str(row.get(name_col, '')).strip()
            roll_number = str(row.get(roll_col, '')) if roll_col else ''
            father_name = str(row.get(father_col, '')) if father_col else ''
            total_fee = float(row.get(fee_col, 0)) if fee_col and pd.notna(row.get(fee_col)) else 0
            paid_amount = float(row.get(paid_col, 0)) if paid_col and pd.notna(row.get(paid_col)) else 0
            pending_amount = float(row.get(pending_col, total_fee - paid_amount)) if pending_col and pd.notna(row.get(pending_col)) else (total_fee - paid_amount)
            
            # Insert into database
            query = """
                INSERT INTO previous_year_data 
                (academic_year, program, year_level, status, student_name, roll_number, 
                 father_name, total_fee, paid_amount, pending_amount, source_file)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            """
            
            cursor.execute(query, (
                academic_year, program, year_level, status, student_name, roll_number,
                father_name, total_fee, paid_amount, pending_amount, filename
            ))
            
            imported += 1
        
        conn.commit()
        print(f"      ✅ Imported {imported} records\n")
        return imported
        
    except Exception as e:
        print(f"      ❌ Error: {str(e)}\n")
        return 0

def main():
    print("📊 IMPORTING PREVIOUS YEAR DATA")
    print("=" * 80)
    print()
    
    # Connect to database
    conn = mysql.connector.connect(**DB_CONFIG)
    cursor = conn.cursor()
    
    base_dir = Path(__file__).parent / 'Previous_Year_Data' / 'GTB Fees Reporting'
    total_imported = 0
    
    # Get all academic year folders
    year_folders = sorted([f for f in base_dir.iterdir() if f.is_dir()])
    
    for year_folder in year_folders:
        academic_year = year_folder.name
        print(f"📅 Processing Academic Year: {academic_year}")
        print("-" * 80)
        
        # Process Active and Releave folders
        for status in ['Active', 'Releave']:
            status_folder = year_folder / status
            
            if not status_folder.exists():
                continue
            
            # Get all Excel files
            excel_files = list(status_folder.glob('*.xlsx')) + list(status_folder.glob('*.xls'))
            
            for file_path in excel_files:
                imported = import_excel_file(str(file_path), academic_year, status, conn, cursor)
                total_imported += imported
        
        print()
    
    # Update stats table
    print("📊 Updating Statistics...")
    print("-" * 80)
    
    stats_query = """
        INSERT INTO previous_year_stats 
        (academic_year, total_students, total_active, total_releave, 
         total_fee_collected, total_pending, programs_count)
        SELECT 
            academic_year,
            COUNT(*) as total_students,
            SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as total_active,
            SUM(CASE WHEN status = 'Releave' THEN 1 ELSE 0 END) as total_releave,
            SUM(paid_amount) as total_fee_collected,
            SUM(pending_amount) as total_pending,
            COUNT(DISTINCT program) as programs_count
        FROM previous_year_data
        GROUP BY academic_year
        ON DUPLICATE KEY UPDATE
            total_students = VALUES(total_students),
            total_active = VALUES(total_active),
            total_releave = VALUES(total_releave),
            total_fee_collected = VALUES(total_fee_collected),
            total_pending = VALUES(total_pending),
            programs_count = VALUES(programs_count)
    """
    
    cursor.execute(stats_query)
    conn.commit()
    
    print("✅ Statistics updated\n")
    
    # Show summary
    print("=" * 80)
    print("✅ IMPORT COMPLETE!")
    print("=" * 80)
    print(f"Total Records Imported: {total_imported}\n")
    
    # Show stats by year
    print("📊 Summary by Academic Year:")
    print("-" * 80)
    
    cursor.execute("""
        SELECT 
            academic_year,
            total_students,
            total_active,
            total_releave,
            FORMAT(total_fee_collected, 2) as collected,
            FORMAT(total_pending, 2) as pending,
            programs_count
        FROM previous_year_stats
        ORDER BY academic_year
    """)
    
    for row in cursor.fetchall():
        print(f"{row[0]}:")
        print(f"   Students: {row[1]} (Active: {row[2]}, Releave: {row[3]})")
        print(f"   Programs: {row[6]}")
        print(f"   Collected: ₹{row[4]} | Pending: ₹{row[5]}\n")
    
    cursor.close()
    conn.close()
    
    print("=" * 80)

if __name__ == '__main__':
    main()
