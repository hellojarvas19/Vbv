FROM php:8.2-cli

WORKDIR /app

COPY api.php .
COPY start.sh .

RUN chmod +x start.sh

EXPOSE 8080

ENTRYPOINT ["/bin/bash", "./start.sh"]
