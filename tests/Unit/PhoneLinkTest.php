<?php

use App\Support\PhoneLink;

/**
 * Call / WhatsApp links in emails must work however the number was typed —
 * a tel: link without "+63" dials as a local number and fails; wa.me needs
 * the country code.
 */
test('call links always dial the Philippine number in international form', function (string $typed, string $tel) {
    expect(PhoneLink::tel($typed))->toBe($tel);
})->with([
    'office mobile' => ['(+63) 977-815-0888', 'tel:+639778150888'],
    'local 09…' => ['0918 555 0101', 'tel:+639185550101'],
    'no spaces' => ['09101930243', 'tel:+639101930243'],
    '+63 …' => ['+63 918 555 0101', 'tel:+639185550101'],
    '63 … without plus' => ['63 994 895 0669', 'tel:+639948950669'],
    'missing leading 0' => ['918 555 0101', 'tel:+639185550101'],
    'landline with area code' => ['(032) 254-8900', 'tel:+63322548900'],
]);

test('WhatsApp links use the country code wa.me expects', function (string $typed, string $wa) {
    expect(PhoneLink::whatsapp($typed))->toBe($wa);
})->with([
    'office mobile' => ['(+63) 977-815-0888', 'https://wa.me/639778150888'],
    'local 09…' => ['0999 111 2222', 'https://wa.me/639991112222'],
    '63 … without plus' => ['63 994 895 0669', 'https://wa.me/639948950669'],
    '+63 …' => ['+63 918 555 0101', 'https://wa.me/639185550101'],
    'missing leading 0' => ['918 555 0101', 'https://wa.me/639185550101'],
]);
