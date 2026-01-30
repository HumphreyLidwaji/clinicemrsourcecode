<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/Permission.php';

// Initialize permissions
SimplePermission::init($session_user_id);
?>

<!-- Main Sidebar Container -->
<aside class="main-sidebar sidebar-dark-<?php echo nullable_htmlentities($config_theme); ?> d-print-none">

    <a class="brand-link" href="/clinic/dashboard.php">
        <div class="brand-image"></div>
        <span class="brand-text h4"><?php echo nullable_htmlentities($session_company_name); ?></span>
    </a>

    <!-- Sidebar -->
    <div class="sidebar">

        <!-- Sidebar Menu -->
        <nav>
            <ul class="nav nav-pills nav-sidebar flex-column mt-3" data-widget="treeview" data-accordion="false">
                
                <!-- Dashboard -->
                <li class="nav-item">
                    <a href="/clinic/dashboard.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "dashboard.php") { echo "active"; } ?>">
                        <i class="nav-icon fas fa-tachometer-alt"></i>
                        <p>Dashboard</p>
                    </a>
                </li>

                <!-- ========== CLINICAL MODULES ========== -->

<!-- Front Desk Module -->
<?php if (SimplePermission::any(['module_patients', 'patient_view', '*'])): ?>
<li class="nav-item has-treeview <?php
    if (in_array(basename($_SERVER["PHP_SELF"]), [
        'patient.php',
        'visit.php',
        'opd.php',
        'ipd.php',
        'patient_files.php'
    ])) {
        echo 'menu-open';
    }
?>">
    <a href="#" class="nav-link">
        <i class="nav-icon fas fa-user-injured"></i>
        <p>
            Front Desk
            <i class="right fas fa-angle-left"></i>
        </p>
    </a>

    <ul class="nav nav-treeview">

        <!-- Patients -->
        <?php if (SimplePermission::any(['patient_view', '*'])): ?>
        <li class="nav-item">
            <a href="/clinic/patient/patient.php"
               class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == 'patient.php') echo 'active'; ?>">
                <i class="nav-icon fas fa-users"></i>
                <p>Patients</p>
            </a>
        </li>
        <?php endif; ?>
        <!-- Visits -->
        <?php if (SimplePermission::any(['visit_view', '*'])): ?>
        <li class="nav-item">
            <a href="/clinic/visit/visits.php"
               class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == 'visits.php') echo 'active'; ?>">
                <i class="nav-icon fas fa-notes-medical"></i>
                <p>Visits</p>
            </a>
        </li>
        <?php endif; ?>


        <!-- IPD -->
        <?php if (SimplePermission::any(['ipd_view', '*'])): ?>
        <li class="nav-item">
            <a href="/clinic/visit/ipd.php"
               class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == 'ipd.php') echo 'active'; ?>">
                <i class="nav-icon fas fa-procedures"></i>
                <p>IPD Admissions</p>
            </a>
        </li>
        <?php endif; ?>
          


        <!-- Patient Files -->
        <?php if (SimplePermission::any(['patient_files_upload', '*'])): ?>
        <li class="nav-item">
            <a href="/clinic/patient/patient_files.php"
               class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == 'patient_files.php') echo 'active'; ?>">
                <i class="nav-icon fas fa-file-upload"></i>
                <p>Patient Files</p>
            </a>
        </li>
        <?php endif; ?>

    </ul>
