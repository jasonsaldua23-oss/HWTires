# HW Tires Codebase Testing Scenarios

Updated: August 25, 2026

This document lists codebase-derived testing scenarios for the HW Tires Branch Data Management System. It is based on the implemented PHP routes, API actions, shared helpers, database schema, and use-case documentation.

## Scope

Test the system as a role-based branch operations app with these actors:

- Admin / Owner
- Front Desk Staff
- Donor Branch Front Desk
- Requesting Branch Front Desk
- Customer as SMS notification recipient

Core modules in scope:

- Authentication, session, profile, dashboard, and navigation
- Customer and vehicle records
- Service operations, implemented as quotations
- Job orders and service status
- Inventory and stock movements
- Inter-branch transfers
- Forecasting and decision support
- Reports and CSV export
- Notifications and pickup SMS outbox
- Admin maintenance for services, users, branches, technicians, and settings
- Utility and supporting APIs such as search suggestions, quotation PDF, and duplicate cleanup

## Test Design Rules

- Test each protected page both signed out and signed in.
- Test each mutating action with valid CSRF, missing CSRF, and invalid CSRF where the code is expected to enforce CSRF.
- Test each front desk action with the correct branch and with a tampered branch or foreign record ID.
- Test admin pages for read/write boundaries. Admin can manage admin maintenance data, but day-to-day operational changes are generally front-desk-owned.
- Test both UI behavior and direct API requests because hidden buttons do not prove server-side protection.
- Use seeded records first, then disposable records for archive, restore, merge, status, and destructive workflow tests.

## Priority Smoke Tests

| ID | Scenario | Expected Result | Priority |
| --- | --- | --- | --- |
| SMK-001 | Open login page | Login page renders with Highway Tires branding | Critical |
| SMK-002 | Admin login | Redirects to `/hwtires/admin/` with Admin/Owner navigation | Critical |
| SMK-003 | Front desk login | Redirects to `/hwtires/front-desk/` with assigned branch context | Critical |
| SMK-004 | Invalid login | Error shown, no protected page access | Critical |
| SMK-005 | Logout | Session destroyed and protected pages redirect to login | Critical |
| SMK-006 | Admin dashboard | KPI, alerts, recent operations, recent jobs render | High |
| SMK-007 | Front desk dashboard | Branch jobs, operations, visits, and quick actions render | High |
| SMK-008 | Database connection failure handling | Friendly error or logged failure without leaking credentials | High |
| SMK-009 | Global sidebar | Correct links and notification counts by role | High |
| SMK-010 | Basic responsive layout | Main pages usable on desktop and mobile widths | Medium |

## Authentication, Session, and Access Control

| ID | Scenario | Expected Result | Priority |
| --- | --- | --- | --- |
| AUTH-001 | Admin valid credentials | Admin session stores user ID, role, branch, and redirects to admin dashboard | Critical |
| AUTH-002 | Front desk valid credentials | Front desk session stores branch ID and redirects to branch dashboard | Critical |
| AUTH-003 | Wrong password | Invalid login message, no session | Critical |
| AUTH-004 | Unknown login ID | Invalid login message, no session | Critical |
| AUTH-005 | Inactive user login | Login refused | Critical |
| AUTH-006 | Already logged-in user opens login page | Redirects to matching dashboard | High |
| AUTH-007 | Signed-out user opens protected page | Redirects to `/hwtires/index.php` | Critical |
| AUTH-008 | Front desk opens admin dashboard | Redirects to front desk dashboard | Critical |
| AUTH-009 | Admin opens front desk-only page | Redirects to admin area or safe route | High |
| AUTH-010 | Corrupted session user value | Session is cleared or user redirected safely | High |
| AUTH-011 | Session timeout policy | Expired session requires login again | Medium |
| AUTH-012 | Logout audit | Logout writes an audit log row | Medium |

## CSRF and Server-Side Security

| ID | Scenario | Expected Result | Priority |
| --- | --- | --- | --- |
| SEC-001 | Customer create without CSRF | Rejected with invalid security token | Critical |
| SEC-002 | Customer update without CSRF | Rejected with invalid security token | Critical |
| SEC-003 | Customer archive without CSRF | Rejected with invalid security token | Critical |
| SEC-004 | Vehicle create/update/archive/restore without CSRF | Rejected with invalid security token | Critical |
| SEC-005 | Service operation create/status/archive without CSRF | Rejected with invalid security token | Critical |
| SEC-006 | Job order create/status/archive without CSRF | Rejected with invalid security token | Critical |
| SEC-007 | Service status update/progress without CSRF | Rejected with invalid security token | Critical |
| SEC-008 | Inventory add/stock in/stock out/transfer without CSRF | Rejected with invalid security token | Critical |
| SEC-009 | Admin services/users/branches without CSRF | Rejected with security check failed message | Critical |
| SEC-010 | Duplicate cleanup merge without CSRF | Rejected with invalid security token | High |
| SEC-011 | Settings save without CSRF | Should be rejected; current code should be verified because the settings form does not show a CSRF check in `admin/settings/index.php` | Critical |
| SEC-012 | XSS in text fields | Rendered output escapes HTML/script in lists, details, PDFs, reports, and notifications | Critical |
| SEC-013 | SQL injection-like search text | Search returns safe results or none, no SQL error | Critical |
| SEC-014 | Direct API request while signed out | JSON 401 or login redirect, no data modification | Critical |
| SEC-015 | Tampered redirect parameter | Redirect remains inside expected app route or is validated before use | High |

