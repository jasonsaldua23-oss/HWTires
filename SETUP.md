# Highway Tires Management System - Setup Guide

## Installation Instructions

### Prerequisites
- XAMPP (with PHP 7.4+, MySQL 5.7+, Apache)
- Web browser (Chrome, Firefox, Safari, Edge)
- Basic knowledge of PHP and MySQL

### Step-by-Step Installation

#### 1. Extract Files
Extract the `hwtires` folder to:
```
C:\xampp\htdocs\hwtires
```

#### 2. Create Database

**Option A: Using phpMyAdmin**
1. Open phpMyAdmin: `http://localhost/phpmyadmin`
2. Create new database: `hwtires`
3. Go to "Import" tab
4. Select `/database/schema.sql`
5. Click "Import"

**Option B: Using MySQL Command Line**
```bash
mysql -u root -p < C:\xampp\htdocs\hwtires\database\schema.sql
```

#### 3. Configure Database Connection
The connection is already configured in `/includes/config.php`:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');  // Default XAMPP password is empty
define('DB_NAME', 'hwtires');
```

If your database credentials are different, edit `/includes/config.php` accordingly.

#### 4. Start XAMPP
1. Open XAMPP Control Panel
2. Start "Apache" service
3. Start "MySQL" service

#### 5. Access Application
Open your browser and navigate to:
```
http://localhost/hwtires
```

### Troubleshooting

**Database Connection Error**
- Ensure MySQL service is running in XAMPP
- Verify database credentials in `/includes/config.php`
- Check if database `hwtires` exists

**PHP Errors**
- Ensure PHP 7.4+ is installed
- Check XAMPP error logs in `logs/` directory

**Cannot find website**
- Verify files are in `C:\xampp\htdocs\hwtires`
- Check localhost works: `http://localhost`

**404 Errors**
- Ensure folder structure matches documentation
- Check file names and paths are correct

## File Structure

```
hwtires/
├── index.php              # Login page
├── logout.php             # Logout handler
├── assets/
│   ├── css/
│   │   └── custom.css     # Custom styles
│   ├── js/
│   ├── images/
│   │   ├── logo.png       # Company logo
│   │   └── favicon.ico    # Favicon
├── includes/
│   ├── config.php         # Database & app config
│   ├── header.php         # HTML head
│   ├── sidebar.php        # Navigation menu
│   └── footer.php         # HTML footer
├── pages/
│   ├── customers/         # Customer management
│   ├── vehicles/          # Vehicle management
│   ├── quotations/        # Quotation system
│   ├── job-orders/        # Job order management
│   ├── service-status/    # Service status tracking
│   ├── tire-inventory/    # Inventory management
│   ├── technicians/       # Technician management
│   ├── forecasting/       # Forecasting (admin)
│   ├── reports/           # Reports (admin)
│   ├── users/            # User management (admin)
│   ├── branch-management/ # Branch management (admin)
│   └── settings/         # Settings (admin)
├── admin/
│   └── index.php         # Admin dashboard
├── front-desk/
│   └── index.php         # Front desk dashboard
├── database/
│   └── schema.sql        # Database schema
├── uploads/              # File uploads
└── SETUP.md             # This file
```

## Default Credentials

See `CREDENTIALS.md` for login credentials.

## Features

### Authentication
- Role-based access control (Admin, Front-Desk)
- Branch-specific access for Front-Desk users
- Session-based login with password hashing

### Admin Features
- Multi-branch management
- Complete reporting and analytics
- Inventory forecasting
- User management
- System settings

### Front-Desk Features
- Customer management
- Quick quotation creation
- Job order management
- Service status tracking
- Branch-specific inventory (if applicable)

### Common Features
- Customer records with history
- Vehicle tracking
- Quotation and approval workflow
- Service history timeline
- Technician assignment
- Responsive mobile design

## Database Tables

- **users** - System users and admins
- **branches** - Branch locations and settings
- **customers** - Client database
- **vehicles** - Customer vehicle inventory
- **technicians** - Staff members
- **quotations** - Service quotes
- **quotation_items** - Quote line items
- **job_orders** - Service jobs
- **service_history** - Service records
- **inventory_items** - Stock items
- **inventory_transactions** - Stock movements
- **customer_visits** - Visit tracking
- **audit_logs** - System audit trail

## Security Features

- SQL injection prevention (prepared statements)
- XSS protection (output escaping)
- CSRF token validation
- Password hashing with bcrypt
- Session management
- Audit logging

## Tips & Best Practices

1. **Regular Backups**
   - Backup database regularly using phpMyAdmin export
   - Backup files in `/uploads` folder

2. **User Management**
   - Create admin users for system management
   - Assign front-desk users to specific branches

3. **Data Entry**
   - Complete customer profiles for accurate reports
   - Update vehicle mileage regularly
   - Track all service work through job orders

4. **Performance**
   - Regularly clear old audit logs
   - Archive completed job orders monthly
   - Optimize database indexes

## Support & Documentation

For support or additional information:
- Check included documentation in each module
- Review error logs in browser console
- Check server error logs in XAMPP logs folder

## Future Enhancements

- Real-time notifications via WebSocket
- SMS/Email notifications
- Advanced analytics with Chart.js
- File uploads for quotes and invoices
- Mobile app (React Native)
- API for third-party integration
- Appointment scheduling
- Customer portal

## Version

**Version:** 1.0.0
**Release Date:** May 2, 2026
**Status:** Production Ready
