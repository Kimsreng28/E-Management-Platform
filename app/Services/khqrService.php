<?php

namespace App\Services;

class KhqrService
{
    public function generateValidKHQR($merchantName, $merchantAccount, $amount, $currency, $orderId)
    {
        // Data sanitization
        $merchantName = $this->cleanText($merchantName, 25); // Allow Khmer, letters, numbers, spaces, dot, dash
        $merchantAccount = $this->cleanAccount($merchantAccount); // Allow alphanumeric, @ and +
        $amount = number_format($amount, 2, '.', ''); // 2 decimal places

        // Currency conversion to ISO 4217 numeric code
        $currencyNumeric = match (strtoupper($currency)) {
            'USD' => '840',
            'KHR', 'KHM' => '116',
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

        // Build TLV (byte-safe)
        $tlvWithoutCRC = $this->buildTLV($payload);
        $tlvWithCRCField = $tlvWithoutCRC . '6304'; // CRC tag before calculating CRC
        $crc = $this->calculateCRC16($tlvWithCRCField);

        return $tlvWithCRCField . strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }

    // Allow letters (Latin + Khmer), numbers, spaces, dot, dash
    private function cleanText($text, $maxLength)
    {
        $cleaned = preg_replace('/[^\p{L}\p{N}\s\.\-]/u', '', $text);
        return mb_substr($cleaned, 0, $maxLength);
    }

    // Allow alphanumeric, @, +
    private function cleanAccount($account)
    {
        return preg_replace('/[^A-Za-z0-9@+]/', '', $account);
    }

    // Builds a TLV string with byte-length counting
    private function buildTLV($data)
    {
        $tlv = '';

        foreach ($data as $tag => $value) {
            if (is_array($value)) {
                $nestedValue = $this->buildTLV($value);
                $length = mb_strlen($nestedValue, '8bit'); // byte length
                $tlv .= $tag . str_pad($length, 2, '0', STR_PAD_LEFT) . $nestedValue;
            } else {
                $length = mb_strlen($value, '8bit'); // byte length
                $tlv .= $tag . str_pad($length, 2, '0', STR_PAD_LEFT) . $value;
            }
        }

        return $tlv;
    }

    // EMVCo CRC16 calculation
    private function calculateCRC16($data)
    {
        $crc = 0xFFFF;

        for ($i = 0; $i < strlen($data); $i++) {
            $crc ^= ord($data[$i]) << 8;

            for ($j = 0; $j < 8; $j++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
            }
        }

        return $crc & 0xFFFF;
    }
}
