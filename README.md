
# Setup Instructions


## Demo Video

<div align="center">
    <video controls width="800" poster="assets/demo-poster.png">
        <div align="center">
            <iframe width="800" height="450" src="https://www.youtube.com/embed/XQSIWdA6uDs" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
        </div>
    </video>
</div>

<!-- Alternatively, embed a YouTube demo (replace VIDEO_ID) -->
<p align="center">
    <iframe width="800" height="450" src="https://www.youtube.com/embed/VIDEO_ID" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
</p>

Replace assets/demo.mp4 or VIDEO_ID with your actual demo file or YouTube ID.


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
