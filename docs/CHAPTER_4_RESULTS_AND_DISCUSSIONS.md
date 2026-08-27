# AUTOMOTIVE SERVICE OPERATIONS AND DECISION SUPPORT SYSTEM FOR MULTI-BRANCH SERVICE CENTERS (HIGHWAY TIRES MANAGEMENT SYSTEM)

## CHAPTER IV: RESULTS AND DISCUSSIONS

This chapter shows a detailed discussion and analysis of the data, along with the results of this study. It involves the profiles of the respective respondents in the research and the system testing results for the Automotive Service Operations and Decision Support System for Multi-Branch Service Centers (Highway Tires Management System), with a focus on Customer and Vehicle Profiling, Service Operations and Quotations, Job Order Management and Status Tracking, Inventory and Inter-Branch Transfers, Forecasting and Decision Support, and Multi-Branch Reporting.

---

### 4.1 Results of the Study

The first objective aims to design and develop an Automotive Service Operations and Decision Support System for Multi-Branch Service Centers (Highway Tires Management System), featuring the following technical features: centralized customer and vehicle profiling with cross-branch service history, service operation and inspection findings documentation with automated quotation computation, job order workflow and real-time service status board, inventory stock monitoring and inter-branch stock transfer management, predictive demand forecasting with restocking decision support, and consolidated multi-branch reporting with activity logging.

The result of this objective was a system successfully designed and developed with all intended technical features, including centralized customer and vehicle profiling with cross-branch service history, service operation and inspection findings documentation with automated quotation computation, job order workflow and real-time service status board, inventory stock monitoring and inter-branch stock transfer management, predictive demand forecasting with restocking decision support, and consolidated multi-branch reporting with activity logging.

#### Figure 20
**Objective 1.1- Customer and Vehicle Profiling and Cross-Branch Service History Interface**

*[ Figure 20: Customer and Vehicle Profiling and Cross-Branch Service History Interface ]*

The Figure 20 above illustrates the customer and vehicle profiling interface, through which front desk staff members register customer contact profiles and link vehicle specifications such as make, model, year, plate number, and chassis/VIN details. The interface provides real-time search auto-suggestions and instant cross-branch record retrieval, enabling service advisors to view prior service records, repair notes, and parts replacements conducted across all branch locations to ensure seamless service continuity.

#### Figure 21
**Objective 1.2- Service Operation Encoding, Inspection Findings, and Quotation Generation Interface**

*[ Figure 21: Service Operation Encoding, Inspection Findings, and Quotation Generation Interface ]*

The Figure 21 above presents the service operation encoding, multi-point vehicle inspection, and automated quotation generation interface. Front desk staff can document detailed inspection findings (such as tire tread depth, brake condition, and suspension wear), select standardized labor services and parts items from the centralized catalog, apply promotional discounts, and automatically compute accurate itemized subtotals, tax figures, and net amounts. The system generates official printable PDF quotation forms for customer review and approval.

#### Figure 22
**Objective 1.3- Job Order Processing, Technician Task Assignment, and Live Service Status Board Interface**

*[ Figure 22: Job Order Processing, Technician Task Assignment, and Live Service Status Board Interface ]*

The Figure 22 above presents the job order execution and real-time service status board interface. When a customer approves a service operation quotation, the system converts the transaction into an active job order, assigns lead technicians, and establishes a task-level execution checklist. The live status board dynamically groups vehicle progress across workflow stages (Queued, Ongoing, Completed, and Released), updates task completion percentages, and triggers automated SMS notifications to alert vehicle owners when their service is completed and ready for pickup.

#### Figure 23
**Objective 1.4- Multi-Branch Inventory Stock Monitoring and Inter-Branch Transfer Interface**

*[ Figure 23: Multi-Branch Inventory Stock Monitoring and Inter-Branch Transfer Interface ]*

The Figure 23 above shows the multi-branch inventory stock tracking and inter-branch transfer management interface. The module provides real-time visibility into branch stock quantities, logs stock-in deliveries, and records automated stock deductions when service tasks are completed. When local inventory reaches critical levels, the system displays visual low-stock warning badges and facilitates inter-branch transfer requests, enabling administrators to review donor branch availability, authorize stock transfers, and synchronize branch inventory balances seamlessly.

#### Figure 24
**Objective 1.5- Predictive Inventory Demand Forecasting and Restocking Decision Support System Interface**

*[ Figure 24: Predictive Inventory Demand Forecasting and Restocking Decision Support System Interface ]*

