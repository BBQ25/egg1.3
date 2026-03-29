# Egg Monitoring ERD

## Diagram (Mermaid)

```mermaid
erDiagram
    USERS {
        INT id PK
        VARCHAR full_name
        VARCHAR username UK
        VARCHAR password_hash
        ENUM role
        BOOLEAN is_active
        TIMESTAMP deactivated_at
        TIMESTAMP created_at
    }

    FARMS {
        INT id PK
        VARCHAR farm_name
        VARCHAR location
        VARCHAR sitio
        VARCHAR barangay
        VARCHAR municipality
        VARCHAR province
        DECIMAL latitude
        DECIMAL longitude
        INT owner_user_id FK
        BOOLEAN is_active
        TIMESTAMP created_at
        TIMESTAMP updated_at
    }

    EGG_ITEMS {
        INT id PK
        INT farm_id FK
        VARCHAR item_code
        VARCHAR egg_type
        ENUM size_class
        DECIMAL unit_cost
        DECIMAL selling_price
        INT reorder_level
        INT current_stock
        TIMESTAMP created_at
        TIMESTAMP updated_at
    }

    STOCK_MOVEMENTS {
        BIGINT id PK
        INT item_id FK
        ENUM movement_type
        INT quantity
        INT stock_before
        INT stock_after
        DECIMAL unit_cost
        VARCHAR reference_no
        VARCHAR notes
        DATE movement_date
        TIMESTAMP created_at
    }

    EGG_INTAKE_RECORDS {
        BIGINT id PK
        INT farm_id FK
        INT item_id FK
        BIGINT movement_id FK
        ENUM source
        VARCHAR egg_type
        VARCHAR size_class
        DECIMAL weight_grams
        INT quantity
        INT stock_before
        INT stock_after
        VARCHAR reference_no
        VARCHAR notes
        TEXT payload_json
        INT created_by_user_id FK
        TIMESTAMP recorded_at
        TIMESTAMP created_at
    }

    FARM_STAFF_ASSIGNMENTS {
        INT id PK
        INT farm_id FK
        INT user_id FK
        TIMESTAMP created_at
    }

    APP_SETTINGS {
        VARCHAR setting_key PK
        VARCHAR setting_value
        TIMESTAMP updated_at
    }

    USERS ||--o{ FARMS : "owner_user_id"
    FARMS ||--o{ EGG_ITEMS : "farm_id"
    EGG_ITEMS ||--o{ STOCK_MOVEMENTS : "item_id"
    FARMS ||--o{ EGG_INTAKE_RECORDS : "farm_id"
    EGG_ITEMS ||--o{ EGG_INTAKE_RECORDS : "item_id"
    STOCK_MOVEMENTS ||--o{ EGG_INTAKE_RECORDS : "movement_id"
    USERS ||--o{ EGG_INTAKE_RECORDS : "created_by_user_id"
    FARMS ||--o{ FARM_STAFF_ASSIGNMENTS : "farm_id"
    USERS ||--o{ FARM_STAFF_ASSIGNMENTS : "user_id"
```

## FK Map

| Child table | Child column | Parent table | Parent column | On update | On delete |
|---|---|---|---|---|---|
| `farms` | `owner_user_id` | `users` | `id` | `CASCADE` | `SET NULL` |
| `egg_items` | `farm_id` | `farms` | `id` | `CASCADE` | `CASCADE` |
| `stock_movements` | `item_id` | `egg_items` | `id` | `CASCADE` | `CASCADE` |
| `egg_intake_records` | `farm_id` | `farms` | `id` | `CASCADE` | `CASCADE` |
| `egg_intake_records` | `item_id` | `egg_items` | `id` | `CASCADE` | `CASCADE` |
| `egg_intake_records` | `movement_id` | `stock_movements` | `id` | `CASCADE` | `CASCADE` |
| `egg_intake_records` | `created_by_user_id` | `users` | `id` | `CASCADE` | `SET NULL` |
| `farm_staff_assignments` | `farm_id` | `farms` | `id` | `CASCADE` | `CASCADE` |
| `farm_staff_assignments` | `user_id` | `users` | `id` | `CASCADE` | `CASCADE` |
