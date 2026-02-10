ClinicEMR Kenya - Open Source Hospital Management System
<p align="center"> <img src="https://img.shields.io/badge/Made%20for-Kenya-008751" alt="Made for Kenya"> <img src="https://img.shields.io/badge/License-MIT-green" alt="License"> <img src="https://img.shields.io/badge/PHP-Procedural-777BB4" alt="PHP Procedural"> <img src="https://img.shields.io/badge/Version-2.0.0-blue" alt="Version"> <img src="https://img.shields.io/badge/Status-Production%20Ready-success" alt="Status"> </p>
🇰🇪 Built Specifically for Kenyan Healthcare Facilities

ClinicEMR Kenya is a comprehensive, open-source Electronic Medical Records (EMR) and Hospital Management System designed specifically for healthcare facilities in Kenya. This system addresses the unique needs of Kenyan clinics and hospitals while being lightweight enough to run efficiently in areas with limited internet connectivity.
 Project Overview

This is a fully-functional Hospital Management System developed using Procedural PHP with a MySQL database backend. It's designed to be easily deployable in both urban and rural healthcare facilities across Kenya, with special consideration for limited infrastructure environments.
Key Features:

    MOH-Compliant reporting structures

    NHIF-compatible billing systems

    Offline-first design for low-connectivity areas

    Multi-language support (English & Swahili)

    Role-based access control for different staff types

 System Modules
1. Reception & Patient Registration

    Patient registration with Kenyan ID support

    NHIF member verification

    Appointment scheduling

    Emergency case handling

    Patient queue management

2. Clinical Module (Doctor/Nurse)

    Electronic Medical Records (EMR)

    Prescription management

    Vital signs tracking

    Treatment plans

    Progress notes

    Referral management

3. Pharmacy & Inventory

    Drug stock management

    Prescription dispensing

    Expiry tracking

    Reorder level alerts

    Essential Medicines List (EML) integration

4. Laboratory Module

    Test ordering and results entry

    Laboratory information management

    Report generation

    Sample tracking

    Equipment maintenance logs

5. Billing & Finance

    NHIF and private billing

    Revenue tracking

    Expense management

    Insurance claim processing

    Financial reports

6. Reports & HMIS

    Automated MOH 711, 712, 717 reports

    Disease surveillance

    Performance dashboards

    DHIS2 export compatibility

 User Roles

    Receptionist - Patient registration and appointments

    Clinical Officer/Nurse - Patient assessment and treatment

    Doctor - Diagnosis and prescriptions

    Pharmacist - Drug dispensing

    Lab Technician - Test processing

    Administrator - System configuration

 Technical Requirements
Minimum Hardware Requirements
Component	Server	Client
Processor	Dual-core 2.0GHz	Dual-core 1.6GHz
RAM	4GB	2GB
Storage	50GB	20GB
Network	LAN/WiFi	LAN/WiFi
Software Requirements

    Web Server: Apache 2.4+ or Nginx

    PHP: 7.4 or higher

    Database: MySQL 5.7+ or MariaDB 10.3+

    OS: Ubuntu 18.04+, Windows Server 2012+, or Windows 10+

 Quick Installation
Step 1: Prerequisites Installation
bash

# On Ubuntu/Debian
sudo apt update
sudo apt install apache2 mysql-server php php-mysql php-gd php-curl php-xml php-mbstring

Step 2: Download and Extract
bash

cd /var/www/html
git clone https://github.com/yourusername/clinicemr-kenya.git
cd clinicemr-kenya

Step 3: Database Setup
bash

mysql -u root -p
CREATE DATABASE clinicemr_kenya;
CREATE USER 'clinicemr_user'@'localhost' IDENTIFIED BY 'secure_password';
GRANT ALL PRIVILEGES ON clinicemr_kenya.* TO 'clinicemr_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;

Step 4: Import Database
bash

mysql -u root -p clinicemr_kenya < database/schema.sql
mysql -u root -p clinicemr_kenya < database/seed_data.sql

Step 5: Configuration

    Copy config.example.php to config.php

    Update database credentials in config.php

    Set facility details (name, MOH code, county, etc.)

    Configure file permissions:

bash

chmod 755 -R /var/www/html/clinicemr-kenya/
chown -R www-data:www-data /var/www/html/clinicemr-kenya/

Step 6: Access the System

    Open browser and navigate to: http://your-server-ip/clinicemr-kenya

    Login with default credentials:

        Username: admin

        Password: admin123

    Change password immediately after first login

 Configuration for Kenyan Context
1. Facility Setup

    Enter facility name and MOH code

    Configure county and sub-county details

    Set up service packages (NHIF, private, etc.)

2. NHIF Configuration

    Enter NHIF facility credentials

    Configure benefit packages

    Set up claim submission settings

3. Local Settings

    Enable Swahili language option

    Configure currency (KES)

    Set up local holidays and working hours

Reports Configuration
Automated MOH Reports

    Configure reporting periods

    Set up indicator definitions

    Test report generation

    Configure auto-submission if internet available

Custom Reports

    Access Reports module

    Select report type

    Choose date range

    Export to PDF/Excel

 Security Best Practices
After Installation:

    Change all default passwords

    Set up SSL certificate for HTTPS

    Configure firewall rules

    Enable automatic backups

    Set up user activity logging

Regular Maintenance:

    Daily backup verification

    Weekly log reviews

    Monthly user access audits

    Quarterly security updates

📱 Mobile Access (Optional)
Via Browser:

    System is mobile-responsive

    Access via any modern smartphone browser

    Optimized for 3G/4G connections

Offline Mode:

    Data entry available offline

    Auto-sync when connection restored

    Conflict resolution for concurrent edits

🤝 Support & Community
Getting Help:

    Documentation: /docs/ directory

    Issue Tracker: GitHub Issues

    Email: humphreylidwaji@proton.me

    Community Forum: GitHub Discussions

For Kenyan Facilities:

    Technical Support: Available during business hours

    Training Materials: Included in installation

    Implementation Guide: Step-by-step setup instructions

🐛 Troubleshooting Common Issues
1. Database Connection Error
php

// Check config.php settings
// Verify MySQL service is running
// Confirm user permissions

2. Slow Performance

    Check server resources

    Optimize MySQL configuration

    Enable PHP opcache

    Consider SSD storage

3. Report Generation Issues

    Verify PHP memory limit (min 256MB)

    Check file permissions on temp directory

    Ensure GD library is installed for charts

Updating the System
Manual Update:
bash

cd /var/www/html/clinicemr-kenya
git pull origin main
mysql -u root -p clinicemr_kenya < database/updates/latest_patch.sql

Backup Before Update:
bash

# Backup database
mysqldump -u root -p clinicemr_kenya > backup_$(date +%Y%m%d).sql

# Backup application files
tar -czf clinicemr_backup_$(date +%Y%m%d).tar.gz /var/www/html/clinicemr-kenya

License

MIT License - See LICENSE file for details.
 Acknowledgments

    Kenya Ministry of Health

    NHIF for billing standards

    Open Source Healthcare Community

    All contributing developers and testers

 Contact

ClinicEMR Kenya Project Team

    Email: humphreylidwaji@proton.me

    Website: https://clinicemr.org

    GitHub: https://github.com/HumphreyLidwaji/clinicemrsourcecode

"Empowering Kenyan healthcare through open-source technology"

Important Note: This is production-ready software. Always test in a staging environment before deploying to production. Regular backups are essential for patient data safety.
