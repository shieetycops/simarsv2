<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/OutgoingNumbering.php';

// Test logika penomoran surat keluar (murni, tanpa HTTP/auth).
// Jalan dengan: cd api-php && composer install && vendor/bin/phpunit tests
final class OutgoingNumberingTest extends TestCase
{
    private function setupDb(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("CREATE TABLE outgoing_letters (
            id VARCHAR(36) PRIMARY KEY,
            letter_number VARCHAR(255),
            letter_date DATETIME,
            issuing_unit VARCHAR(50),
            subject VARCHAR(255),
            destination VARCHAR(255),
            signer VARCHAR(255)
        )");
        $db->exec("CREATE TABLE outgoing_number_slots (
            id VARCHAR(36) PRIMARY KEY,
            issuing_unit VARCHAR(50),
            letter_date DATE,
            sequence INT,
            suffix VARCHAR(10),
            kode VARCHAR(255),
            letter_number VARCHAR(255),
            status VARCHAR(20),
            reserved_by VARCHAR(36)
        )");
        Db::$pdo = $db;
        return $db;
    }

    private function addLetter(string $number, string $date, string $unit = 'PPK'): void
    {
        Db::q(
            "INSERT INTO outgoing_letters (id, letter_number, letter_date, issuing_unit, subject, destination, signer)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [Db::generateId(), $number, $date, $unit, 'x', 'x', 'x']
        );
    }

    private function addSlot(string $number, string $date, string $unit = 'PPK', string $status = 'DIPESAN'): void
    {
        Db::q(
            "INSERT INTO outgoing_number_slots (id, issuing_unit, letter_date, sequence, suffix, kode, letter_number, status, reserved_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [Db::generateId(), $unit, $date, 0, null, null, $number, $status, 'u1']
        );
    }

    public function testStartsAtOneWhenEmpty(): void
    {
        $this->setupDb();
        $n = OutgoingNumbering::next(Db::$pdo, 'PPK', '2026-02-03');
        $this->assertSame(1, $n['sequence']);
        $this->assertNull($n['suffix']);
        $this->assertSame('1/2/2026', $n['letterNumber']);
    }

    public function testContinuesAfterMaxExisting(): void
    {
        $this->setupDb();
        $this->addLetter('26/SPD.PPK.PA.PSW/2/2026', '2026-02-03');
        $this->addLetter('27/KPA.W21-A7/HK.05/2/2026', '2026-02-10');
        $n = OutgoingNumbering::next(Db::$pdo, 'PPK', '2026-02-15');
        $this->assertSame(28, $n['sequence']);
        $this->assertSame('28/2/2026', $n['letterNumber']);
    }

    public function testResetsEachYear(): void
    {
        $this->setupDb();
        $this->addLetter('380/SPD.PPK.PA.PSW/12/2026', '2026-12-20');
        $n = OutgoingNumbering::next(Db::$pdo, 'PPK', '2027-01-05');
        $this->assertSame(1, $n['sequence']);
        $this->assertSame('1/1/2027', $n['letterNumber']);
    }

    public function testEachUnitHasOwnSequence(): void
    {
        $this->setupDb();
        $this->addLetter('5/KPA.W21-A7/SK/HK.05/I/2026', '2026-01-10', 'KPA');
        $n = OutgoingNumbering::next(Db::$pdo, 'PPK', '2026-01-15');
        $this->assertSame(1, $n['sequence']);
        $this->assertSame('1/1/2026', $n['letterNumber']);
    }

    public function testSisipanAssignsNextFreeSuffix(): void
    {
        $this->setupDb();
        $this->addLetter('5/SPD.PPK.PA.PSW/2/2026', '2026-02-03');
        $n = OutgoingNumbering::next(Db::$pdo, 'PPK', '2026-02-05', 5);
        $this->assertSame(5, $n['sequence']);
        $this->assertSame('a', $n['suffix']);
        $this->assertSame('5.a/2/2026', $n['letterNumber']);

        $this->addLetter('5.a/SPD.PPK.PA.PSW/2/2026', '2026-02-04');
        $n2 = OutgoingNumbering::next(Db::$pdo, 'PPK', '2026-02-05', 5);
        $this->assertSame('b', $n2['suffix']);
        $this->assertSame('5.b/2/2026', $n2['letterNumber']);
    }

    public function testSisipanRecognizesOldFormatWithoutDot(): void
    {
        $this->setupDb();
        $this->addLetter('8a/KPA.W21-A7/HK.05/1/2026', '2026-01-08', 'KPA');
        $this->addLetter('8b/KPA.W21-A7/HK.05/1/2026', '2026-01-09', 'KPA');
        $n = OutgoingNumbering::next(Db::$pdo, 'KPA', '2026-01-10', 8);
        $this->assertSame('c', $n['suffix']);
        $this->assertSame('8.c/I/2026', $n['letterNumber']);
    }

    public function testMessyImportDataIsIgnored(): void
    {
        $this->setupDb();
        $this->addLetter('TANPA-NOMOR-1', '2026-01-10');
        $this->addLetter('SURAT', '2026-01-11');
        $this->addLetter('', '2026-01-12');
        $n = OutgoingNumbering::next(Db::$pdo, 'PPK', '2026-01-15');
        $this->assertSame(1, $n['sequence']);
        $this->assertSame('1/1/2026', $n['letterNumber']);
    }

