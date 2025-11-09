# Use a standard PHP runtime image (e.g., PHP 8.3 with Apache)
FROM php:8.3-apache

# Copy your local PHP files (index.html, api.php, etc.) to the web root
COPY . /var/www/html/

# Expose port 80 (Apache default) or PHP-FPM port (optional)
EXPOSE 80