## Dashboard and Navigation

| ID | Scenario | Expected Result | Priority |
| --- | --- | --- | --- |
| NAV-001 | Admin sidebar module links | Links route to admin customers, vehicles, service operations, inventory, job orders, transfers, forecasting, reports, services, users, branches, and settings | High |
| NAV-002 | Front desk sidebar module links | Links route to front desk customers, vehicles, service operations, inventory, job orders, service status, forecasting, reports, and notifications | High |
| NAV-003 | Admin branch shortcut list | Active branches are listed for admin access where shown | Medium |
| NAV-004 | Front desk branch label | Assigned branch name is consistently displayed | Medium |
| NAV-005 | Header notification badges | Low-stock, pending operations, active jobs, and transfer badges reflect accessible records | High |
| NAV-006 | Breadcrumb/back buttons | Return to role-correct list page | Medium |
| NAV-007 | Empty dashboard datasets | Empty states display without PHP warnings | Medium |
| NAV-008 | Dashboard links to missing record | Graceful not-found or redirect | Medium |

## Customer Records

| ID | Scenario | Expected Result | Priority |
| --- | --- | --- | --- |
| CUST-001 | Admin customer search | Admin can search all active customer records | High |
| CUST-002 | Admin branch customer filter | Filter limits visible rows by selected branch | High |
| CUST-003 | Front desk customer search | Front desk sees assigned branch customer records | Critical |
| CUST-004 | Front desk other-branch customer search | Other-branch-only customers are hidden or non-editable | Critical |
| CUST-005 | Add customer with required fields | Customer is created in front desk branch | Critical |
| CUST-006 | Add customer with optional address/email/type | Optional fields save correctly | Medium |
| CUST-007 | Add customer with vehicle data | Customer and linked vehicle are created together | High |
| CUST-008 | Add customer with partial vehicle data | Rejected unless required vehicle fields are present | High |
| CUST-009 | Missing customer name | Rejected | High |
| CUST-010 | Missing mobile/contact number | Rejected | High |
| CUST-011 | Invalid Philippine mobile number | Rejected | High |
| CUST-012 | Duplicate active customer name | Rejected with duplicate message | High |
| CUST-013 | Duplicate active vehicle plate during customer add | Rejected | High |
| CUST-014 | Archived vehicle plate reused during customer add | Inactive vehicle is restored and reassigned as expected | Medium |
| CUST-015 | Update customer valid values | Customer details persist | High |
| CUST-016 | Update customer with invalid mobile | Rejected | High |
| CUST-017 | Front desk updates another branch customer by direct API ID | Should be rejected; verify current code because `api/customers-api.php` update does not visibly enforce customer branch ownership | Critical |
| CUST-018 | Archive customer | Customer, branch records, vehicles, quotations, and jobs are archived/inactivated as designed | High |
| CUST-019 | Archive other-branch customer by direct API ID | Should be rejected; verify current code because archive does not visibly enforce customer branch ownership before cascading | Critical |
| CUST-020 | Archive nonexistent customer | Rejected with customer not found | Medium |
| CUST-021 | Archive customer with related history | Records are preserved and archive metadata is populated | High |
| CUST-022 | Customer profile service history | Related quotations, jobs, visits, and service history display correctly | High |
| CUST-023 | Cross-branch customer activity | Admin can view, front desk sees only allowed branch context | High |

## Vehicle Records

| ID | Scenario | Expected Result | Priority |
| --- | --- | --- | --- |
| VEH-001 | Admin vehicle list/search | Admin can search all vehicles | High |
| VEH-002 | Front desk vehicle list/search | Front desk sees branch vehicles | High |
| VEH-003 | Vehicle profile load | Plate, make, model, owner, branch, condition, mileage, and history display | High |
| VEH-004 | Add vehicle to existing customer | New vehicle belongs to customer and user branch | High |
| VEH-005 | Add vehicle missing plate/make/model | Rejected | High |
| VEH-006 | Add vehicle invalid plate format | Rejected | High |
| VEH-007 | Add vehicle duplicate active plate | Rejected | High |
| VEH-008 | Add inactive duplicate plate | Restores inactive vehicle where applicable | Medium |
| VEH-009 | Update vehicle valid values | Values persist and audit log is written | High |
| VEH-010 | Update vehicle duplicate plate | Rejected | High |
| VEH-011 | Update vehicle invalid condition | Falls back to valid default or rejects by UI rules | Medium |
| VEH-012 | Front desk updates another branch vehicle by direct API ID | Should be rejected; verify current code because `api/vehicles-api.php` update does not visibly enforce branch ownership | Critical |
| VEH-013 | Archive vehicle | Status changes inactive and archive metadata is populated | High |
| VEH-014 | Archive other-branch vehicle | Rejected by branch ownership check | Critical |
| VEH-015 | Restore inactive vehicle | Status changes active and branch customer record is touched | High |
| VEH-016 | Restore other-branch vehicle | Rejected by branch ownership check | Critical |
| VEH-017 | Get vehicles for customer AJAX | Returns active vehicles for selected customer | Medium |
| VEH-018 | Get other-branch customer vehicles by direct API | Should be branch-scoped; verify because `get_customer_vehicles` does not visibly enforce branch ownership | Critical |
| VEH-019 | Vehicle history summary | Services and ownership changes render without warnings | Medium |