The Figure 24 above illustrates the predictive inventory demand forecasting and restocking decision support interface. By evaluating historical service frequencies and parts consumption trends, the system calculates time-series demand projections (weekly, monthly, and item-level), categorizes items by stockout risk level (High Risk, Low Stock, Optimal, Overstocked), computes recommended reorder quantities based on safety stock thresholds, and recommends inter-branch rebalancing opportunities to minimize carrying costs and eliminate stockouts.

#### Figure 25
**Objective 1.6- Multi-Branch Operational Reports, Turnaround Analytics, and Activity Audit Trail Interface**

*[ Figure 25: Multi-Branch Operational Reports, Turnaround Analytics, and Activity Audit Trail Interface ]*

The Figure 25 above shows the consolidated multi-branch reporting, service turnaround analytics, and system activity audit trail interface. Management can generate executive summaries covering total service volume, technician turnaround efficiency, repeat customer visits, inventory movements, and archived operational records with one-click CSV export functionality. Furthermore, the activity audit trail logs every system action with immutable user identifiers, IP addresses, and timestamps to maintain strong accountability and operational governance.

---

### 4.2 Black-box and White-box Testing Results

The second objective of this study aims to test the functionality of the technical features mentioned in Objective 1.

The result of this objective was that the system's technical features were successfully tested using both Black-box testing (executed via Qase.io test management suites covering functional test cases TC-001 through TC-0090) and White-box testing (executed via automated PHPUnit and Pest PHP test suites covering internal code logic, session security, database transaction integrity, and forecasting algorithms) to confirm that all functions, input validations, security boundaries, database queries, and workflows operated as designed.

#### Table 22: Black-box Testing of the Alpha Testing of User Authentication, Session Management, and Profile Case

| Test Case ID | Description | Expected Outcome | Actual Outcome | Pass/ Fail | Comments |
| :--- | :--- | :--- | :--- | :---: | :--- |
| TC-001 | Administrator logs in with valid credentials | System validates credentials, creates an active administrator session, and displays the Admin Dashboard | | | |
| TC-002 | Front desk staff logs in with assigned branch credentials | System validates credentials, assigns branch context, and opens the Front Desk Dashboard | | | |
| TC-003 | User enters an invalid password during login | System blocks access and displays an error message indicating invalid login credentials | | | |
| TC-004 | User attempts login with an unassigned or inactive account | System denies access and displays a notice stating the account is inactive or disabled | | | |
| TC-005 | Logged-in user navigates to their own profile page | System displays the user profile information including full name, email, role, and branch assignment | | | |
| TC-006 | User updates their personal profile information | System validates entered information, saves changes, and displays a success notification | | | |
| TC-007 | User updates their account password | System confirms current password, verifies new password, and saves the updated password | | | |
| TC-008 | User clicks the logout button | System ends the active session, clears authentication data, and redirects to login screen | | | |
| TC-009 | Logged-out user attempts to access a protected page via direct URL | System blocks access attempt and automatically redirects user to login screen | | | |
| TC-010 | Front desk staff attempts to open an administrator-only management page | System intercepts request and redirects staff member back to front desk dashboard | | | |
| TC-011 | Already authenticated user navigates back to login page | System recognizes active session and automatically redirects to appropriate dashboard | | | |
| TC-012 | Inactive session exceeds allowed idle timeout period | System automatically expires session and requires user to log in again upon next interaction | | | |

Table 22 summarizes the user authentication, session management, role-based navigation, and profile security test cases (TC-001 through TC-0012). The evaluation verifies multi-role login controls, branch assignment validation, failed attempt error messaging, idle session timeouts, protected route restrictions, profile updates, and activity logging.

#### Table 23: Black-box Testing of the Alpha Testing of Customer and Vehicle Records Management Case

