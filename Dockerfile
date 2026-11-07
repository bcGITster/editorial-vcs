FROM php:8.2-apache

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_mysql

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY src/ /var/www/html/
COPY config.example.php /var/www/html/
COPY schema.sql /var/www/html/

# Create config.php that reads from environment variables
RUN cat > /var/www/html/config.php << 'EOFPHP'
<?php
return [
    'db' => [
        'host'     => getenv('DB_HOST') ?: 'db',
        'name'     => getenv('DB_NAME') ?: 'editorial_vcs',
        'user'     => getenv('DB_USER') ?: 'editorial_user',
        'password' => getenv('DB_PASS') ?: '',
        'charset'  => 'utf8mb4',
    ],
    'app' => [
        'title'           => getenv('APP_TITLE') ?: 'Editorial VCS',
        'timezone'        => getenv('APP_TIMEZONE') ?: 'UTC',
        'max_upload_size' => 10 * 1024 * 1024,
    ],
    'features' => [
        'lore_bible'         => getenv('FEATURE_LORE_BIBLE') !== 'false',
        'relationship_graph' => getenv('FEATURE_GRAPH') !== 'false',
    ],
    'security' => [
        'trust_proxy_auth'  => getenv('TRUST_PROXY_AUTH') === 'true',
        'proxy_auth_header' => getenv('PROXY_AUTH_HEADER') ?: 'X-Forwarded-User',
    ],
];
EOFPHP

# Configure Apache
RUN cat > /etc/apache2/conf-available/editorial-vcs.conf << 'EOF'
<Directory /var/www/html>
    AllowOverride All
    Require all granted
</Directory>
EOF
RUN a2enconf editorial-vcs

# Set permissions
RUN chown -R www-data:www-data /var/www/html

# Expose port
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=3s \
    CMD curl -f http://localhost/ || exit 1
