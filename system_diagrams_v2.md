# StockBoard — System Diagrams v2

> Reflects all current features as of schema **v6.2** and the live codebase.
> Major additions vs. original: `agent_commission_payouts` table, `agent_price`/`is_pending`/`is_deleted`/`avatar`/`last_seen`/`commission_cleared`/`status` fields, 5-role RBAC, full sales-status lifecycle, Quotation → Sales Queue workflow, Stock Prediction module, and Commission Dashboard.

---

## 1 — Entity-Relationship Diagram

```mermaid
erDiagram
    USERS {
        int id PK
        string username
        string password
        string full_name
        enum role "Administrator|Manager|OnlineAgent|SalesCashier|InventoryOfficer"
        tinyint is_active
        tinyint is_pending "1 = self-registered, awaiting manager approval"
        tinyint is_deleted "soft-delete flag"
        string avatar "profile photo path"
        timestamp last_seen "last activity timestamp"
        timestamp created_at
        timestamp updated_at
    }

    CATEGORIES {
        int id PK
        string name
        string description
    }

    PRODUCTS {
        int id PK
        int category_id FK
        string board_type
        string color_design
        string unit
        decimal cost_price
        decimal selling_price
        decimal agent_price "base price for commission calculation"
        int current_stock
        int low_stock_threshold
        tinyint is_active "soft-delete flag"
        timestamp created_at
        timestamp updated_at
    }

    SALES {
        int id PK
        int user_id FK
        string invoice_no
        decimal total_amount
        string notes
        string payment_type "Cash, GCash, Bank Transfer, Check"
        string payment_reference "Ref/Check No"
        date sale_date
        enum status "Valid|VoidPending|Voided|Returned|PendingOrder"
        timestamp created_at
    }

    SALE_ITEMS {
        int id PK
        int sale_id FK
        int product_id FK
        int quantity
        decimal price_per_unit
        decimal total
        tinyint commission_cleared "0=unpaid 1=paid"
    }

    STOCK_MOVEMENTS {
        int id PK
        int product_id FK
        int user_id FK
        enum type "IN|OUT|ADJUSTMENT|SALE"
        int quantity
        string notes
        timestamp created_at
    }

    AUDIT_LOG {
        bigint id PK
        int user_id FK
        string action
        string target_type
        int target_id
        text detail
        string ip_address
        timestamp created_at
    }

    AGENT_COMMISSION_PAYOUTS {
        int id PK
        int agent_id FK
        int cleared_by FK
        decimal amount
        string note
        timestamp cleared_at
    }

    CATEGORIES ||--o{ PRODUCTS : "has"
    USERS ||--o{ SALES : "processes"
    SALES ||--|{ SALE_ITEMS : "contains"
    PRODUCTS ||--o{ SALE_ITEMS : "sold as"
    PRODUCTS ||--o{ STOCK_MOVEMENTS : "tracked via"
    USERS ||--o{ STOCK_MOVEMENTS : "initiates"
    USERS ||--o{ AUDIT_LOG : "performs"
    USERS ||--o{ AGENT_COMMISSION_PAYOUTS : "earns (agent_id)"
    USERS ||--o{ AGENT_COMMISSION_PAYOUTS : "clears (cleared_by)"
    SALE_ITEMS }o--|| PRODUCTS : "links commission to"
```

---

## 2 — Application Flowchart

