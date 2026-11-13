# Online Marriage Registration System (OMRS)

A complete PHP & MySQL web application for managing online marriage registrations, fully upgraded for **PHP 8.2+**, **Aiven Cloud MySQL (SSL)**, and **Render Cloud Deployment** via Docker.

---

## 🌟 Key Features & Cloud Architecture

- **Dockerized for Render**: Built on `php:8.2-apache` with dynamic `$PORT` routing (default 10000).
- **Reverse-Proxy & Port 10000 Fix**: Configured Apache `UseCanonicalName Off` and `UseCanonicalPhysicalPort Off` to prevent internal port leaks in redirects.
- **Aiven Cloud MySQL SSL**: Integrated `MYSQLI_CLIENT_SSL` encryption for secure transport (`require_secure_transport=ON`).
- **PHP 8.x Safe**: Zero deprecation/fatal errors; safe property initialization, graceful MySQLi error reporting, and table null-checks.
- **1-Click Web Database Importer**: Built-in visual installer (`db_setup.php`) to test SSL connectivity, inspect tables, and import the SQL dump with one click.

---

## 🚀 Live Render Deployment Guide

### 1. Push to GitHub
Deploy repository to GitHub:
```bash
git push -u origin main
```

### 2. Create Web Service on Render
1. Go to [Render Dashboard](https://dashboard.render.com/) -> **New** -> **Web Service**.
2. Connect your GitHub repository: `maheshchouhanmsk12-sketch/Online-Marriage-Registration-System`.
3. Choose **Docker** environment.
4. Set the following **Environment Variables**:

| Variable | Description | Example Value |
|---|---|---|
| `DB_SERVER` | Aiven MySQL Hostname | `mysql-xxxx.aivencloud.com` |
| `DB_PORT` | Aiven MySQL Custom Port | `26284` |
| `DB_USERNAME` | Aiven MySQL Username | `avnadmin` |
| `DB_PASSWORD` | Aiven MySQL Password | `your_secret_password` |
| `DB_NAME` | Database Name | `defaultdb` (or `omrs_db`) |
| `DB_SSL` | Enable SSL for Aiven | `true` |
| `BASE_URL` | Render Public App URL | `https://online-marriage-registration.onrender.com/` |

---

## 🛠️ 1-Click Database Setup (`db_setup.php`)

Once the Render service is deployed, initialize your database:
1. Open your browser and navigate to:
   ```
   https://<your-render-url>/db_setup.php
   ```
2. The tool will automatically perform a health check on your Aiven Cloud MySQL database (verifying SSL cipher, host, and port).
3. Click the **"1-Click Import Database"** button to automatically run the `omrs_db.sql` schema and seeds.
4. Once verified, click **"Go to Admin Login"** or **"Go to Website Home"**.

---

## 🔐 Default Access Credentials

### Admin Portal
- **Login URL**: `https://<your-render-url>/admin/login.php`
- **Username**: `admin`
- **Password**: `admin123`
- **Role**: Administrator (Full Access)

### Client Portal
- **Client Login URL**: `https://<your-render-url>/login.php`
- **Registration URL**: `https://<your-render-url>/registration.php`
- **Demo Client 1**: `mwilliams@sample.com`
- **Demo Client 2**: `slou@sample.com`

---

## 💻 Local Development with Docker

Run locally with Docker:
```bash
# Build the Docker image
docker build -t omrs-app .

# Run container on port 10000 with Aiven Cloud MySQL credentials
docker run -p 10000:10000 \
  -e DB_SERVER="mysql-xxxx.aivencloud.com" \
  -e DB_PORT="26284" \
  -e DB_USERNAME="avnadmin" \
  -e DB_PASSWORD="your_password" \
  -e DB_NAME="defaultdb" \
  -e DB_SSL="true" \
  omrs-app
```
Access at `http://localhost:10000/`.

---

## 📁 Repository Structure & Safety Backups

All modified original files have safe `.original` backups preserved:
- `initialize.php.original`
- `config.php.original`
- `classes/DBConnection.php.original`
- `classes/SystemSettings.php.original`
- `classes/Login.php.original`
- `dist/js/script.js.original`
- `.htaccess.original`
- `admin/.htaccess.original`
- `admin/inc/sess_auth.php.original`
- `inc/sess_auth.php.original`
- `admin/login.php.original`
- `login.php.original`
- `registration.php.original`

