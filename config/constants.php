<?php
// Single airline: its details live here instead of in an airlines table.
const AIRLINE_NAME = 'PK Airline';
const AIRLINE_CODE = 'PK';
const SITE_NAME = AIRLINE_NAME;
const AIRCRAFT_TYPE = 'ATR 72';
const BAGGAGE_ALLOWANCE = '15 kg';

// PK Airline operates on Nepal time. XAMPP's php.ini defaults to Europe/Berlin, while MySQL's NOW() uses
// the system clock, so PHP must be set to match or deadlines and "today" disagree between PHP and SQL.
date_default_timezone_set('Asia/Kathmandu');

// A chosen seat is held this long while the customer pays; after that the hold lapses and the seat is free.
const HOLD_MINUTES = 10;

// Booking (and paying) closes this many minutes before departure.
const BOOKING_CUTOFF_MINUTES = 30;

// Simulated payment provider. Any Nepal Pay login whose phone number starts with the prefix succeeds
// (any password); every other number fails with "credentials did not match".
const NEPAL_PAY_NAME = 'Nepal Pay';
const NEPAL_PAY_PHONE_PREFIX = '98426880';

// Seat letters in one row; seat numbers (1A, 1B, ...) are generated from flights.total_seats.
const SEAT_LETTERS = ['A', 'B', 'C', 'D'];

// Derive the app's URL prefix from its location under the document root, so links work both
// under XAMPP (/airlines-ticket-booking-system/) and with `php -S` run from the project root (/).
// define() rather than const: the value is computed at runtime and chosen conditionally.
$appRootPath = str_replace('\\', '/', (string) realpath(__DIR__ . '/..'));
$docRootPath = rtrim(str_replace('\\', '/', (string) realpath($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');

if ($docRootPath !== '' && stripos($appRootPath, $docRootPath) === 0) {
	define('BASE_URL', substr($appRootPath, strlen($docRootPath)) . '/');
} else {
	define('BASE_URL', '/airlines-ticket-booking-system/');
}

unset($appRootPath, $docRootPath);

session_start();
