# Highway Tires ERD Mermaid Diagrams

Paste one Mermaid block at a time into draw.io:

`Insert` -> `Advanced` -> `Mermaid`

The whole-system ERD is large. If draw.io becomes crowded, use the module ERDs instead.

## Whole System ERD

```mermaid
erDiagram
    BRANCHES ||--o{ USERS : has_users
    USERS ||--o{ BRANCHES : supervises
    BRANCHES ||--o{ CUSTOMERS : default_branch
    CUSTOMERS ||--o{ CUSTOMER_BRANCH_RECORDS : has_branch_records
    BRANCHES ||--o{ CUSTOMER_BRANCH_RECORDS : owns_customer_access
    USERS ||--o{ CUSTOMER_BRANCH_RECORDS : created_by
    CUSTOMERS ||--o{ VEHICLES : owns
    BRANCHES ||--o{ VEHICLES : registered_at
    BRANCHES ||--o{ TECHNICIANS : has
    CUSTOMERS ||--o{ CUSTOMER_VISITS : visits
    BRANCHES ||--o{ CUSTOMER_VISITS : receives_visit
    USERS ||--o{ CUSTOMER_VISITS : recorded_by
    CUSTOMERS ||--o{ QUOTATIONS : requests
    VEHICLES ||--o{ QUOTATIONS : quoted_for
    BRANCHES ||--o{ QUOTATIONS : creates
    USERS ||--o{ QUOTATIONS : created_by
    QUOTATIONS ||--o{ QUOTATION_ITEMS : contains
    SERVICE_CATALOG ||--o{ QUOTATION_ITEMS : logical_service_source
    QUOTATIONS ||--o{ JOB_ORDERS : converts_to
    CUSTOMERS ||--o{ JOB_ORDERS : receives
    VEHICLES ||--o{ JOB_ORDERS : serviced_vehicle
    BRANCHES ||--o{ JOB_ORDERS : handles
    USERS ||--o{ JOB_ORDERS : created_by
    TECHNICIANS ||--o{ JOB_ORDERS : optional_assignee
    JOB_ORDERS ||--o{ SERVICE_HISTORY : produces
    QUOTATIONS ||--o{ SERVICE_HISTORY : source_quote
    CUSTOMERS ||--o{ SERVICE_HISTORY : has_history
    VEHICLES ||--o{ SERVICE_HISTORY : has_history
    BRANCHES ||--o{ SERVICE_HISTORY : performed_at
    BRANCHES ||--o{ INVENTORY_ITEMS : stocks
    INVENTORY_ITEMS ||--o{ INVENTORY_TRANSACTIONS : has_movements
    USERS ||--o{ INVENTORY_TRANSACTIONS : performed_by
    JOB_ORDERS ||--o| SMS_OUTBOX : queues_pickup_sms
    CUSTOMERS ||--o{ SMS_OUTBOX : recipient
    VEHICLES ||--o{ SMS_OUTBOX : vehicle_reference
    BRANCHES ||--o{ SMS_OUTBOX : branch_notice
    USERS ||--o{ SMS_OUTBOX : queued_by
    USERS ||--o{ AUDIT_LOGS : performs

    BRANCHES {
        int id PK
        string name
        string location
        string branch_supervisor
        string contact_number
        string email
        int manager_id FK
        boolean has_inventory
        enum status
    }

    USERS {
        int id PK
        string name
        string email UK
        string password_hash
        enum role
        int branch_id FK
        enum status
    }

    CUSTOMERS {
        int id PK
        string name
        string contact
        string email
        string address
        string city
        string phone_mobile
        string phone_work
        enum customer_type
        int branch_id FK
        enum status
    }

    CUSTOMER_BRANCH_RECORDS {
        int id PK
        int customer_id FK
        int branch_id FK
        enum status
        datetime last_visit_at
        int created_by FK
    }

    VEHICLES {
        int id PK
        int customer_id FK
        int branch_id FK
        string plate_number UK
        string vin
        enum condition
        string make
        string model
        int year
        string color
        date last_service_date
        int last_mileage
        enum status
    }

    TECHNICIANS {
        int id PK
        string name
        int branch_id FK
        string specialization
        string contact_number
        enum status
        date join_date
    }

    SERVICE_CATALOG {
        int id PK
        string name UK
        string category
        decimal price
        enum status
    }

    QUOTATIONS {
        int id PK
        string quotation_number UK
        int customer_id FK
        int vehicle_id FK
        int branch_id FK
        date quotation_date
        decimal labor_cost
        decimal parts_cost
        decimal tires_cost
        decimal total_amount
        enum status
        text notes
        text inspection_complaint
        text inspection_findings
        text inspection_recommendations
        int inspection_mileage
        int created_by FK
    }

    QUOTATION_ITEMS {
        int id PK
        int quotation_id FK
        string item_name
        string category
        enum item_type
        int quantity
        decimal unit_price
        decimal subtotal
        enum source
        text notes
    }

    JOB_ORDERS {
        int id PK
        string job_number UK
        int customer_id FK
        int vehicle_id FK
        string assigned_technician_name
        int branch_id FK
        int quotation_id FK
        date job_date
        time scheduled_start_time
        time scheduled_end_time
        datetime actual_start_time
        datetime actual_end_time
        enum status
        int assigned_technician_id FK
        text notes
        int created_by FK
    }

    SERVICE_HISTORY {
        int id PK
        int customer_id FK
        int vehicle_id FK
        int branch_id FK
        date service_date
        text services_description
        decimal total_cost
        int mileage_at_service
        int job_order_id FK
        int quotation_id FK
        text notes
    }

    INVENTORY_ITEMS {
        int id PK
        int branch_id FK
        string item_name
        enum category
        string brand
        string size
        text description
        string sku
        int quantity
        int reorder_level
        decimal unit_price
        string supplier_name
        string supplier_contact
        enum status
        date last_restock_date
    }

    INVENTORY_TRANSACTIONS {
        int id PK
        int item_id FK
        enum transaction_type
        int quantity
        string reference_type
        int reference_id
        text notes
        int created_by FK
        datetime created_at
    }

    CUSTOMER_VISITS {
        int id PK
        int customer_id FK
        int branch_id FK
        datetime visit_date
        enum visit_type
        text notes
        int created_by FK
    }

    SMS_OUTBOX {
        int id PK
        int job_order_id FK
        int customer_id FK
        int vehicle_id FK
        int branch_id FK
        string recipient_name
        string recipient_phone
        text message_body
        enum status
        string provider
        string provider_message_id
        text error_message
        datetime queued_at
        datetime sent_at
        int created_by FK
    }

    AUDIT_LOGS {
        int id PK
        int user_id FK
        string action
        string table_name
        int record_id
        json old_values
        json new_values
        string ip_address
        datetime created_at
    }

    SYSTEM_SETTINGS {
        string setting_key PK
        text setting_value
        datetime updated_at
    }
```

