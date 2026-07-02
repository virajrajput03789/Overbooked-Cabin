<?php
declare(strict_types=1);

function generateMessyPayload(int $count = 20): string {
    $firstNames = ['John', 'Jane', 'Alex', '玛丽', 'Carlos', 'Fatima'];
    $lastNames = ['Doe', 'Smith', 'Müller', "O'Connor", 'Lopez', 'Al-Farsi'];
    $phones = ['+1-555-0199', 5550123, '1 (555) 987-6543', 'N/A', '00491234567'];
    $ages = [25, '34', 'forty', 12, '67', 'UNKNOWN', 0];

    $passengers = [];

    for ($i = 0; $i < $count; $i++) {
        $passengers[] = [
            'passenger_full_name' => $firstNames[array_rand($firstNames)] . ' ' . $lastNames[array_rand($lastNames)],
            'contact_info' => [
                'telephone' => $phones[array_rand($phones)],
                'alt_email' => 'test' . $i . '@legacy-system.local'
            ],
            'passenger_age' => $ages[array_rand($ages)],
            'timestamp' => time() - rand(0, 10000)
        ];
    }

    // Inject intentional duplicates to test deduplication
    if ($count > 5) {
        $passengers[1] = $passengers[0];
        $passengers[4] = $passengers[3];
    }

    return json_encode(['data' => ['bookings' => $passengers]], JSON_PRETTY_PRINT);
}
