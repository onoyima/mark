# NYSC Frontend Structure (Next.js)

This document outlines the structure for the Next.js frontend application that will interface with the NYSC Student Verification System API.

## Project Setup

```bash
npx create-next-app@latest nysc-frontend --typescript --eslint
cd nysc-frontend
npm install axios react-hook-form @hookform/resolvers yup react-toastify @chakra-ui/react @emotion/react @emotion/styled framer-motion
```

## Project Structure

```
nysc-frontend/
├── public/
│   ├── favicon.ico
│   └── logo.svg
├── src/
│   ├── components/
│   │   ├── common/
│   │   │   ├── Button.tsx
│   │   │   ├── Card.tsx
│   │   │   ├── FormInput.tsx
│   │   │   ├── Layout.tsx
│   │   │   ├── Loader.tsx
│   │   │   └── Navbar.tsx
│   │   ├── student/
│   │   │   ├── DataConfirmationForm.tsx
│   │   │   ├── PaymentForm.tsx
│   │   │   └── StudentDashboard.tsx
│   │   └── admin/
│   │       ├── AdminDashboard.tsx
│   │       ├── ControlPanel.tsx
│   │       ├── ExportPanel.tsx
│   │       ├── PaymentLog.tsx
│   │       └── StudentEditor.tsx
│   ├── contexts/
│   │   ├── AuthContext.tsx
│   │   └── NyscContext.tsx
│   ├── hooks/
│   │   ├── useAuth.ts
│   │   ├── useNysc.ts
│   │   └── usePaystack.ts
│   ├── pages/
│   │   ├── _app.tsx
│   │   ├── _document.tsx
│   │   ├── index.tsx
│   │   ├── login.tsx
│   │   ├── student/
│   │   │   ├── index.tsx
│   │   │   ├── confirm.tsx
│   │   │   └── payment.tsx
│   │   └── admin/
│   │       ├── index.tsx
│   │       ├── login.tsx
│   │       ├── control.tsx
│   │       ├── students/
│   │       │   ├── index.tsx
│   │       │   └── [id].tsx
│   │       ├── exports.tsx
│   │       └── payments.tsx
│   ├── services/
│   │   ├── api.ts
│   │   ├── auth.service.ts
│   │   ├── student.service.ts
│   │   ├── payment.service.ts
│   │   └── admin.service.ts
│   ├── styles/
│   │   ├── globals.css
│   │   └── theme.ts
│   ├── types/
│   │   ├── auth.types.ts
│   │   ├── student.types.ts
│   │   └── admin.types.ts
│   └── utils/
│       ├── axios.ts
│       ├── formatters.ts
│       └── validators.ts
├── .env.local
├── .env.example
├── .gitignore
├── next.config.js
├── package.json
├── README.md
└── tsconfig.json
```

## Key Features Implementation

### Student Interface

1. **Authentication**
   - Login page with matric number and password fields
   - JWT token storage in cookies or local storage
   - Protected routes for authenticated students

2. **Data Confirmation Form**
   - Pre-filled form with student data from API
   - Form validation with Yup schema
   - Submit button disabled after successful submission
   - Error handling and feedback

3. **Payment Flow**
   - Display of correct fee (standard or late)
   - Paystack integration with React hooks
   - Payment status tracking and verification
   - Success/failure notifications

### Admin Interface

1. **Staff Login**
   - Email and password authentication
   - Admin-specific JWT token
   - Protected admin routes

2. **Dashboard**
   - List of all submitted student records
   - Statistics cards (total submissions, payment status)
   - Search and filter functionality
   - Pagination for large datasets

3. **Management Features**
   - Control panel for setting open/close dates
   - Force stop button with confirmation
   - Student profile editor with form validation
   - Export functionality with format selection
   - Payment log with detailed statistics

## API Integration

The frontend will communicate with the backend API using Axios. All API calls will be centralized in service files, and authentication tokens will be automatically included in request headers.

```typescript
// Example API service structure
import axios from '../utils/axios';

export const studentService = {
  getDetails: () => axios.get('/nysc/student/details'),
  updateDetails: (data) => axios.post('/nysc/student/update', data),
  initiatePayment: () => axios.post('/nysc/payment'),
  verifyPayment: (reference) => axios.get(`/nysc/payment/verify?reference=${reference}`),
};
```

## Responsive Design

The application will be fully responsive, ensuring a good user experience on both desktop and mobile devices. This will be achieved using Chakra UI's responsive design system and custom CSS media queries where needed.

## Deployment

The Next.js application can be deployed to Vercel, Netlify, or any other hosting platform that supports Next.js applications. Environment variables will be used to configure the API base URL for different environments.