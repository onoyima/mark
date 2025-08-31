# NYSC Backend Test Workflow

This document provides comprehensive test workflows for both Student and Admin flows using the NYSC backend API.

## Prerequisites

- Backend server running on `http://localhost:8000`
- API testing tool (Insomnia, Postman, etc.)
- Test credentials provided below

## Test Credentials

### Student Credentials
- **Identity**: `vug/csc/16/1336`
- **Password**: `welcome`

### Admin Credentials
- **Identity**: `onoyimab@veritas.edu.ng`
- **Password**: `ASDewq@123`

---

## STUDENT WORKFLOW TEST

### Step 1: Student Login

**Endpoint**: `POST /api/nysc/login`

**Request Body**:
```json
{
  "identity": "vug/csc/16/1336",
  "password": "welcome"
}
```

**Expected Response**:
```json
{
  "success": true,
  "message": "Login successful",
  "token": "your-auth-token-here",
  "user_type": "student",
  "user": {
    "id": 123,
    "fname": "Student Name",
    "lname": "Last Name",
    "matric_no": "vug/csc/16/1336",
    "email": "student@example.com",
    "nysc_data": {
      "is_submitted": false,
      "is_paid": false
    }
  }
}
```

**Save the token** for subsequent requests.

### Step 2: Get Student Details

**Endpoint**: `GET /api/nysc/student/details`

**Headers**:
```
Authorization: Bearer {your-token-from-step-1}
Content-Type: application/json
```

**Expected Response**:
```json
{
  "success": true,
  "data": {
    "personal": {
      "fname": "Student Name",
      "lname": "Last Name",
      "mname": "Middle Name",
      "gender": "male",
      "dob": "1995-01-01",
      "marital_status": "single",
      "phone": "+234...",
      "email": "student@example.com",
      "address": "Student Address",
      "state_of_origin": "Lagos",
      "lga": "Ikeja"
    },
    "academic": {
      "matric_no": "vug/csc/16/1336",
      "course_of_study": "Computer Science",
      "department": "Computer Science",
      "faculty": "Science",
      "graduation_year": "2020",
      "cgpa": 3.5,
      "jambno": "12345678",
      "study_mode": "Full Time"
    },
    "emergency_contact": {
      "name": "Emergency Contact",
      "phone": "+234...",
      "relationship": "Parent",
      "address": "Emergency Address"
    },
    "nysc_status": {
      "is_submitted": false,
      "is_paid": false,
      "payment_amount": null,
      "payment_date": null
    }
  }
}
```

### Step 3: Update Student Details (Data Confirmation)

**Endpoint**: `PUT /api/nysc/student/details`

**Headers**:
```
Authorization: Bearer {your-token}
Content-Type: application/json
```

**Request Body** (update any field as needed):
```json
{
  "fname": "Updated First Name",
  "phone": "+2348012345678",
  "address": "Updated Address, Lagos State",
  "emergency_contact_name": "Updated Emergency Contact",
  "emergency_contact_phone": "+2348087654321"
}
```

**Expected Response**:
```json
{
  "success": true,
  "message": "Details updated successfully",
  "data": {
    // Updated student data
  }
}
```

### Step 4: Initiate Payment

**Endpoint**: `POST /api/nysc/student/payment/initiate`

**Headers**:
```
Authorization: Bearer {your-token}
Content-Type: application/json
```

**Request Body**:
```json
{
  "amount": 500,
  "callback_url": "http://localhost:3000/payment/callback"
}
```

**Expected Response**:
```json
{
  "success": true,
  "message": "Payment initiated successfully",
  "data": {
    "authorization_url": "https://checkout.paystack.com/...",
    "access_code": "access_code_here",
    "reference": "payment_reference_here"
  }
}
```

### Step 5: Verify Payment (Simulate Success)

**Endpoint**: `POST /api/nysc/student/payment/verify`

**Headers**:
```
Authorization: Bearer {your-token}
Content-Type: application/json
```

**Request Body**:
```json
{
  "reference": "payment_reference_from_step_4"
}
```

