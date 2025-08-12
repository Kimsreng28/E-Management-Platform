<?php

namespace App\Services;

class KhqrService
{
    public function generateValidKHQR($merchantName, $merchantAccount, $amount, $currency, $orderId)
    {
        // Data sanitization
        $merchantName = $this->cleanText($merchantName, 25); // Max length 25
        $merchantAccount = $this->cleanAccount($merchantAccount); // Allow alphanumeric and special characters @ and + for Bakong IDs
        $amount = number_format($amount, 2, '.', ''); // Allow only 2 decimal places or fix it to 2 decimal places

        // Currrency conversion to ISO 4217 numeric code
        $currencyNumeric = match (strtoupper($currency)) {
            'USD' => '840', // US Dollar
            'KHR', 'KHM' => '116', // Riel (Khmer Riel)
            default => throw new \Exception("Unsupported currency: $currency"),
        };

        $payload = [
            '00' => '01',
            '01' => '12',
            '26' => [
                '00' => 'A000000677',
                '01' => $merchantAccount
            ],
            '52' => '0000',
            '53' => $currencyNumeric,
            '54' => $amount,
            '58' => 'KH',
            '59' => $merchantName,
            '60' => 'PhnomPenh',
            '62' => [
                '05' => $orderId
            ]
        ];

        // Build TLV
        $tlvWithoutCRC = $this->buildTLV($payload);
        $tlvWithCRCField = $tlvWithoutCRC . '6304'; // Add tag before calculating CRC
        $crc = $this->calculateCRC16($tlvWithCRCField);

        return $tlvWithCRCField . strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }


    private function cleanText($text, $maxLength)
    {
        // Remove all special characters and spaces
        $cleaned = preg_replace('/[^A-Za-z0-9]/', '', $text);
        return substr($cleaned, 0, $maxLength);
    }

    private function cleanAccount($account)
    {
        return preg_replace('/[^A-Za-z0-9@+]/', '', $account);
    }

    // Builds a TLV (Tag-Length-Value) string from the provided data, the payload use EMVCo's TLV format for structured data transmission
    private function buildTLV($data)
    {
        $tlv = '';

        foreach ($data as $tag => $value) {
            if (is_array($value)) {
                // Nested TLV structure (e.g., for merchant account info)
                $nestedValue = $this->buildTLV($value);
                $length = strlen($nestedValue);
                $tlv .= $tag . str_pad($length, 2, '0', STR_PAD_LEFT) . $nestedValue;
            } else {
                // Simple field - ensure correct length indicator
                $length = strlen($value);
                $tlv .= $tag . str_pad($length, 2, '0', STR_PAD_LEFT) . $value;
            }
        }

        return $tlv;
    }

    // Calculates CRC16 for the provided data, that ensures data integrity during qr code scanning
    private function calculateCRC16($data)
    {
        // Initialize value
        $crc = 0xFFFF;

        for ($i = 0; $i < strlen($data); $i++) {
            $crc ^= ord($data[$i]) << 8;

            for ($j = 0; $j < 8; $j++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
            }
        }

        return $crc & 0xFFFF; // Ensure CRC is 16 bits result
    }
}