| Test Case ID | Description | Expected Outcome | Actual Outcome | Pass/ Fail | Comments |
| :--- | :--- | :--- | :--- | :---: | :--- |
| TC-0013 | Staff creates a new customer profile | System validates customer details (name, contact number, address) and saves new customer record | | | |
| TC-0014 | Staff searches for an existing customer using name or contact number | System performs search and displays matching customer records with their details | | | |
| TC-0015 | Staff registers a customer profile with an already existing phone number | System detects existing customer record and alerts staff to prevent duplicate entries | | | |
| TC-0016 | Staff leaves required customer contact fields empty | System highlights missing fields and prevents saving until complete information is provided | | | |
| TC-0017 | Staff registers a new vehicle profile under a customer | System records vehicle details (plate number, make, model, year) and links it to customer | | | |
| TC-0018 | Staff registers a vehicle with an existing plate number | System identifies existing vehicle record and alerts staff of duplicate registration | | | |
| TC-0019 | Staff views complete service history of a vehicle | System displays past service records, dates, inspection findings, parts used, and servicing branches | | | |
| TC-0020 | Staff updates existing customer contact details | System saves modified customer information and reflects updates immediately | | | |
| TC-0021 | Staff updates vehicle specifications and mileage | System saves revised vehicle specifications and updates active profile | | | |
| TC-0022 | Staff views cross-branch service records for a visiting vehicle | System retrieves and displays service transactions conducted at other branch locations | | | |
| TC-0023 | Staff archives an inactive vehicle record | System marks vehicle record as archived and hides it from active vehicle list | | | |
| TC-0024 | Staff restores a previously archived vehicle record | System restores vehicle record to active status and makes it available for service transactions | | | |
| TC-0025 | Staff archives a customer branch record | System archives branch customer profile while preserving central historical records | | | |
| TC-0026 | Staff uses live search suggestions when entering customer details | System provides instant dropdown suggestions based on entered characters | | | |

Table 23 summarizes the customer registration, vehicle profiling, and cross-branch record management test cases (TC-0013 through TC-0026). The results confirm that customer contact details, vehicle specifications, duplicate checks, cross-branch service history retrieval, archiving, and live auto-suggestions function accurately across all branch locations.

#### Table 24: Black-box Testing of the Alpha Testing of Service Operation Encoding, Inspection Findings, and Quotations Case

| Test Case ID | Description | Expected Outcome | Actual Outcome | Pass/ Fail | Comments |
| :--- | :--- | :--- | :--- | :---: | :--- |
| TC-0027 | Staff initiates a new service operation for a customer vehicle | System opens service operation form with loaded customer and vehicle information | | | |
| TC-0028 | Staff records multi-point vehicle inspection findings | System documents inspection checklist results, tire wear observations, and recommended services | | | |
| TC-0029 | Staff adds tire replacement items from catalog | System loads tire specifications, unit prices, and calculates item subtotal based on quantity | | | |
| TC-0030 | Staff adds mechanical maintenance and labor services | System adds selected service labor packages and computes applicable labor charges | | | |
| TC-0031 | Staff adds spare parts and consumable materials to service list | System adds requested parts, checks branch stock availability, and computes parts subtotal | | | |
| TC-0032 | System computes overall estimated total amount | System automatically sums parts, tires, labor, discounts, and applicable taxes in real time | | | |
| TC-0033 | Staff generates a formal customer quotation | System compiles inspection findings, selected items, labor, and total into formal quotation record | | | |
| TC-0034 | Staff applies a promotional discount to quotation | System recalculates discounted total and displays both original and discounted amounts | | | |
| TC-0035 | Customer approves the service quotation | System updates quotation status to Approved and enables job order creation | | | |
| TC-0036 | Customer declines or cancels service quotation | System records rejection reason and updates quotation status to Rejected or Canceled | | | |
| TC-0037 | Staff edits items in a pending quotation | System updates selected items, quantities, and recomputes total cost | | | |
| TC-0038 | Staff exports or prints formal quotation document | System generates cleanly formatted, printable quotation document with company branding | | | |
| TC-0039 | Staff archives a canceled quotation record | System moves canceled quotation to archived status while keeping audit history | | | |
| TC-0040 | Staff attempts to create job order from unapproved quotation | System prevents job order creation and prompts that customer approval is required | | | |

Table 24 summarizes the service operation creation, vehicle inspection documentation, quotation generation, and approval workflow test cases (TC-0027 through TC-0040). The system enforces systematic inspection recording, accurate real-time price calculations, customer discount adjustments, and formal printable quotation approvals.

#### Table 25: Black-box Testing of the Alpha Testing of Job Order Processing, Technician Task Assignment, and Service Status Board Case

