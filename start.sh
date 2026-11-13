#!/bin/bash
set -e

# Default port to 10000 (Render standard) or $PORT if assigned dynamically
PORT="${PORT:-10000}"
echo "Configuring Apache to listen on port ${PORT}..."

# Update ports.conf and default site with Render assigned port
sed -i "s/Listen 80/Listen ${PORT}/g" /etc/apache2/ports.conf
sed -i "s/:80/:${PORT}/g" /etc/apache2/sites-available/000-default.conf

# Reverse Proxy & Port 10000 Leak Prevention (CRITICAL)
echo "ServerName localhost" >> /etc/apache2/apache2.conf
echo "UseCanonicalName Off" >> /etc/apache2/apache2.conf
echo "UseCanonicalPhysicalPort Off" >> /etc/apache2/apache2.conf

# Enable AllowOverride All for /var/www/ so .htaccess rules are processed
sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Set permissions for web root and uploads
chown -R www-data:www-data /var/www/html
chmod -R 755 /var/www/html
if [ -d "/var/www/html/uploads" ]; then
    chmod -R 777 /var/www/html/uploads
fi

echo "Starting Apache web server on port ${PORT}..."
exec apache2-foreground