## Module 1: Access And System Maintenance

```mermaid
erDiagram
    BRANCHES ||--o{ USERS : has_users
    USERS ||--o{ BRANCHES : supervises
    USERS ||--o{ AUDIT_LOGS : performs

    BRANCHES {
        int id PK
        string name
        string location
        string branch_supervisor
        string contact_number
        int manager_id FK
        boolean has_inventory
        enum status
    }

    USERS {
        int id PK
        string name
        string email UK
        string password_hash
        enum role
        int branch_id FK
        enum status
    }

    SERVICE_CATALOG {
        int id PK
        string name UK
        string category
        decimal price
        enum status
    }

    SYSTEM_SETTINGS {
        string setting_key PK
        text setting_value
        datetime updated_at
    }

    AUDIT_LOGS {
        int id PK
        int user_id FK
        string action
        string table_name
        int record_id
        json old_values
        json new_values
        string ip_address
        datetime created_at
    }
```

## Module 2: Customer And Vehicle Records

```mermaid
erDiagram
    BRANCHES ||--o{ CUSTOMERS : default_branch
    CUSTOMERS ||--o{ CUSTOMER_BRANCH_RECORDS : has_branch_records
    BRANCHES ||--o{ CUSTOMER_BRANCH_RECORDS : owns_customer_access
    USERS ||--o{ CUSTOMER_BRANCH_RECORDS : created_by
    CUSTOMERS ||--o{ VEHICLES : owns
    BRANCHES ||--o{ VEHICLES : registered_at
    CUSTOMERS ||--o{ CUSTOMER_VISITS : visits
    BRANCHES ||--o{ CUSTOMER_VISITS : receives_visit
    USERS ||--o{ CUSTOMER_VISITS : recorded_by

    BRANCHES {
        int id PK
        string name
        boolean has_inventory
        enum status
    }

    USERS {
        int id PK
        string name
        enum role
        int branch_id FK
    }

    CUSTOMERS {
        int id PK
        string name
        string contact
        string address
        string city
        string phone_mobile
        enum customer_type
        int branch_id FK
        enum status
    }

    CUSTOMER_BRANCH_RECORDS {
        int id PK
        int customer_id FK
        int branch_id FK
        enum status
        datetime last_visit_at
        int created_by FK
    }

    VEHICLES {
        int id PK
        int customer_id FK
        int branch_id FK
        string plate_number UK
        string make
        string model
        int year
        string color
        date last_service_date
        int last_mileage
        enum status
    }

    CUSTOMER_VISITS {
        int id PK
        int customer_id FK
        int branch_id FK
        datetime visit_date
        enum visit_type
        text notes
        int created_by FK
    }
```

