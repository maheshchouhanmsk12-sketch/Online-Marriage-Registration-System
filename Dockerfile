FROM php:8.2-apache

# Install required system packages and PHP extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) mysqli pdo pdo_mysql gd zip \
    && a2enmod rewrite headers \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Set working directory
WORKDIR /var/www/html

# Copy all project files into web root
COPY . /var/www/html/

# Copy startup script, strip potential CRLF line endings, set executable
COPY start.sh /usr/local/bin/start.sh
RUN tr -d '\r' < /usr/local/bin/start.sh > /usr/local/bin/start_clean.sh && \
    mv /usr/local/bin/start_clean.sh /usr/local/bin/start.sh && \
    chmod +x /usr/local/bin/start.sh && \
    chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html

# Expose Render standard container port
EXPOSE 10000

# Start container via start.sh
CMD ["/usr/local/bin/start.sh"]
