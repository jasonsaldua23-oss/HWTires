# HW Tires Use Case Diagram

Updated: August 25, 2026

These diagrams document the current HW Tires Branch Data Management System as Mermaid flowcharts that follow UML use case diagram conventions as closely as Mermaid allows.

## UML Rules Applied

- Actors are outside the system boundary.
- Use cases are inside the system boundary and are named with verb-object phrases.
- Solid lines show actor participation.
- `<<include>>` points from a base use case to mandatory reused behavior.
- `<<extend>>` points from optional or conditional behavior back to the base use case it extends.
- Database tables, UI widgets, and internal implementation details are not modeled as actors.
- Authentication and branch authorization are treated as preconditions for protected use cases.
- Admin/Owner operational pages are modeled as monitoring or view-only where the code enforces branch Front Desk changes.

## Whole System Use Case

```mermaid
flowchart LR
    Admin["Admin / Owner"]
    FD["Front Desk Staff"]
    Customer["Customer"]

    subgraph SYS["HW Tires Branch Data Management System"]
        UC01(["Manage User Session"])
        UC02(["View Dashboard"])
        UC03(["Manage Own Profile"])
        UC04(["View Customer and Vehicle Records"])
        UC05(["Maintain Customer and Vehicle Records"])
        UC06(["View Service Operation Records"])
        UC07(["Create and Update Service Operations"])
        UC08(["View Job Orders"])
        UC09(["Create Job Orders"])
        UC10(["Track Service Status and Tasks"])
        UC11(["View Inventory Records"])
        UC12(["Add Inventory Items"])
        UC13(["Process Branch Stock Movements"])
        UC14(["Monitor and Process Inter-Branch Transfers"])
        UC15(["View Inventory Forecasting"])
        UC16(["View Reports and Export CSV"])
        UC17(["Manage Notifications"])
        UC18(["Manage Service Catalog"])
        UC19(["Manage Users and Roles"])
        UC20(["Manage Branches and Technicians"])
        UC21(["Configure System Settings"])
        UC22(["Notify Customer for Pickup"])
    end

    Admin --- UC01
    Admin --- UC02
    Admin --- UC03
    Admin --- UC04
    Admin --- UC06
    Admin --- UC08
    Admin --- UC11
    Admin --- UC12
    Admin --- UC14
    Admin --- UC15
    Admin --- UC16
    Admin --- UC17
    Admin --- UC18
    Admin --- UC19
    Admin --- UC20
    Admin --- UC21

    FD --- UC01
    FD --- UC02
    FD --- UC03
    FD --- UC04
    FD --- UC05
    FD --- UC06
    FD --- UC07
    FD --- UC08
    FD --- UC09
    FD --- UC10
    FD --- UC11
    FD --- UC12
    FD --- UC13
    FD --- UC14
    FD --- UC15
    FD --- UC16
    FD --- UC17

    Customer --- UC22

    UC05 -.->|"<<include>>"| UC04
    UC07 -.->|"<<include>>"| UC04
    UC09 -.->|"<<include>>"| UC06
    UC10 -.->|"<<include>>"| UC08
    UC13 -.->|"<<include>>"| UC11
    UC14 -.->|"<<extend>> if item is from another branch"| UC07
    UC15 -.->|"<<include>>"| UC11
    UC16 -.->|"<<include>>"| UC04
    UC16 -.->|"<<include>>"| UC06
    UC16 -.->|"<<include>>"| UC08
    UC16 -.->|"<<include>>"| UC11
    UC22 -.->|"<<extend>> after job completion"| UC10

    classDef actor fill:#ffffff,stroke:#000000,stroke-width:2px,color:#000000;
    classDef usecase fill:#ffffff,stroke:#000000,stroke-width:2px,color:#000000;
    class Admin,FD,Customer actor;
    class UC01,UC02,UC03,UC04,UC05,UC06,UC07,UC08,UC09,UC10,UC11,UC12,UC13,UC14,UC15,UC16,UC17,UC18,UC19,UC20,UC21,UC22 usecase;
```

