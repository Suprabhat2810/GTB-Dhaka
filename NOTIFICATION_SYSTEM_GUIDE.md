# Notification System - Complete Guide

## 📋 Overview
The notification system allows admins to send messages to students at various stages of their academic journey. Notifications are automatically triggered by system events and can also be manually sent by admins.

---

## 🔔 Automatic Notifications (System-Triggered)

### 1. **Student Registration**
- **Trigger**: When a new student completes registration
- **Message**: Welcome message (via WhatsApp if configured)
- **Database**: No in-app notification (WhatsApp only)

### 2. **Admin Approval**
- **Trigger**: When admin approves a student's registration
- **Message**: "Your registration has been approved. Please complete your final registration details."
- **Additional**: WhatsApp notification sent (if configured)
- **Location**: `approvals.php` line 132

### 3. **Admin Rejection**
- **Trigger**: When admin rejects a student's registration
- **Message**: "Your registration has been rejected."
- **Location**: `approvals.php` line 207

### 4. **Semester Promotion** ✅ NEW
- **Trigger**: When admin promotes students to next semester
- **Message**: "Congratulations! You have been promoted from Semester X to Semester Y. Academic Year: YYYY-YYYY"
- **Location**: `semester_promotion.php` line 295-311

### 5. **Payment Submission**
- **Trigger**: When student submits payment for verification
- **Message**: "Payment of ₹X submitted. Awaiting admin verification."
- **Location**: `payment.php` line 609

### 6. **Payment Confirmation**
- **Trigger**: When admin confirms/approves payment
- **Message**: "Your payment of ₹X has been confirmed by admin."
- **Additional**: WhatsApp notification sent (if configured)
- **Location**: `payment.php` line 625, 641

### 7. **Payment Verification (Razorpay)**
- **Trigger**: When payment is verified via Razorpay
- **Message**: "Payment of X INR has been received successfully."
- **Location**: `verify_payment.php` line 52

### 8. **Birthday Wishes**
- **Trigger**: Automated birthday notification
- **Message**: "Happy Birthday, [Name]! Wishing you a fantastic day!"
- **Location**: `sendBirthdayNotification.php` line 55

---

## 📤 Manual Notifications (Admin-Sent)

### Notification Types:

#### 1. **Individual Student**
- Send to a specific student
- Select program (optional filter)
- Select student from dropdown
- **Recipient Count**: Shows "1 student"

#### 2. **Program & Semester Group**
- Send to all students in a specific program and semester
- Example: All Computer Science - Semester 3 students
- **Recipient Count**: Shows actual count before sending
- **Warning**: Shows if no students found for selected criteria

#### 3. **All Students**
- Broadcast to entire student body
- **Recipient Count**: Shows total student count
- **Limit**: Maximum 2000 recipients per batch (configurable)

---

## 🎨 UI/UX Improvements

### ✅ **New Features Added:**

1. **Recipient Count Display**
   - Shows exact number of students who will receive the notification
   - Blue badge with count
   - Updates in real-time as you change selections

2. **Empty State Warning**
   - Yellow warning box if no students match the criteria
   - Example: "⚠️ No students found for Computer Science - Semester 8"

3. **Dynamic Button Text**
   - Changes from "Send Notification" to "Send to X Students"
   - Provides clear feedback before sending

4. **Visual Feedback**
   - Success toasts for sent notifications
   - Error toasts for failures
   - Refresh button with spinner animation

5. **Smart Filtering**
   - Program filter for individual notifications
   - Datalist autocomplete for student selection
   - Real-time filtered student list

---

## 🔄 Notification Workflow

### **Sending Flow:**
```
Admin selects type → Chooses recipients → Sees count → Writes message → Confirms → Notification sent
```

### **Student Receiving Flow:**
```
Notification created in DB → Student sees in dashboard → Can mark as read (if implemented)
```

---

## 📊 Database Schema

### **Current Structure:**
```sql
CREATE TABLE notifications (
  id INT PRIMARY KEY AUTO_INCREMENT,
  student_id INT NOT NULL,
  message TEXT NOT NULL,
  notification_date DATETIME NOT NULL,
  FOREIGN KEY (student_id) REFERENCES students(id)
);
```

### **Recommended Enhancements (Future):**
```sql
ALTER TABLE notifications ADD COLUMN notification_type VARCHAR(50);
ALTER TABLE notifications ADD COLUMN sent_by INT; -- admin_id
ALTER TABLE notifications ADD COLUMN read_status BOOLEAN DEFAULT 0;
ALTER TABLE notifications ADD COLUMN program VARCHAR(100);
ALTER TABLE notifications ADD COLUMN semester INT;
```