    public function testSuffixOnlyMarksBaseAsUsed(): void
    {
        $this->setupDb();
        $this->addLetter('2.a/SPD.PPK.PA.PSW/2/2026', '2026-02-03');
        $this->addLetter('2.b/SPD.PPK.PA.PSW/2/2026', '2026-02-04');
        $n = OutgoingNumbering::next(Db::$pdo, 'PPK', '2026-02-05');
        $this->assertSame(3, $n['sequence']);
        $this->assertSame('3/2/2026', $n['letterNumber']);
    }

    public function testReservedSlotsAreSkipped(): void
    {
        $this->setupDb();
        $this->addLetter('30/SPD.PPK.PA.PSW/2/2026', '2026-02-03');
        $this->addSlot('31/SPD.PPK.PA.PSW/2/2026', '2026-02-04');
        $n = OutgoingNumbering::next(Db::$pdo, 'PPK', '2026-02-05');
        $this->assertSame(32, $n['sequence']);
        $this->assertSame('32/2/2026', $n['letterNumber']);
    }

    public function testReservedSlotSuffixBlocksThatSuffix(): void
    {
        $this->setupDb();
        $this->addLetter('5/SPD.PPK.PA.PSW/2/2026', '2026-02-03');
        $this->addSlot('5.a/SPD.PPK.PA.PSW/2/2026', '2026-02-04');
        $n = OutgoingNumbering::next(Db::$pdo, 'PPK', '2026-02-05', 5);
        $this->assertSame('b', $n['suffix']);
        $this->assertSame('5.b/2/2026', $n['letterNumber']);
    }

    public function testCancelledSlotIsFreeAgain(): void
    {
        $this->setupDb();
        $this->addLetter('30/SPD.PPK.PA.PSW/2/2026', '2026-02-03');
        $this->addSlot('31/SPD.PPK.PA.PSW/2/2026', '2026-02-04', 'PPK', 'BATAL');
        $n = OutgoingNumbering::next(Db::$pdo, 'PPK', '2026-02-05');
        $this->assertSame(31, $n['sequence']);
    }

    public function testNextLetterWithKode(): void
    {
        $this->setupDb();
        $this->addLetter('26/SPD.PPK.PA.PSW/2/2026', '2026-02-03');
        $n = OutgoingNumbering::next(Db::$pdo, 'PPK', '2026-02-05', null, 'SPD.PPK.PA.PSW');
        $this->assertSame('27/SPD.PPK.PA.PSW/2/2026', $n['letterNumber']);
        $this->assertSame('SPD.PPK.PA.PSW', $n['kode']);
    }

    public function testUsedNumbersSortedPerUnitAndYear(): void
    {
        $this->setupDb();
        $this->addLetter('26/SPD.PPK.PA.PSW/2/2026', '2026-02-03');
        $this->addLetter('2/SPD.PPK.PA.PSW/2/2026', '2026-02-04');
        $this->addLetter('15.a/SPD.PPK.PA.PSW/2/2026', '2026-02-05');
        $this->addSlot('20/SPD.PPK.PA.PSW/2/2026', '2026-02-06');
        $this->assertSame([2, 15, 20, 26], OutgoingNumbering::usedNumbers(Db::$pdo, 'PPK', 2026));
        $this->assertSame([], OutgoingNumbering::usedNumbers(Db::$pdo, 'PPK', 2027));
        $this->assertSame([], OutgoingNumbering::usedNumbers(Db::$pdo, 'SEKRETARIS', 2026));
    }

    public function testMonthLabelPerUnit(): void
    {
        $this->setupDb();
        $this->assertSame('2', OutgoingNumbering::monthLabel('PPK', 2));
        $this->assertSame('II', OutgoingNumbering::monthLabel('SEKRETARIS', 2));
        $this->assertSame('X', OutgoingNumbering::monthLabel('KPA', 10));
    }

    public function testComposeWithKode(): void
    {
        $this->assertSame(
            '5.a/SPK.PPK.PA.PSW/2/2026',
            OutgoingNumbering::compose('PPK', 5, 'a', 'SPK.PPK.PA.PSW', 2, 2026)
        );
        $this->assertSame(
            '42/KPA.W21-A7/KU1.1.3/II/2026',
            OutgoingNumbering::compose('KPA', 42, null, 'KPA.W21-A7/KU1.1.3', 2, 2026)
        );
        $this->assertSame('1/1/2026', OutgoingNumbering::compose('PPK', 1, null, '', 1, 2026));
    }

    public function testParseNumberFormats(): void
    {
        $this->assertSame(['sequence' => 5, 'suffix' => null], OutgoingNumbering::parseNumber('5/KPA.W21-A7/HK.05/I/2026'));
        $this->assertSame(['sequence' => 5, 'suffix' => 'a'], OutgoingNumbering::parseNumber('5.a/KPA.W21-A7/2/2026'));
        $this->assertSame(['sequence' => 5, 'suffix' => 'a'], OutgoingNumbering::parseNumber('5a/KPA.W21-A7/2/2026'));
        $this->assertSame(['sequence' => 12, 'suffix' => null], OutgoingNumbering::parseNumber('12/KPA.W21-A7/2/2026'));
        $this->assertNull(OutgoingNumbering::parseNumber('TANPA-NOMOR-1'));
        $this->assertNull(OutgoingNumbering::parseNumber(''));
    }

    public function testInvalidUnitThrows(): void
    {
        $this->setupDb();
        $this->expectException(InvalidArgumentException::class);
        OutgoingNumbering::next(Db::$pdo, 'BOGUS', '2026-02-03');
    }
}

