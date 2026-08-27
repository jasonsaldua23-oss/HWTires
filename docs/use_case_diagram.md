# HW Tires System Use Case Diagram

Updated: August 26, 2026

Paste the Mermaid block below into a Mermaid previewer or draw.io using `Insert > Advanced > Mermaid`.

```mermaid
flowchart LR
    Admin["Admin / Owner"]
    FD["Front Desk Staff"]
    DonorFD["Donor Branch Front Desk"]
    RequestFD["Requesting Branch Front Desk"]
    Customer["Customer"]

    subgraph SYS["Highway Tires Branch Data Management System"]
        UC01(["Manage User Session"])
        UC02(["View Role Dashboard"])
        UC03(["Manage Own Profile"])
        UC04(["View Customer and Vehicle Records"])
        UC05(["Maintain Customer and Vehicle Records"])
        UC06(["Create and Update Service Operations"])
        UC07(["Approve or Reject Service Operations"])
        UC08(["Create Job Orders"])
        UC09(["Track Service Status and Tasks"])
        UC10(["Manage Inventory and Stock Movements"])
        UC11(["Request Inter-Branch Stock Transfer"])
        UC12(["Process Inter-Branch Transfer"])
        UC13(["View Inventory Forecasting"])
        UC14(["View Reports and Export CSV"])
        UC15(["Manage Notifications"])
        UC16(["Notify Customer for Pickup"])
        UC17(["Manage Service Catalog"])
        UC18(["Manage Users and Roles"])
        UC19(["Manage Branches and Technicians"])
        UC20(["Configure System Settings"])
        UC21(["Record Audit Trail"])
    end

    Admin --- UC01
    Admin --- UC02
    Admin --- UC03
    Admin --- UC04
    Admin --- UC07
    Admin --- UC10
    Admin --- UC12
    Admin --- UC13
    Admin --- UC14
    Admin --- UC15
    Admin --- UC17
    Admin --- UC18
    Admin --- UC19
    Admin --- UC20

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
    FD --- UC13
    FD --- UC14
    FD --- UC15

    RequestFD --- UC11
    DonorFD --- UC12
    Customer --- UC16

    UC02 -.->|"<<include>> requires active session"| UC01
    UC05 -.->|"<<include>>"| UC04
    UC06 -.->|"<<include>>"| UC04
    UC06 -.->|"<<extend>> when item is unavailable locally"| UC11
    UC08 -.->|"<<include>> approved service operation"| UC06
    UC09 -.->|"<<extend>> after job is created"| UC08
    UC09 -.->|"<<extend>> when work is completed"| UC16
    UC10 -.->|"<<include>>"| UC21
    UC11 -.->|"<<include>> check branch stock"| UC10
    UC12 -.->|"<<extend>> after transfer request is submitted"| UC11
    UC13 -.->|"<<include>>"| UC10
    UC14 -.->|"<<include>>"| UC04
    UC14 -.->|"<<include>>"| UC08
    UC15 -.->|"<<extend>> low stock, active jobs, pending operations, transfers"| UC02
    UC16 -.->|"<<include>>"| UC15
    UC17 -.->|"<<include>>"| UC21
    UC18 -.->|"<<include>>"| UC21
    UC19 -.->|"<<include>>"| UC21
    UC20 -.->|"<<include>>"| UC21

    classDef actor fill:#ffffff,stroke:#111827,stroke-width:2px,color:#111827;
    classDef usecase fill:#ffffff,stroke:#111827,stroke-width:2px,color:#111827;
    class Admin,FD,DonorFD,RequestFD,Customer actor;
    class UC01,UC02,UC03,UC04,UC05,UC06,UC07,UC08,UC09,UC10,UC11,UC12,UC13,UC14,UC15,UC16,UC17,UC18,UC19,UC20,UC21 usecase;
```

## Actor Summary

| Actor | Role in the system |
| --- | --- |
| Admin / Owner | Monitors branches, views records, manages inventory visibility, reports, forecasting, notifications, users, branches, services, and settings. |
| Front Desk Staff | Handles branch operations such as customers, vehicles, service operations, job orders, service status, inventory actions, reports, forecasting, and notifications. |
| Requesting Branch Front Desk | Requests inventory from another branch when a service operation needs an unavailable item. |
| Donor Branch Front Desk | Reviews, approves, and completes transfer requests from its branch inventory. |
| Customer | Receives pickup notification when a job order is completed. |

## Main Constraints

- Protected use cases require an active logged-in user.
- Front desk changes are limited to the user's assigned branch.
- Admin-only maintenance includes service catalog, user roles, branches, technicians, and system settings.
- Service operations can create inter-branch transfer requests when selected items are sourced from another branch.
- Completed job orders generate service history and trigger customer pickup notification handling.
