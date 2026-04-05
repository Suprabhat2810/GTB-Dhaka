"""
Import Previous Year Student Details from Excel Files to MySQL
"""

import os
import glob
import pandas as pd
import mysql.connector
from pathlib import Path
import re
from datetime import datetime

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

def parse_date(date_val):
    """Parse date from various formats"""
    if pd.isna(date_val) or str(date_val).strip() == '':
        return None
    
    try:
        if isinstance(date_val, datetime):
            return date_val.strftime('%Y-%m-%d')
        
        date_str = str(date_val).strip()
        
        # Try different date formats
        for fmt in ['%Y-%m-%d', '%d-%m-%Y', '%d/%m/%Y', '%Y/%m/%d', '%d.%m.%Y']:
            try:
                return datetime.strptime(date_str, fmt).strftime('%Y-%m-%d')
            except:
                continue
        
        return None
    except:
        return None

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
        name_col = next((v for k, v in columns_lower.items() if 'name' in k and 'father' not in k and 'mother' not in k), None)
        roll_col = next((v for k, v in columns_lower.items() if 'roll' in k or 'reg' in k or 'registration' in k), None)
        father_col = next((v for k, v in columns_lower.items() if 'father' in k), None)
        mother_col = next((v for k, v in columns_lower.items() if 'mother' in k), None)
        dob_col = next((v for k, v in columns_lower.items() if 'birth' in k or 'dob' in k or 'date of birth' in k), None)
        gender_col = next((v for k, v in columns_lower.items() if 'gender' in k or 'sex' in k), None)
        category_col = next((v for k, v in columns_lower.items() if 'category' in k or 'caste' in k), None)
        address_col = next((v for k, v in columns_lower.items() if 'address' in k), None)
        phone_col = next((v for k, v in columns_lower.items() if 'phone' in k or 'mobile' in k or 'contact' in k), None)
        email_col = next((v for k, v in columns_lower.items() if 'email' in k or 'mail' in k), None)
        admission_col = next((v for k, v in columns_lower.items() if 'admission' in k or 'joining' in k), None)
        
        imported = 0
        
        for _, row in df.iterrows():
            # Skip empty rows
            if pd.isna(row.get(name_col)) or str(row.get(name_col)).strip() == '':
                continue
            
            student_name = str(row.get(name_col, '')).strip()
            roll_number = str(row.get(roll_col, '')) if roll_col and pd.notna(row.get(roll_col)) else None
            father_name = str(row.get(father_col, '')) if father_col and pd.notna(row.get(father_col)) else None
            mother_name = str(row.get(mother_col, '')) if mother_col and pd.notna(row.get(mother_col)) else None
            dob = parse_date(row.get(dob_col)) if dob_col else None
            gender = str(row.get(gender_col, '')) if gender_col and pd.notna(row.get(gender_col)) else None
            category = str(row.get(category_col, '')) if category_col and pd.notna(row.get(category_col)) else None
            address = str(row.get(address_col, '')) if address_col and pd.notna(row.get(address_col)) else None
            phone = str(row.get(phone_col, '')) if phone_col and pd.notna(row.get(phone_col)) else None
            email = str(row.get(email_col, '')) if email_col and pd.notna(row.get(email_col)) else None
            admission_date = parse_date(row.get(admission_col)) if admission_col else None
            
            # Insert into database
            query = """
                INSERT INTO previous_year_students 
                (academic_year, program, year_level, student_name, roll_number, 
                 father_name, mother_name, date_of_birth, gender, category,
                 address, phone, email, admission_date, source_file)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            """
            
            cursor.execute(query, (
                academic_year, program, year_level, student_name, roll_number,
                father_name, mother_name, dob, gender, category,
                address, phone, email, admission_date, filename
            ))
            
            imported += 1
        
        conn.commit()
        print(f"      ✅ Imported {imported} records\n")
        return imported
        
    except Exception as e:
        print(f"      ❌ Error: {str(e)}\n")
        return 0

def main():
    print("📊 IMPORTING PREVIOUS YEAR STUDENT DETAILS")
    print("=" * 80)
    print()
    
    # Connect to database
    conn = mysql.connector.connect(**DB_CONFIG)
    cursor = conn.cursor()
    
    base_dir = Path(__file__).parent / 'Previous_Year_Data' / 'GTB Student Details'
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
    print(f"Total Student Records Imported: {total_imported}\n")
    
    # Show stats by year
    print("📊 Summary by Academic Year:")
    print("-" * 80)
    
    cursor.execute("""
        SELECT 
            academic_year,
            COUNT(*) as total_students,
            COUNT(DISTINCT program) as programs,
            COUNT(CASE WHEN gender = 'Male' OR gender = 'M' THEN 1 END) as male,
            COUNT(CASE WHEN gender = 'Female' OR gender = 'F' THEN 1 END) as female
        FROM previous_year_students
        GROUP BY academic_year
        ORDER BY academic_year
    """)
    
    for row in cursor.fetchall():
        print(f"{row[0]}:")
        print(f"   Students: {row[1]} | Programs: {row[2]}")
        print(f"   Male: {row[3]} | Female: {row[4]}\n")
    
    cursor.close()
    conn.close()
    
    print("=" * 80)

if __name__ == '__main__':
    main()
