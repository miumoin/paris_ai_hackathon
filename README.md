
# Setup Instructions

## Environment Configuration
```bash
cp .env.example .env
```
Edit `.env` and add your required credentials.

## Database Setup
```bash
# Create database
php bin/console doctrine:database:create

# Import dump file
mysql -u [user] -p [database] < dump.sql
```

## Build & Run
```bash
npm run build
symfony start
```