## Module 3: Service Operations

```mermaid
erDiagram
    CUSTOMERS ||--o{ QUOTATIONS : requests
    VEHICLES ||--o{ QUOTATIONS : quoted_for
    BRANCHES ||--o{ QUOTATIONS : creates
    USERS ||--o{ QUOTATIONS : created_by
    QUOTATIONS ||--o{ QUOTATION_ITEMS : contains
    SERVICE_CATALOG ||--o{ QUOTATION_ITEMS : logical_service_source
    QUOTATIONS ||--o{ JOB_ORDERS : converts_to
    CUSTOMERS ||--o{ JOB_ORDERS : receives
    VEHICLES ||--o{ JOB_ORDERS : serviced_vehicle
    BRANCHES ||--o{ JOB_ORDERS : handles
    USERS ||--o{ JOB_ORDERS : created_by
    TECHNICIANS ||--o{ JOB_ORDERS : optional_assignee
    JOB_ORDERS ||--o{ SERVICE_HISTORY : produces
    QUOTATIONS ||--o{ SERVICE_HISTORY : source_quote
    CUSTOMERS ||--o{ SERVICE_HISTORY : has_history
    VEHICLES ||--o{ SERVICE_HISTORY : has_history
    BRANCHES ||--o{ SERVICE_HISTORY : performed_at

    CUSTOMERS {
        int id PK
        string name
        string contact
        string phone_mobile
    }

    VEHICLES {
        int id PK
        int customer_id FK
        string plate_number UK
        string make
        string model
        int last_mileage
    }

    BRANCHES {
        int id PK
        string name
    }

    USERS {
        int id PK
        string name
        enum role
        int branch_id FK
    }

    SERVICE_CATALOG {
        int id PK
        string name UK
        string category
        decimal price
        enum status
    }

    QUOTATIONS {
        int id PK
        string quotation_number UK
        int customer_id FK
        int vehicle_id FK
        int branch_id FK
        date quotation_date
        decimal total_amount
        enum status
        text inspection_complaint
        text inspection_findings
        text inspection_recommendations
        int inspection_mileage
        int created_by FK
    }

    QUOTATION_ITEMS {
        int id PK
        int quotation_id FK
        string item_name
        enum item_type
        int quantity
        decimal unit_price
        enum source
        text notes
    }

    JOB_ORDERS {
        int id PK
        string job_number UK
        int customer_id FK
        int vehicle_id FK
        int branch_id FK
        int quotation_id FK
        string assigned_technician_name
        int assigned_technician_id FK
        enum status
        date job_date
        text notes
        int created_by FK
    }

    TECHNICIANS {
        int id PK
        string name
        int branch_id FK
        enum status
    }

    SERVICE_HISTORY {
        int id PK
        int customer_id FK
        int vehicle_id FK
        int branch_id FK
        int job_order_id FK
        int quotation_id FK
        date service_date
        text services_description
        decimal total_cost
        int mileage_at_service
        text notes
    }
```

## Module 4: Inventory Management

