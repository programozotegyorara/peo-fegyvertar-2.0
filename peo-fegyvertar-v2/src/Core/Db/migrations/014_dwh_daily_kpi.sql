-- DWH: pre-calculated daily KPIs. Full 17-column schema verified against
-- the live 1.0 PEOFT_DWH_DAILY_KPI table.

CREATE TABLE IF NOT EXISTS `{prefix}peoft_dwh_daily_kpi` (
    kpi_date              DATE          NOT NULL,
    active_subs           INT           NULL,
    active_monthly        INT           NULL DEFAULT 0,
    active_yearly         INT           NULL DEFAULT 0,
    active_trial          INT           NULL DEFAULT 0,
    churn_count_30        INT           NULL,
    churn_rate            DECIMAL(10,2) NULL,
    arpu                  DECIMAL(10,2) NULL,
    mrr                   DECIMAL(10,2) NULL,
    ltv                   DECIMAL(10,2) NULL,
    acl                   DECIMAL(10,2) NULL,
    purchases_count       INT           NULL DEFAULT 0,
    trial_to_active_count INT           NULL DEFAULT 0,
    cancellations_count   INT           NULL DEFAULT 0,
    ended_count           INT           NULL DEFAULT 0,
    trial_created_count   INT           NULL DEFAULT 0,
    trial_canceled_count  INT           NULL DEFAULT 0,
    created_at            DATETIME      NULL,
    PRIMARY KEY (kpi_date)
) {charset_collate};