```mermaid
flowchart TD
    Start(["User Visits Login Page"]) --> SelfReg{"Self-Register?"}
    SelfReg -- Yes --> RegForm["Register Form\n(username + password)"]
    RegForm --> Pending["Account set is_pending=1\nAwaits Manager Approval"]
    Pending --> AdminApprove{"Manager Reviews\nPending Account"}
    AdminApprove -- Approve --> Active["Account Activated\n(is_pending=0, is_active=1)"]
    AdminApprove -- Reject/Delete --> SoftDelete["User soft-deleted\n(is_deleted=1)"]
    Active --> Auth
    SelfReg -- No --> Auth

    Auth{"Check Credentials\n+ is_active check"} -- Fail --> Start
    Auth -- Success --> RoleRouter{"Route by Role"}

    RoleRouter -- "Administrator / Manager" --> Dash["Dashboard\n(KPI Cards + Charts)"]
    RoleRouter -- SalesCashier --> Sales["Sales Page"]
    RoleRouter -- InventoryOfficer --> Inventory["Inventory Page"]
    RoleRouter -- OnlineAgent --> Quotation["Quotation Maker"]

    %% ── DASHBOARD ──────────────────────────────────────────────────
    Dash --> DashLinks{"Sidebar Navigation"}
    DashLinks --> Inventory
    DashLinks --> Sales
    DashLinks --> Quotation
    DashLinks --> Reports["Reports & Analytics"]
    DashLinks --> Prediction["Stock Prediction"]
    DashLinks --> Commissions["Agent Commissions"]
    DashLinks --> AuditLog["Audit Log Viewer"]
    DashLinks --> UserMgmt["User Management"]
    DashLinks --> StockMov["Stock Movements Log"]
    DashLinks --> Categories["Categories"]

    %% ── INVENTORY ──────────────────────────────────────────────────
    Inventory --> AddProd["Add / Edit Product\n(w/ agent_price)"]
    AddProd --> DBProd[("Products DB")]
    DBProd --> TriggerMoveIN["Log Movement: IN"]
    TriggerMoveIN --> MoveDB[("Stock Movements DB")]
    TriggerMoveIN --> AuditDB[("Audit Log DB")]
    Inventory --> AdjStock["Manual Stock Adjustment\nIN / OUT / ADJUSTMENT"]
    AdjStock --> DBProd
    AdjStock --> MoveDB
    AdjStock --> AuditDB

    %% ── QUOTATION → SALES QUEUE ──────────────────────────────────
    Quotation --> QuoteBuilder["Build Quote\n(select product + qty + price)"]
    QuoteBuilder --> StockCheckAJAX{"AJAX Stock Check\napi/check-stock.php"}
    StockCheckAJAX -- Available --> QuoteReady["Quote Ready\n(no DB write yet)"]
    StockCheckAJAX -- Unavailable --> QuoteReady
    QuoteReady --> DownloadImg["Download Quote\nas PNG Image\n(html2canvas)"]
    QuoteReady --> BookOrder{"Send to Sales Queue?\n(Requires Payment Type,\nRef No, & Liability Check)"}
    BookOrder -- Stock OK --> PendingOrder["Create Sale\nstatus=PendingOrder\n(stock reserved)"]
    BookOrder -- Stock Low --> RejectBook["Reject Booking"]
    PendingOrder --> SaleDB[("Sales DB")]
    PendingOrder --> MoveDB
    PendingOrder --> AuditDB

    %% ── DIRECT SALE ───────────────────────────────────────────────
    Sales --> NewSaleForm["New Sale Entry\n(cart + invoice + date\n+ payment type/ref + liability check)"]
    NewSaleForm --> StockValidate{"Validate Stock\nfor all cart items"}
    StockValidate -- Fail --> SaleErr["Flash Error\n(insufficient stock)"]
    StockValidate -- Pass --> RecordSale["Insert Sale + Items\nstatus=Valid"]
    RecordSale --> DeductStock["Deduct current_stock"]
    DeductStock --> DBProd
    RecordSale --> TriggerSALE["Log Movement: SALE"]
    TriggerSALE --> MoveDB
    RecordSale --> SaleDB
    RecordSale --> AuditDB

    %% ── PENDING ORDER LIFECYCLE ───────────────────────────────────
    Sales --> PendingMgmt["Sales History\n(filter PendingOrder)"]
    PendingMgmt --> CompletePending["Complete Order\n→ status=Valid"]
    PendingMgmt --> CancelPending["Cancel Order\n→ status=Voided\n(stock restored)"]
    CompletePending --> SaleDB
    CancelPending --> DBProd
    CancelPending --> MoveDB

    %% ── VOID WORKFLOW ────────────────────────────────────────────
    Sales --> ValidSale["Valid Sale in History"]
    ValidSale --> RequestVoid["Cashier: Request Void\n→ status=VoidPending"]
    RequestVoid --> AdminDecision{"Manager/Administrator Reviews"}
    AdminDecision -- "Confirm Void" --> VoidedSale["status=Voided\n(stock restored)"]
    AdminDecision -- "Confirm Return" --> ReturnedSale["status=Returned\n(stock restored)"]
    AdminDecision -- "Reject" --> ValidAgain["status=Valid\n(no change)"]
    VoidedSale --> DBProd
    ReturnedSale --> DBProd
    VoidedSale --> AuditDB
    ReturnedSale --> AuditDB

    %% ── COMMISSIONS ───────────────────────────────────────────────
    Commissions --> CommDash["Per-Agent Summary\n(Unpaid Commission)"]
    CommDash --> BreakdownModal["Item-Level Breakdown\n(AJAX api/commission_breakdown.php)"]
    CommDash --> AgentPriceEditor["Agent Price Editor\n(sets products.agent_price)"]
    CommDash --> MarkPaid["Mark as Paid\n(commission_cleared=1)\n+ Insert Payout Record"]
    MarkPaid --> CommPayoutsDB[("agent_commission_payouts DB")]
    MarkPaid --> AuditDB

    %% ── PREDICTION ────────────────────────────────────────────────
    Prediction --> MovingAvg["30-day Moving Avg\n(only Valid sales)"]
    MovingAvg --> RiskLevel{"Risk Level"}
    RiskLevel -- "≤7 days" --> Critical["CRITICAL badge"]
    RiskLevel -- "≤14 days" --> Warning["WARNING badge"]
    RiskLevel -- ">14 days" --> OK["OK badge"]
```

---

## 3 — Data Flow Diagrams (Level 0 to 2)

