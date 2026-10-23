<?php

declare(strict_types=1);

namespace Wdc\Store\Persistence;

use RuntimeException;
use Wdc\Framework\Persistence\Blob;
use Wdc\Framework\Persistence\Database;
use Wdc\Framework\Domain\Security\PasswordUtil;

/**
 * DDL and initial (seed) data for PostgreSQL: native sequences, image as BYTEA, dates as
 * TIMESTAMP (UTC). NAME is plain TEXT — equality search is case-sensitive.
 */
final class StoreSchema
{
    // :: Seed ids — id 0 is valid: never test an id with `!$id`
    public const int ADMIN_ID = 0;
    public const int JOHN_ID = 1;
    public const int JANE_ID = 2;

    public const int COFFEE_MAKER_ID = 0;
    public const int WILSON_BALL_ID = 1;
    public const int THREAD_SEAL_TAPE_ID = 2;
    public const int FLASH_DRIVE2GB_ID = 3;

    public const int ADMIN_FIRST_PURCHASE_ID = 0;
    public const int ADMIN_SECOND_PURCHASE_ID = 1;
    public const int ADMIN_FIRST_PURCHASE_ITEM0_ID = 0;
    public const int ADMIN_SECOND_PURCHASE_ITEM0_ID = 1;
    public const int ADMIN_SECOND_PURCHASE_ITEM1_ID = 2;

    /** CHAR(32) for the password (the hash has a fixed size), BYTEA for the image, native sequences. */
    private const string DDL = <<<'SQL'
        CREATE TABLE EN_USER (
            ID BIGINT NOT NULL PRIMARY KEY,
            USERNAME VARCHAR(255) NOT NULL,
            PASSWORD CHAR(32) NOT NULL,
            NAME VARCHAR(255) NOT NULL,
            ROLES VARCHAR(255)
        );
        CREATE SEQUENCE SQ_USER START WITH 1 INCREMENT BY 1;
        CREATE INDEX IX_USER_NAME ON EN_USER (NAME);
        CREATE INDEX IX_USER_USERNAME ON EN_USER (USERNAME);

        CREATE TABLE EN_PRODUCT (
            ID BIGINT NOT NULL PRIMARY KEY,
            NAME TEXT NOT NULL,
            PRICE NUMERIC(20,2) NOT NULL,
            DESCRIPTION TEXT NOT NULL,
            IMAGE BYTEA
        );
        CREATE SEQUENCE SQ_PRODUCT START WITH 1 INCREMENT BY 1;
        CREATE INDEX IX_PRODUCT_NAME ON EN_PRODUCT (NAME);
        CREATE INDEX IX_PRODUCT_PRICE ON EN_PRODUCT (PRICE);

        CREATE TABLE EN_PURCHASE (
            ID BIGINT NOT NULL PRIMARY KEY,
            USERID BIGINT NOT NULL REFERENCES EN_USER (ID),
            BUYDATE TIMESTAMP NOT NULL
        );
        CREATE SEQUENCE SQ_PURCHASE START WITH 1 INCREMENT BY 1;
        CREATE INDEX IX_PURCHASE_BUYDATE ON EN_PURCHASE (BUYDATE);

        CREATE TABLE EN_PURCHASEITEM (
            ID BIGINT NOT NULL PRIMARY KEY,
            PURCHASEID BIGINT NOT NULL REFERENCES EN_PURCHASE (ID),
            PRODUCTID BIGINT NOT NULL REFERENCES EN_PRODUCT (ID),
            AMOUNT INT NOT NULL,
            PRICE NUMERIC(20,2) NOT NULL
        );
        CREATE SEQUENCE SQ_PURCHASEITEM START WITH 1 INCREMENT BY 1;
        CREATE INDEX IX_PURCHASEITEM_PRICE ON EN_PURCHASEITEM (PRICE);
        CREATE INDEX IX_PURCHASEITEM_AMOUNT ON EN_PURCHASEITEM (AMOUNT);
        SQL;

    private const string DROP = 'DROP TABLE IF EXISTS EN_PURCHASEITEM, EN_PURCHASE, EN_PRODUCT, EN_USER;'
        . ' DROP SEQUENCE IF EXISTS SQ_USER, SQ_PRODUCT, SQ_PURCHASE, SQ_PURCHASEITEM;';

    private function __construct()
    {
    }

