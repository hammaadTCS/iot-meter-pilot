# Entity Relationship Diagram

```mermaid
erDiagram
    USERS {
        bigint id PK
        string name
        string email UK
        string password
        string cnic
        string phone_number
        string address
        enum role "user | admin | super_admin"
        timestamp email_verified_at
        timestamps created_at
    }

    DEVICES {
        bigint id PK
        bigint user_id FK
        string name
        string code UK
        string type
        string mqtt_topic
        string availability_topic
        timestamp last_seen_at
        timestamp last_message_at
        string last_error_code
        string last_error_message
        json last_error_context
        timestamp last_error_at
        timestamp last_recovered_at
        string last_availability_status
        string last_availability_message
        json last_availability_context
        timestamp last_availability_at
        timestamp last_heartbeat_at
        timestamps created_at
    }

    METER_READINGS {
        bigint id PK
        bigint device_id FK
        timestamp ts
        timestamp received_at
        decimal voltage
        decimal current
        decimal power
        decimal energy_computed_wh
        decimal energy_pzem_wh
        decimal frequency
        decimal pf
        json raw_payload
    }

    LATEST_METER_STATES {
        bigint id PK
        bigint device_id FK "unique"
        timestamp ts
        timestamp received_at
        decimal voltage
        decimal current
        decimal power
        decimal energy_computed_wh
        decimal energy_pzem_wh
        decimal frequency
        decimal pf
        json raw_payload
    }

    METER_INGESTION_EVENTS {
        bigint id PK
        bigint device_id FK
        string topic
        enum status "stored | duplicate | out_of_order | invalid_json | payload_invalid | unknown_topic"
        string error_code
        string error_message
        string payload_preview
        json context
        timestamp received_at
    }

    METER_ALERT_EVENTS {
        bigint id PK
        bigint device_id FK
        enum alert_type "telemetry_stale | telemetry_down"
        enum severity "warning | critical"
        enum status "open | resolved"
        string message
        json context
        timestamp triggered_at
        timestamp resolved_at
    }

    USERS ||--o{ DEVICES : "owns"
    DEVICES ||--o{ METER_READINGS : "has many"
    DEVICES ||--|| LATEST_METER_STATES : "has one"
    DEVICES ||--o{ METER_INGESTION_EVENTS : "has many"
    DEVICES ||--o{ METER_ALERT_EVENTS : "has many"
```

## Notes

- `LATEST_METER_STATES` is a 1-to-1 cache of the most recent reading per device — exists purely for fast dashboard reads without scanning full history.
- `METER_INGESTION_EVENTS` is an audit log — every MQTT message decision is recorded regardless of outcome (stored, duplicate, invalid, etc.).
- When new device types are added (AC, WMS), a decision is needed: reuse `meter_readings` with nullable columns, or create separate `ac_readings` / `wms_readings` tables.
