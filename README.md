# php-node-frontend

PHP page that gets all its data from the Node API (node-db-app), which talks to MySQL.

PHP  ->  Node API  ->  MySQL

Settings at the top of index.php:
- `API_URL`  – default `http://216.10.251.130` (server IP)
- `API_HOST` – the Node app's domain, default `mysite3.masvrlogs.com`

No DB password needed here; only the Node app talks to the DB.
