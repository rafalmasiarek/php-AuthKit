# AuthKit Examples

This folder contains two runnable demo applications showing how to use AuthKit with different databases:

- **sqlite-demo/** — minimal example with SQLite (good for local testing and prototyping).
- **mysql-demo/** — example with MySQL (production-oriented).

## Running SQLite demo

```bash
php -S 127.0.0.1:8080 -t examples/sqlite-demo
```

On first run, it will create `authkit.sqlite` and initialize the schema from `schema.sql`.

## Running MySQL demo

Edit `bootstrap.php` to configure your MySQL DSN, user, and password.

```bash
php -S 127.0.0.1:8080 -t examples/mysql-demo
```

Make sure you have created the MySQL schema using `schema.sql` before running.
