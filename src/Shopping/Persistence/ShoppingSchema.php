<?php

declare(strict_types=1);

namespace Wdc\Shopping\Persistence;

use RuntimeException;
use Wdc\Framework\Persistence\Blob;
use Wdc\Framework\Persistence\Database;
use Wdc\Framework\Domain\Security\PasswordUtil;

/**
 * DDL e dados iniciais (seed), para SQLite e PostgreSQL.
 *
 * SQLite: sem SEQUENCE (emulada na tabela SQ), NAME do produto com COLLATE NOCASE, datas como
 * TEXT em UTC. PostgreSQL: sequências nativas; NAME é TEXT comum — a busca por igualdade
 * diferencia maiúsculas.
 */
final class ShoppingSchema
{
    // :: Ids do seed — ids 0 são válidos: nunca teste id com `!$id`
    public const int ADMIN_ID = 0;
    public const int FULANO_ID = 1;
    public const int BEOTRANO_ID = 2;

    public const int CAFETEIRA_ID = 0;
    public const int BOLA_WILSON_ID = 1;
    public const int FITA_VEDA_ROSCA_ID = 2;
    public const int PEN_DRIVE2GB_ID = 3;

    public const int ADMIN_FIRST_PURCHASE_ID = 0;
    public const int ADMIN_SECOND_PURCHASE_ID = 1;
    public const int ADMIN_FIRST_PURCHASE_ITEM0_ID = 0;
    public const int ADMIN_SECOND_PURCHASE_ITEM0_ID = 1;
    public const int ADMIN_SECOND_PURCHASE_ITEM1_ID = 2;

    private const string DDL_SQLITE = <<<'SQL'
        CREATE TABLE SQ (
            NAME TEXT NOT NULL PRIMARY KEY,
            NEXT INTEGER NOT NULL
        );

        CREATE TABLE EN_USER (
            ID INTEGER NOT NULL PRIMARY KEY,
            USERNAME TEXT NOT NULL,
            PASSWORD TEXT NOT NULL,
            NAME TEXT NOT NULL,
            ROLES TEXT
        );
        CREATE INDEX IX_USER_NAME ON EN_USER (NAME);
        CREATE INDEX IX_USER_USERNAME ON EN_USER (USERNAME);

        CREATE TABLE EN_PRODUCT (
            ID INTEGER NOT NULL PRIMARY KEY,
            NAME TEXT NOT NULL COLLATE NOCASE,
            PRICE NUMERIC NOT NULL,
            DESCRIPTION TEXT NOT NULL,
            IMAGE BLOB
        );
        CREATE INDEX IX_PRODUCT_NAME ON EN_PRODUCT (NAME);
        CREATE INDEX IX_PRODUCT_PRICE ON EN_PRODUCT (PRICE);

        CREATE TABLE EN_PURCHASE (
            ID INTEGER NOT NULL PRIMARY KEY,
            USERID INTEGER NOT NULL REFERENCES EN_USER (ID),
            BUYDATE TEXT NOT NULL
        );
        CREATE INDEX IX_PURCHASE_BUYDATE ON EN_PURCHASE (BUYDATE);

        CREATE TABLE EN_PURCHASEITEM (
            ID INTEGER NOT NULL PRIMARY KEY,
            PURCHASEID INTEGER NOT NULL REFERENCES EN_PURCHASE (ID),
            PRODUCTID INTEGER NOT NULL REFERENCES EN_PRODUCT (ID),
            AMOUNT INTEGER NOT NULL,
            PRICE NUMERIC NOT NULL
        );
        CREATE INDEX IX_PURCHASEITEM_PRICE ON EN_PURCHASEITEM (PRICE);
        CREATE INDEX IX_PURCHASEITEM_AMOUNT ON EN_PURCHASEITEM (AMOUNT);
        SQL;

    /** O DDL para PostgreSQL (CHAR(32) na senha, BYTEA, sequências nativas). */
    private const string DDL_POSTGRES = <<<'SQL'
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