```mermaid
erDiagram
    BRANCHES ||--o{ INVENTORY_ITEMS : stocks
    INVENTORY_ITEMS ||--o{ INVENTORY_TRANSACTIONS : has_movements
    USERS ||--o{ INVENTORY_TRANSACTIONS : performed_by

    BRANCHES {
        int id PK
        string name
        boolean has_inventory
        enum status
    }

    USERS {
        int id PK
        string name
        enum role
        int branch_id FK
    }

    INVENTORY_ITEMS {
        int id PK
        int branch_id FK
        string item_name
        enum category
        string brand
        string size
        string sku
        int quantity
        int reorder_level
        decimal unit_price
        enum status
        date last_restock_date
    }

    INVENTORY_TRANSACTIONS {
        int id PK
        int item_id FK
        enum transaction_type
        int quantity
        string reference_type
        int reference_id
        text notes
        int created_by FK
        datetime created_at
    }
```

## Module 5: Forecasting And Decision Support

Forecasting is computed from inventory tables. It does not have a separate physical forecasting table.

```mermaid
erDiagram
    BRANCHES ||--o{ INVENTORY_ITEMS : stocks
    INVENTORY_ITEMS ||--o{ INVENTORY_TRANSACTIONS : historical_movements

    BRANCHES {
        int id PK
        string name
        boolean has_inventory
        enum status
    }

    INVENTORY_ITEMS {
        int id PK
        int branch_id FK
        string item_name
        enum category
        string brand
        string size
        int quantity
        int reorder_level
        decimal unit_price
        enum status
    }

    INVENTORY_TRANSACTIONS {
        int id PK
        int item_id FK
        enum transaction_type
        int quantity
        string reference_type
        datetime created_at
    }
```

## Module 6: Reports, Notifications, And Audit

```mermaid
erDiagram
    BRANCHES ||--o{ QUOTATIONS : report_source
    BRANCHES ||--o{ JOB_ORDERS : report_source
    BRANCHES ||--o{ SERVICE_HISTORY : report_source
    BRANCHES ||--o{ INVENTORY_ITEMS : report_source
    INVENTORY_ITEMS ||--o{ INVENTORY_TRANSACTIONS : report_source
    JOB_ORDERS ||--o| SMS_OUTBOX : queues_pickup_sms
    CUSTOMERS ||--o{ SMS_OUTBOX : receives
    VEHICLES ||--o{ SMS_OUTBOX : vehicle_reference
    BRANCHES ||--o{ SMS_OUTBOX : branch_notice
    USERS ||--o{ SMS_OUTBOX : queued_by
    USERS ||--o{ AUDIT_LOGS : performs

    BRANCHES {
        int id PK
        string name
    }

    CUSTOMERS {
        int id PK
        string name
        string contact
        string phone_mobile
    }

    VEHICLES {
        int id PK
        int customer_id FK
        string plate_number UK
        string make
        string model
    }

    USERS {
        int id PK
        string name
        enum role
        int branch_id FK
    }

    QUOTATIONS {
        int id PK
        string quotation_number UK
        int customer_id FK
        int branch_id FK
        date quotation_date
        decimal total_amount
        enum status
    }

    JOB_ORDERS {
        int id PK
        string job_number UK
        int customer_id FK
        int vehicle_id FK
        int branch_id FK
        int quotation_id FK
        enum status
        date job_date
    }

    SERVICE_HISTORY {
        int id PK
        int customer_id FK
        int vehicle_id FK
        int branch_id FK
        int job_order_id FK
        int quotation_id FK
        date service_date
        decimal total_cost
    }

    INVENTORY_ITEMS {
        int id PK
        int branch_id FK
        string item_name
        enum category
        int quantity
        int reorder_level
        decimal unit_price
    }

    INVENTORY_TRANSACTIONS {
        int id PK
        int item_id FK
        enum transaction_type
        int quantity
        string reference_type
        datetime created_at
    }

    SMS_OUTBOX {
        int id PK
        int job_order_id FK
        int customer_id FK
        int vehicle_id FK
        int branch_id FK
        string recipient_phone
        text message_body
        enum status
        datetime queued_at
        datetime sent_at
        int created_by FK
    }

    AUDIT_LOGS {
        int id PK
        int user_id FK
        string action
        string table_name
        int record_id
        json old_values
        json new_values
        datetime created_at
    }
```
