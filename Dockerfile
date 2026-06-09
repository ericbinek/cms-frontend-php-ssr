FROM php:8.5-alpine

WORKDIR /app

COPY . .

RUN chown -R www-data:www-data /app

USER www-data

EXPOSE 4002

HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
  CMD wget --quiet --spider http://127.0.0.1:4002/health || exit 1

CMD ["php", "-S", "0.0.0.0:4002", "-t", "public", "src/server.php"]