## Search Suggestions

| ID | Scenario | Expected Result | Priority |
| --- | --- | --- | --- |
| SRCH-001 | Empty query | Returns success with empty suggestions | Medium |
| SRCH-002 | Query over 80 characters | Query is truncated safely | Medium |
| SRCH-003 | Limit below 5 or above 12 | Limit is clamped to 5-12 | Medium |
| SRCH-004 | Customer context | Returns customer and vehicle suggestions | Medium |
| SRCH-005 | Vehicle context | Returns vehicle suggestions with branch/customer details | Medium |
| SRCH-006 | Service operations context | Returns quotation/customer suggestions excluding archived operations | Medium |
| SRCH-007 | Job order context | Returns job suggestions excluding archived jobs | Medium |
| SRCH-008 | Inventory/forecasting context | Returns active inventory suggestions | Medium |
| SRCH-009 | Transfers context | Returns inventory-style suggestions | Medium |
| SRCH-010 | Unknown context | Returns mixed suggestions | Low |
| SRCH-011 | Front desk branch filter tampering | Suggestions stay scoped to the user branch where force-user-branch is used | High |
| SRCH-012 | Special characters in query | No SQL errors and JSON remains valid | High |

## Service Operations / Quotations

| ID | Scenario | Expected Result | Priority |
| --- | --- | --- | --- |
| QUO-001 | Front desk create service operation | Pending quotation is created with generated QT number | Critical |
| QUO-002 | Required inspection fields | Complaint, findings, and recommendations are required | Critical |
| QUO-003 | Customer required | Missing customer rejected | Critical |
| QUO-004 | Branch required | Missing branch rejected | Critical |
| QUO-005 | Negative labor cost | Rejected | High |
| QUO-006 | Vehicle does not belong to customer | Rejected | High |
| QUO-007 | Branch ownership tamper | Front desk cannot create operation in another branch | Critical |
| QUO-008 | Service item line only | Service item saves but is excluded from non-service item totals as coded | Medium |
| QUO-009 | Part item total | Parts cost equals quantity times unit price | High |
| QUO-010 | Tire item total | Tires cost equals quantity times unit price | High |
| QUO-011 | Total amount | Total equals labor plus part and tire subtotals | High |
| QUO-012 | Own inventory insufficient stock | Create is rejected before saving | High |
| QUO-013 | External item | Saves without inventory availability check | Medium |
| QUO-014 | Customer-supplied item | Saves without inventory availability check | Medium |
| QUO-015 | Other-branch item | Creates pending inter-branch transfer request and donor notification | Critical |
| QUO-016 | Duplicate other-branch request for same item/quote | Duplicate pending transfer is not created | High |
| QUO-017 | View operation list | Admin all branches, front desk branch-scoped | High |
| QUO-018 | View operation detail | Customer, vehicle, inspection, items, totals, and status render | High |
| QUO-019 | Print/PDF quotation | PDF renders with branding, customer, vehicle, lines, and totals | High |
| QUO-020 | Front desk prints other-branch quotation by direct PDF URL | Should be rejected; verify current code because `api/quotation-print.php` does not visibly check branch access | Critical |
| QUO-021 | Update status pending to approved | Status saves and audit log is written | Critical |
| QUO-022 | Update status pending to rejected | Status saves and audit log is written | High |
| QUO-023 | Invalid quotation status | Rejected | High |
| QUO-024 | Admin direct status update | Should be rejected by front-desk-only modification rule | Critical |
| QUO-025 | Edit operation items | Existing items update and totals recalculate | High |
| QUO-026 | Edit operation creates transfer request for new other-branch item | Transfer request and notification are created | High |
| QUO-027 | Archive pending operation with no dependencies | Status becomes archived | High |
| QUO-028 | Archive operation with linked job order | Rejected | Critical |
| QUO-029 | Archive operation with service history | Rejected | Critical |
| QUO-030 | Archive operation with non-pending transfer | Rejected | Critical |
| QUO-031 | Archive operation with only pending transfer | Operation archived and pending transfer cancelled/read | High |
| QUO-032 | Archive restores prior quotation inventory deductions | Stock-in transaction reverses eligible deduction once | High |
| QUO-033 | Invalid quotation ID | 400 or not-found behavior, no warnings | Medium |

## Job Orders

