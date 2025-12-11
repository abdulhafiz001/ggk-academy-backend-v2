# Deployment Guide for cPanel

This guide covers deploying the GGK Academy backend to cPanel.

## Prerequisites

- Git repository pushed to GitHub
- cPanel access with terminal/SSH access
- Database created in cPanel
- PHP 8.2+ installed

## Deployment Steps

### 1. Clone the Repository

```bash
cd ~/public_html  # or your document root
git clone <your-repo-url> ggk-academy-api
cd ggk-academy-api/ggk-academy-portal-backend
```

### 2. Install Dependencies

```bash
composer install --no-dev --optimize-autoloader
```

### 3. Environment Configuration

```bash
cp .env.example .env
```

Edit `.env` file with your production settings:

```env
APP_NAME="GGK Academy"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://ggkacademyapi.termresult.com

# Database Configuration
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database_name
DB_USERNAME=your_database_user
DB_PASSWORD=your_database_password

# CORS Configuration - IMPORTANT!
# Add your frontend URL(s) here (comma-separated if multiple)
CORS_ALLOWED_ORIGINS=https://your-frontend.vercel.app,https://www.yourdomain.com
FRONTEND_URL=https://your-frontend.vercel.app

# Logging
LOG_LEVEL=error
```

### 4. Generate Application Key

```bash
php artisan key:generate
```

### 5. Run Migrations

```bash
php artisan migrate --force
```

### 6. Set Permissions

```bash
chmod -R 775 storage
chmod -R 775 bootstrap/cache
```

### 7. Clear and Cache Configuration (IMPORTANT for CORS!)

```bash
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
```

**Note:** Always run `php artisan config:clear` after changing `.env` file, especially CORS settings!

### 8. Optimize for Production (Optional but Recommended)

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

**Important:** If you need to change CORS settings later, run `php artisan config:clear` first, then update `.env`, then run `php artisan config:cache` again.

## CORS Configuration

The CORS configuration is now environment-based to prevent issues. It reads from:

1. `CORS_ALLOWED_ORIGINS` - Comma-separated list of allowed origins
2. `FRONTEND_URL` - Single frontend URL (fallback)

**To add/update allowed origins:**

1. Edit `.env` file:
   ```env
   CORS_ALLOWED_ORIGINS=https://your-frontend.vercel.app,https://www.yourdomain.com
   ```

2. Clear config cache:
   ```bash
   php artisan config:clear
   ```

3. Re-cache config (optional):
   ```bash
   php artisan config:cache
   ```

## Troubleshooting CORS Issues

If you experience CORS errors after deployment:

1. **Clear config cache:**
   ```bash
   php artisan config:clear
   ```

2. **Verify `.env` file has correct CORS settings:**
   ```bash
   cat .env | grep CORS
   ```

3. **Check that your frontend URL is in the allowed origins list**

4. **Verify the config is reading correctly:**
   ```bash
   php artisan tinker
   >>> config('cors.allowed_origins')
   ```

5. **Clear all caches:**
   ```bash
   php artisan config:clear
   php artisan cache:clear
   php artisan route:clear
   ```

## Storage Link

You mentioned you don't think `php artisan storage:link` is needed. This is correct if:
- You're not serving files from `storage/app/public` via the web
- All file uploads/downloads are handled through API endpoints

If you need to serve files publicly (e.g., user avatars, documents), run:
```bash
php artisan storage:link
```

## Post-Deployment Checklist

- [ ] Environment variables configured correctly
- [ ] Database connection working
- [ ] Migrations completed successfully
- [ ] Storage and cache directories have correct permissions (775)
- [ ] Config cache cleared and re-cached
- [ ] CORS origins configured correctly
- [ ] Frontend can successfully make API requests
- [ ] Error logging is working (check `storage/logs/laravel.log`)

## Updating the Application

When pulling updates from GitHub:

```bash
git pull origin main
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:clear
php artisan cache:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## Security Notes

- Never commit `.env` file to Git
- Keep `APP_DEBUG=false` in production
- Use strong database passwords
- Regularly update dependencies: `composer update --no-dev`
- Monitor `storage/logs/laravel.log` for errors