## Access, Administration, and Maintenance

```mermaid
flowchart LR
    Admin["Admin / Owner"]
    FD["Front Desk Staff"]

    subgraph M1["Access, Administration, and Maintenance"]
        Login(["Log In"])
        Authenticate(["Authenticate Credentials"])
        OpenDashboard(["Open Role Dashboard"])
        Profile(["Update Own Profile"])
        Logout(["Log Out"])
        Notifications(["Manage Notifications"])
        MarkNotification(["Mark Notification Read or Unread"])
        Catalog(["Manage Service Catalog"])
        AddService(["Add Service"])
        EditService(["Edit Service"])
        ArchiveService(["Archive Service"])
        Users(["Manage Users and Roles"])
        AddUser(["Add User"])
        EditUser(["Edit User"])
        AssignRoleBranch(["Assign Role and Branch"])
        ArchiveUser(["Archive or Reactivate User"])
        Branches(["Manage Branches and Technicians"])
        AddBranch(["Add Branch"])
        EditBranch(["Edit Branch Details"])
        ArchiveBranch(["Archive or Reactivate Branch"])
        TechnicianRoster(["Maintain Technician Roster"])
        Settings(["Configure System Settings"])
        Branding(["Update Branding and Contact Details"])
        Logo(["Upload Company Logo"])
        Audit(["Record Audit Trail"])
    end

    Admin --- Login
    Admin --- OpenDashboard
    Admin --- Profile
    Admin --- Logout
    Admin --- Notifications
    Admin --- Catalog
    Admin --- Users
    Admin --- Branches
    Admin --- Settings

    FD --- Login
    FD --- OpenDashboard
    FD --- Profile
    FD --- Logout
    FD --- Notifications

    Login -.->|"<<include>>"| Authenticate
    Login -.->|"<<include>>"| OpenDashboard
    Notifications -.->|"<<include>>"| MarkNotification
    Catalog -.->|"<<include>>"| AddService
    Catalog -.->|"<<include>>"| EditService
    ArchiveService -.->|"<<extend>>"| Catalog
    Users -.->|"<<include>>"| AddUser
    Users -.->|"<<include>>"| EditUser
    Users -.->|"<<include>>"| AssignRoleBranch
    ArchiveUser -.->|"<<extend>>"| Users
    Branches -.->|"<<include>>"| AddBranch
    Branches -.->|"<<include>>"| EditBranch
    Branches -.->|"<<include>>"| TechnicianRoster
    ArchiveBranch -.->|"<<extend>>"| Branches
    Settings -.->|"<<include>>"| Branding
    Logo -.->|"<<extend>>"| Settings
    Catalog -.->|"<<include>>"| Audit
    Users -.->|"<<include>>"| Audit
    Branches -.->|"<<include>>"| Audit

    classDef actor fill:#ffffff,stroke:#000000,stroke-width:2px,color:#000000;
    classDef usecase fill:#ffffff,stroke:#000000,stroke-width:2px,color:#000000;
    class Admin,FD actor;
    class Login,Authenticate,OpenDashboard,Profile,Logout,Notifications,MarkNotification,Catalog,AddService,EditService,ArchiveService,Users,AddUser,EditUser,AssignRoleBranch,ArchiveUser,Branches,AddBranch,EditBranch,ArchiveBranch,TechnicianRoster,Settings,Branding,Logo,Audit usecase;
```

## Customer, Vehicle, and Service Operations

