FROM php:8.2-apache
RUN docker-php-ext-install mysqli && docker-php-ext-enable mysqli
# Enable Apache mod_rewrite just in case
RUN a2enmod rewrite
