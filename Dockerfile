# Use the official PHP 8.2 Image
FROM php:8.2-cli

# Install system dependencies and the PDO MySQL driver
RUN apt-get update && apt-get install -y \
    libmariadb-dev \
    && docker-php-ext-install pdo pdo_mysql

# Set the working directory
WORKDIR /app

# Copy all your files (app.php, etc.) into the container
COPY . /app

# Expose the port Railway uses
EXPOSE 8080

# Start the PHP server
# Railway automatically maps its internal $PORT to the container
CMD ["php", "-S", "0.0.0.0:8080", "app.php"]