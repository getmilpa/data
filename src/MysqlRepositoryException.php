<?php

/**
 * This file is part of Milpa Data — the runtime-native persistence primitive of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/data
 */

declare(strict_types=1);

namespace Milpa\Data;

/**
 * Teaching failure of the MySQL backend: when {@see MysqlRepository} cannot reach its server it
 * refuses to die raw. MySQL is the one backend in this package that depends on a network service,
 * so a broken connection blocks every read and write of the store — which is exactly why each
 * message here says what failed, why it matters, and how to fix it (start the server, switch to a
 * zero-service backend, or repair the DSN) instead of surfacing a bare driver error. Credentials
 * never travel in a message: a `password=` segment in the DSN is redacted before it is shown.
 */
final class MysqlRepositoryException extends \RuntimeException
{
    /**
     * MySQL did not answer at the address the DSN names — the server is down, the address is
     * wrong, or the credentials were refused; the driver's own message rides along to say which.
     *
     * @param string          $dsn      the DSN exactly as the repository was constructed with; any `password=` segment is redacted before display
     * @param \Throwable|null $previous the original driver exception, preserved as `getPrevious()`
     */
    public static function unreachable(string $dsn, ?\Throwable $previous = null): self
    {
        preg_match('/host=([^;]+)/i', $dsn, $host);
        preg_match('/port=([^;]+)/i', $dsn, $port);
        $at = ($host[1] ?? 'localhost') . ':' . ($port[1] ?? '3306');
        $redacted = (string) preg_replace('/password=[^;]*/i', 'password=***', $dsn);
        $cause = $previous instanceof \Throwable ? " ({$previous->getMessage()})" : '';

        return new self(
            "MysqlRepository could not connect to MySQL at {$at}{$cause}." . PHP_EOL
            . 'Why it matters: this backend keeps its entities in a MySQL server — every find(), save(), '
            . 'delete(), all() and query() goes through this one connection, so until the server answers '
            . 'nothing can be stored or found.' . PHP_EOL
            . 'Fixes:' . PHP_EOL
            . "  1. Start the MySQL server at {$at} (systemctl start mysqld, or bring up your compose "
            . "'mysql' service)." . PHP_EOL
            . '  2. No server around? Switch this store to a zero-service backend: in a Milpa app set '
            . 'storage.driver=file (FileRepository — the same six-method contract over one JSON file) or '
            . 'storage.driver=sqlite (SqliteRepository — a real database in one file); standalone, '
            . 'construct that repository directly.' . PHP_EOL
            . "  3. Fix the DSN if it points at the wrong place: {$redacted} — in a Milpa app the "
            . 'connection comes from connections.mysql in config/database.php (env MYSQL_HOST / '
            . 'MYSQL_PORT / MYSQL_DATABASE / MYSQL_USER / MYSQL_PASSWORD).',
            0,
            $previous,
        );
    }

    /**
     * `ext-pdo_mysql` is not loaded, so PHP has no way to speak to a MySQL server at all.
     */
    public static function extensionMissing(): self
    {
        return new self(
            "MysqlRepository needs the PHP extension 'pdo_mysql', which is not loaded." . PHP_EOL
            . 'Why it matters: this backend stores entities in a MySQL server, and PHP talks to MySQL '
            . 'only through ext-pdo_mysql.' . PHP_EOL
            . 'Fixes:' . PHP_EOL
            . '  1. Install/enable the extension (Debian/Ubuntu: apt install php-mysql; Fedora: dnf '
            . 'install php-mysqlnd; then restart PHP).' . PHP_EOL
            . '  2. Or switch to FileRepository or SqliteRepository — the same six-method contract, no '
            . 'database server at all.',
        );
    }
}
