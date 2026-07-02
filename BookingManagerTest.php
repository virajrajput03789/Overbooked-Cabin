<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/BookingManager.php';

/**
 * Wrapper class to expose protected sanitization methods for isolated testing.
 */
class TestableBookingManager extends BookingManager
{
    public function publicSanitizeAge(mixed $age): ?int
    {
        return $this->sanitizeAge($age);
    }

    public function publicSanitizePhone(?string $phone): ?string
    {
        return $this->sanitizePhone($phone);
    }

    public function publicGenerateDedupKey(string $name, ?string $phone): string
    {
        return $this->generateDedupKey($name, $phone);
    }
}

class BookingManagerTest extends TestCase
{
    private TestableBookingManager $manager;
    private \PDO $pdoMock;
    private \PDOStatement $stmtMock;

    protected function setUp(): void
    {
        $this->pdoMock = $this->createMock(\PDO::class);
        $this->stmtMock = $this->createMock(\PDOStatement::class);
        
        $this->manager = new TestableBookingManager($this->pdoMock, function(string $msg) {});
    }

    public function testSanitizeAge(): void
    {
        $this->assertSame(30, $this->manager->publicSanitizeAge(30));
        $this->assertSame(45, $this->manager->publicSanitizeAge("45"));
        $this->assertNull($this->manager->publicSanitizeAge("forty"));
        $this->assertNull($this->manager->publicSanitizeAge(0));
        $this->assertSame(12, $this->manager->publicSanitizeAge(12));
        $this->assertNull($this->manager->publicSanitizeAge(121));
        $this->assertNull($this->manager->publicSanitizeAge(-5));
        $this->assertNull($this->manager->publicSanitizeAge(null));
    }

    public function testSanitizePhone(): void
    {
        $this->assertSame("+15550199", $this->manager->publicSanitizePhone("+1-555-0199"));
        $this->assertSame("5550123", $this->manager->publicSanitizePhone("5550123"));
        $this->assertNull($this->manager->publicSanitizePhone("N/A"));
        $this->assertSame("00491234567", $this->manager->publicSanitizePhone("00491234567"));
        $this->assertSame("+491701234567", $this->manager->publicSanitizePhone("  +49 170 1234567  "));
        $this->assertNull($this->manager->publicSanitizePhone("UNKNOWN"));
    }

    public function testDedupKey(): void
    {
        $key1 = $this->manager->publicGenerateDedupKey("Alice Smith", "+15550199");
        $key2 = $this->manager->publicGenerateDedupKey("alice smith ", "+15550199");
        $this->assertSame($key1, $key2);

        $key3 = $this->manager->publicGenerateDedupKey("Bob Jones", null);
        $key4 = $this->manager->publicGenerateDedupKey("bob jones", null);
        $this->assertSame($key3, $key4);
    }

    public function testCapacityCap(): void
    {
        $data = [];
        for ($i = 0; $i < 15; $i++) {
            $data[] = [
                'passenger_full_name' => "Passenger $i",
                'timestamp' => 1600000000 + $i,
                'phone' => '12345' . $i
            ];
        }

        $payload = json_encode(['data' => ['bookings' => $data]]);

        $this->pdoMock->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmtMock);

        $this->pdoMock->expects($this->once())
            ->method('beginTransaction');

        $this->pdoMock->expects($this->once())
            ->method('commit');

        // We expect execute to be called exactly 15 times
        $this->stmtMock->expects($this->exactly(15))
            ->method('execute')
            ->willReturn(true);

        $summary = $this->manager->processPayload($payload);

        $this->assertSame(10, $summary['confirmed']);
        $this->assertSame(5, $summary['waitlisted']);
        $this->assertSame(15, $summary['processed']);
    }

    public function testMalformedJson(): void
    {
        $payload = "{ malformed json ";
        
        // PDO should not be touched
        $this->pdoMock->expects($this->never())->method('prepare');

        $summary = $this->manager->processPayload($payload);
        
        $this->assertSame(1, $summary['malformed']);
        $this->assertSame(0, $summary['processed']);
    }

    public function testSkipStructurallyInvalidRow(): void
    {
        $data = [
            ['passenger_full_name' => 'Alice Smith', 'timestamp' => 1620000000],
            ['timestamp' => 1620000005], // Missing name
            ['passenger_full_name' => '', 'timestamp' => 1620000010], // Empty name
            ['passenger_full_name' => 'Bob Jones', 'timestamp' => 'not an int'], // Invalid timestamp
            ['passenger_full_name' => 'Valid User', 'timestamp' => 1620000015],
        ];

        $payload = json_encode(['data' => ['bookings' => $data]]);

        $this->pdoMock->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmtMock);

        $this->stmtMock->expects($this->exactly(2))
            ->method('execute')
            ->willReturn(true);

        $summary = $this->manager->processPayload($payload);

        $this->assertSame(2, $summary['processed']);
        $this->assertSame(3, $summary['skipped']);
    }
}
