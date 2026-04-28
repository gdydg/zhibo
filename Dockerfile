FROM php:8.2-apache

# 开启 Apache Rewrite 模块，安装所需扩展
RUN a2enmod rewrite \
    && apt-get update && apt-get install -y libcurl4-openssl-dev \
    && docker-php-ext-install curl

# 将源码复制到 web 目录
COPY ./src /var/www/html/

# 创建数据目录并赋予 www-data 读写权限
RUN mkdir -p /var/www/html/data \
    && chown -R www-data:www-data /var/www/html/data \
    && chmod -R 777 /var/www/html/data

EXPOSE 801
