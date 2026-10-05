<?php

namespace Tests\Unit;

use App\Services\QrisPayloadService;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class QrisPayloadServiceTest extends TestCase
{
    #[Test]
    public function it_converts_static_qris_into_dynamic_qris_with_the_checkout_amount(): void
    {
        $service = new QrisPayloadService;
        $staticPayload = $this->withCrc(implode('', [
            $this->field('00', '01'),
            $this->field('01', '11'),
            $this->field('26', $this->field('00', 'ID.CO.QRIS')),
            $this->field('52', '0000'),
            $this->field('53', '360'),
            $this->field('58', 'ID'),
            $this->field('59', 'WARUNG MODERN'),
            $this->field('60', 'BOGOR'),
        ]));

        $dynamicPayload = $service->convertStaticToDynamic($staticPayload, 87123);
        $fields = $this->parse($dynamicPayload);

        $this->assertSame('12', $this->valueFor($fields, '01'));
        $this->assertSame('87123', $this->valueFor($fields, '54'));
        $this->assertSame('ID.CO.QRIS', $this->valueFor($this->parse($this->valueFor($fields, '26')), '00'));
        $this->assertSame('6304', substr($dynamicPayload, -8, 4));
        $this->assertSame($this->crc(substr($dynamicPayload, 0, -4)), substr($dynamicPayload, -4));
    }

    #[Test]
    public function it_reads_a_static_qris_image_and_renders_the_dynamic_result(): void
    {
        $service = new QrisPayloadService;
        $staticPayload = $this->withCrc($this->field('00', '01').$this->field('01', '11'));
        $path = tempnam(sys_get_temp_dir(), 'qris-');

        try {
            (new QRCode(new QROptions([
                'outputType' => QROutputInterface::GDIMAGE_PNG,
            ])))->render($staticPayload, $path);

            $dynamicPayload = $service->convertStaticImageToDynamic($path, 87123);
            $qrImage = $service->renderDataUri($dynamicPayload);

            $this->assertSame('87123', $this->valueFor($this->parse($dynamicPayload), '54'));
            $this->assertStringStartsWith('data:image/svg+xml;base64,', $qrImage);
            $this->assertStringContainsString('<svg', base64_decode(substr($qrImage, strlen('data:image/svg+xml;base64,'))));
        } finally {
            unlink($path);
        }
    }

    #[Test]
    public function it_rejects_a_static_payload_with_an_invalid_crc(): void
    {
        $service = new QrisPayloadService;
        $payload = $this->withCrc($this->field('00', '01').$this->field('01', '11'));
        $payload = substr($payload, 0, -1).($payload[-1] === '0' ? '1' : '0');

        $this->expectException(InvalidArgumentException::class);

        $service->convertStaticToDynamic($payload, 87123);
    }

    #[Test]
    public function it_rejects_non_positive_checkout_amounts(): void
    {
        $service = new QrisPayloadService;
        $payload = $this->withCrc($this->field('00', '01').$this->field('01', '11'));

        $this->expectException(InvalidArgumentException::class);

        $service->convertStaticToDynamic($payload, 0);
    }

    private function field(string $tag, string $value): string
    {
        return $tag.str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT).$value;
    }

    private function withCrc(string $body): string
    {
        $crcInput = $body.'6304';

        return $crcInput.$this->crc($crcInput);
    }

    private function parse(string $payload): array
    {
        $fields = [];
        $offset = 0;
        $bodyLength = strlen($payload) - 8;

        while ($offset < $bodyLength) {
            $tag = substr($payload, $offset, 2);
            $length = (int) substr($payload, $offset + 2, 2);
            $fields[] = ['tag' => $tag, 'value' => substr($payload, $offset + 4, $length)];
            $offset += 4 + $length;
        }

        return $fields;
    }

    private function valueFor(array $fields, string $tag): string
    {
        foreach ($fields as $field) {
            if ($field['tag'] === $tag) {
                return $field['value'];
            }
        }

        $this->fail("Missing QRIS field {$tag}.");
    }

    private function crc(string $value): string
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
