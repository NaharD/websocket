FROM php:7.2-cli

# Власне наш сокет сервер
RUN pecl install swoole
RUN docker-php-ext-enable swoole.so

# Кешування
RUN pecl install apcu
RUN docker-php-ext-enable apcu.so

# Вмикаємо кешування для консольних скриптів
RUN echo "apc.enable_cli=On" > /usr/local/etc/php/php.ini

COPY www /opt/websocket

WORKDIR /opt/websocket

EXPOSE 8080

CMD ["/usr/local/bin/php", "websocket.php"]