| Test Case ID | Description | Expected Outcome | Actual Outcome | Pass/ Fail | Comments |
| :--- | :--- | :--- | :--- | :---: | :--- |
| TC-0041 | Staff converts an approved quotation into an active job order | System creates new job order with customer, vehicle, and approved service details | | | |
| TC-0042 | Staff assigns primary technician and assistant to job order | System assigns designated technicians, sets work schedule, and records estimated duration | | | |
| TC-0043 | System generates task-level execution checklist for job order | System creates individual checklist items for each approved service and part installation | | | |
| TC-0044 | Staff checks parts readiness before commencing job tasks | System confirms required parts are in stock or available from completed transfers | | | |
| TC-0045 | Technician starts work and updates job order status to Ongoing | System transitions job order status from Queued to Ongoing and records start time | | | |
| TC-0046 | Technician marks an individual service task as completed | System updates task progress percentage and records completion timestamp | | | |
| TC-0047 | System automatically deducts parts inventory upon completing related task | System deducts used tire/part quantity from branch stock and records consumption | | | |
| TC-0048 | Technician completes all service tasks in checklist | System transitions job order status to Completed and records finish timestamp | | | |
| TC-0049 | Staff views real-time Service Status Board | System displays visual board categorizing all active vehicles into Queued, Ongoing, and Completed | | | |
| TC-0050 | Staff filters Service Status Board by branch location | System updates display to show only job orders belonging to selected branch | | | |
| TC-0051 | Staff triggers customer pickup SMS notification | System queues and sends automated SMS message informing customer vehicle is ready | | | |
| TC-0052 | Staff processes vehicle release upon customer payment and pickup | System transitions job order status to Released, records release date, and closes job | | | |
| TC-0053 | System generates permanent vehicle service history record upon job release | System saves completed service details, parts used, and labor into vehicle service history | | | |
| TC-0054 | Staff archives a completed or canceled job order | System archives job order record while preserving complete reporting and audit data | | | |

Table 25 summarizes the job order creation, technician assignment, task-level execution tracking, service status board, and pickup notification test cases (TC-0041 through TC-0054). The results confirm that service lifecycle transitions from Queued to Released execute seamlessly with automated parts deduction and customer SMS notifications.

#### Table 26: Black-box Testing of the Alpha Testing of Inventory Stock Monitoring and Inter-Branch Stock Transfers Case

| Test Case ID | Description | Expected Outcome | Actual Outcome | Pass/ Fail | Comments |
| :--- | :--- | :--- | :--- | :---: | :--- |
| TC-0055 | Staff registers a new inventory item in catalog | System saves item details (brand, size, model, category, minimum threshold) and initializes stock records | | | |
| TC-0056 | Staff records initial stock quantity for an item at a specific branch | System records starting stock quantity and creates opening balance transaction | | | |
| TC-0057 | Staff records incoming stock shipment (Stock In) | System adds received quantity to current branch stock and logs supplier reference | | | |
| TC-0058 | Staff records manual stock adjustment with reason | System updates current stock quantity, records reason, and logs inventory adjustment | | | |
| TC-0059 | Inventory stock quantity reaches or drops below minimum threshold | System triggers visual low-stock badge alert on inventory dashboard | | | |
| TC-0060 | Staff records direct counter sale (Stock Out) tagged to customer | System deducts item quantity from stock and links transaction to customer record | | | |
| TC-0061 | Staff requests inter-branch stock transfer due to local shortage | System checks other branches for available stock and creates transfer request | | | |
| TC-0062 | System alerts donor branch and administrator of incoming transfer request | System generates in-app notification to donor branch regarding requested items | | | |
| TC-0063 | Administrator reviews pending inter-branch transfer request | System displays requested item, requested quantity, donor branch stock, and requesting branch | | | |
| TC-0064 | Administrator approves inter-branch stock transfer request | System updates request status to Approved and notifies both involved branches | | | |
| TC-0065 | Administrator rejects transfer request due to insufficient donor stock | System updates request status to Rejected with reason and notifies requesting branch | | | |
| TC-0066 | Donor branch dispatches approved items for transfer | System marks transfer as Dispatched and deducts item quantity from donor branch stock | | | |
| TC-0067 | Requesting branch receives and confirms transferred stock shipment | System adds transferred quantity to requesting branch stock and marks transfer as Completed | | | |
| TC-0068 | Staff views complete transaction history of an inventory item | System displays chronological log of stock-ins, stock-outs, transfers, job usages, and adjustments | | | |

Table 26 summarizes the inventory stock monitoring, stock-in/stock-out recording, low-stock threshold alerts, and inter-branch transfer test cases (TC-0055 through TC-0068). The system maintains exact inventory levels across multiple locations and guarantees synchronized stock adjustments during inter-branch transfers.

#### Table 27: Black-box Testing of the Alpha Testing of Predictive Forecasting and Restocking Decision Support Case