```mermaid
flowchart LR
    Admin["Admin / Owner"]
    FD["Front Desk Staff"]

    subgraph M2["Customer, Vehicle, and Service Operations"]
        SearchRecords(["Search and Filter Records"])
        ViewCustomer(["View Customer Profile"])
        ViewVehicle(["View Vehicle Profile"])
        ViewHistory(["View Service and Ownership History"])
        ViewCrossBranch(["View Cross-Branch Activity"])
        AddCustomerVehicle(["Add Customer and Vehicle"])
        EditCustomer(["Update Customer Details"])
        AddVehicle(["Add Vehicle"])
        EditVehicle(["Update Vehicle Details"])
        ArchiveBranchRecord(["Archive Branch Customer Record"])
        ArchiveVehicle(["Archive Vehicle Record"])
        RestoreVehicle(["Restore Vehicle Record"])
        StartOperation(["Create Service Operation"])
        RecordInspection(["Record Service Inspection"])
        SelectCustomerVehicle(["Select Customer and Vehicle"])
        SelectServices(["Select Services from Catalog"])
        SelectItems(["Select Parts, Tires, or External Items"])
        RequestOtherBranch(["Request Other-Branch Item"])
        CalculateTotal(["Calculate Operation Total"])
        SaveOperation(["Save Service Operation"])
        ViewOperation(["View Service Operation Details"])
        PrintOperation(["Print Service Operation"])
        UpdateOperationStatus(["Approve or Reject Service Operation"])
        EditOperationItems(["Edit Service Operation Items"])
        ArchiveOperation(["Archive Pending Service Operation"])
        Audit(["Record Audit Trail"])
    end

    Admin --- SearchRecords
    Admin --- ViewCustomer
    Admin --- ViewVehicle
    Admin --- ViewHistory
    Admin --- ViewCrossBranch
    Admin --- ViewOperation
    Admin --- PrintOperation

    FD --- SearchRecords
    FD --- ViewCustomer
    FD --- ViewVehicle
    FD --- ViewHistory
    FD --- ViewCrossBranch
    FD --- AddCustomerVehicle
    FD --- EditCustomer
    FD --- AddVehicle
    FD --- EditVehicle
    FD --- ArchiveBranchRecord
    FD --- ArchiveVehicle
    FD --- RestoreVehicle
    FD --- StartOperation
    FD --- ViewOperation
    FD --- PrintOperation
    FD --- UpdateOperationStatus
    FD --- EditOperationItems
    FD --- ArchiveOperation

    ViewCustomer -.->|"<<include>>"| ViewVehicle
    ViewCustomer -.->|"<<include>>"| ViewHistory
    ViewCrossBranch -.->|"<<extend>>"| ViewCustomer
    AddCustomerVehicle -.->|"<<include>>"| AddVehicle
    AddCustomerVehicle -.->|"<<include>>"| Audit
    EditCustomer -.->|"<<include>>"| Audit
    AddVehicle -.->|"<<include>>"| Audit
    EditVehicle -.->|"<<include>>"| Audit
    ArchiveBranchRecord -.->|"<<extend>>"| ViewCustomer
    ArchiveVehicle -.->|"<<extend>>"| ViewVehicle
    RestoreVehicle -.->|"<<extend>>"| ViewVehicle
    ArchiveBranchRecord -.->|"<<include>>"| Audit
    ArchiveVehicle -.->|"<<include>>"| Audit
    RestoreVehicle -.->|"<<include>>"| Audit
    StartOperation -.->|"<<include>>"| RecordInspection
    StartOperation -.->|"<<include>>"| SelectCustomerVehicle
    StartOperation -.->|"<<include>>"| SelectServices
    StartOperation -.->|"<<include>>"| SelectItems
    StartOperation -.->|"<<include>>"| CalculateTotal
    StartOperation -.->|"<<include>>"| SaveOperation
    RequestOtherBranch -.->|"<<extend>>"| SelectItems
    SaveOperation -.->|"<<include>>"| Audit
    UpdateOperationStatus -.->|"<<include>>"| Audit
    EditOperationItems -.->|"<<extend>>"| ViewOperation
    EditOperationItems -.->|"<<include>>"| CalculateTotal
    EditOperationItems -.->|"<<include>>"| Audit
    ArchiveOperation -.->|"<<extend>> only before job/history/active transfer"| ViewOperation
    ArchiveOperation -.->|"<<include>>"| Audit

    classDef actor fill:#ffffff,stroke:#000000,stroke-width:2px,color:#000000;
    classDef usecase fill:#ffffff,stroke:#000000,stroke-width:2px,color:#000000;
    class Admin,FD actor;
    class SearchRecords,ViewCustomer,ViewVehicle,ViewHistory,ViewCrossBranch,AddCustomerVehicle,EditCustomer,AddVehicle,EditVehicle,ArchiveBranchRecord,ArchiveVehicle,RestoreVehicle,StartOperation,RecordInspection,SelectCustomerVehicle,SelectServices,SelectItems,RequestOtherBranch,CalculateTotal,SaveOperation,ViewOperation,PrintOperation,UpdateOperationStatus,EditOperationItems,ArchiveOperation,Audit usecase;
```