| ID | Scenario | Expected Result | Priority |
| --- | --- | --- | --- |
| JOB-001 | Create from approved service operation | Job is created with generated JO number and source quote data | Critical |
| JOB-002 | Create from pending/rejected operation | Rejected | Critical |
| JOB-003 | Create from missing quotation | Rejected | High |
| JOB-004 | Create from quotation in another branch | Rejected for front desk | Critical |
| JOB-005 | Create manual job order | Job is created with customer, vehicle, branch, schedule, technician, notes | High |
| JOB-006 | Missing customer | Rejected | High |
| JOB-007 | Missing branch | Rejected | High |
| JOB-008 | Expected completion date earlier than job date | Rejected | High |
| JOB-009 | Invalid create status | Defaults to waiting | Medium |
| JOB-010 | Status in-progress on create | Inventory consumption attempts for eligible items | High |
| JOB-011 | Customer visit created on job creation | Customer visit row exists | Medium |
| JOB-012 | Job list admin | Admin can view all branches with filters | High |
| JOB-013 | Job list front desk | Front desk sees branch jobs | High |
| JOB-014 | Job detail | Customer, vehicle, quotation, technicians, schedule, notes, tasks render | High |
| JOB-015 | Front desk direct access to other-branch job detail | Should be rejected or non-editable | Critical |
| JOB-016 | Update status waiting to in-progress | Status changes and audit log is written | Critical |
| JOB-017 | Update status to completed | Status changes, SMS queue attempted | Critical |
| JOB-018 | Invalid status rejected/cancelled through job API | Rejected | High |
| JOB-019 | Completed job moved back through ordinary status update | Rejected | High |
| JOB-020 | Archive waiting job | Status becomes archived | High |
| JOB-021 | Archive in-progress/completed job | Rejected | Critical |
| JOB-022 | Archive other-branch job | Rejected | Critical |
| JOB-023 | Admin create job direct API | Should be rejected by front-desk-only modification rule | Critical |

## Service Status and Job Progress

| ID | Scenario | Expected Result | Priority |
| --- | --- | --- | --- |
| STAT-001 | Open front desk service status board | Branch jobs grouped by waiting, in-progress, completed | High |
| STAT-002 | Admin service-status route | Redirects to admin job orders as implemented | Medium |
| STAT-003 | Get status summary | Counts returned for allowed branch | High |
| STAT-004 | Get summary other branch as front desk | Rejected | Critical |
| STAT-005 | Update job status valid values | waiting, in-progress, completed accepted where state rules allow | Critical |
| STAT-006 | Update job status invalid values | Rejected | High |
| STAT-007 | Update task progress to done | Task marked done and progress summary updates | Critical |
| STAT-008 | Update task progress to not done | Task reopens and summary updates | High |
| STAT-009 | Complete all tasks | Recommended status becomes completed | Critical |
| STAT-010 | No tasks on job | Progress sync handles empty task set | Medium |
| STAT-011 | Transfer-dependent task before received | Rejected with readiness message | Critical |
| STAT-012 | Transfer-dependent task after received | Task can be completed | Critical |
| STAT-013 | Inventory-consuming task completed | Stock decreases once and transaction is logged | Critical |
| STAT-014 | Repeat completed task update | No duplicate stock consumption | Critical |
| STAT-015 | Completed job reopened by unchecking task | Job returns to in-progress, service history removed, queued/failed SMS cancelled | High |
| STAT-016 | Other-branch progress update | Rejected | Critical |
| STAT-017 | Invalid task key | Rejected | High |
| STAT-018 | Task progress JSON shape | Response includes success, data summary, inventory result, and SMS result when applicable | Medium |

## Inventory and Stock Movement

| ID | Scenario | Expected Result | Priority |
| --- | --- | --- | --- |
| INV-001 | Admin inventory list | Admin sees all active inventory with branch filters | High |
| INV-002 | Front desk inventory list | Front desk sees own branch inventory | Critical |
| INV-003 | Inventory page for branch without inventory | Redirects or blocks with proper message | High |
| INV-004 | Low stock view | Items at or below reorder level are highlighted/countable | High |
| INV-005 | Transactions page admin | Admin can filter by branch/type/search | High |
| INV-006 | Transactions page front desk | Branch transaction history only | High |
| INV-007 | Add inventory valid item | Item is created active | Critical |
| INV-008 | Add missing item name | Rejected | High |
| INV-009 | Add invalid category | Rejected | High |
| INV-010 | Add missing required brand/size/SKU/price | Rejected | High |
| INV-011 | Add invalid SKU characters | Rejected | High |
| INV-012 | Add duplicate SKU | Rejected | High |
| INV-013 | Add duplicate serial number | Rejected | High |
| INV-014 | Add future manufacturing date | Rejected | Medium |
| INV-015 | Add negative/decimal quantity | Rejected as whole number | High |
| INV-016 | Add zero quantity | Allowed for starting stock | Medium |
| INV-017 | Add item to another branch as front desk | Rejected | Critical |
| INV-018 | Stock in valid | Quantity increases, last_restock_date set, transaction and audit logged | Critical |
| INV-019 | Stock in invalid quantity | Rejected | High |
| INV-020 | Stock in source notes | Supplier/ref/notes are composed into transaction notes | Medium |
| INV-021 | Stock out valid | Quantity decreases, transaction and audit logged | Critical |
| INV-022 | Stock out insufficient quantity | Rejected | Critical |
| INV-023 | Stock out with customer tag | Customer branch record touched, transaction includes customer_id | High |
| INV-024 | Stock out with vehicle tag | Vehicle must belong to selected customer and transaction includes vehicle_id | High |
| INV-025 | Stock out customer from another branch | Rejected | Critical |
| INV-026 | Direct transfer matching items | Source decreases, target increases, two transactions logged | Critical |
| INV-027 | Direct transfer same item source/target | Rejected | High |
| INV-028 | Direct transfer same branch | Rejected | High |
| INV-029 | Direct transfer mismatched category/details | Rejected | High |
| INV-030 | Direct transfer insufficient source stock | Rejected | Critical |
| INV-031 | Direct transfer target branch notification | Receiving branch gets success notification | High |
| INV-032 | Admin stock in/out/transfer direct API | Rejected as admin view-only | Critical |
| INV-033 | Concurrent stock updates | Final quantity is correct and cannot go negative | High |