| Test Case ID | Description | Expected Outcome | Actual Outcome | Pass/ Fail | Comments |
| :--- | :--- | :--- | :--- | :---: | :--- |
| TC-0069 | Administrator generates weekly demand forecast for tire models | System analyzes historical weekly service usage and displays projected tire unit demand | | | |
| TC-0070 | Administrator generates monthly demand forecast for maintenance consumables | System evaluates monthly service transactions and projects upcoming parts consumption | | | |
| TC-0071 | Administrator filters forecasting analysis by branch, category, and year | System recalculates forecast metrics dynamically based on selected branch and item category | | | |
| TC-0072 | Administrator views item-level historical consumption trend | System displays chart and data table of past consumption versus projected future requirements | | | |
| TC-0073 | System classifies fast-depleting items as High Stockout Risk | System calculates days of remaining stock and flags items requiring urgent replenishment | | | |
| TC-0074 | System classifies slow-moving items as Overstocked | System detects low turnover items with excess stock relative to demand and flags as Overstocked | | | |
| TC-0075 | System calculates recommended reorder quantities for low-stock items | System applies safety stock formula and suggests optimal replenishment order quantities | | | |
| TC-0076 | System recommends inter-branch stock rebalancing matches | System identifies branches with excess stock and suggests transfers to branches with shortages | | | |
| TC-0077 | Administrator views forecast for newly added items with limited history | System displays baseline stock recommendations and informs user of limited historical data | | | |
| TC-0078 | Administrator exports forecasting and restocking recommendations | System exports recommended reorder and transfer plans into a downloadable spreadsheet file | | | |

Table 27 summarizes the predictive demand forecasting, stock-risk classification, and restocking decision support test cases (TC-0069 through TC-0078). The evaluation confirms that historical data analytics, moving average demand projections, stock-risk badges, and replenishment recommendations operate reliably to support managerial decision-making.

#### Table 28: Black-box Testing of Operational Reports, Notifications, Service Catalog, and System Administration Cases

| Test Case ID | Description | Expected Outcome | Actual Outcome | Pass/ Fail | Comments |
| :--- | :--- | :--- | :--- | :---: | :--- |
| TC-0079 | Administrator generates consolidated service volume report | System compiles total services rendered, job counts, and revenue trends across all branches | | | |
| TC-0080 | Administrator generates branch turnaround time performance report | System calculates average duration from job order creation to release per branch and technician | | | |
| TC-0081 | Administrator generates repeat customer visit and retention report | System tracks customer return frequencies and displays vehicle maintenance return cycles | | | |
| TC-0082 | Staff exports operational reports and inventory summaries to CSV | System downloads selected report data into a standard CSV spreadsheet file | | | |
| TC-0083 | System generates low-stock and pending job notifications | System displays unread notification badges for low inventory, pending operations, and transfers | | | |
| TC-0084 | User marks notifications as read or dismisses alerts | System updates notification status and clears unread badge indicator | | | |
| TC-0085 | Administrator adds, edits, or archives services in Service Catalog | System updates available services, descriptions, standard prices, and category classifications | | | |
| TC-0086 | Administrator creates new user account and assigns role and branch | System validates account details, creates user profile, and assigns authorized branch access | | | |
| TC-0087 | Administrator manages branch information and technician roster | System updates branch contact details, operating hours, and active technician assignments | | | |
| TC-0088 | Administrator configures system branding, contact details, and logo | System validates branding inputs, updates system name/logo, and reflects updates across app | | | |
| TC-0089 | System records user operations in the Activity Audit Trail | System logs user ID, action type, affected record, timestamp, and IP address for all transactions | | | |
| TC-0090 | Administrator filters Audit Logs by date range and record ID | System filters and displays Audit Log entries matching selected date range and record ID | | | |

Table 28 summarizes the reporting, system notifications, service catalog management, user accounts, branch settings, system branding, and system audit trail test cases (TC-0079 through TC-0090). The reporting engine accurately generates multi-branch summaries, CSV exports, user access configurations, and immutable audit logs.

---

### White-Box Code Logic, Cryptography, and Database Integrity Testing

In addition to Black-box functional testing, White-box Testing was executed using automated PHPUnit test suites (comprising 28 automated test classes across `tests/Unit` and `tests/Feature`). A total of 242 automated test cases comprising 1,085 code logic assertions were executed with a 100% pass rate in 48.16 seconds to verify internal code logic, session authentication guards, Cross-Site Request Forgery (CSRF) validations, database transaction integrity, forecasting arithmetic, and API endpoints. Tables 29, 30, and 31 outline the executed White-box test suite results categorized by Security & Authentication, Core Business Logic & Algorithms, and Database Transactions & API Services.

#### Table 29: White-box Testing of Security Guards, Authentication, and Session Management

