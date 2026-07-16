-- LombokClarion least-privilege DB roles (PostgreSQL) — master prompt §7.
-- Run once as a superuser. App runtime NEVER gets DDL; migrations use a separate role.
--   psql -U postgres -d app -v app_pw='...' -v mig_pw='...' -f deploy/db-roles.sql

CREATE ROLE lc_app     LOGIN PASSWORD :'app_pw';
CREATE ROLE lc_migrate LOGIN PASSWORD :'mig_pw';

-- Runtime: DML only, on current and future tables.
GRANT CONNECT ON DATABASE app TO lc_app;
GRANT USAGE ON SCHEMA public TO lc_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO lc_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO lc_app;

-- Migrations: owns the schema, may run DDL. `lombokclarion migrate` connects as this role.
GRANT CONNECT ON DATABASE app TO lc_migrate;
GRANT ALL ON SCHEMA public TO lc_migrate;
ALTER DEFAULT PRIVILEGES FOR ROLE lc_migrate IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO lc_app;
ALTER DEFAULT PRIVILEGES FOR ROLE lc_migrate IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO lc_app;
