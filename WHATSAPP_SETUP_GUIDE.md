# WhatsApp Notification Setup Guide

## Overview
The system now sends automated WhatsApp messages to students at 3 key points:
1. **Registration** - Welcome message when student registers
2. **Approval** - Congratulations message when admin approves
3. **Payment** - Confirmation message when semester fee is paid

## ✅ Features
- **Non-Breaking**: If WhatsApp fails, the main functionality continues normally
- **Optional**: Works only when Twilio credentials are configured
- **Professional**: Polite, well-formatted messages with institute logo
- **Logged**: All attempts (success/failure) are logged for debugging

---

## 🔧 Setup Instructions

### Step 1: Get Twilio Account
1. Go to [Twilio.com](https://www.twilio.com/)
2. Sign up for a free account
3. Navigate to **Console Dashboard**
4. Note down:
   - **Account SID**
   - **Auth Token**
5. Enable **WhatsApp Sandbox** or get approved WhatsApp number

### Step 2: Configure Environment Variables
Open the file: `new_api_deploy/.env`

Update these values:
```env
# Twilio WhatsApp Configuration
TWILIO_ACCOUNT_SID=your_actual_account_sid_here
TWILIO_AUTH_TOKEN=your_actual_auth_token_here
TWILIO_WHATSAPP_FROM=whatsapp:+14155238886
INSTITUTE_NAME=Guru Tegh Bahadur Khalsa College
INSTITUTE_LOGO_URL=http://localhost:8080/logo.png
```

**Important Notes:**
- Replace `your_actual_account_sid_here` with your real Twilio Account SID
- Replace `your_actual_auth_token_here` with your real Twilio Auth Token
- For testing, use Twilio's sandbox number: `whatsapp:+14155238886`
- For production, get your own WhatsApp Business number from Twilio

### Step 3: Install Twilio PHP SDK
Run this command in the `new_api_deploy` directory:
```bash
composer require twilio/sdk
```

If you don't have composer, download it from: https://getcomposer.org/

### Step 4: Test the Setup
1. Register a new student with a valid phone number (format: +91XXXXXXXXXX)
2. Check the logs in `new_api_deploy/logs/` for WhatsApp status
3. If configured correctly, student should receive a welcome message

---

## 📱 Message Templates

### 1. Welcome Message (Registration)
```
🎓 Welcome to Guru Tegh Bahadur Khalsa College!

Dear [Student Name],

Thank you for registering with us! Your registration has been received successfully.

📋 Registration Number: [Number]

✅ Next Steps:
1. Complete your profile information
2. Upload required documents
3. Wait for admin approval

We will notify you once your application is reviewed.

Best regards,
Guru Tegh Bahadur Khalsa College Team
```

### 2. Approval Message
```
🎉 Congratulations [Student Name]!

Your application has been APPROVED by Guru Tegh Bahadur Khalsa College.

📚 Enrollment Details:
• Program: [Program Name]
• Semester: 1
• Academic Year: [Year]

✅ Next Steps:
1. Complete your final registration form
2. Pay the semester fees
3. Access your student portal

Welcome to our academic family! 🎓

Best regards,
Guru Tegh Bahadur Khalsa College Team
```

### 3. Payment Confirmation
```
✅ Payment Received Successfully

Dear [Student Name],

We have received your semester fee payment.

💰 Payment Details:
• Amount: ₹[Amount]
• Semester: Semester [Number]
• Transaction ID: [ID]
• Date: [Date]

📝 Your payment has been recorded in our system.
You can now access all semester resources and materials.

Thank you for your prompt payment! 🙏

Best regards,
Guru Tegh Bahadur Khalsa College Team
```

---

## 🔍 Troubleshooting

### WhatsApp Not Sending?
1. **Check Logs**: Look in `new_api_deploy/logs/student.log`, `approvals.log`, `payment.log`
2. **Verify Credentials**: Ensure `.env` has correct Twilio credentials
3. **Check Phone Format**: Must be `+91XXXXXXXXXX` (with country code)
4. **Twilio Sandbox**: For testing, students must first send "join [sandbox-code]" to Twilio's sandbox number
5. **Balance**: Check your Twilio account balance

### Common Errors
- **"WhatsApp service disabled"** → Credentials not configured in `.env`
- **"Failed to initialize Twilio client"** → Invalid Account SID or Auth Token
- **"Failed to send message"** → Phone number not verified in sandbox, or insufficient balance

### Testing Without Twilio
The system works perfectly fine without Twilio configured. Simply leave the default values in `.env`:
```env
TWILIO_ACCOUNT_SID=your_twilio_account_sid_here
TWILIO_AUTH_TOKEN=your_twilio_auth_token_here
```
The WhatsApp service will be disabled, but all other functionality continues normally.

---

## 📂 Files Modified

### New Files
- `new_api_deploy/v1/WhatsAppService.php` - WhatsApp service class

### Modified Files
- `new_api_deploy/.env` - Added Twilio configuration
- `new_api_deploy/v1/student.php` - Added welcome message on registration
- `new_api_deploy/v1/approvals.php` - Added approval notification
- `new_api_deploy/v1/payment.php` - Added payment confirmation

---

## 🔒 Security Notes
1. **Never commit `.env` file** to version control
2. **Keep Auth Token secret** - treat it like a password
3. **Use environment variables** in production
4. **Rotate credentials** periodically
5. **Monitor Twilio usage** to prevent abuse

---

## 💰 Twilio Pricing (Approximate)
- **WhatsApp Messages**: ~$0.005 per message (varies by country)
- **Free Trial**: $15 credit for testing
- **India Rates**: Check Twilio's pricing page for exact rates

---

## 📞 Support
- **Twilio Documentation**: https://www.twilio.com/docs/whatsapp
- **Twilio Console**: https://console.twilio.com/
- **PHP SDK Docs**: https://www.twilio.com/docs/libraries/php

---

## ✨ Future Enhancements
- Add SMS fallback if WhatsApp fails
- Queue messages for bulk sending
- Add message templates in database
- Support for multiple languages
- Add delivery status tracking
- Custom message templates per program

---

**Last Updated**: December 2024
**Version**: 1.0.0
