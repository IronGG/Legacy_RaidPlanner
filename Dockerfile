FROM php:8.3-apache

# Le site sert uniquement public/ ; src/, data/ et seed/ restent hors de la racine web.
ENV APACHE_DOCUMENT_ROOT=/var/www/app/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf \
 && a2enmod headers \
 && printf "ServerTokens Prod\nServerSignature Off\n" > /etc/apache2/conf-enabled/zz-hardening.conf \
 && echo "expose_php = Off" > /usr/local/etc/php/conf.d/zz-hardening.ini

COPY --chown=www-data:www-data . /var/www/app
RUN mkdir -p /var/www/app/data && chown www-data:www-data /var/www/app/data
VOLUME /var/www/app/data