</li>
<?php endif; ?>



                <!-- Doctor Module -->
                <?php if (SimplePermission::any(['module_doctor', 'doctor_view', '*'])): ?>
                <li class="nav-item has-treeview <?php if (in_array(basename($_SERVER["PHP_SELF"]), ['doctor_dashboard.php', 'doctor_patients.php', 'doctor_appointments.php', 'doctor_consultation.php', 'doctor_prescription.php', 'nurse_assignments.php'])) { echo 'menu-open'; } ?>">
                    <a href="#" class="nav-link">
                        <i class="nav-icon fas fa-user-md"></i>
                        <p>
                            Doctor
                            <i class="right fas fa-angle-left"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <?php if (SimplePermission::any(['doctor_dashboard', '*'])): ?>
                        <li class="nav-item">
                            <a href="/clinic/doctor/doctor_dashboard.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "doctor_dashboard.php") { echo "active"; } ?>">
                                <i class="fas fa-tachometer-alt nav-icon"></i>
                                <p>Dashboard</p>
                            </a>
                        </li>
                        <?php endif; ?>
                </ul>
                </li>
                <?php endif; ?>

                <!-- Nursing Module -->
                <?php if (SimplePermission::any(['module_nursing', 'nurse_view', '*'])): ?>
                <li class="nav-item has-treeview <?php if (in_array(basename($_SERVER["PHP_SELF"]), ['nurse_dashboard.php', 'nurse_handover.php'])) { echo 'menu-open'; } ?>">
                    <a href="#" class="nav-link">
                        <i class="nav-icon fas fa-user-nurse"></i>
                        <p>
                            Nursing
                            <i class="right fas fa-angle-left"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                                        
                          <?php if (SimplePermission::any(['nurse_dashboard', '*'])): ?>
                        <li class="nav-item">
                            <a href="/clinic/nurse/nurse_dashboard.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "nurse_dashboard.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-tachometer-alt"></i>
                                <p>Nurse Dashboard</p>
                            </a>
                        </li>
                        <?php endif; ?>
                        
                  
                              <li class="nav-item">
                            <a href="/clinic/nurse/wards_management_nurse.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "wards_management_nurse.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-exchange-alt"></i>
                                <p>Wards & Beds</p>
                            </a>
                        </li>
                        
                          
                        
                    </ul>
                </li>
                <?php endif; ?>

                <!-- ========== DIAGNOSTIC MODULES ========== -->

                <!-- Laboratory Module -->
                <?php if (SimplePermission::any(['module_laboratory', 'lab_view', '*'])): ?>
                <li class="nav-item has-treeview <?php if (in_array(basename($_SERVER["PHP_SELF"]), ['lab_dashboard.php', 'lab_orders.php', 'lab_results.php', 'lab_category.php', 'lab_tests.php', 'lab_collection.php'])) { echo 'menu-open'; } ?>">
                    <a href="#" class="nav-link">
                        <i class="nav-icon fas fa-vials"></i>
                        <p>
                            Laboratory
                            <i class="right fas fa-angle-left"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                    
                        
                        <?php if (SimplePermission::any(['lab_orders', 'order_view', '*'])): ?>
                        <li class="nav-item">
                            <a href="/clinic/laboratory/lab_orders.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "lab_orders.php") { echo "active"; } ?>">
                                <i class="fas fa-clipboard-list nav-icon"></i>
                                <p>Lab Orders</p>
                            </a>
                        </li>
                        <?php endif; ?>
                        
                      
                        
                        <?php if (SimplePermission::any(['lab_tests', 'test_manage', '*'])): ?>
                        <li class="nav-item">
                            <a href="/clinic/laboratory/lab_tests.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "lab_tests.php") { echo "active"; } ?>">
                                <i class="fas fa-microscope nav-icon"></i>
                                <p>Lab Tests</p>
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php if (SimplePermission::any(['lab_category', 'category_manage', '*'])): ?>
                        <li class="nav-item">
                            <a href="/clinic/laboratory/lab_category.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "lab_category.php") { echo "active"; } ?>">
                                <i class="fas fa-tags nav-icon"></i>
                                <p>Lab Categories</p>
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>

                <!-- Operating Theatre Module -->
                <?php if (SimplePermission::any(['module_theatre', 'theatre_view', '*'])): ?>
                <li class="nav-item has-treeview <?php if (in_array(basename($_SERVER["PHP_SELF"]), ['theatre_dashboard.php'])) { echo 'menu-open'; } ?>">
                    <a href="#" class="nav-link">
                        <i class="nav-icon fas fa-procedures"></i>
                        <p>
                            Operating Theatre
                            <i class="right fas fa-angle-left"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <?php if (SimplePermission::any(['theatre_dashboard', '*'])): ?>
                        <li class="nav-item">
                            <a href="/clinic/ot/theatre_dashboard.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "theatre_dashboard.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-tachometer-alt"></i>
                                <p>Dashboard</p>
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>

                <!-- ========== PHARMACY & INVENTORY ========== -->

                <!-- Pharmacy Module -->
                <?php if (SimplePermission::any(['module_pharmacy', 'pharmacy_view', '*'])): ?>
                <li class="nav-item has-treeview <?php if (in_array(basename($_SERVER["PHP_SELF"]), ['pharmacy_dashboard.php', 'pharmacy_drugs.php', 'pharmacy_prescriptions.php', 'pharmacy_purchase_orders.php', 'pharmacy_invoices.php', 'pharmacy_requisitions.php', 'pharmacy_transfers.php', 'pharmacy_adjustments.php', 'pharmacy_stock.php', 'pharmacy_reports.php', 'pharmacy_returns.php', 'pharmacy_suppliers.php'])) { echo 'menu-open'; } ?>">
                    <a href="#" class="nav-link">
                        <i class="nav-icon fas fa-pills"></i>
                        <p>
                            Pharmacy
                            <i class="right fas fa-angle-left"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                   
                        
                        <?php if (SimplePermission::any(['drug_manage', '*'])): ?>
                        <li class="nav-item">
                            <a href="/clinic/pharmacy/drugs_manage.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "pharmacy_drugs.php") { echo "active"; } ?>">
                                <i class="fas fa-capsules nav-icon"></i>
                                <p>Drug Items</p>
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php if (SimplePermission::any(['pharmacy_prescriptions', 'prescription_dispense', '*'])): ?>
                        <li class="nav-item">
                            <a href="/clinic/pharmacy/pharmacy_prescriptions.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "pharmacy_prescriptions.php") { echo "active"; } ?>">
                                <i class="fas fa-prescription nav-icon"></i>
                                <p>Prescriptions</p>
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        
                        <?php if (SimplePermission::any(['pharmacy_reports', 'report_view', '*'])): ?>
                        <li class="nav-item">
                            <a href="/clinic/pharmacy/pharmacy_reports.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "pharmacy_reports.php") { echo "active"; } ?>">
                                <i class="fas fa-chart-bar nav-icon"></i>
                                <p>Reports</p>
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>

                <!-- Inventory Module -->
                <?php if (SimplePermission::any(['module_inventory', 'inventory_view', '*'])): ?>
                <li class="nav-item has-treeview <?php if (in_array(basename($_SERVER["PHP_SELF"]), ['inventory_dashboard.php', 'inventory_transaction.php', 'inventory_audit.php', 'inventory_stock.php', 'purchase_orders.php', 'inventory_reports.php', 'inventory.php'])) { echo 'menu-open'; } ?>">
                    <a href="#" class="nav-link">
                        <i class="nav-icon fas fa-warehouse"></i>
                        <p>
                            Inventory
                            <i class="right fas fa-angle-left"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                     
                        
                        <?php if (SimplePermission::any(['inventory_items', 'item_manage', '*'])): ?>
                        <li class="nav-item">
                            <a href="/clinic/inventory/inventory_items.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "inventory_items.php") { echo "active"; } ?>">
                                <i class="fas fa-box nav-icon"></i>
                                <p>Items</p>
                            </a>
                        </li>
                        <?php endif; ?>
                       <li class="nav-item">
                            <a href="/clinic/asset/asset_management.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "asset_management.php") { echo "active"; } ?>">
                                <i class="fas fa-box nav-icon"></i>
                                <p>Asset</p>
                            </a>
                        </li>
                    </ul>
                </li>
                <?php endif; ?>
                 <!-- ========== RADIOLOGY MODULES ========== -->

               
                <?php if (SimplePermission::any(['module_radiology'])): ?>
                <li class="nav-item has-treeview <?php if (in_array(basename($_SERVER["PHP_SELF"]), ['radiology_imaging.php'])) { echo 'menu-open'; } ?>">
                    <a href="#" class="nav-link">
                        <i class="nav-icon fas fa-users"></i>
                        <p>
                            Radiology
                            <i class="right fas fa-angle-left"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <?php if (SimplePermission::any(['module_radiology','raiology_view_image'])): ?>
                        <li class="nav-item">
                            <a href="/clinic/radiology/radiology_imaging.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "radiology_imaging.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-tachometer-alt"></i>
                                <p>
                                    Imaging Types
                             
                                </p>
                            </a>
                        </li>
                        <?php endif; ?>
                   
                    </ul>
                </li>
                <?php endif; ?>
       <!-- ========== LAUNDRY MODULES ========== -->

               
             
                <li class="nav-item has-treeview <?php if (in_array(basename($_SERVER["PHP_SELF"]), ['radiology_imaging.php'])) { echo 'menu-open'; } ?>">
                    <a href="#" class="nav-link">
                        <i class="nav-icon fas fa-tshirt"></i>
                        <p>
                            Laundry
                            <i class="right fas fa-angle-left"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                      
                        <li class="nav-item">
                            <a href="/clinic/laundry/laundry_management.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "laundry_management.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-tshirt"></i>
                                <p>
                                    Laundry Management
                             
                                </p>
                            </a>
                        </li>
                       
                   
                    </ul>
                </li>

                <!-- ========== FINANCIAL MODULES ========== -->

                <!-- Billing Module -->
                <?php if (SimplePermission::any(['module_billing', 'billing_view', '*'])): ?>
                <li class="nav-item has-treeview <?php if (in_array(basename($_SERVER["PHP_SELF"]), ['billing_dashboard.php', 'billing_invoices.php', 'billing_payments.php'])) { echo 'menu-open'; } ?>">
                    <a href="#" class="nav-link">
                        <i class="nav-icon fas fa-file-invoice-dollar"></i>
                        <p>
                            Billing
                            <i class="right fas fa-angle-left"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                     
                           <?php if (SimplePermission::any(['billing_invoices', 'invoice_manage', '*'])): ?>
                        <li class="nav-item">
                            <a href="/clinic/billing/cash_register_dashboard.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "cash_register_dashboard.php") { echo "active"; } ?>">
                                <i class="fas fa-file-invoice nav-icon"></i>
                                <p>Cashbook</p>
                            </a>
                        </li>
                         <?php endif; ?>
                        <?php if (SimplePermission::any(['billing_invoices', 'invoice_manage', '*'])): ?>
                        <li class="nav-item">
                            <a href="/clinic/billing/billing.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "billing.php") { echo "active"; } ?>">
                                <i class="fas fa-file-invoice nav-icon"></i>
                                <p>Bills</p>
                            </a>
                        </li>
                        <?php endif; ?>
                            <li class="nav-item">
                            <a href="/clinic/billing/invoices.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "invoices.php") { echo "active"; } ?>">
                                <i class="fas fa-file-invoice nav-icon"></i>
                                <p>Invoices</p>
                            </a>
                        </li>
                        
                        <?php if (SimplePermission::any(['billing_payments', 'payment_manage', '*'])): ?>
                        <li class="nav-item">
                            <a href="/clinic/billing/payment_dashboard.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "payment_dashboard.php") { echo "active"; } ?>">
                                <i class="fas fa-credit-card nav-icon"></i>
                                <p>Payments</p>
                            </a>
                        </li>
                        <?php endif; ?>
                        

                    </ul>
                </li>
                <?php endif; ?>

                <!-- Accounts/Finance Module -->
                <?php if (SimplePermission::any(['module_accounts', 'accounts_view', '*'])): ?>
                <li class="nav-item has-treeview <?php if (in_array(basename($_SERVER["PHP_SELF"]), ['accounts.php', 'journal_entries.php', 'reports_trial_balance.php'])) { echo 'menu-open'; } ?>">
                    <a href="#" class="nav-link">
                        <i class="nav-icon fas fa-calculator"></i>
                        <p>
                            Accounts/Finance
                            <i class="right fas fa-angle-left"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <?php if (SimplePermission::any(['accounts_dashboard', '*'])): ?>
                        <li class="nav-item">
                            <a href="/clinic/accounts/accounts.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "accounts.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-tachometer-alt"></i>
                                <p>Dashboard</p>
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php if (SimplePermission::any(['journal_entries', 'journal_manage', '*'])): ?>
                        <li class="nav-item">
                            <a href="/clinic/accounts/journal_entries.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "journal_entries.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-book"></i>
                                <p>Journals</p>
                            </a>
                        </li>
                        <?php endif; ?>
                          <li class="nav-item">
                            <a href="/clinic/accounts/petty_cash.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == " petty_cash.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-book"></i>
                                <p>Petty Cash</p>
                            </a>
                        </li>
                       
                        <?php if (SimplePermission::any(['reports_finance', 'report_view', '*'])): ?>
                        <li class="nav-item">
                            <a href="/clinic/accounts/reports_trial_balance.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "reports_trial_balance.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-balance-scale"></i>
                                <p>Trial Balance</p>
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>

                <!-- ========== ADMINISTRATIVE MODULES ========== -->

                <!-- HR Module -->
                <?php if (SimplePermission::any(['module_hr', 'hr_view', '*'])): ?>
                <li class="nav-item has-treeview <?php if (in_array(basename($_SERVER["PHP_SELF"]), ['hr_dashboard.php'])) { echo 'menu-open'; } ?>">
                    <a href="#" class="nav-link">
                        <i class="nav-icon fas fa-users"></i>
                        <p>
                            Human Resources
                            <i class="right fas fa-angle-left"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <?php if (SimplePermission::any(['hr_dashboard', '*'])): ?>
                        <li class="nav-item">
                            <a href="/clinic/hr/hr_dashboard.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "hr_dashboard.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-tachometer-alt"></i>
                                <p>
                                    Dashboard
                             
                                </p>
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>

                <!-- System Admin Module -->
                <?php if (SimplePermission::any(['module_sysadmin', '*'])): ?>
                <li class="nav-item has-treeview <?php if (in_array(basename($_SERVER["PHP_SELF"]), ['user_management.php', 'permission_dashboard.php'])) { echo 'menu-open'; } ?>">
                    <a href="#" class="nav-link">
                        <i class="nav-icon fas fa-cogs"></i>
                        <p>
                            System Admin
                            <i class="right fas fa-angle-left"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <?php if (SimplePermission::any(['sysadmin_user_manage', '*'])): ?>
                        <li class="nav-item">
                            <a href="/clinic/admin/user/user_management.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "user_management.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-users-cog"></i>
                                <p>User Management</p>
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php if (SimplePermission::any(['sysadmin_permission_manage', '*'])): ?>
                        <li class="nav-item">
                            <a href="/clinic/admin/user/permission_dashboard.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "permission_dashboard.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-user-shield"></i>
                                <p>Permission Dashboard</p>
                            </a>
                        </li>
                        <?php endif; ?>

                           <li class="nav-item">
                            <a href="/clinic/medical_services/medical_services.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "medical_services.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-user-shield"></i>
                                <p>Medical Services</p>
                            </a>
                        </li>
                          <li class="nav-item">
                            <a href="/clinic/insurances/insurance_management.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "insurance_management.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-user-shield"></i>
                                <p>Insurances</p>
                            </a>
                        </li>
                           <li class="nav-item">
                            <a href="/clinic/wards/wards_management.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "wards_management.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-user-shield"></i>
                                <p>Wards&Beds</p>
                            </a>
                        </li>
                                <li class="nav-item">
                            <a href="/clinic/prices/price_management.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "price_management.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-user-shield"></i>
                                <p>Prices Setup</p>
                            </a>
                        </li>

                           <li class="nav-item">
                            <a href="/clinic/departments/departments.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "departments.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-user-shield"></i>
                                <p>Departments</p>
                            </a>
                        </li>
                         <li class="nav-item">
                            <a href="/clinic/admin/mail.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "mail.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-user-shield"></i>
                                <p>Mail</p>
                            </a>
                        </li>
                           <li class="nav-item">
                            <a href="/clinic/admin/icd11codes_dashboard.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "icd11codes_dashboard.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-user-shield"></i>
                                <p>ICD11 CODES</p>
                            </a>
                        </li>
                          <li class="nav-item">
                            <a href="/clinic/admin/company_settings.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "company_settings.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-user-shield"></i>
                                <p>Company Settings</p>
                            </a>
                        </li>
                            <li class="nav-item">
                            <a href="/clinic/facility/facilities.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "facilities.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-user-shield"></i>
                                <p>Facilities Settings</p>
                            </a>
                        </li>
                             <li class="nav-item">
                            <a href="/clinic/admin/update.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "update.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-user-shield"></i>
                                <p>Update Sytem</p>
                            </a>
                        </li>
                              <li class="nav-item">
                            <a href="/clinic/admin/backup_dashboard.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "backup_dashboard.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-user-shield"></i>
                                <p>Backup& Restore</p>
                            </a>
                        </li>
                              </li>
                              <li class="nav-item">
                            <a href="/clinic/logs/logs.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "logs.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-user-shield"></i>
                                <p>System Logs</p>
                            </a>
                        </li>
                    </ul>
                </li>
                <?php endif; ?>

                <!-- ========== SUPPORT MODULES ========== -->

                <!-- Tickets Module -->
                <?php if (SimplePermission::any(['module_tickets', 'ticket_view', '*'])): ?>
                <li class="nav-item has-treeview <?php if (in_array(basename($_SERVER["PHP_SELF"]), ['tickets.php', 'recurring_tickets.php'])) { echo 'menu-open'; } ?>">
                    <a href="#" class="nav-link">
                        <i class="nav-icon fas fa-life-ring"></i>
                        <p>
                            Support Tickets
                            <i class="right fas fa-angle-left"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <?php if (SimplePermission::any(['ticket_manage', '*'])): ?>
                        <li class="nav-item">
                            <a href="/clinic/tickets/tickets.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "tickets.php" || basename($_SERVER["PHP_SELF"]) == "ticket.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-ticket-alt"></i>
                                <p>Tickets</p>
                            </a>
                        </li>
                        <?php endif; ?>
                        
                    </ul>
                </li>
                <?php endif; ?>

                <!-- ========== REPORTS ========== -->

                <?php if (SimplePermission::any(['module_reports', 'report_view', '*'])): ?>
                <li class="nav-item mt-3">
                    <a href="/moh_reports/moh_dashboard.php" class="nav-link">
                        <i class="fas fa-chart-line nav-icon"></i>
                        <p>MOH Reports</p>
                        <i class="fas fa-angle-right nav-icon float-right"></i>
                    </a>
                </li>
                <?php endif; ?>

            </ul>
        </nav>
        <!-- /.sidebar-menu -->

        <div class="mb-3"></div>

    </div>
    <!-- /.sidebar -->

</aside>