## Job Orders and Service Status

```mermaid
flowchart LR
    Admin["Admin / Owner"]
    FD["Front Desk Staff"]
    Customer["Customer"]

    subgraph M3["Job Orders and Service Status"]
        ViewJobs(["View Job Orders"])
        FilterJobs(["Filter Job Orders"])
        ViewJobDetails(["View Job Order Details"])
        CreateJob(["Create Job Order"])
        SelectApprovedOperation(["Select Approved Service Operation"])
        PopulateJob(["Auto-Populate Customer, Vehicle, and Services"])
        AssignTechnicians(["Assign Technician Names"])
        ScheduleJob(["Set Job Schedule and Expected Completion"])
        SaveJob(["Save Job Order"])
        UpdateJobStatus(["Update Job Status"])
        TrackTasks(["Update Task-Level Progress"])
        CheckTransferReadiness(["Check Transfer Readiness"])
        DeductTaskInventory(["Deduct Inventory for Completed Task"])
        CompleteJob(["Complete Job Order"])
        GenerateHistory(["Generate Service History"])
        NotifyPickup(["Notify Customer for Pickup"])
        QueueSms(["Queue Pickup SMS Message"])
        ReopenCompleted(["Reopen Completed Job Progress"])
        ArchiveWaitingJob(["Archive Waiting Job Order"])
        ViewStatusBoard(["View Service Status Board"])
        GetStatusSummary(["View Status Summary"])
        Audit(["Record Audit Trail"])
    end

    Admin --- ViewJobs
    Admin --- FilterJobs
    Admin --- ViewJobDetails
    Admin --- ViewStatusBoard
    Admin --- GetStatusSummary

    FD --- ViewJobs
    FD --- FilterJobs
    FD --- ViewJobDetails
    FD --- CreateJob
    FD --- UpdateJobStatus
    FD --- TrackTasks
    FD --- CompleteJob
    FD --- ArchiveWaitingJob
    FD --- ViewStatusBoard
    FD --- GetStatusSummary

    Customer --- NotifyPickup

    ViewJobs -.->|"<<include>>"| FilterJobs
    ViewJobs -.->|"<<include>>"| ViewJobDetails
    CreateJob -.->|"<<include>>"| SelectApprovedOperation
    CreateJob -.->|"<<include>>"| PopulateJob
    CreateJob -.->|"<<include>>"| AssignTechnicians
    CreateJob -.->|"<<include>>"| ScheduleJob
    CreateJob -.->|"<<include>>"| SaveJob
    SaveJob -.->|"<<include>>"| Audit
    TrackTasks -.->|"<<include>>"| CheckTransferReadiness
    DeductTaskInventory -.->|"<<extend>> if task uses inventory"| TrackTasks
    TrackTasks -.->|"<<include>>"| UpdateJobStatus
    UpdateJobStatus -.->|"<<include>>"| Audit
    CompleteJob -.->|"<<extend>> when all work is done"| UpdateJobStatus
    CompleteJob -.->|"<<include>>"| GenerateHistory
    CompleteJob -.->|"<<include>>"| NotifyPickup
    NotifyPickup -.->|"<<include>>"| QueueSms
    ReopenCompleted -.->|"<<extend>>"| TrackTasks
    ArchiveWaitingJob -.->|"<<extend>> only while waiting"| ViewJobDetails
    ArchiveWaitingJob -.->|"<<include>>"| Audit
    ViewStatusBoard -.->|"<<include>>"| GetStatusSummary

    classDef actor fill:#ffffff,stroke:#000000,stroke-width:2px,color:#000000;
    classDef usecase fill:#ffffff,stroke:#000000,stroke-width:2px,color:#000000;
    class Admin,FD,Customer actor;
    class ViewJobs,FilterJobs,ViewJobDetails,CreateJob,SelectApprovedOperation,PopulateJob,AssignTechnicians,ScheduleJob,SaveJob,UpdateJobStatus,TrackTasks,CheckTransferReadiness,DeductTaskInventory,CompleteJob,GenerateHistory,NotifyPickup,QueueSms,ReopenCompleted,ArchiveWaitingJob,ViewStatusBoard,GetStatusSummary,Audit usecase;
```

