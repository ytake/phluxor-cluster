FROM phpswoole/swoole:6.0.0-php8.4-alpine

RUN apk add --no-cache gmp-dev protobuf protobuf-dev \
    && docker-php-ext-install mysqli pdo_mysql gmp