## Inter-Branch Transfers

| ID | Scenario | Expected Result | Priority |
| --- | --- | --- | --- |
| TRF-001 | Check availability valid | Returns donor branches with matching item and stock above reorder level | High |
| TRF-002 | Check availability invalid item/requesting branch | Rejected | Medium |
| TRF-003 | Create transfer request valid | Pending TXF request created | Critical |
| TRF-004 | Create request invalid quantity | Rejected | High |
| TRF-005 | Create request missing branch/item | Rejected | High |
| TRF-006 | Create request donor branch inactive/no inventory | Rejected | High |
| TRF-007 | Create request item not in donor branch | Rejected | High |
| TRF-008 | Create request insufficient donor stock | Rejected | Critical |
| TRF-009 | Create request notifications | Donor and requester users receive notifications | High |
| TRF-010 | Approve request by donor branch | Status becomes approved, approved_by set | Critical |
| TRF-011 | Approve request by admin | Status becomes approved when admin is allowed | High |
| TRF-012 | Approve request by non-donor front desk | Rejected | Critical |
| TRF-013 | Approve quantity greater than requested | Rejected | High |
| TRF-014 | Approve missing/nonexistent transfer | Rejected | Medium |
| TRF-015 | Complete pending request without explicit approval | Uses requested quantity and marks received as coded | High |
| TRF-016 | Complete approved request | Donor stock decreases, request status received | Critical |
| TRF-017 | Complete already received request | Returns already completed without duplicate stock movement | High |
| TRF-018 | Complete cancelled request | Rejected | High |
| TRF-019 | Complete insufficient donor stock | Rejected and rolls back | Critical |
| TRF-020 | Receiving branch has matching inventory item | Existing receiver item quantity increases | High |
| TRF-021 | Receiving branch lacks matching inventory item | New receiver item is created | High |
| TRF-022 | Requesting branch has no inventory | Donor stock-out only; service task readiness updates | High |
| TRF-023 | Completion notifications | Both branches receive transfer completion notices | High |
| TRF-024 | Transfer list admin | Admin views all requests and filters by status/branch | High |
| TRF-025 | Transfer focus by request parameter | Page highlights or opens the specified request safely | Medium |

## Forecasting and Decision Support

| ID | Scenario | Expected Result | Priority |
| --- | --- | --- | --- |
| FRC-001 | Admin forecast page | Loads inventory DSS for all allowed inventory branches | High |
| FRC-002 | Front desk forecast page | Loads assigned branch forecast only | High |
| FRC-003 | Weekly view | Weekly usage and duration labels render | Medium |
| FRC-004 | Monthly view | Monthly usage and duration labels render | Medium |
| FRC-005 | Item forecast view | Item-level forecast details render | Medium |
| FRC-006 | Branch filter as admin | Data changes to selected branch | High |
| FRC-007 | Branch filter tamper as front desk | Rejected or forced to assigned branch | Critical |
| FRC-008 | Category filter | Only tire/accessory/part category appears | Medium |
| FRC-009 | Status filter | Risk/status segments filter correctly | Medium |
| FRC-010 | Year latest/all/specific | Uses selected transaction year basis | Medium |
| FRC-011 | No stock movement history | Forecast handles zero usage without divide-by-zero | High |
| FRC-012 | Zero quantity inventory | Classified as out-of-stock/high risk | High |
| FRC-013 | Reorder recommendation | Recommendation quantity is sensible and non-negative | Medium |
| FRC-014 | Transfer match recommendation | Matches by normalized tire size or category/item name | Medium |
| FRC-015 | Forecast API invalid action | Returns 400 invalid action | Low |
| FRC-016 | Job analytics API | Returns summary and daily trend for allowed branch | Medium |
| FRC-017 | Job analytics branch tamper | Rejected | Critical |

## Reports and CSV Export

| ID | Scenario | Expected Result | Priority |
| --- | --- | --- | --- |
| RPT-001 | Admin reports page | Summary, detailed services, branch performance, jobs, operations, inventory, customer/vehicle sections load | High |
| RPT-002 | Front desk reports page | Branch-scoped report page loads | High |
| RPT-003 | Admin branch filter | All vs specific branch totals change correctly | High |
| RPT-004 | Front desk branch tamper | Other branch records are not returned | Critical |
| RPT-005 | Date range filter | Rows and summary metrics match selected range | High |
| RPT-006 | Status filter | Rows match selected status | Medium |
| RPT-007 | Search filter | Detail rows narrow by customer, vehicle, job, item, or quotation | Medium |
| RPT-008 | Service report tab | Service report columns and links are correct | High |
| RPT-009 | Item sales report tab | Inventory sales columns and values are correct | High |
| RPT-010 | Vehicle report tab | Ownership/service/sales values are correct | High |
| RPT-011 | Vehicle history tab | Current and previous owner fields render correctly | Medium |
| RPT-012 | Stock movement tab | Transactions show item, type, quantity, reference, entered by, and notes | High |
| RPT-013 | Archived records tab | Archived customers, vehicles, quotations, jobs, and branches show archive metadata | High |
| RPT-014 | Admin export summary CSV | Download has metadata, sections, headers, and valid rows | High |
| RPT-015 | Admin export detail CSV per tab | File name and columns match selected report tab | High |
| RPT-016 | Front desk export detail CSV | CSV is branch-scoped | High |
| RPT-017 | Empty export | CSV still includes metadata and headers with no server error | Medium |
| RPT-018 | Money/date formatting | Amounts and dates are readable and consistent in UI and CSV | Medium |
| RPT-019 | Large export | Does not time out for seeded or production-sized ranges | Medium |
| RPT-020 | CSV injection values | Cells beginning with =, +, -, @ are handled safely if user data can contain them | High |

