
# Setup Instructions


## Demo Video

<!-- Alternatively, embed a YouTube demo (replace VIDEO_ID) -->
<p align="center">
    <iframe width="800" height="450" src="https://www.youtube.com/embed/XQSIWdA6uDs" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
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
symfony start
```
