# XScore Database Configuration Guide

## Automatic Environment Detection
XScore now automatically detects whether it's running in local development or production and uses the appropriate database connection without any manual configuration.

## Local Development (XAMPP)
When running locally, the system uses:
- Host: localhost
- User: root
- Password: (empty)
- Database: XScore

## Production Deployment
When deployed online, the system automatically detects and uses:
- Host: mysql8003.site4now.net
- User: ac41df_xscore
- Password: BeybladeX4Ever!
- Database: db_ac41df_xscore

## Detection Logic
The system checks multiple indicators to determine the environment:
- Domain name (site4now.net, xscore, .com)
- Server IP (not 127.0.0.1 or localhost)
- Server name (not localhost)
- Document path (not containing 'htdocs')

## Database Schema Files
Two schema files are available:

### Local Development
- Use `db/schema.sql` for local XAMPP installation
- Creates database: `XScore`

### Production Deployment
- Use `db/production_schema.sql` for production server
- Creates database: `db_ac41df_xscore`

## Import Instructions

### Local Development
```sql
-- Import db/schema.sql into your local MySQL
mysql -u root -p < db/schema.sql
```

### Production Deployment
```sql
-- Import db/production_schema.sql into production MySQL
mysql -h mysql8003.site4now.net -u ac41df_xscore -p db_ac41df_xscore < db/production_schema.sql
```

## Debug Logging
Check your error logs to verify which database is being used:
- Local: "Database Connection: LOCAL -> XScore"
- Production: "Database Connection: PRODUCTION -> db_ac41df_xscore"

## No Manual Configuration Required
Simply upload the files to your production server or run them locally - the system will automatically use the correct database connection.