## Notifications and SMS

| ID | Scenario | Expected Result | Priority |
| --- | --- | --- | --- |
| NTF-001 | Low-stock notification | Low-stock count appears for accessible inventory | High |
| NTF-002 | Pending operation notification | Pending operations count appears by role/branch | Medium |
| NTF-003 | Active job notification | Active jobs count appears by role/branch | Medium |
| NTF-004 | Transfer request notification | Donor branch sees incoming transfer request notification | High |
| NTF-005 | Transfer approval notification | Requester and donor receive approval notice | High |
| NTF-006 | Transfer completion notification | Requester and donor receive completion notice | High |
| NTF-007 | Mark one notification read | Notification state changes to read and read_at set | High |
| NTF-008 | Mark one notification unread | Notification state changes unread and read_at cleared | Medium |
| NTF-009 | Mark multiple IDs | Up to 100 IDs processed and duplicates ignored | Medium |
| NTF-010 | Mark empty IDs | Succeeds with updated count 0 | Low |
| NTF-011 | Mark another user's notification | Updated count 0 and record unchanged | Critical |
| NTF-012 | Invalid notification action | Returns 400 invalid action | Low |
| SMS-001 | Complete job with phone | One queued SMS outbox row is created | Critical |
| SMS-002 | Complete job with missing phone | Job completion does not fail; SMS reports clear no-phone/failure state | High |
| SMS-003 | Repeat complete job | Does not create duplicate SMS due to unique job_order_id | High |
| SMS-004 | Reopen completed job | Queued/failed SMS is cancelled | High |
| SMS-005 | SMS message content | Message includes customer/job/vehicle/service pickup context | Medium |

## Admin Service Catalog

| ID | Scenario | Expected Result | Priority |
| --- | --- | --- | --- |
| SVC-001 | Open service catalog | Admin sees total, active, category counts and service list | High |
| SVC-002 | Create active service | Service saves and appears in catalog | High |
| SVC-003 | Create variable-price service | Variable flag persists | Medium |
| SVC-004 | Missing service name | Rejected | High |
| SVC-005 | Empty category | Defaults to Service | Medium |
| SVC-006 | Invalid status | Rejected | Medium |
| SVC-007 | Negative price/labor in form | Values are clamped to zero by code; verify intended behavior | Medium |
| SVC-008 | Duplicate service name | Database unique key should reject duplicate | High |
| SVC-009 | Update service | Values persist | High |
| SVC-010 | Archive service | Status becomes inactive and historical records remain | High |
| SVC-011 | Front desk opens service catalog | Redirected/blocked | Critical |
| SVC-012 | CSRF missing on service catalog POST | Rejected | Critical |

## Admin User Management

| ID | Scenario | Expected Result | Priority |
| --- | --- | --- | --- |
| USR-001 | Open user management | Admin sees stats and user list | High |
| USR-002 | Create front desk user | Requires valid branch and password, user can log in | Critical |
| USR-003 | Create admin user | Branch is null/all branches and user can access admin dashboard | Critical |
| USR-004 | Missing name | Rejected | High |
| USR-005 | Invalid login ID format | Rejected | High |
| USR-006 | Duplicate login ID | Rejected | High |
| USR-007 | Password under 8 chars | Rejected | High |
| USR-008 | Password mismatch | Rejected | High |
| USR-009 | Invalid role/status | Rejected | High |
| USR-010 | Front desk role without branch | Rejected | Critical |
| USR-011 | Update user without password change | Existing password remains valid | High |
| USR-012 | Update user with password change | New password works, old password fails | High |
| USR-013 | Deactivate user | Inactive user cannot log in | Critical |
| USR-014 | Reactivate user | User can log in again | High |
| USR-015 | Archive current admin account | Rejected | Critical |
| USR-016 | Remove last active admin | Rejected | Critical |
| USR-017 | Front desk opens user management | Redirected/blocked | Critical |
| USR-018 | CSRF missing on user POST | Rejected | Critical |

## Branch and Technician Management