    /**
     * Creates the tables if they do not exist and, in that case, seeds them.
     *
     * @return bool true if they were created now
     */
    public static function create(Database $db): bool
    {
        if ($db->dialect()->tableExists($db, 'EN_USER')) {
            return false;
        }
        $db->exec(self::DDL);
        self::reset($db);

        return true;
    }

    /** Drops the tables and the sequences. */
    public static function drop(Database $db): void
    {
        $db->exec(self::DROP);
    }

    /** Deletes everything and seeds the demo data (password = login). */
    public static function reset(Database $db): void
    {
        $db->exec('DELETE FROM EN_PURCHASEITEM; DELETE FROM EN_PURCHASE; DELETE FROM EN_PRODUCT; DELETE FROM EN_USER;');

        $users = [
            [0, 'admin', 'John Smith', 'ADMIN'],
            [1, 'john', 'John Doe', 'CUSTOMER'],
            [2, 'jane', 'Jane Roe', 'CUSTOMER'],
        ];
        foreach ($users as [$id, $login, $name, $roles]) {   // destructuring: unpacks the array into variables
            $db->execute(
                'INSERT INTO EN_USER (ID, USERNAME, PASSWORD, NAME, ROLES) VALUES (?, ?, ?, ?, ?)',
                [$id, $login, PasswordUtil::hashPassword($login), $name, $roles],
            );
        }
        $db->restartSequence('SQ_USER', 3);

        $products = [
            [0, 'Italian design coffee maker', 199.99, 'cafeteira.png',
                '<ul><li>Makes 30 coffees (50ml each) or 24 coffees (62ml each)</li><li>Drip-stop system</li>'
                . '<li>Comes with a removable permanent filter and a measuring spoon</li><li>Works with paper filters</li>'
                . '<li>Graduated water tank</li><li>Illuminated on/off button</li><li>Easy to clean</li>'
                . '<li>Parts are dishwasher safe (except the motor base)</li>'
                . '<li>Power: 1000W - equivalent to 1 kWh (kilowatt-hour).</li></ul>'],
            [1, 'Wilson ball', 45.30, 'wilson.png',
                '<ul><li>Wilson ball, official size and weight.</li><li>Warranty: against manufacturing defects.</li>'
                . '<li>Origin: imported.</li></ul>'],
            [2, 'Thread seal tape', 2.67, 'vedarosca.png',
                '<ul><li>Brand: Tigre.</li><li>Size: 18 mm x 10 m.</li><li>Material: Teflon.</li>'
                . '<li>Use: sealing threaded joints.</li></ul>'],
            [3, 'Flash drive 2GB', 16.0, 'pendrive2gb.png',
                'Ideal for carrying data files, audio, video, photos and much more. The best value for '
                . 'storing and transferring information. Portable, easy to use and super light, it keeps '
                . 'your data safe, has an LED that shows when it is in use, and is drop-resistant. A flash drive with '
                . '2 GB of storage capacity: practicality and quality for your files!'],
        ];
        foreach ($products as [$id, $name, $price, $image, $description]) {
            $db->execute(
                'INSERT INTO EN_PRODUCT (ID, NAME, PRICE, DESCRIPTION, IMAGE) VALUES (?, ?, ?, ?, ?)',
                [$id, $name, $price, $description, new Blob(self::image($image))],
            );
        }
        $db->restartSequence('SQ_PRODUCT', 4);

        $db->execute("INSERT INTO EN_PURCHASE (ID, USERID, BUYDATE) VALUES (0, 0, '2010-01-01 14:30:00'), (1, 0, '2011-04-03 09:15:00')");
        $db->restartSequence('SQ_PURCHASE', 2);

        $db->execute('INSERT INTO EN_PURCHASEITEM (ID, PURCHASEID, PRODUCTID, AMOUNT, PRICE) VALUES '
            . '(0, 0, 0, 1, 200.00), (1, 1, 1, 1, 45.30), (2, 1, 2, 1, 2.67)');
        $db->restartSequence('SQ_PURCHASEITEM', 3);
    }

    private static function image(string $file): string
    {
        // __DIR__ = the folder of this .php file (like loading a classpath resource)
        $bytes = file_get_contents(__DIR__ . '/Resources/images/' . $file);
        if ($bytes === false) {
            throw new RuntimeException("Missing image resource {$file}");
        }

        return $bytes;
    }
}