## Inventory and Inter-Branch Transfers

```mermaid
flowchart LR
    Admin["Admin / Owner"]
    FD["Front Desk Staff"]
    DonorFD["Donor Branch Front Desk"]
    ReceiverFD["Requesting Branch Front Desk"]

    subgraph M4["Inventory and Inter-Branch Transfers"]
        ViewInventory(["View Inventory Records"])
        SearchInventory(["Search and Filter Inventory"])
        ViewLowStock(["View Low Stock Items"])
        ViewTransactions(["View Inventory Transactions"])
        AddItem(["Add Inventory Item"])
        ValidateInventoryBranch(["Use Active Inventory Branch"])
        RecordInitialStock(["Record Initial Stock"])
        StockIn(["Record Stock In"])
        StockOut(["Record Stock Out"])
        TagCustomerVehicle(["Tag Stock Out to Customer or Vehicle"])
        DirectTransfer(["Transfer Stock Directly"])
        UpdateQuantities(["Update Source and Target Quantities"])
        SaveTransaction(["Save Inventory Transaction"])
        CheckAvailability(["Check Other-Branch Availability"])
        CreateTransferRequest(["Create Transfer Request"])
        NotifyBranches(["Notify Affected Branches"])
        ReviewIncoming(["Review Incoming Item Requests"])
        ApproveRequest(["Approve Transfer Request"])
        CompleteTransfer(["Complete Transfer Request"])
        DeductDonorStock(["Deduct Donor Stock"])
        ReceiveRequestedStock(["Receive Stock at Requesting Branch"])
        MarkTransferNotification(["Mark Transfer Notification Read or Unread"])
        Audit(["Record Audit Trail"])
    end

    Admin --- ViewInventory
    Admin --- SearchInventory
    Admin --- ViewLowStock
    Admin --- ViewTransactions
    Admin --- AddItem
    Admin --- CheckAvailability
    Admin --- ReviewIncoming

    FD --- ViewInventory
    FD --- SearchInventory
    FD --- ViewLowStock
    FD --- ViewTransactions
    FD --- AddItem
    FD --- StockIn
    FD --- StockOut
    FD --- DirectTransfer
    FD --- CheckAvailability
    FD --- CreateTransferRequest
    FD --- MarkTransferNotification

    DonorFD --- ReviewIncoming
    DonorFD --- ApproveRequest
    DonorFD --- CompleteTransfer
    ReceiverFD --- CreateTransferRequest
    ReceiverFD --- ReceiveRequestedStock

    ViewInventory -.->|"<<include>>"| SearchInventory
    ViewLowStock -.->|"<<extend>>"| ViewInventory
    ViewTransactions -.->|"<<extend>>"| ViewInventory
    AddItem -.->|"<<include>>"| ValidateInventoryBranch
    RecordInitialStock -.->|"<<extend>> if quantity is entered"| AddItem
    RecordInitialStock -.->|"<<include>>"| SaveTransaction
    StockIn -.->|"<<include>>"| ValidateInventoryBranch
    StockIn -.->|"<<include>>"| SaveTransaction
    StockOut -.->|"<<include>>"| ValidateInventoryBranch
    TagCustomerVehicle -.->|"<<extend>> if customer or vehicle is selected"| StockOut
    StockOut -.->|"<<include>>"| SaveTransaction
    DirectTransfer -.->|"<<include>>"| ValidateInventoryBranch
    DirectTransfer -.->|"<<include>>"| UpdateQuantities
    DirectTransfer -.->|"<<include>>"| SaveTransaction
    DirectTransfer -.->|"<<include>>"| NotifyBranches
    CreateTransferRequest -.->|"<<include>>"| CheckAvailability
    CreateTransferRequest -.->|"<<include>>"| NotifyBranches
    ApproveRequest -.->|"<<extend>>"| ReviewIncoming
    CompleteTransfer -.->|"<<extend>>"| ReviewIncoming
    CompleteTransfer -.->|"<<include>>"| DeductDonorStock
    ReceiveRequestedStock -.->|"<<extend>> if receiving branch has inventory"| CompleteTransfer
    CompleteTransfer -.->|"<<include>>"| SaveTransaction
    MarkTransferNotification -.->|"<<extend>>"| NotifyBranches
    AddItem -.->|"<<include>>"| Audit
    StockIn -.->|"<<include>>"| Audit
    StockOut -.->|"<<include>>"| Audit
    DirectTransfer -.->|"<<include>>"| Audit
    CreateTransferRequest -.->|"<<include>>"| Audit
    ApproveRequest -.->|"<<include>>"| Audit
    CompleteTransfer -.->|"<<include>>"| Audit

    classDef actor fill:#ffffff,stroke:#000000,stroke-width:2px,color:#000000;
    classDef usecase fill:#ffffff,stroke:#000000,stroke-width:2px,color:#000000;
    class Admin,FD,DonorFD,ReceiverFD actor;
    class ViewInventory,SearchInventory,ViewLowStock,ViewTransactions,AddItem,ValidateInventoryBranch,RecordInitialStock,StockIn,StockOut,TagCustomerVehicle,DirectTransfer,UpdateQuantities,SaveTransaction,CheckAvailability,CreateTransferRequest,NotifyBranches,ReviewIncoming,ApproveRequest,CompleteTransfer,DeductDonorStock,ReceiveRequestedStock,MarkTransferNotification,Audit usecase;
```