| ID | Scenario | Expected Result | Priority |
| --- | --- | --- | --- |
| BRN-001 | Open branch management | Admin sees branch stats, active/inactive branches, technicians | High |
| BRN-002 | Create branch | Branch saves with active/inactive status and inventory enabled | High |
| BRN-003 | Missing branch name | Rejected | High |
| BRN-004 | Invalid branch status | Rejected | Medium |
| BRN-005 | Invalid branch contact phone | Rejected | High |
| BRN-006 | Update branch details | Values persist | High |
| BRN-007 | Deactivate branch | Status becomes inactive | High |
| BRN-008 | Reactivate branch | Status becomes active | High |
| BRN-009 | Deactivate last active branch | Rejected | Critical |
| BRN-010 | Delete permanent action | Soft-archives by setting inactive | Medium |
| BRN-011 | Related record blockers display | Permanent delete blocker helper counts related data if exposed | Medium |
| TEC-001 | Save technician roster with comma-separated names | Names parsed, deduplicated, saved | High |
| TEC-002 | Save roster with line breaks/semicolons | Names parsed correctly | Medium |
| TEC-003 | Remove technician by omitting from roster | Technician row deleted for branch | Medium |
| TEC-004 | Empty roster | All technicians for branch are removed | Medium |
| TEC-005 | Roster branch invalid | Rejected | High |
| BRN-012 | Front desk opens branch management | Redirected/blocked | Critical |
| BRN-013 | CSRF missing on branch/technician POST | Rejected | Critical |

## System Settings

| ID | Scenario | Expected Result | Priority |
| --- | --- | --- | --- |
| SET-001 | Open settings | Admin sees current company, system title, contact, color, and logo | High |
| SET-002 | Save valid settings | Values persist in system_settings | High |
| SET-003 | Missing company name | Rejected | High |
| SET-004 | Missing system title | Rejected | High |
| SET-005 | Invalid email | Rejected | High |
| SET-006 | Invalid Philippine contact phone | Rejected | High |
| SET-007 | Color without leading # | Saved after prefix normalization | Medium |
| SET-008 | Invalid hex color | Rejected | High |
| SET-009 | Upload PNG/JPG logo under 5 MB | Upload succeeds and logo path updates | High |
| SET-010 | Upload disallowed extension | Rejected | High |
| SET-011 | Upload over 5 MB | Rejected | High |
| SET-012 | Upload failure | Error shown and previous logo remains | Medium |
| SET-013 | Front desk opens settings | Redirected/blocked | Critical |
| SET-014 | Settings POST without CSRF | Should be rejected; current code should be verified because no CSRF token/check is visible | Critical |

## Duplicate Cleanup Utility

| ID | Scenario | Expected Result | Priority |
| --- | --- | --- | --- |
| DUP-001 | Admin merges duplicate customers | Duplicate rows are removed and related records move to primary | High |
| DUP-002 | Merge with fewer than two IDs | Rejected | High |
| DUP-003 | Merge with nonexistent customer ID | Rejected | High |
| DUP-004 | Primary omitted | First ID is used as primary | Medium |
| DUP-005 | Merge branch records where primary already has branch | Duplicate branch record is not duplicated | High |
| DUP-006 | Merge moves quotations, jobs, service histories, vehicles, and visits | Related counts are moved correctly | High |
| DUP-007 | Front desk calls cleanup API | 403 admin access required | Critical |
| DUP-008 | Merge without CSRF | Rejected | Critical |

## Data Integrity and Audit

| ID | Scenario | Expected Result | Priority |
| --- | --- | --- | --- |
| DATA-001 | Audit on customer create/update/archive | audit_logs row records table, action, record ID, old/new values, user | High |
| DATA-002 | Audit on vehicle create/update/archive/restore | audit_logs row exists | High |
| DATA-003 | Audit on quotation create/status/archive | audit_logs row exists | High |
| DATA-004 | Audit on job create/status/archive/completion | audit_logs row exists | High |
| DATA-005 | Audit on inventory stock movement | audit_logs row exists | High |
| DATA-006 | Audit on transfer create/approve/complete | audit_logs row exists | High |
| DATA-007 | Audit on admin maintenance | service, user, branch, technician changes write audit | High |
| DATA-008 | Generated numbers unique | QT, JO, and TXF numbers do not collide during same-day creates | Critical |
| DATA-009 | Transaction rollback on failed transfer | No partial stock or status changes after exception | Critical |
| DATA-010 | Service operation archive cancels linked pending transfers | transfer status and notifications update consistently | High |
| DATA-011 | Job completion sync idempotency | Service history and SMS are not duplicated on repeat completion | Critical |
| DATA-012 | Inventory quantity cannot go negative | All stock deduction paths enforce availability | Critical |
| DATA-013 | Customer branch records sync | Visits, jobs, quotations, and stock-outs touch branch record as expected | Medium |
| DATA-014 | Schema auto-ensure helpers | Existing database migrations add missing columns/tables without breaking pages | Medium |

## UI, Usability, and Browser Scenarios

| ID | Scenario | Expected Result | Priority |
| --- | --- | --- | --- |
| UI-001 | Main pages at 1366x768 | No clipped text, broken tables, or hidden action controls | Medium |
| UI-002 | Main pages at mobile width | Navigation, forms, modals, and tables remain usable | Medium |
| UI-003 | Modals open/close cleanly | Form data resets where expected | Medium |
| UI-004 | Required field client validation | Browser blocks obvious missing required fields before submit where marked required | Low |
| UI-005 | Server validation after bypassing UI | Server still rejects invalid data | Critical |
| UI-006 | Pagination | List pages respect page/per-page rules | Medium |
| UI-007 | Empty states | Lists with no records render useful empty message | Medium |
| UI-008 | Flash messages | Success/error messages appear once and clear after reload | Medium |
| UI-009 | Print/PDF rendering | Printable quotation output is readable and not missing logo/table data | Medium |
| UI-010 | Long text fields | Long notes/customer/service names do not break layout | Medium |
| UI-011 | Browser back after mutation | Does not resubmit unexpectedly or corrupt state | Low |

