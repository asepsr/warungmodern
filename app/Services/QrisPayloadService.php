<?php

namespace App\Services;

use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use InvalidArgumentException;
use Throwable;

class QrisPayloadService
{
    public function convertStaticImageToDynamic(string $path, int $amount): string
    {
        try {
            $payload = (new QRCode)->readFromFile($path)->data;
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('QRIS image could not be decoded.', 0, $exception);
        }

        return $this->convertStaticToDynamic($payload, $amount);
    }

    public function convertStaticToDynamic(string $payload, int $amount): string
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('QRIS amount must be greater than zero.');
        }

        $fields = $this->parse($payload);
        $this->assertValidCrc($payload);

        $pointOfInitiationIndexes = [];
        $amountIndexes = [];

        foreach ($fields as $index => $field) {
            if ($field['tag'] === '01') {
                $pointOfInitiationIndexes[] = $index;
            }

            if ($field['tag'] === '54') {
                $amountIndexes[] = $index;
            }
        }

        if (count($pointOfInitiationIndexes) !== 1 || $fields[$pointOfInitiationIndexes[0]]['value'] !== '11') {
            throw new InvalidArgumentException('Payload must be a valid static QRIS code.');
        }

        if (count($amountIndexes) > 1) {
            throw new InvalidArgumentException('Payload contains multiple amount fields.');
        }

        $fields[$pointOfInitiationIndexes[0]]['value'] = '12';
        $amountField = ['tag' => '54', 'value' => (string) $amount];

        if ($amountIndexes !== []) {
            $fields[$amountIndexes[0]] = $amountField;
        } else {
            $insertAt = count($fields);

            foreach ($fields as $index => $field) {
                if ($field['tag'] === '58') {
                    $insertAt = $index;
                    break;
                }
            }

            array_splice($fields, $insertAt, 0, [$amountField]);
        }

        $body = '';

        foreach ($fields as $field) {
            $length = strlen($field['value']);

            if ($length > 99) {
                throw new InvalidArgumentException('QRIS field value is too long.');
            }

            $body .= $field['tag'].str_pad((string) $length, 2, '0', STR_PAD_LEFT).$field['value'];
        }

        $crcInput = $body.'6304';

        return $crcInput.$this->calculateCrc($crcInput);
    }

    public function renderDataUri(string $payload): string
    {
        $svg = (new QRCode(new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG,
            'outputBase64' => false,
        ])))->render($payload);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    private function parse(string $payload): array
    {
        if (strlen($payload) < 12 || substr($payload, -8, 4) !== '6304') {
            throw new InvalidArgumentException('Payload is not a complete QRIS message.');
        }

        $fields = [];
        $offset = 0;
        $payloadLength = strlen($payload) - 8;

        while ($offset < $payloadLength) {
            $tag = substr($payload, $offset, 2);
            $lengthText = substr($payload, $offset + 2, 2);

            if (strlen($tag) !== 2 || ! ctype_digit($tag) || strlen($lengthText) !== 2 || ! ctype_digit($lengthText)) {
                throw new InvalidArgumentException('Payload contains an invalid TLV header.');
            }

            $length = (int) $lengthText;
            $offset += 4;

            if ($offset + $length > $payloadLength) {
                throw new InvalidArgumentException('Payload contains a truncated TLV value.');
            }

            $fields[] = [
                'tag' => $tag,
                'value' => substr($payload, $offset, $length),
            ];
            $offset += $length;
        }

        return $fields;
    }

    private function assertValidCrc(string $payload): void
    {
        $crcInput = substr($payload, 0, -4);
        $expectedCrc = $this->calculateCrc($crcInput);
        $providedCrc = strtoupper(substr($payload, -4));

        if (! hash_equals($expectedCrc, $providedCrc)) {
            throw new InvalidArgumentException('Payload has an invalid QRIS CRC.');
        }
    }

    private function calculateCrc(string $value): string
    {
        $crc = 0xFFFF;

        for ($index = 0, $length = strlen($value); $index < $length; $index++) {
            $crc ^= ord($value[$index]) << 8;

            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) !== 0
                    ? (($crc << 1) ^ 0x1021) & 0xFFFF
                    : ($crc << 1) & 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}
