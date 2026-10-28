# WordPress integration fixture prerequisites

Run `php tests/integration.php` with SQLite3, OpenSSL and cURL enabled and
`proc_open` available. SQLite3 is mandatory: the metadata census uses an actual
in-memory SQL query, including deliberately conflicting metadata rows. The
bootstrap fails before any cases run when SQLite3 is missing.

The final TLS loopback case also needs a loaded PHP configuration file. Its
owned child server inherits that file through `php_ini_loaded_file()`. A local
configuration selected with `php -c /path/to/php.ini tests/integration.php` keeps
the parent and child runtimes consistent without changing global PHP settings.

The TLS client retains certificate and hostname verification. The fixture uses
a generated test certificate and a loopback-only server, terminates its own
server in `finally`, and models WordPress rather than claiming an installed
WordPress result. These prerequisites do not change production requirements or
weaken any test assertion.
