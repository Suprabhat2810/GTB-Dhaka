# Complete Admin User Manual
## Guru Tegh Bahadur Institute - Student Management System

---

## 📚 Table of Contents
1. [Student Approvals](#student-approvals)
2. [Notifications](#notifications)
3. [Document Management](#document-management)
4. [Semester Management](#semester-management)
5. [Payment Management](#payment-management)
6. [Students Management](#students-management)
7. [Programs Management](#programs-management)
8. [Dashboard Overview](#dashboard-overview)

---

## 1. Student Approvals

### What It Does
Review and approve/reject student registration applications.

### Step-by-Step Guide

#### Approving a Student:
1. Review student details (Name, Email, Program, Date)
2. Verify information is correct
3. Click green **"Approve"** button
4. Wait for confirmation
5. Student disappears from pending list

#### What Happens After Approval:
- ✅ Student assigned to Semester 1
- ✅ Academic year set automatically
- ✅ Student receives in-app notification
- ✅ WhatsApp message sent (if configured)
- ✅ Student can complete final registration

#### Rejecting a Student:
**When to Reject:**
- Incomplete/incorrect information
- Duplicate registration
- Doesn't meet eligibility
- Suspicious details

**Steps:**
1. Click red **"Reject"** button
2. Confirm decision
3. Student receives rejection notification

### Important Notes
⚠️ **Cannot undo** - Actions are permanent  
⚠️ **Double-check** - Verify details before approving  
⚠️ **Be prompt** - Students are waiting  
⚠️ **Check duplicates** - Look for similar names/emails

### Best Practices
✅ Review applications daily  
✅ Verify email addresses  
✅ Check phone number format (+91XXXXXXXXXX)  
✅ Process oldest applications first  
✅ Look for duplicate names

---

## 2. Notifications

### What It Does
Send messages to students (individual, groups, or broadcast).

### Three Types of Notifications

#### 1. Individual Student
- Send to one specific student
- Example: "Please submit pending documents by Friday"

**Steps:**
1. Select "Individual Student"
2. (Optional) Filter by program
3. Type student name
4. Select from dropdown
5. See "1 student will receive" badge
6. Write message
7. Click "Send to 1 Student"

#### 2. Program & Semester Group
- Send to all students in specific program/semester
- Example: "All CS Semester 3 - Exam schedule released"

**Steps:**
1. Select "Program & Semester"
2. Choose program
3. Choose semester
4. See recipient count (e.g., "15 students")
5. If count is 0, warning appears
6. Write message
7. Click "Send to X Students"

#### 3. All Students (Broadcast)
- Send to entire student body
- Example: "Institute closed Monday for holiday"

**Steps:**
1. Select "All Students"
2. See total count
3. Write message carefully
4. Click "Send to X Students"

### NEW Feature: Recipient Count
Before sending, you always see exact count:
```
[15] 15 students will receive this notification
```

✅ Prevents accidental wrong sends  
✅ Shows warning if no students match  
✅ Updates in real-time

### Managing Sent Notifications
- **View Recent**: Left panel shows all sent notifications
- **Resend**: Click Send icon to resend
- **Delete**: Click Trash icon to remove from history
- **Refresh**: Reload notification list

### Automatic Notifications
System automatically sends for:
- ✅ Registration (WhatsApp welcome)
- ✅ Approval notification
- ✅ Rejection notification
- ✅ Payment confirmation
- ✅ Semester promotion
- ✅ Birthday wishes

### Important Notes
⚠️ **Cannot unsend** - Once sent, cannot be undone  
⚠️ **Check count** - Always verify before sending  
⚠️ **Be clear** - Write concise, actionable messages  
⚠️ **Broadcast carefully** - Use "All Students" sparingly  
⚠️ **No spam** - Don't send too many

### Best Practices
✅ Keep messages under 200 characters  
✅ Include action items with deadlines  
✅ Use proper grammar  
✅ Send to specific groups for urgent matters  
✅ Double-check recipient count  
✅ Test with individual student first  
✅ Avoid multiple notifications for same topic

---

## 3. Document Management

### What It Does
View and verify student-uploaded documents.

### How It Works
1. **Student Uploads** - During/after registration
2. **Appears Here** - Grouped by student name
3. **You Review** - Click name to expand
4. **Mark Verified** - Click "Verify" button

### Verifying Documents

**Steps:**
1. Find student (use search box)
2. Click student name to expand
3. Click **"View"** to open document
4. Check if clear, readable, valid
5. Click green **"Verify"** button
6. Status changes to "Verified" ✓

### Dashboard Metrics
- **Total Documents**: All uploaded documents
- **Verified**: Already checked (green badge)
- **Pending**: Waiting for review (yellow badge)

### Document Status Colors
- 🟡 **Pending** - Needs your review
- 🟢 **Verified** - Checked and approved

### What to Check
✅ **Clarity** - Not blurry or dark  
✅ **Completeness** - All corners visible  
✅ **Authenticity** - Looks genuine  
✅ **Validity** - Not expired  
✅ **Match** - Name matches registration

### Using Search
Type any part of student name to filter list.  
Example: Type "John" to see only Johns

### Important Notes
⚠️ **Cannot unverify** - Once verified, permanent  
⚠️ **Check carefully** - Verify only if 100% correct  
⚠️ **New tab** - View opens in new tab  
⚠️ **Contact student** - If unclear, ask to re-upload

### Troubleshooting
**Q: Document not loading?**  
A: Check internet. If still fails, file may be corrupted.

**Q: No documents for student?**  
A: Student hasn't uploaded yet.

**Q: Accidentally verified wrong?**  
A: Contact system administrator.

**Q: List collapsed after verifying?**  
A: Normal behavior. Click name again to expand.

### Best Practices
✅ Verify documents daily  
✅ Start with pending (yellow badge)  
✅ Open in full screen  
✅ Cross-check names  
✅ Consult senior admin if unsure  
✅ Keep record of re-upload requests

---

## 4. Semester Management

### What It Does
Manage academic calendar, semesters, and student promotions.

### Key Concepts

#### Academic Calendar
- **Program-specific** - Each program has own calendar
- **Semester entries** - Tracks start/end dates, exams
- **Status tracking** - Upcoming, Active, Frozen, Completed

#### Semester Promotion
- **Bulk operation** - Promote multiple students at once
- **Eligibility check** - System verifies before promotion
- **Automatic updates** - Year increments when needed

### Setting Up New Semester

**Steps:**
1. Click **"Setup New Semester"**
2. Select program
3. Enter academic year (e.g., 2024-2025)
4. Enter semester number (1-8)
5. Set start and end dates
6. (Optional) Set registration/exam dates
7. Choose status (Upcoming/Active)
8. Click **"Create Semester"**

### Promoting Students

**Steps:**
1. Select program
2. Select current semester (e.g., Semester 1)
3. Select academic year
4. Click **"Check Eligibility"**
5. Review eligible students list
6. See promotion criteria (fees cleared)
7. Select students to promote
8. Click **"Promote Selected Students"**
9. Confirm promotion
10. Students receive notification

### Eligibility Criteria
- ✅ **Clear Dues** - All fees paid (ACTIVE)
- ⏸️ **Attendance** - Not currently enforced

### Understanding Year vs Semester
- **Semester** - 1-8 (increments every semester)
- **Year** - 1-4 (increments when moving from even to odd semester)
- Example: Sem 2 → Sem 3 = Year 1 → Year 2

### Important Notes
⚠️ **Cannot undo promotion** - Permanent action  
⚠️ **Check eligibility first** - Don't skip this step  
⚠️ **Fees must be clear** - Students with pending fees can't be promoted  
⚠️ **Backup recommended** - Before bulk operations

### Best Practices
✅ Set up semesters at start of academic year  
✅ Promote students at end of semester  
✅ Verify eligibility before promoting  
✅ Promote in batches by program  
✅ Keep academic calendar updated

---

## 5. Payment Management

### What It Does
Manage student fee payments, set fee amounts, and track payment status.

### Key Features
- View all student payments
- Confirm submitted payments
- Set/update fee amounts by program
- Make payment system live/offline
- Export payment reports

### Confirming Payments

**Steps:**
1. Find student in payment list
2. Check payment status (Pending/Processing/Paid)
3. Click **"View Details"** to see payment info
4. Verify payment amount and date
5. Click **"Confirm Payment"** button
6. Payment status changes to "Paid"
7. Student receives confirmation notification

### Setting Fee Amounts

**Steps:**
1. Click **"Fee Settings"** button
2. Select program
3. Enter total fee amount
4. (Optional) Check "Apply to existing students"
5. Click **"Save Fee Settings"**
6. Fee amount updated for program

### Making Payment Live

**What it does:** Enables students to pay fees online

**Steps:**
1. Ensure Razorpay is configured
2. Click **"Make Payment Live"** button
3. Select program (or leave empty for all)
4. Confirm action
5. Students can now pay online

### Payment Statuses
- 🟡 **Pending** - Submitted, awaiting confirmation
- 🔵 **Processing** - Being verified
- 🟢 **Paid** - Confirmed and complete

### Dashboard Metrics
- **Total Collected** - Sum of all confirmed payments
- **Pending Payments** - Awaiting confirmation
- **Students Paid** - Count of students who paid
- **Outstanding** - Total pending amount

### Filtering & Search
- **By Status** - Pending, Processing, Paid, All
- **By Program** - Computer Science, Data Science, etc.
- **Search** - Type student name or registration number

### Important Notes
⚠️ **Verify before confirming** - Check payment proof  
⚠️ **Cannot unconfirm** - Payment confirmation is permanent  
⚠️ **Fee settings affect all** - Be careful when updating  
⚠️ **Payment live = students can pay** - Don't enable if not ready

### Best Practices
✅ Confirm payments daily  
✅ Verify payment proofs carefully  
✅ Set fees at start of semester  
✅ Export reports regularly  
✅ Keep Razorpay credentials updated  
✅ Monitor pending payments

---

## 6. Students Management

### What It Does
View all students, track their progress, and manage student data.

### Key Features
- View all approved students
- Filter by program and semester
- Search by name or registration number
- Track payment status
- Track form completion status
- View student details

### Viewing Students

**Steps:**
1. Go to Students Management page
2. Use filters:
   - **Program** - Select specific program
   - **Semester** - Select semester (1-8)
   - **Status** - Approved, Pending, Rejected
3. Use search box for specific student
4. Click student row to view details

### Student Information Displayed
- Name
- Registration Number
- Program
- Semester & Year
- Email & Phone
- Payment Status
- Form Lock Status
- Documents Status

### Tracking Student Progress

**Payment Status:**
- ✅ **Paid** - All fees cleared
- ⏳ **Partial** - Some payment made
- ❌ **Unpaid** - No payment yet

**Form Status:**
- 🔒 **Locked** - Final registration submitted
- 🔓 **Unlocked** - Still editing

### Exporting Data

**Steps:**
1. Apply desired filters
2. Click **"Export"** button
3. Choose format (CSV/Excel)
4. File downloads automatically

### Important Notes
⚠️ **Read-only view** - Cannot edit from this page  
⚠️ **Use other modules** - For approvals, payments, etc.  
⚠️ **Refresh regularly** - Data updates in real-time

### Best Practices
✅ Use filters to narrow down view  
✅ Export data for record-keeping  
✅ Check student progress regularly  
✅ Follow up with students who haven't paid  
✅ Monitor form completion rates

---

## 7. Programs Management

### What It Does
Create and manage academic programs offered by the institute.

### Creating New Program

**Steps:**
1. Click **"Add New Program"** button
2. Enter program name (e.g., "Computer Science")
3. Enter program code (e.g., "CS")
4. Set available seats (e.g., 60)
5. Click **"Create Program"**
6. Program appears in list

### Program Information
- **Name** - Full program name
- **Code** - Short code (2-4 letters)
- **Seats** - Total available seats
- **Enrolled** - Current student count

### Viewing Programs

**What you see:**
- List of all programs
- Seat availability
- Current enrollment
- Program codes

### Important Notes
⚠️ **Cannot delete programs** - With enrolled students  
⚠️ **Unique codes** - Each program needs unique code  
⚠️ **Seat limits** - System enforces seat availability

### Best Practices
✅ Set realistic seat limits  
✅ Use clear, descriptive names  
✅ Keep program codes short  
✅ Update seat counts annually  
✅ Monitor enrollment vs capacity

---

## 8. Dashboard Overview

### What It Shows
Quick overview of entire system with key metrics.

### Key Metrics

#### Student Statistics
- **Total Students** - All approved students
- **Pending Approvals** - Waiting for review
- **This Month** - New registrations

#### Payment Statistics
- **Total Collected** - All confirmed payments
- **Pending Payments** - Awaiting confirmation
- **Payment Rate** - Percentage paid

#### Document Statistics
- **Total Documents** - All uploaded
- **Verified** - Checked and approved
- **Pending Verification** - Waiting for review

#### Program Statistics
- **Active Programs** - Currently offered
- **Total Enrollment** - Students across all programs
- **Seat Utilization** - Percentage filled

### Quick Actions
- **Approve Students** - Jump to approvals
- **Verify Documents** - Jump to documents
- **Confirm Payments** - Jump to payments
- **Send Notification** - Jump to notifications

### Recent Activity
Shows last 10 actions:
- Student approvals
- Payment confirmations
- Document verifications
- Semester promotions

### Important Notes
⚠️ **Auto-refresh** - Dashboard updates every 30 seconds  
⚠️ **Click metrics** - To drill down into details  
⚠️ **Use quick actions** - For faster navigation

### Best Practices
✅ Check dashboard daily  
✅ Monitor pending counts  
✅ Use quick actions for efficiency  
✅ Track trends over time  
✅ Address high pending counts promptly

---

## 🔐 General Security Best Practices

### For All Modules
1. **Logout** - Always logout when done
2. **Don't share credentials** - Keep password private
3. **Verify actions** - Double-check before confirming
4. **Report issues** - Contact IT immediately
5. **Keep updated** - Use latest browser version

### Data Privacy
- Don't share student data externally
- Don't screenshot sensitive information
- Don't discuss student details publicly
- Follow GDPR/data protection guidelines

---

## 🆘 Getting Help

### Common Issues

**Can't login?**
- Check username/password
- Clear browser cache
- Try different browser
- Contact IT support

**Page not loading?**
- Refresh the page
- Check internet connection
- Clear browser cache
- Try incognito mode

**Action not working?**
- Check if you have permission
- Refresh and try again
- Check browser console for errors
- Contact system administrator

### Contact Support
- **Email**: admin@gtbinstitute.edu
- **Phone**: +91-XXXXXXXXXX
- **IT Help Desk**: Available 9 AM - 5 PM

---

## 📝 Quick Reference

### Keyboard Shortcuts
- `Ctrl + F` - Search on page
- `F5` - Refresh page
- `Ctrl + P` - Print
- `Esc` - Close modal

### Status Colors
- 🟢 **Green** - Completed/Verified/Paid
- 🟡 **Yellow** - Pending/Waiting
- 🔵 **Blue** - Processing/In Progress
- 🔴 **Red** - Rejected/Failed
- ⚪ **Gray** - Inactive/Disabled

### Icons Meaning
- ✅ - Approved/Verified
- ❌ - Rejected/Failed
- ⏳ - Pending/Waiting
- 🔒 - Locked/Secured
- 🔓 - Unlocked/Editable
- 📄 - Document
- 💰 - Payment
- 🔔 - Notification
- 👤 - Student
- 📊 - Statistics

---

**Last Updated**: December 2024  
**Version**: 2.0.0  
**System**: Guru Tegh Bahadur Institute Student Management  
**Status**: ✅ Production Ready

---

## 📚 Additional Resources

- **Video Tutorials**: Available on internal portal
- **FAQ**: Check system FAQ section
- **Training Sessions**: Monthly admin training
- **System Updates**: Check announcements regularly

---

**Remember**: When in doubt, ask! It's better to clarify than make a mistake.

**Happy Managing! 🎓**