## API Contract Scenarios

| ID | Scenario | Expected Result | Priority |
| --- | --- | --- | --- |
| API-001 | Invalid action for each API | Returns 400 JSON or safe redirect with invalid action message | Medium |
| API-002 | POST endpoint called with GET | Mutations reject or require CSRF/valid method where intended | High |
| API-003 | JSON content type endpoints | JSON responses are valid and parseable | High |
| API-004 | Redirect parameter on form-backed APIs | Redirect occurs after success/error without losing flash message | Medium |
| API-005 | Numeric ID zero/negative/non-numeric | Rejected without PHP warning | High |
| API-006 | Missing required POST fields | Rejected with useful error | High |
| API-007 | Large text input | Rejected where max length exists or safely stored/escaped | Medium |
| API-008 | Unicode input | Stored and rendered correctly under utf8mb4 tables | Medium |
| API-009 | Concurrent duplicate number generation | No duplicate QT/JO/TXF numbers under rapid requests | High |
| API-010 | Database exception | Transaction rolls back and response is safe | High |

## Critical End-to-End Flows

| ID | Scenario | Expected Result | Priority |
| --- | --- | --- | --- |
| E2E-001 | New customer to completed job | Add customer/vehicle, create service operation, approve, create job, complete tasks, generate history, queue SMS | Critical |
| E2E-002 | Existing customer repeat visit | Search customer, add new service operation, create job, update vehicle/service history | Critical |
| E2E-003 | Other-branch item service | Create operation with other-branch item, request transfer, approve/complete transfer, complete dependent job task | Critical |
| E2E-004 | Inventory direct sale | Stock out item tagged to customer/vehicle, verify inventory transaction, reports, and customer branch touch | High |
| E2E-005 | Supplier restock to forecast improvement | Stock in low item, verify low-stock count and forecasting risk changes | High |
| E2E-006 | Archive workflow | Archive eligible operation/job/customer/vehicle and verify active lists, archive reports, and audit logs | High |
| E2E-007 | Admin setup workflow | Add branch, add technicians, add front desk user, log in as new front desk, create branch job | High |
| E2E-008 | Reporting workflow | Perform service/inventory activity, view report filters, export CSV, verify downloaded rows | High |
| E2E-009 | Notification workflow | Generate transfer notification, mark read/unread, verify count and ownership | High |

## Regression Risks Found From Code Review

These are not accusations that the app is broken; they are high-value tests because the code paths should be verified directly.

| ID | Risk Probe | Why It Matters | Priority |
| --- | --- | --- | --- |
| RISK-001 | Front desk updates another branch customer via `api/customers-api.php?action=update` | Update path does not visibly call customer branch ownership enforcement | Critical |
| RISK-002 | Front desk archives another branch customer via `api/customers-api.php?action=archive` | Archive path cascades related records and does not visibly check branch ownership | Critical |
| RISK-003 | Front desk updates another branch vehicle via `api/vehicles-api.php?action=update` | Update path does not visibly call branch ownership enforcement | Critical |
| RISK-004 | Front desk reads another customer's vehicles via `api/vehicles-api.php?action=get_customer_vehicles` | AJAX path does not visibly check branch ownership | Critical |
| RISK-005 | Front desk downloads another branch quotation PDF via `api/quotation-print.php?id=...` | PDF path does not visibly check branch access | Critical |
| RISK-006 | Settings save without CSRF | `admin/settings/index.php` validates fields but does not visibly verify CSRF | Critical |
| RISK-007 | Admin quotation view shows management area while edit handler is front-desk-only | Verify UI expectations and actual permission behavior match requirements | High |
| RISK-008 | Direct transfer completion from pending state | Code allows completing pending/approved/shipped requests; verify this is intended | High |
| RISK-009 | Service item totals excluded from item total calculation | Code excludes service item lines from non-service item total and uses labor cost separately; verify business rule | Medium |
| RISK-010 | GET archive support in vehicle/job APIs | Verify CSRF still protects GET-based archive paths and UI does not expose unsafe links | High |

## Recommended Testing Order

1. Smoke and authentication tests.
2. Authorization, branch access, and CSRF tests.
3. Customer and vehicle master data tests.
4. Service operation creation, status, archive, and transfer-request tests.
5. Job order creation, status, progress, completion, history, and SMS tests.
6. Inventory stock in/out/direct transfer tests.
7. Inter-branch transfer approval/completion tests.
8. Forecasting, reporting, CSV export, and notifications.
9. Admin maintenance and utility tests.
10. UI, API contract, concurrency, and data-integrity regression tests.

## Minimum Release Gate

Before accepting a release, these must pass:

- All Critical tests in authentication, authorization, CSRF, customer/vehicle branch ownership, service operations, job orders, inventory, transfers, reports, and SMS.
- At least one complete E2E path from customer creation to completed job and SMS queue.
- At least one E2E path involving other-branch transfer readiness.
- At least one admin maintenance workflow creating branch, technician, and front desk user.
- At least one report CSV export for admin and one for front desk.
- No PHP warnings/notices on primary pages.
- No direct API path allows a front desk user to change another branch's records.