**Expected Response**:
```json
{
  "success": true,
  "message": "Payment verified successfully",
  "data": {
    "status": "success",
    "amount": 50000,
    "reference": "payment_reference",
    "paid_at": "2024-01-15T10:30:00Z"
  }
}
```

### Step 6: Submit Data (After Payment)

**Endpoint**: `POST /api/nysc/student/submit`

**Headers**:
```
Authorization: Bearer {your-token}
Content-Type: application/json
```

**Expected Response**:
```json
{
  "success": true,
  "message": "Data submitted successfully",
  "data": {
    "submission_id": "NYSC2024001",
    "submitted_at": "2024-01-15T10:35:00Z",
    "status": "submitted"
  }
}
```

### Step 7: Get Payment History

**Endpoint**: `GET /api/nysc/student/payments`

**Headers**:
```
Authorization: Bearer {your-token}
Content-Type: application/json
```

**Expected Response**:
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "reference": "payment_reference",
      "amount": 50000,
      "status": "success",
      "paid_at": "2024-01-15T10:30:00Z"
    }
  ]
}
```

---

## ADMIN WORKFLOW TEST

### Step 1: Admin Login

**Endpoint**: `POST /api/nysc/login`

**Request Body**:
```json
{
  "identity": "onoyimab@veritas.edu.ng",
  "password": "ASDewq@123"
}
```

**Expected Response**:
```json
{
  "success": true,
  "message": "Login successful",
  "token": "admin-auth-token-here",
  "user_type": "admin",
  "user": {
    "id": 1,
    "fname": "Admin",
    "lname": "User",
    "email": "onoyimab@veritas.edu.ng",
    "role": "admin"
  }
}
```

**Save the admin token** for subsequent requests.

### Step 2: Get Admin Dashboard

**Endpoint**: `GET /api/nysc/admin/dashboard`

**Headers**:
```
Authorization: Bearer {admin-token}
Content-Type: application/json
```

**Expected Response**:
```json
{
  "students": [
    {
      "id": 1,
      "fname": "Student",
      "lname": "Name",
      "matric_no": "vug/csc/16/1336",
      "is_paid": true,
      "is_submitted": true,
      "payment_amount": 500,
      "created_at": "2024-01-15T10:35:00Z"
    }
  ],
  "statistics": {
    "total_submitted": 1,
    "total_paid": 1,
    "total_unpaid": 0,
    "payment_percentage": 100
  },
  "system_status": {
    "is_open": true,
    "deadline": "2024-02-15T23:59:59Z",
    "is_late_fee": false,
    "current_fee": 500
  }
}
```

### Step 3: Get Students List (with pagination)

**Endpoint**: `GET /api/nysc/admin/students`

**Headers**:
```
Authorization: Bearer {admin-token}
Content-Type: application/json
```

**Query Parameters** (optional):
```
?search=vug&payment_status=paid&per_page=10&page=1&sort_by=created_at&sort_order=desc
```

**Expected Response**:
```json
{
  "current_page": 1,
  "data": [
    {
      "id": 1,
      "fname": "Student",
      "lname": "Name",
      "matric_no": "vug/csc/16/1336",
      "email": "student@example.com",
      "is_paid": true,
      "payment_amount": 500,
      "created_at": "2024-01-15T10:35:00Z"
    }
  ],
  "per_page": 10,
  "total": 1
}
```

### Step 4: Update Student Record

**Endpoint**: `PUT /api/nysc/admin/students/{student_id}`

**Headers**:
```
Authorization: Bearer {admin-token}
Content-Type: application/json
```

**Request Body**:
```json
{
  "fname": "Updated by Admin",
  "is_paid": true,
  "payment_amount": 500
}
```

**Expected Response**:
```json
{
  "message": "Student record updated successfully.",
  "data": {
    // Updated student data
  }
}
```

### Step 5: Get System Control Settings

**Endpoint**: `GET /api/nysc/admin/control`

**Headers**:
```
Authorization: Bearer {admin-token}
Content-Type: application/json
```

**Expected Response**:
```json
{
  "system_status": {
    "is_open": true,
    "deadline": "2024-02-15T23:59:59Z",
    "is_late_fee": false,
    "current_fee": 500
  }
}
```

### Step 6: Update System Control

**Endpoint**: `POST /api/nysc/admin/control`

**Headers**:
```
Authorization: Bearer {admin-token}
Content-Type: application/json
```

**Request Body**:
```json
{
  "open": false,
  "deadline": "2024-03-01T23:59:59Z"
}
```

**Expected Response**:
```json
{
  "message": "System settings updated successfully.",
  "system_status": {
    "is_open": false,
    "deadline": "2024-03-01T23:59:59Z",
    "is_late_fee": false,
    "current_fee": 500
  }
}
```

### Step 7: Get Payment Data

**Endpoint**: `GET /api/nysc/admin/payments`

**Headers**:
```
Authorization: Bearer {admin-token}
Content-Type: application/json
```

**Expected Response**:
```json
{
  "payments": [
    {
      "id": 1,
      "student_id": 123,
      "full_name": "Student Name",
      "matric_no": "vug/csc/16/1336",
      "is_paid": true,
      "payment_amount": 500,
      "payment_reference": "ref_123",
      "payment_date": "2024-01-15T10:30:00Z"
    }
  ],
  "statistics": {
    "total_amount": 500,
    "standard_fee_count": 1,
    "late_fee_count": 0
  }
}
```

### Step 8: Export Data

**Endpoint**: `GET /api/nysc/admin/export/{format}`

**Headers**:
```
Authorization: Bearer {admin-token}
Content-Type: application/json
```

**Formats**: `csv`, `excel`, `pdf`

**Example**: `GET /api/nysc/admin/export/csv`

**Expected Response**: CSV file download or JSON response with export data.

### Step 9: Get System Settings

**Endpoint**: `GET /api/nysc/admin/settings/system`

**Headers**:
```
Authorization: Bearer {admin-token}
Content-Type: application/json
```

**Expected Response**:
```json
{
  "settings": {
    "update_fee": 500,
    "late_fee": 10000,
    "payment_deadline": "2024-02-15T23:59:59Z",
    "system_open": true,
    "system_message": "",
    "contact_email": "admin@nysc.gov.ng",
    "contact_phone": "+234-800-NYSC"
  }
}
```

### Step 10: Update System Settings

**Endpoint**: `PUT /api/nysc/admin/settings/system`

**Headers**:
```
Authorization: Bearer {admin-token}
Content-Type: application/json
```

**Request Body**:
```json
{
  "update_fee": 1000,
  "late_fee": 15000,
  "system_message": "System maintenance scheduled for tomorrow.",
  "contact_email": "support@nysc.gov.ng"
}
```

**Expected Response**:
```json
{
  "message": "System settings updated successfully.",
  "settings": {
    "update_fee": 1000,
    "late_fee": 15000,
    "system_message": "System maintenance scheduled for tomorrow.",
    "contact_email": "support@nysc.gov.ng"
  }
}
```

---

## Testing Notes

1. **Authentication**: All protected endpoints require the `Authorization: Bearer {token}` header.
2. **Error Handling**: Test invalid credentials, expired tokens, and malformed requests.
3. **Validation**: Test with invalid data to ensure proper validation responses.
4. **Permissions**: Ensure student endpoints don't work with admin tokens and vice versa.
5. **Payment Flow**: The payment verification step may require actual Paystack integration testing.

## Common Error Responses

### 401 Unauthorized
```json
{
  "message": "Unauthenticated."
}
```

### 403 Forbidden
```json
{
  "message": "This action is unauthorized."
}
```

### 422 Validation Error
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["The email field is required."]
  }
}
```

### 404 Not Found
```json
{
  "message": "Student record not found."
}
```

---

## Next Steps

1. Run through the complete student workflow from login to data submission
2. Test the admin workflow for managing students and system settings
3. Verify that the payment-first architecture is working correctly
4. Test error scenarios and edge cases
5. Validate that data is only saved after successful payment verification
