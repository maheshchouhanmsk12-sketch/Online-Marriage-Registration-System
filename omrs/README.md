# Online Marriage Registration System (OMRS)

A complete PHP & MySQL web application for managing online marriage registrations, fully upgraded for **PHP 8.2+**, **Aiven Cloud MySQL (SSL)**, and **Render Cloud Deployment** via Docker.

---

## 🌐 Live Application Links

- **Live Web App**: [https://online-marriage-registration-system.onrender.com/](https://online-marriage-registration-system.onrender.com/)
- **Admin Login Portal**: [https://online-marriage-registration-system.onrender.com/admin/login.php](https://online-marriage-registration-system.onrender.com/admin/login.php)
- **Client Login Portal**: [https://online-marriage-registration-system.onrender.com/login.php](https://online-marriage-registration-system.onrender.com/login.php)
- **New User Registration**: [https://online-marriage-registration-system.onrender.com/registration.php](https://online-marriage-registration-system.onrender.com/registration.php)
- **1-Click Database Setup Tool**: [https://online-marriage-registration-system.onrender.com/db_setup.php](https://online-marriage-registration-system.onrender.com/db_setup.php)

---

## 🔐 Login Credentials (IDs & Passwords)

### 👑 1. Administrator Portal
Access full management features (approve/reject marriage applications, manage users, update system settings):
- **URL**: `https://online-marriage-registration-system.onrender.com/admin/login.php`
- **Username**: `admin`
- **Password**: `admin123`
- **Role**: Administrator (Full Access)

---

### 👤 2. Client / Couple Portal
Access marriage registration dashboard, submit new marriage applications, view certificates and status:
- **URL**: `https://online-marriage-registration-system.onrender.com/login.php`

| Account | Email | Password |
|---|---|---|
| **Demo Client 1** | `slou@sample.com` | `slou123` *(or `admin123`)* |
| **Demo Client 2** | `mwilliams@sample.com` | `mwilliams` *(or `admin123`)* |
| **Direct Admin Bypass** | `admin` | `admin123` |

> [!TIP]
> **Universal Login Feature**: If you enter `admin` and `admin123` on the client login page (`login.php`), the system will automatically recognize your admin privileges and redirect you straight to the Admin Dashboard!

---

## 🌟 Key Features & Cloud Architecture

- **Dockerized for Render**: Built on `php:8.2-apache` with dynamic `$PORT` routing (default 10000).
- **Reverse-Proxy & Port 10000 Fix**: Configured Apache `UseCanonicalName Off` and `UseCanonicalPhysicalPort Off` to prevent internal port leaks in redirects.
- **Aiven Cloud MySQL SSL**: Integrated `MYSQLI_CLIENT_SSL` encryption for secure transport (`require_secure_transport=ON`).
- **PHP 8.x Safe**: Zero deprecation/fatal errors; safe property initialization, graceful MySQLi error reporting, and table null-checks.
- **Database Auto-Healer**: Automatically verifies and provisions missing tables and users upon startup.
- **1-Click Web Database Importer**: Built-in visual installer (`db_setup.php`) to test SSL connectivity, create dedicated databases (`omrs_db`), and import SQL dumps with one click.

---

## 🚀 Live Render Deployment Configuration

When setting up or updating the service on Render:

| Variable | Description | Example Value |
|---|---|---|
| `DB_SERVER` | Aiven MySQL Hostname | `mysql-xxxx.aivencloud.com` |
| `DB_PORT` | Aiven MySQL Custom Port | `26284` |
| `DB_USERNAME` | Aiven MySQL Username | `avnadmin` |
| `DB_PASSWORD` | Aiven MySQL Password | `your_secret_password` |
| `DB_NAME` | Database Name | `omrs_db` *(or `defaultdb`)* |
| `DB_SSL` | Enable SSL for Aiven | `true` |
| `BASE_URL` | Render Public App URL | `https://online-marriage-registration-system.onrender.com/` |

---

## 🛠️ 1-Click Database Setup (`db_setup.php`)

If you want to create a new clean database or reset tables:
1. Open your browser and navigate to:
   ```
   https://online-marriage-registration-system.onrender.com/db_setup.php
   ```
2. The tool will automatically perform a health check on your Aiven Cloud MySQL database (verifying SSL cipher, host, and port).
3. Choose **Option 1** to create a fresh dedicated `omrs_db` database, or **Option 2** to wipe and re-import into the current active database.
4. Once verified, click **"Go to Admin Login"** or **"Go to Client Login"**.

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
  -e DB_NAME="omrs_db" \
  -e DB_SSL="true" \
  omrs-app
```
Access locally at `http://localhost:10000/`.

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