    private const string DROP_POSTGRES = 'DROP TABLE IF EXISTS EN_PURCHASEITEM, EN_PURCHASE, EN_PRODUCT, EN_USER;'
        . ' DROP SEQUENCE IF EXISTS SQ_USER, SQ_PRODUCT, SQ_PURCHASE, SQ_PURCHASEITEM;';

    private function __construct()
    {
    }

    /**
     * Cria as tabelas se não existirem e, nesse caso, semeia.
     *
     * @return bool true se criou agora
     */
    public static function create(Database $db): bool
    {
        if ($db->dialect()->tableExists($db, 'EN_USER')) {
            return false;
        }
        $db->exec($db->dialect()->name() === 'pgsql' ? self::DDL_POSTGRES : self::DDL_SQLITE);
        self::reset($db);

        return true;
    }

    /** Remove as tabelas e as sequências (só PostgreSQL; no SQLite basta apagar o arquivo). */
    public static function drop(Database $db): void
    {
        if ($db->dialect()->name() === 'pgsql') {
            $db->exec(self::DROP_POSTGRES);
        }
    }

    /** Apaga tudo e semeia os dados de demonstração (senha = login). */
    public static function reset(Database $db): void
    {
        $db->exec('DELETE FROM EN_PURCHASEITEM; DELETE FROM EN_PURCHASE; DELETE FROM EN_PRODUCT; DELETE FROM EN_USER;');

        $users = [
            [0, 'admin', 'João da Silva', 'ADMIN'],
            [1, 'fulano', 'Fulano de Tal', 'CUSTOMER'],
            [2, 'beotrano', 'Beotrano de Alguma Coisa', 'CUSTOMER'],
        ];
        foreach ($users as [$id, $login, $name, $roles]) {   // destructuring: desmonta o array em variáveis
            $db->execute(
                'INSERT INTO EN_USER (ID, USERNAME, PASSWORD, NAME, ROLES) VALUES (?, ?, ?, ?, ?)',
                [$id, $login, PasswordUtil::hashPassword($login), $name, $roles],
            );
        }
        $db->restartSequence('SQ_USER', 3);

        $products = [
            [0, 'Cafeteira design italiano', 199.99, 'cafeteira.png',
                '<ul><li>Capacidade para 30 cafés (50ml cada) ou 24 cafés (62ml cada)</li><li>Sistema corta-pingos</li>'
                . '<li>Acompanha filtro permanente removível e colher medidora</li><li>Permite uso de filtro de papel</li>'
                . '<li>Reservatório de água com graduação</li><li>Botão luminoso liga/desliga</li><li>Fácil de lavar</li>'
                . '<li>Peças podem ser lavadas em máquina de lavar louça (exceto a base motora)</li>'
                . '<li>Potência: 1000W - correspondente a 1 Kwh (Kilowatts hora).</li></ul>'],
            [1, 'Bola Wilson', 45.30, 'wilson.png',
                '<ul><li>Bola Wilson Tamanho e Peso Oficial.</li><li>Garantia: Contra defeito de fabricação.</li>'
                . '<li>Origem: Importada.</li></ul>'],
            [2, 'Fita veda rosca', 2.67, 'vedarosca.png',
                '<ul><li>Marca Tigre.</li><li>Tamanho e medida: 18 mm x 10 m.</li><li>Composição: Teflon.</li>'
                . '<li>Utilização: vedação de juntas roscaveis.</li></ul>'],
            [3, 'Pen Drive 2GB', 16.0, 'pendrive2gb.png',
                'Ideal para transporte de arquivos de dados, áudio, vídeo, fotos e muito mais. Melhor valor para '
                . 'armazenamento e transferência de informação. Portátil, fácil de usar e super leve, ele possui '
                . 'segurança com seus dados, led indicando o uso, além de ser resistente a quedas. Pen Drive com '
                . 'capacidade de armazenamento de 2 GB, praticidade e qualidade com seus arquivos!'],
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
        // __DIR__ = pasta deste arquivo .php (equivale a buscar um recurso no classpath)
        $bytes = file_get_contents(__DIR__ . '/Resources/images/' . $file);
        if ($bytes === false) {
            throw new RuntimeException("Missing image resource {$file}");
        }

        return $bytes;
    }
}
