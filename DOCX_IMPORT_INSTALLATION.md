# DOCX Class of Degree Import Feature - Installation Guide

## Overview

This feature allows administrators to import Class of Degree information from Microsoft Word documents (.docx) and update student records in the NYSC system.

## Backend Installation

### 1. Dependencies

The required PHP packages are already included in composer.json:

-   `phpoffice/phpword` - For processing DOCX files
-   `maatwebsite/excel` - For Excel export functionality

### 2. Files Added/Modified

-   `app/Services/DocxImportService.php` - Core service for DOCX processing
-   `app/Http/Controllers/NyscDocxImportController.php` - API controller
-   `routes/nysc.php` - Added new API routes
-   `storage/app/README_DOCX_FORMAT.md` - Documentation for file format

### 3. API Endpoints Added

-   `POST /api/nysc/admin/docx-import/upload` - Upload and process DOCX file
-   `GET /api/nysc/admin/docx-import/review/{sessionId}` - Get review data
-   `POST /api/nysc/admin/docx-import/approve` - Apply approved updates
-   `GET /api/nysc/admin/docx-import/stats` - Get import statistics
-   `GET /api/nysc/admin/export/student-data` - Export complete student data

## Frontend Installation

### 1. Dependencies

Added to `front/package.json`:

-   `react-dropzone` - For drag-and-drop file upload

### 2. Files Added

-   `front/components/admin/DocxImportForm.tsx` - File upload component
-   `front/components/admin/ImportReviewTable.tsx` - Data review component
-   `front/components/admin/ExcelExportButton.tsx` - Export functionality
-   `front/app/admin/docx-import/page.tsx` - Main import page
-   `front/app/admin/docx-import/review/page.tsx` - Review and approval page
-   `front/services/docx-import.service.ts` - API service layer
-   `front/types/docx-import.types.ts` - TypeScript type definitions

### 3. Files Modified

-   `front/components/common/Sidebar.tsx` - Added DOCX Import navigation
-   `front/services/admin.service.ts` - Added DOCX import methods
-   `front/package.json` - Added react-dropzone dependency

## Installation Steps

### Backend Setup

1. The PHP dependencies are already installed via composer
2. Ensure the `storage/app/temp/docx_imports` directory is writable
3. The routes are automatically loaded from `routes/nysc.php`

### Frontend Setup

1. ✅ **Dependency Installed**: `react-dropzone@^14.2.3` has been installed

2. ✅ **Build Successful**: Frontend compiled successfully with new pages:
    - `/admin/docx-import` - Main import interface (22 kB)
    - `/admin/docx-import/review` - Review and approval interface (9.31 kB)

## Usage

### For Administrators

1. Navigate to "DOCX Import" in the admin sidebar
2. Upload a .docx file containing matriculation numbers and Class of Degree information
3. Review the extracted and matched data
4. Approve the updates you want to apply
5. The system will update the student records in the database

### File Format Requirements

-   File must be in .docx format (Microsoft Word)
-   Maximum file size: 10MB
-   Should contain matriculation numbers (e.g., ABC/2020/001) and Class of Degree information
-   Supported formats: tables or paragraph text
-   Valid Class of Degree values: First Class, Second Class Upper, Second Class Lower, Third Class, Pass

### Export Functionality

-   Administrators can export complete student NYSC data including Class of Degree information
-   Export includes: matric_no, fname, mname, lname, phone, state, class_of_degree, dob, graduation_year, gender, marital_status, jamb_no, course_study, study_mode

## Security Features

-   File type validation (only .docx files allowed)
-   File size limits (10MB maximum)
-   Session-based review workflow with expiration (6 hours)
-   Admin authentication and permission checks
-   Database transactions for data integrity
-   Comprehensive logging for audit trails

## Error Handling

-   Graceful handling of corrupted or invalid files
-   Detailed error messages for troubleshooting
-   Rollback mechanisms for failed database operations
-   Session expiration handling
-   Network error recovery

## Performance Considerations

-   Background processing for large files
-   Efficient database queries with batch operations
-   Memory-optimized Excel export for large datasets
-   Progress tracking for long-running operations

## Troubleshooting

### Common Issues

1. **File upload fails**: Check file size (max 10MB) and format (.docx only)
2. **No matches found**: Verify matriculation number format in the document
3. **Session expired**: Re-upload the file (sessions expire after 6 hours)
4. **Permission denied**: Ensure user has 'canManageSystem' permission

### Logs

Check Laravel logs for detailed error information:

-   File processing errors
-   Database operation results
-   API request/response details
-   Session management events

## Testing

The feature includes comprehensive error handling and validation. Test with:

-   Valid .docx files with table format
-   Valid .docx files with paragraph format
-   Invalid file formats
-   Large files
-   Files with no matching students
-   Network interruption scenarios