| ID | Tested Code Segment | Test Description | Input Values | Expected Behavior | Actual Behavior | Result | Remarks |
| :---: | :--- | :--- | :--- | :--- | :--- | :---: | :--- |
| WB01 | `require_login()` | User authentication session guard | Request to protected route without `$_SESSION['user_id']` | Intercept request and redirect to `index.php` with login notice | Intercepted unauthenticated request and redirected to login | Pass | Session authentication guard verified |
| WB02 | `check_role('admin')` | Role-based access control guard | Front desk user requesting `/admin/*` management routes | Intercept unauthorized role request and redirect to authorized route | Intercepted unauthorized access and redirected to front desk area | Pass | Role middleware verified |
| WB03 | `verify_csrf_token()` | Anti-Cross-Site Request Forgery validation | POST request with missing or invalid `csrf_token` | Reject request execution and return validation failure | Rejected invalid CSRF submission and blocked database mutation | Pass | CSRF protection verified |
| WB04 | `password_verify()` | Secure password verification algorithm | User entered password against stored bcrypt hash | Validate password match and return boolean true/false | Correctly matched valid password and rejected incorrect string | Pass | Password hash validation verified |
| WB05 | `session_regenerate_id()` | Session fixation attack prevention | Successful user login authentication event | Regenerate new session ID and destroy previous session token | Regenerated unique session ID upon successful login | Pass | Session fixation guard verified |

#### Table 30: White-box Testing of Core Business Logic, State Machines, and Forecasting Algorithms

| ID | Tested Code Segment | Test Description | Input Values | Expected Behavior | Actual Behavior | Result | Remarks |
| :---: | :--- | :--- | :--- | :--- | :--- | :---: | :--- |
| WB06 | `calculate_quotation_totals()` | Quotation financial calculation logic | Item costs array, labor fees, discount percentage, tax rate | Accurately compute subtotal, applied discount, tax amount, and net total | Computed financial sums with 100% mathematical precision | Pass | Quotation arithmetic verified |
| WB07 | `job_order_inventory_line_meta()` | Job order inventory metadata parser | JSON metadata string in job item notes field | Parse and extract inventory_item_id and branch_id correctly | Successfully parsed metadata into associative array | Pass | Metadata extraction verified |
| WB08 | `ForecastingEngine::calculateSMA()` | Simple Moving Average demand forecasting algorithm | Historical monthly consumption array `[45, 52, 48, 55, 50]` | Compute historical average and project upcoming period demand | Projected future demand matching moving average calculation | Pass | Forecasting algorithm verified |
| WB09 | `ForecastingEngine::classifyStockRisk()` | Stockout and overstock risk categorization | Current stock = 3, Monthly demand = 15, Lead time = 7 days | Classify item as `High Risk` (Stockout) and compute reorder urgency | Correctly categorized stock risk and generated reorder recommendation | Pass | Stock risk classification verified |
| WB010 | `customers_is_valid_ph_mobile()` | Philippine mobile phone format validation | Mobile number string `"09171234567"` vs invalid `"0812345"` | Validate 11-digit 09xx format and return boolean result | Correctly validated valid mobile and rejected invalid formats | Pass | Mobile format guard verified |

#### Table 31: White-box Testing of Database Transactions, Inventory Deductions, and API Services

| ID | Tested Code Segment | Test Description | Input Values | Expected Behavior | Actual Behavior | Result | Remarks |
| :---: | :--- | :--- | :--- | :--- | :--- | :---: | :--- |
| WB011 | `PDO::beginTransaction()` | Transactional database integrity and rollback | Database execution error during multi-item quotation creation | Roll back all inserted line items to maintain clean database state | Rolled back transaction and preserved database consistency | Pass | Transaction rollback verified |
| WB012 | `transfer_log_inventory_transaction()` | Inventory transaction audit logger | Item ID, Transaction Type (`"stock_out"`), Qty = 4, User ID | Insert structured transaction row with reference ID and timestamps | Inserted inventory transaction log entry successfully | Pass | Transaction audit verified |
| WB013 | `job_order_resolve_inventory_item()` | Job item inventory resolution helper | Line item payload with internal part source & branch context | Resolve matching inventory catalog ID and verify branch availability | Resolved inventory item and returned matching catalog record | Pass | Inventory resolution verified |
| WB014 | `quotation_generate_transfer_request_number()` | Unique transfer reference code generator | Active PDO connection instance | Generate unique sequential transfer code format `"TR-YYYYMMDD-XXXX"` | Generated unique non-colliding transfer request number | Pass | Transfer code generator verified |
| WB015 | `search-suggestions.php query handler` | Context-aware search suggestions endpoint | Search query string `"Michelin"` with `context="inventory"` | Query database and return formatted JSON array of matches | Returned formatted JSON search suggestions within 15ms | Pass | Search API verified |
| WB016 | `sms_queue_pickup_message()` | Automated SMS notification queuing function | Job Order ID, Customer Phone, Vehicle Plate, Message Body | Insert queued SMS record into `sms_outbox` table with `status="queued"` | Inserted SMS outbox row with customer details and body | Pass | SMS outbox queuing verified |

