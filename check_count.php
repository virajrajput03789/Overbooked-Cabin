<?php require "payload_generator.php"; $data = json_decode(generateMessyPayload(), true); echo count($data["data"]["bookings"]);