### Level 0: Context Diagram
```mermaid
flowchart LR
    %% External Entities (Squares)
    ADMIN["Manager / Administrator"]
    IO["Inventory Officer"]
    CASHIER["Sales Cashier"]
    AGENT["Online Agent"]

    %% Central Process (Rounded rectangle with line)
    SYS("0.0\nStockBoard System")

    %% Data Flows
    ADMIN -->|Manage Users, Products, Voids, Comm., Reports| SYS
    SYS -->|System Alerts & Dashboards| ADMIN
    IO -->|Manage Products, Stock Adjustments| SYS
    SYS -->|Inventory Levels| IO
    CASHIER -->|Record Sales, Request Voids| SYS
    SYS -->|Sale Confirmations| CASHIER
    AGENT -->|Create Quotations, Book Orders| SYS
    SYS -->|Quotation PDFs, Stock Checks| AGENT
    
    %% Style to match reference image (White background, black borders)
    classDef default fill:#ffffff,stroke:#000000,stroke-width:1px,color:#000000
```

### Level 1: High-Level Subsystems
```mermaid
flowchart TD
    %% External Entities
    ADMIN["Manager / Administrator"]
    IO["Inventory Officer"]
    CASHIER["Sales Cashier"]
    AGENT["Online Agent"]

    %% Processes (Rounded boxes with hr line)
    P1("1.0\nManage Users")
    P2("2.0\nManage Inventory")
    P3("3.0\nProcess Sales")
    P4("4.0\nManage Commissions")

    %% Data Stores
    DS1[("D1 Users")]
    DS2[("D2 Products")]
    DS3[("D3 Sales")]
    DS4[("D4 Commissions")]

    %% Flows
    ADMIN -->|Account Approvals| P1
    P1 -->|User Details| DS1
    DS1 -->|Authentication| P1

    IO -->|Product & Stock Data| P2
    P2 -->|Inventory Updates| DS2
    DS2 -->|Stock Alerts| IO

    AGENT -->|Pending Orders| P3
    CASHIER -->|Direct Sales Data| P3
    DS2 -->|Product Availability| P3
    P3 -->|Sale Records| DS3
    DS3 -->|Receipts| CASHIER

    ADMIN -->|Payout Instructions| P4
    DS3 -->|Sales Totals| P4
    P4 -->|Commission Payouts| DS4
    DS4 -->|Payout Summaries| ADMIN

    %% Style to match reference image
    classDef default fill:#ffffff,stroke:#000000,stroke-width:1px,color:#000000
```

### Level 2: Sales & Orders Subsystem (Process 3.0)
```mermaid
flowchart TD
    %% External Entities
    CASHIER["Sales Cashier"]
    AGENT["Online Agent"]
    ADMIN["Manager / Administrator"]

    %% Processes
    P3_1("3.1\nBuild Quotation")
    P3_2("3.2\nBook Pending Order")
    P3_3("3.3\nRecord Direct Sale")
    P3_4("3.4\nManage Voids")

    %% Data Stores
    DS2[("D2 Products")]
    DS3[("D3 Sales")]

    %% Flows
    AGENT -->|Selected Items| P3_1
    DS2 -->|Stock Check| P3_1
    P3_1 -->|Quotation Details| P3_2
    AGENT -->|Liability Confirmation| P3_2
    P3_2 -->|Pending Sale Record| DS3
    
    CASHIER -->|Items & Payment| P3_3
    P3_3 -->|Valid Sale Record| DS3
    P3_3 -->|Stock Deduction| DS2

    CASHIER -->|Void Request| P3_4
    ADMIN -->|Void Approval| P3_4
    DS3 -->|Sale History| P3_4
    P3_4 -->|Status Update| DS3
    P3_4 -->|Stock Restoration| DS2

    %% Style to match reference image
    classDef default fill:#ffffff,stroke:#000000,stroke-width:1px,color:#000000
```


---

## Key Changes from Original Diagrams

| Area | Original | Current (v2) |
|---|---|---|
| **Roles** | Generic string field | ENUM: `Administrator`, `Manager`, `OnlineAgent`, `SalesCashier`, `InventoryOfficer` |
| **Users table** | `is_active`, basic fields | + `is_pending`, `is_deleted`, `avatar`, `last_seen`, `updated_at` |
| **Products table** | `cost_price`, `selling_price`, `thickness`, `size` | + `agent_price`, `is_active`, `updated_at`; removed `thickness`/`size` |
| **Sales table** | No status field | + `status` ENUM (`Valid`, `VoidPending`, `Voided`, `Returned`, `PendingOrder`), `payment_type`, `payment_reference` |
| **Sale Items table** | Basic fields | + `commission_cleared` flag |
| **New table** | — | `agent_commission_payouts` (agent_id, cleared_by, amount, note) |
| **Quotation** | Not in original | Full Quotation Maker → Sales Queue booking workflow |
| **Void workflow** | Simple void | Request → Manager Approve (Void/Return) or Reject |
| **Commission module** | — | Full dashboard: per-agent summary, item breakdown, agent price editor, payout history |
| **Stock prediction** | — | 30-day moving average engine (Valid sales only, excludes Voided/Returned/Pending) |
| **Self-registration** | — | `is_pending` queue; Manager approves/rejects pending accounts |
| **Stock movement types** | `IN`, `OUT`, `ADJUSTMENT`, `SALE` | Same, but now explicit: `SALE` on direct sale, `OUT` on pending reservation, `IN` on cancel restore, `ADJUSTMENT` on void restore |
