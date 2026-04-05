"""
Import Previous Year Subject Details from Excel Files to MySQL
"""

import os
import glob
import pandas as pd
import mysql.connector
from pathlib import Path
import re
import json

# Database configuration
DB_CONFIG = {
    'host': 'localhost',
    'user': 'root',
    'password': 'sup2005',
    'database': 'gtb_database',
    'port': 3307,
}

def extract_program_and_year(filename):
    """Extract program and year level from filename"""
    name = Path(filename).stem
    
    # Extract year level
    year_match = re.search(r'(1st|2nd|3rd|First|Second|Third)\s*Year', name, re.IGNORECASE)
    year_level = year_match.group(0) if year_match else '1 Year'
    
    # Extract program
    program = re.sub(r'(1st|2nd|3rd|First|Second|Third)\s*Year', '', name, flags=re.IGNORECASE).strip()
    
    return program, year_level

def import_excel_file(file_path, academic_year, conn, cursor):
    """Import a single Excel file"""
    try:
        # Read Excel file
        df = pd.read_excel(file_path)
        
        # Extract program and year from filename
        program, year_level = extract_program_and_year(file_path)
        filename = os.path.basename(file_path)
        
        print(f"   📄 {filename}")
        print(f"      Program: {program} | Year: {year_level}")
        
        # Find columns (case-insensitive)
        columns_lower = {col.lower(): col for col in df.columns}
        
        # Common column mappings
        name_col = next((v for k, v in columns_lower.items() if 'name' in k and 'subject' not in k), None)
        roll_col = next((v for k, v in columns_lower.items() if 'roll' in k or 'reg' in k), None)
        
        # Find subject-related columns (they usually have subject names or codes)
        subject_cols = []
        for col in df.columns:
            col_lower = col.lower()
            # Skip name and roll columns
            if col == name_col or col == roll_col:
                continue
            # Include columns that might be subjects
            if any(keyword in col_lower for keyword in ['subject', 'paper', 'course', 'marks', 'grade', 'result']):
                subject_cols.append(col)
            # Also include columns that look like subject codes/names
            elif not any(keyword in col_lower for keyword in ['name', 'roll', 'reg', 'total', 'percentage', 'cgpa', 'sgpa']):
                subject_cols.append(col)
        
        imported = 0
        
        for _, row in df.iterrows():
            # Skip empty rows
            if pd.isna(row.get(name_col)) or str(row.get(name_col)).strip() == '':
                continue
            
            student_name = str(row.get(name_col, '')).strip()
            roll_number = str(row.get(roll_col, '')) if roll_col and pd.notna(row.get(roll_col)) else None
            
            # Collect all subject data
            subjects_data = []
            for col in subject_cols:
                value = row.get(col)
                if pd.notna(value) and str(value).strip() != '':
                    subjects_data.append({
                        'subject_name': col,
                        'value': str(value)
                    })
            
            # If we have subject data, store it
            if subjects_data:
                # Store as one record with JSON data
                query = """
                    INSERT INTO previous_year_subjects 
                    (academic_year, program, year_level, student_name, roll_number, 
                     subjects_data, source_file)
                    VALUES (%s, %s, %s, %s, %s, %s, %s)
                """
                
                cursor.execute(query, (
                    academic_year, program, year_level, student_name, roll_number,
                    json.dumps(subjects_data), filename
                ))
                
                imported += 1
        
        conn.commit()
        print(f"      ✅ Imported {imported} records\n")
        return imported
        
    except Exception as e:
        print(f"      ❌ Error: {str(e)}\n")
        import traceback
        traceback.print_exc()
        return 0

def main():
    print("📊 IMPORTING PREVIOUS YEAR SUBJECT DETAILS")
    print("=" * 80)
    print()
    
    # Connect to database
    conn = mysql.connector.connect(**DB_CONFIG)
    cursor = conn.cursor()
    
    base_dir = Path(__file__).parent / 'Previous_Year_Data' / 'GTB Student Subject Details'
    total_imported = 0
    
    # Get all academic year folders
    year_folders = sorted([f for f in base_dir.iterdir() if f.is_dir()])
    
    for year_folder in year_folders:
        academic_year = year_folder.name
        print(f"📅 Processing Academic Year: {academic_year}")
        print("-" * 80)
        
        # Get all Excel files
        excel_files = list(year_folder.glob('*.xlsx')) + list(year_folder.glob('*.xls'))
        
        for file_path in excel_files:
            imported = import_excel_file(str(file_path), academic_year, conn, cursor)
            total_imported += imported
        
        print()
    
    # Show summary
    print("=" * 80)
    print("✅ IMPORT COMPLETE!")
    print("=" * 80)
    print(f"Total Subject Records Imported: {total_imported}\n")
    
    # Show stats by year
    print("📊 Summary by Academic Year:")
    print("-" * 80)
    
    cursor.execute("""
        SELECT 
            academic_year,
            COUNT(*) as total_records,
            COUNT(DISTINCT program) as programs,
            COUNT(DISTINCT student_name) as unique_students
        FROM previous_year_subjects
        GROUP BY academic_year
        ORDER BY academic_year
    """)
    
    for row in cursor.fetchall():
        print(f"{row[0]}:")
        print(f"   Records: {row[1]} | Programs: {row[2]} | Students: {row[3]}\n")
    
    cursor.close()
    conn.close()
    
    print("=" * 80)

if __name__ == '__main__':
    main()