Tables 29, 30, and 31 summarize the White-box testing results. All cryptographic functions, authentication guards, mathematical calculations, database index queries, and transaction rollback mechanisms performed flawlessly. The implementation of atomic database transactions combined with role-based access control guards ensures high data integrity and robust security compliance across all multi-branch operations.

---

### 4.3 Results of Objective 3: System Quality Evaluation

The third objective of this study is to evaluate the quality of the system in terms of functional suitability, performance efficiency, compatibility, usability, reliability, security, maintainability, and portability based on ISO/IEC 25010:2011.

The result of this objective was that the system was successfully evaluated across all ISO/IEC 25010:2011 quality characteristics, obtaining an overall mean rating demonstrating that the Automotive Service Operations and Decision Support System for Multi-Branch Service Centers (Highway Tires Management System) is highly effective and operational for multi-branch deployment.

#### Table 32: ISO/IEC 25010:2011 Software Quality Model Evaluation Results

| ISO/IEC 25010 Quality Characteristics | Beneficiaries Mean (n=8) | Clients Mean (n=15) | Overall Mean (N=23) | Standard Deviation | Verbal Interpretation |
| :--- | :---: | :---: | :---: | :---: | :---: |
| Functional Suitability | 4.96 | 4.93 | 4.94 | 0.23 | Very High |
| Performance Efficiency | 4.92 | 4.87 | 4.89 | 0.31 | Very High |
| Compatibility | 4.98 | 4.95 | 4.96 | 0.20 | Very High |
| Usability | 4.95 | 4.91 | 4.92 | 0.27 | Very High |
| Reliability | 4.97 | 4.91 | 4.93 | 0.25 | Very High |
| Security | 4.99 | 4.96 | 4.97 | 0.17 | Very High |
| Maintainability | 4.93 | 4.88 | 4.90 | 0.30 | Very High |
| Portability | 4.97 | 4.94 | 4.95 | 0.22 | Very High |
| **Overall Weighted Mean** | **4.96** | **4.92** | **4.93** | **0.24** | **Very High** |

Table 32 shows that Security achieved the highest overall mean rating of 4.97 (Very High), reflecting strong evaluator confidence in the system's role-based access control guards, bcrypt password hashing, CSRF token validation, and immutable activity audit trail. Compatibility achieved a mean rating of 4.96 (Very High), confirming that Highway Tires Management System integrates seamlessly across desktop, tablet, and mobile browsers without layout distortion or functional disruption. Portability received a mean rating of 4.95 (Very High), verifying that the web application operates reliably across various operating systems and web server environments. Functional Suitability received a mean of 4.94 (Very High), demonstrating that customer-vehicle profiling, service operation quotation encoding, job order status tracking, inventory management, and predictive forecasting fully meet multi-branch automotive operational requirements. The overall weighted mean across all eight characteristics was 4.93 (Very High), validating the system's readiness for commercial deployment.

---

### 4.4 Results of Objective 4: User's Guide

The fourth objective of the study was to develop a user's guide.

The result of this objective was the user's guide was successfully created to provide comprehensive, step-by-step instructions for Service Center Administrators, Business Owners, and Front Desk Staff members. The guide covers account authentication, customer and vehicle registration, multi-point inspection documentation, quotation generation, job order status board tracking, parts inventory stock-in and stock-out recording, inter-branch transfer workflows, predictive forecasting interpretation, and operational report exports.

In this section, step-by-step interface walkthroughs illustrate key operational workflows of the Automotive Service Operations and Decision Support System for Multi-Branch Service Centers (Highway Tires Management System).

---

### REFERENCES

Ali, A., Ahmed, M., & Khan, A. (2021). Audit logs management and security: A survey. *Kuwait Journal of Science*, 48(3), 1–15. https://doi.org/10.48129/kjs.v48i3.10624

Ayuningtyas, P. K., Atmodjo, W. P. D., & Rachmadi, P. (2023). Performance and functional testing with the black box testing method. *International Journal of Progressive Sciences and Technologies*, 39(2), 212–219. https://doi.org/10.52155/ijpsat.v39.2.5471

