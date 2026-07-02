<?php
declare(strict_types=1);

/**
 * Class BookingManager
 * Manages the parsing, validation, deduplication, and database insertion of passenger payloads.
 */
class BookingManager
{
    private \PDO $pdo;
    /**
     * @var callable
     */
    private $logger;

    /**
     * BookingManager constructor.
     * @param \PDO $pdo Injected database connection.
     * @param callable|null $logger Injected callable for logging errors.
     */
    public function __construct(\PDO $pdo, ?callable $logger = null)
    {
        $this->pdo = $pdo;
        $this->logger = $logger ?? function (string $message) {
            error_log($message);
        };
    }

    /**
     * Processes a JSON payload containing passenger details.
     * 
     * @param string $jsonPayload Raw JSON payload
     * @return array Summary of processed rows
     */
    public function processPayload(string $jsonPayload): array
    {
        $summary = [
            'confirmed' => 0,
            'waitlisted' => 0,
            'skipped' => 0,
            'malformed' => 0,
            'processed' => 0,
        ];

        try {
            $data = json_decode($jsonPayload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->log("Failed to parse JSON payload: " . $e->getMessage());
            $summary['malformed'] = 1;
            return $summary;
        }

        if (!is_array($data)) {
        $this->log("JSON payload is not an array.");
        $summary['malformed'] = 1;
        return $summary;
        }

        if (!isset($data['data']['bookings']) || !is_array($data['data']['bookings'])) {
            $this->log("JSON payload missing expected 'data.bookings' structure.");
            $summary['malformed'] = 1;
            return $summary;
        }

        $bookings = $data['data']['bookings'];

        $validRecords = [];
        $seenKeys = [];

        foreach ($bookings as $index => $row) {if (!is_array($row)) {
                $this->log("Record at index {$index} is not an object/array. Skipping.");
                $summary['skipped']++;
                continue;
            }

            if (empty($row['passenger_full_name']) || !is_string($row['passenger_full_name']) || !isset($row['timestamp']) || !is_int($row['timestamp'])) {
                $this->log("Record at index {$index} is structurally invalid (missing/invalid name or timestamp). Skipping.");
                $summary['skipped']++;
                continue;
            }

            $name = $row['passenger_full_name'];
            $phone = $row['contact_info']['telephone'] ?? null;
            $age = $row['passenger_age'] ?? null;
            $timestamp = $row['timestamp'];

            $sanitizedPhone = $this->sanitizePhone(is_scalar($phone) ? (string)$phone : null);
            $dedupKey = $this->generateDedupKey($name, $sanitizedPhone);

            if (isset($seenKeys[$dedupKey])) {
                $this->log("Record at index {$index} is a duplicate. Skipping.");
                $summary['skipped']++;
                continue;
            }

            $seenKeys[$dedupKey] = true;

            $validRecords[] = [
                'index' => $index,
                'name' => $name,
                'phone' => $sanitizedPhone,
                'age' => $this->sanitizeAge($age),
                'timestamp' => $timestamp,
            ];
        }

        // Sort chronologically by timestamp ascending
        usort($validRecords, function ($a, $b) {
            return $a['timestamp'] <=> $b['timestamp'];
        });

        $stmt = null;
        try {
            $sql = "INSERT INTO `tbl_pass_det_v2` (`pname`, `ph_num`, `age_val`, `bk_status`) VALUES (:pname, :ph_num, :age_val, :bk_status)";
            $stmt = $this->pdo->prepare($sql);
            $this->pdo->beginTransaction();
        } catch (\PDOException $e) {
            $this->log("Database error during prepare: " . $e->getMessage());
            return $summary;
        }

        $processedCount = 0;

        foreach ($validRecords as $record) {
            $status = $processedCount < 10 ? 'CONFIRMED' : 'WAITLISTED';

            try {
                $stmt->execute([
                    ':pname' => $record['name'],
                    ':ph_num' => $record['phone'],
                    ':age_val' => $record['age'] !== null ? (string)$record['age'] : null,
                    ':bk_status' => $status
                ]);

                if ($status === 'CONFIRMED') {
                    $summary['confirmed']++;
                } else {
                    $summary['waitlisted']++;
                }
                $summary['processed']++;
                $processedCount++;
            } catch (\PDOException $e) {
                $this->log("Failed to insert record from index {$record['index']}: " . $e->getMessage());
                // We'll continue to process others, even if one specific insertion fails
            }
        }

        try {
            $this->pdo->commit();
        } catch (\PDOException $e) {
            $this->log("Database error during commit: " . $e->getMessage());
            $this->pdo->rollBack();
        }

        return $summary;
    }

    /**
     * Sanitizes the age value.
     * 
     * @param mixed $age Raw age input.
     * @return int|null Sanitized integer age between 1-120, or null if invalid.
     */
    protected function sanitizeAge(mixed $age): ?int
    {
        if (is_int($age)) {
            $val = $age;
        } elseif (is_string($age) && is_numeric($age)) {
            // Checks if string can be safely interpreted as a numeric int
            $val = (int)$age;
        } else {
            return null;
        }

        if ($val >= 1 && $val <= 120) {
            return $val;
        }

        return null;
    }

    /**
     * Sanitizes phone number by stripping characters except leading + and digits.
     * 
     * @param string|null $phone Raw phone input.
     * @return string|null Sanitized phone string, or null if no valid digits.
     */
    protected function sanitizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $isPlus = str_starts_with(trim($phone), '+');
        $digits = preg_replace('/\D/', '', $phone);

        if ($digits === '') {
            return null;
        }

        return ($isPlus ? '+' : '') . $digits;
    }

    /**
     * Generates a unique deduplication key for a passenger.
     * 
     * @param string $name Raw full name.
     * @param string|null $phone Sanitized phone.
     * @return string Hashed unique identifier.
     */
    protected function generateDedupKey(string $name, ?string $phone): string
    {
        $normalizedName = strtolower(trim($name));
        $normalizedPhone = $phone ?? '';
        return hash('sha256', $normalizedName . '|' . $normalizedPhone);
    }

    /**
     * Internal logger helper.
     * 
     * @param string $message The message to log.
     */
    private function log(string $message): void
    {
        call_user_func($this->logger, $message);
    }
}