## Forecasting, Reports, and Notifications

```mermaid
flowchart LR
    Admin["Admin / Owner"]
    FD["Front Desk Staff"]

    subgraph M5["Forecasting, Reports, and Notifications"]
        Forecast(["View Inventory Forecasting"])
        SelectForecastView(["Select Weekly, Monthly, or Item Forecast"])
        ApplyForecastFilters(["Apply Branch, Category, Status, Year, and Search Filters"])
        ReadInventory(["Read Active Inventory"])
        ReadHistory(["Read Historical Stock Movements"])
        CalculateUsage(["Calculate Usage and Demand"])
        ClassifyRisk(["Classify Stock Risk"])
        RecommendReorder(["Recommend Reorder Quantity"])
        RecommendTransfer(["Recommend Branch Transfer Matches"])
        ViewForecastCharts(["View Forecast Charts"])
        Reports(["View Reports"])
        SelectReport(["Select Report Type"])
        ApplyReportFilters(["Apply Branch, Date, Status, and Search Filters"])
        ViewServiceReport(["View Service Report"])
        ViewItemSalesReport(["View Item Sales Report"])
        ViewVehicleReport(["View Vehicle and Ownership Report"])
        ViewStockMovementReport(["View Stock Movement Report"])
        ViewArchiveReport(["View Archived Records Report"])
        ExportCsv(["Export CSV"])
        Notifications(["View Notification Center"])
        MarkNotice(["Mark Notice Read or Unread"])
        LowStockNotice(["Receive Low Stock Notice"])
        PendingOperationNotice(["Receive Pending Operation Notice"])
        ActiveJobNotice(["Receive Active Job Notice"])
        TransferNotice(["Receive Transfer Notice"])
    end

    Admin --- Forecast
    Admin --- Reports
    Admin --- ExportCsv
    Admin --- Notifications

    FD --- Forecast
    FD --- Reports
    FD --- ExportCsv
    FD --- Notifications

    Forecast -.->|"<<include>>"| SelectForecastView
    Forecast -.->|"<<include>>"| ApplyForecastFilters
    Forecast -.->|"<<include>>"| ReadInventory
    Forecast -.->|"<<include>>"| ReadHistory
    Forecast -.->|"<<include>>"| CalculateUsage
    Forecast -.->|"<<include>>"| ClassifyRisk
    ClassifyRisk -.->|"<<include>>"| RecommendReorder
    ClassifyRisk -.->|"<<include>>"| RecommendTransfer
    Forecast -.->|"<<include>>"| ViewForecastCharts
    Reports -.->|"<<include>>"| SelectReport
    Reports -.->|"<<include>>"| ApplyReportFilters
    ViewServiceReport -.->|"<<extend>>"| Reports
    ViewVehicleReport -.->|"<<extend>>"| Reports
    ViewItemSalesReport -.->|"<<extend>> if inventory is available"| Reports
    ViewStockMovementReport -.->|"<<extend>> if inventory is available"| Reports
    ViewArchiveReport -.->|"<<extend>>"| Reports
    ExportCsv -.->|"<<extend>>"| Reports
    Notifications -.->|"<<include>>"| MarkNotice
    LowStockNotice -.->|"<<extend>>"| Notifications
    PendingOperationNotice -.->|"<<extend>>"| Notifications
    ActiveJobNotice -.->|"<<extend>>"| Notifications
    TransferNotice -.->|"<<extend>>"| Notifications

    classDef actor fill:#ffffff,stroke:#000000,stroke-width:2px,color:#000000;
    classDef usecase fill:#ffffff,stroke:#000000,stroke-width:2px,color:#000000;
    class Admin,FD actor;
    class Forecast,SelectForecastView,ApplyForecastFilters,ReadInventory,ReadHistory,CalculateUsage,ClassifyRisk,RecommendReorder,RecommendTransfer,ViewForecastCharts,Reports,SelectReport,ApplyReportFilters,ViewServiceReport,ViewItemSalesReport,ViewVehicleReport,ViewStockMovementReport,ViewArchiveReport,ExportCsv,Notifications,MarkNotice,LowStockNotice,PendingOperationNotice,ActiveJobNotice,TransferNotice usecase;
```

## Actor Access Summary

| Actor | Main responsibilities |
| --- | --- |
| Admin / Owner | Monitors all branches, views operational records, adds inventory items, manages services, users, branches, technician rosters, settings, forecasting, reports, and notifications. |
| Front Desk Staff | Creates and maintains branch records, service operations, job orders, service progress, inventory movements, branch transfers, forecasting views, reports, and notifications within branch access rules. |
| Donor Branch Front Desk | Reviews incoming stock requests and completes transfer requests from its own inventory branch. |
| Requesting Branch Front Desk | Requests items from another branch and receives transfer completion updates. |
| Customer | Receives pickup SMS notification when a job order is completed and a phone number is available. |

## Important Constraints

- Protected use cases require a logged-in active user.
- Front Desk record changes are limited to the user's assigned branch.
- Admin/Owner can view operational records across branches, but current customer, service operation, job order, and stock movement handlers keep day-to-day operational changes with Front Desk users.
- Service operation archiving is allowed only before linked job orders, service history, or non-pending transfer requests exist.
- Job order archiving is allowed only while the job order is still waiting.
- Completing a job order generates service history and queues a pickup SMS message.
- Other-branch service items may create transfer requests and block task completion until the needed item has been transferred.
