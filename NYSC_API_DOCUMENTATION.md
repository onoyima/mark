# NYSC API Documentation

This document describes all backend API endpoints related to the NYSC Student Verification System, including expected inputs and outputs.

## Authentication

### Student Login
- **Endpoint:** `POST /api/nysc/login`
- **Input:**
  - `matric_no` (string, required)
  - `password` (string, required)
- **Output:**
  - `token` (string)
  - `student` (object: id, name, matric_no)
  - Error: 401 if credentials are invalid

### Admin Login
- **Endpoint:** `POST /api/nysc/admin/login`
- **Input:**
  - `email` (string, required)
  - `password` (string, required)
- **Output:**
  - `token` (string)
  - `staff` (object: id, name, email)
  - Error: 401 if credentials are invalid

---

## Student APIs (Authenticated)

### Get Student Details
- **Endpoint:** `GET /api/nysc/student/details`
- **Output:**
  - `student` (object)
  - `academic` (object)
  - `contact` (object)
  - `nysc` (object)
  - `is_submitted` (boolean)
  - `is_paid` (boolean)
  - `payment_amount` (integer|null)

### Update Student Details
- **Endpoint:** `POST /api/nysc/student/update`
- **Input:**
  - All student NYSC details (see controller for full list)
  - Required fields: fname, lname, gender, dob, marital_status, phone, email, address, state_of_origin, lga, matric_no, course_of_study, department, faculty, graduation_year, cgpa, jambno, study_mode, emergency_contact_name, emergency_contact_phone, emergency_contact_relationship, emergency_contact_address
- **Output:**
  - `message` (string)
  - `data` (object)
  - Error: 403 if already submitted

---

## Payment APIs (Authenticated)

### Initiate Payment
- **Endpoint:** `POST /api/nysc/payment`
- **Output:**
  - `message` (string)
  - `payment_url` (string)
  - `reference` (string)
  - `amount` (integer)
  - Error: 403 if details not submitted, or already paid

### Verify Payment
- **Endpoint:** `GET /api/nysc/payment/verify?reference={reference}`
- **Input:**
  - `reference` (string, required)
- **Output:**
  - `message` (string)
  - `payment_details` (object: amount, reference, date)
  - Error: 400 if verification fails

---

## Admin APIs (Authenticated, ability: nysc-admin)

### Dashboard
- **Endpoint:** `GET /api/nysc/admin/dashboard`
- **Output:**
  - `students` (array)
  - `statistics` (object: total_submitted, total_paid, total_unpaid, payment_percentage)
  - `system_status` (object)

### Control System
- **Endpoint:** `POST /api/nysc/admin/control`
- **Input:**
  - `open` (boolean, required)
  - `deadline` (date, required)
- **Output:**
  - `message` (string)
  - `system_status` (object)

### Update Student Record
- **Endpoint:** `PUT /api/nysc/admin/student/{studentId}`
- **Input:**
  - Any student NYSC field (see controller for full list)
- **Output:**
  - `message` (string)
  - `data` (object)
  - Error: 404 if not found

### Export Data
- **Endpoint:** `GET /api/nysc/admin/exports/{format}`
- **Input:**
  - `format` (string: excel, csv, pdf)
- **Output:**
  - File download or JSON message

### Payments
- **Endpoint:** `GET /api/nysc/admin/payments`
- **Output:**
  - `payments` (array)
  - `statistics` (object: total_amount, standard_fee_count, late_fee_count)

---

## Notes
- All endpoints under `/api/nysc` require authentication via Sanctum token.
- Admin endpoints require `nysc-admin` ability.
- Payment integration uses Paystack; see controller for details.
- System configuration (open/close, fees, deadlines) is managed via cache and config.