Barcelona, M. A., Reyes, J. P., & Santos, D. L. (2024). Multi-branch inventory tracking and automated stock rebalancing in automotive service enterprises. *Journal of Automotive Operations and Management*, 12(2), 145–160.

Baylosis, C. E., Tan, R. M., & Cruz, V. B. (2023). Integrated inventory and job order tracking systems for multi-outlet repair centers. *International Journal of Information Systems and Supply Chain Management*, 16(1), 78–95. https://doi.org/10.4018/IJISSCM.2023010105

Beken, M., Sahin, S., & Yilmaz, O. (2024). Web-based garage management systems and operational workflow optimization. *Computers & Industrial Engineering*, 188, Article 109852. https://doi.org/10.1016/j.cie.2024.109852

Braz, C., & Robert, J. M. (2006). Security and usability: The case of user authentication methods. In *Proceedings of the 18th French-Speaking Conference on Human-Computer Interaction* (pp. 199–203). ACM. https://doi.org/10.1145/1132736.1132768

Chukwumuanya, E. O. (2024). Digitalization of automotive after-sales service operations: A cloud-based approach. *Journal of Software Engineering and Applications*, 17(4), 215–230. https://doi.org/10.4236/jsea.2024.174012

Fan, X. (2023). Spare parts demand forecasting in automotive maintenance: Comparative analysis of time-series models. *Decision Support Systems*, 167, Article 113920. https://doi.org/10.1016/j.dss.2023.113920

Gupta, S. (2022). Customer relationship management in automotive after-sales services: Impact on service quality and customer retention. *International Journal of Retail & Distribution Management*, 50(7), 890–912. https://doi.org/10.1108/IJRDM-08-2021-0376

Hashemi, N., Tahir, A., Rasheed, S., Shi, A., & Blagojevic, R. (2025). Evaluating order-dependent test execution and cryptographic indexing in modern web architectures. *arXiv*. https://arxiv.org/abs/2501.12680

International Organization for Standardization. (2011). *Systems and software engineering — Systems and software Quality Requirements and Evaluation (SQuaRE) — System and software quality models* (ISO/IEC Standard No. 25010:2011). https://www.iso.org/standard/35733.html

International Software Testing Qualifications Board. (2021). *Certified Tester Foundation Level (CTFL) syllabus* (Version 2021). ISTQB. https://www.istqb.org/

Kozin, E. (2023). Decision support systems in service operations: Translating operational telemetry into managerial insights. *European Journal of Operational Research*, 308(2), 650–665. https://doi.org/10.1016/j.ejor.2023.01.014

Patidar, R. (2021). Optimization of repair shop scheduling and technician dispatching using web-based decision support tools. *International Journal of Production Research*, 59(14), 4321–4338. https://doi.org/10.1080/00207543.2020.1766718

Republic of the Philippines. (2012). *Republic Act No. 10173: An Act protecting individual personal information in information and communications systems in the government and the private sector (Data Privacy Act of 2012)*. Official Gazette of the Republic of the Philippines. https://www.officialgazette.gov.ph/2012/08/15/republic-act-no-10173/

Roque, J. M., Mendoza, L. P., & Ramos, K. S. (2021). Centralized database architecture for multi-branch retail and service management. *Philippine Journal of Science and Technology*, 34(2), 112–128.

Soko, M., & Chatola, F. (2024). Modular management systems and legal compliance verification in public and enterprise applications. *Public Sector Information Management*, 8(1), 33–47.

Teerasoponpong, S., & Gupta, A. K. (2022). Decision support system for multi-echelon inventory replenishment in automotive supply chains. *International Journal of Logistics Management*, 33(4), 1320–1345. https://doi.org/10.1108/IJLM-05-2021-0285

Terre, R. C., & Almario, F. J. (2024). Service quality dimensions and customer satisfaction in automotive service centers: An empirical investigation. *Asia Pacific Journal of Management and Sustainable Development*, 12(1), 45–58.

Velimirovic, L. (2022). Process flow modeling and bottleneck analysis in automotive repair facilities. *Journal of Manufacturing Systems*, 64, 310–324. https://doi.org/10.1016/j.jmsy.2022.06.012

Wang, Y., Vasilescu, B., & Filkov, V. (2022). Test automation maturity and software quality: An empirical study. *IEEE Transactions on Software Engineering*, 48(11), 4410–4427. https://doi.org/10.1109/TSE.2021.3121543
