
# Setup Instructions


## Demo Video

<p align="center">
    <a href="https://youtu.be/XQSIWdA6uDs" target="_blank" rel="noopener">
        <img src="https://img.youtube.com/vi/XQSIWdA6uDs/hqdefault.jpg" alt="Demo Video" width="800" />
    </a>
</p>


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
symfony server:start
```