---

## 🔍 How to Use (Admin Guide)

### **Sending Individual Notification:**
1. Go to **Notifications** page
2. Select **"Individual Student"** type
3. (Optional) Filter by program
4. Type student name in the search box
5. Select from dropdown
6. See "1 student will receive this notification"
7. Write your message
8. Click **"Send to 1 Student"**

### **Sending Group Notification:**
1. Select **"Program & Semester"** type
2. Choose program (e.g., "Computer Science")
3. Choose semester (e.g., "3")
4. See recipient count (e.g., "15 students will receive this notification")
5. If count is 0, you'll see a warning
6. Write your message
7. Click **"Send to 15 Students"**

### **Sending Broadcast:**
1. Select **"All Students"** type
2. See total student count
3. Write your message
4. Click **"Send to X Students"**

---

## 🔧 Integration Points

### **Files Modified:**

1. **`semester_promotion.php`**
   - Added notification on student promotion
   - Non-breaking: Logs error if notification fails

2. **`Notifications.tsx`** (Frontend)
   - Added recipient count calculation
   - Added visual count display
   - Added empty state warning
   - Improved button text

3. **`approvals.php`**
   - Already has notifications for approval/rejection
   - Integrated with WhatsApp service

4. **`payment.php`**
   - Already has notifications for payment events
   - Integrated with WhatsApp service

---

## ⚠️ Known Limitations

1. **No Read Status**: Students can't mark notifications as read
2. **No Filtering**: Admin can't filter notification history by date/type
3. **No Notification Type**: All notifications look the same
4. **No Sender Tracking**: Can't see which admin sent a notification
5. **No Delivery Status**: Can't confirm if student actually received it
6. **No Bulk Actions**: Can't delete/resend multiple notifications at once

---

## 🚀 Future Enhancements

### **Priority 1 (High Impact):**
- [ ] Add read/unread status for students
- [ ] Add notification types (approval, payment, promotion, etc.)
- [ ] Add sender (admin_id) tracking
- [ ] Add date range filtering in admin panel

### **Priority 2 (Medium Impact):**
- [ ] Add email notifications alongside in-app
- [ ] Add notification preferences for students
- [ ] Add bulk delete/resend actions
- [ ] Add notification templates

### **Priority 3 (Nice to Have):**
- [ ] Add push notifications (browser)
- [ ] Add notification scheduling
- [ ] Add notification analytics (open rate, etc.)
- [ ] Add rich text formatting in messages

---

## 📱 Student View (Current)

Students can view their notifications in:
- **Dashboard**: Recent notifications widget
- **Notifications Page**: Full list of all notifications
- **Real-time**: New notifications appear automatically

---

## 🔐 Security & Best Practices

### **Current Safeguards:**
✅ Admin authentication required for sending
✅ Maximum batch size limit (2000 students)
✅ SQL injection prevention (prepared statements)
✅ Input validation and sanitization
✅ Transaction rollback on errors

### **Best Practices:**
1. **Keep messages concise** - Students read notifications quickly
2. **Use clear language** - Avoid technical jargon
3. **Include action items** - Tell students what to do next
4. **Test before broadcasting** - Send to yourself first
5. **Check recipient count** - Verify before sending to large groups

---

## 📈 Usage Statistics (To Track)

### **Metrics to Monitor:**
- Total notifications sent per day/week/month
- Notifications by type (individual, group, broadcast)
- Average recipient count per notification
- Failed notification attempts
- Most common notification messages

---

## 🐛 Troubleshooting

### **Notification Not Sent:**
1. Check admin authentication
2. Verify student exists in database
3. Check for SQL errors in logs
4. Verify recipient count > 0

### **Recipient Count Shows 0:**
1. Verify students exist for selected program/semester
2. Check if students have semester assigned
3. Refresh the page to reload student data

### **WhatsApp Not Sending:**
1. Check Twilio credentials in `.env`
2. Verify phone number format (+91XXXXXXXXXX)
3. Check Twilio account balance
4. Review logs in `notifications.log`

---

## 📞 Support

For issues or questions:
1. Check logs in `new_api_deploy/logs/notifications.log`
2. Review database `notifications` table
3. Check browser console for frontend errors
4. Contact system administrator

---

**Last Updated**: December 2024  
**Version**: 2.0.0  
**Status**: ✅ Production Ready
